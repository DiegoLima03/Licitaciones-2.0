<?php
declare(strict_types=1);

session_start();
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../src/Repositories/AnalyticsRepository.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

/** @var array<string, mixed> $user */
$user = $_SESSION['user'];
$email = (string)($user['email'] ?? '');
$fullName = (string)($user['full_name'] ?? '');
$role = (string)($user['role'] ?? '');

$materialRaw = trim((string)($_GET['material'] ?? ''));
$deviationMaterialRaw = trim((string)($_GET['deviation_material'] ?? ''));
$currentPriceRaw = trim((string)($_GET['current_price'] ?? ''));

$currentPrice = null;
if ($currentPriceRaw !== '') {
    $priceNorm = str_replace(',', '.', $currentPriceRaw);
    if (is_numeric($priceNorm) && (float)$priceNorm >= 0.0) {
        $currentPrice = (float)$priceNorm;
    }
}

$errors = [];
$repoError = null;

/** @var array<string, array<int, array<string, float|string>>> $materialTrends */
$materialTrends = ['pvu' => [], 'pcu' => []];
/** @var array<int, array<string, float|string>> $riskPipeline */
$riskPipeline = [['category' => 'Comparativa', 'pipeline_bruto' => 0.0, 'pipeline_ajustado' => 0.0]];
/** @var array<int, array<string, mixed>> $sweetSpots */
$sweetSpots = [];
/** @var array<string, mixed>|null $priceDeviation */
$priceDeviation = null;

try {
    $repo = new AnalyticsRepository();

    $idsAnalisis = $repo->getEstadoIdsByNames(['En analisis', 'En análisis', 'EN ANALISIS', 'EN ANÁLISIS']);
    if ($idsAnalisis !== []) {
        $riskPipeline = $repo->getRiskAdjustedPipeline((int)$idsAnalisis[0]);
    }

    $idsCerrados = $repo->getEstadoIdsByNames(['Adjudicada', 'No adjudicada', 'Terminada']);
    $sweetSpots = $repo->getSweetSpots($idsCerrados);

    if ($materialRaw !== '') {
        $materialTrends = $repo->getMaterialTrends($materialRaw);
    }

    if ($deviationMaterialRaw !== '' || $currentPriceRaw !== '') {
        if ($deviationMaterialRaw === '') {
            $errors[] = 'Para comprobar desviacion debes indicar el producto.';
        } elseif ($currentPrice === null) {
            $errors[] = 'El precio actual debe ser numerico y mayor o igual que 0.';
        } else {
            $priceDeviation = $repo->getPriceDeviationCheck($deviationMaterialRaw, $currentPrice);
        }
    }
} catch (\Throwable $e) {
    $repoError = 'No se pudieron cargar los datos de analitica.';
}

$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$fmtEuro = static function (float $value): string {
    return number_format($value, 0, ',', '.') . ' &euro;';
};

$fmtNumber = static function (float $value, int $decimals = 2): string {
    return number_format($value, $decimals, ',', '.');
};

$fmtPercent = static function (?float $value, int $decimals = 1): string {
    if ($value === null) {
        return '&mdash;';
    }
    return number_format($value, $decimals, ',', '.') . '%';
};

$pvuPoints = (isset($materialTrends['pvu']) && is_array($materialTrends['pvu'])) ? $materialTrends['pvu'] : [];
$pcuPoints = (isset($materialTrends['pcu']) && is_array($materialTrends['pcu'])) ? $materialTrends['pcu'] : [];

$riskRow = (isset($riskPipeline[0]) && is_array($riskPipeline[0])) ? $riskPipeline[0] : ['pipeline_bruto' => 0.0, 'pipeline_ajustado' => 0.0];
$riskBruto = (float)($riskRow['pipeline_bruto'] ?? 0.0);
$riskAjustado = (float)($riskRow['pipeline_ajustado'] ?? 0.0);
$riskRatio = $riskBruto > 0 ? max(0.0, min(100.0, ($riskAjustado / $riskBruto) * 100.0)) : 0.0;

$summaryByEstado = [];
foreach ($sweetSpots as $row) {
    $estado = trim((string)($row['estado'] ?? 'Desconocido'));
    if (!isset($summaryByEstado[$estado])) {
        $summaryByEstado[$estado] = 0;
    }
    $summaryByEstado[$estado]++;
}
ksort($summaryByEstado, SORT_NATURAL | SORT_FLAG_CASE);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Anal&iacute;tica</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        a { color: inherit; }
        .layout { display: flex; min-height: 100vh; }
        .sidebar { width: 220px; padding: 16px 14px; display: flex; flex-direction: column; }
        .sidebar-logo { font-weight: 600; font-size: 1rem; margin-bottom: 18px; }
        .sidebar-nav { display: flex; flex-direction: column; gap: 4px; margin-bottom: auto; }
        .nav-link { display: block; padding: 8px 10px; border-radius: 8px; text-decoration: none; font-size: 0.9rem; }
        .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }

        .analytics-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; padding: 22px 24px; }
        .analytics-header-copy { display: grid; gap: 6px; }
        .analytics-kicker { margin: 0; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
        .analytics-title { margin: 0; font-size: 1.75rem; line-height: 1.08; font-weight: 700; }
        .analytics-subtitle { margin: 0; max-width: 760px; font-size: 0.92rem; }
        .user-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            font-size: 0.85rem;
        }
        .user-top {
            display: flex; align-items: center; gap: 8px;
        }
        .user-avatar {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.78rem;
            flex-shrink: 0;
        }
        .user-name {
            font-weight: 600; color: #e5e7eb; font-size: 0.85rem;
        }
        .user-meta {
            display: flex; align-items: center; gap: 8px; justify-content: center;
        }
        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            background-color: #1e293b;
            color: #a5b4fc;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }
        .logout-link {
            color: #94a3b8;
            font-size: 0.75rem;
            text-decoration: none;
            transition: color 0.15s;
        }
        .logout-link:hover { color: #f87171; }

        .analytics-main { max-width: 1330px; width: 100%; margin: 0 auto; padding: 26px 18px 38px; display: grid; gap: 18px; }

        .message-stack { display: grid; gap: 10px; }
        .error { border: 1px solid rgba(200, 60, 50, 0.35); background: #fff4f2; color: #8c2e24; border-radius: 14px; padding: 13px 15px; font-size: 0.92rem; }

        .grid-2 { display: grid; gap: 18px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .grid-1 { display: grid; gap: 18px; }

        .analytics-card {
            position: relative; overflow: visible; padding: 22px 18px 16px;
            border-radius: 18px !important;
            border-top: 4px solid var(--vz-verde) !important;
        }
        .analytics-card--pipeline { border-image: linear-gradient(90deg, #2e77d0, #2aa69d) 1 !important; border-top-width: 4px !important; border-top-style: solid !important; }
        .analytics-card--sweetspots { border-image: linear-gradient(90deg, var(--vz-verde), #d4a830) 1 !important; border-top-width: 4px !important; border-top-style: solid !important; }
        .analytics-card--deviation { border-image: linear-gradient(90deg, #c48a18, #d4a830) 1 !important; border-top-width: 4px !important; border-top-style: solid !important; }

        .analytics-card h2 { margin: 0; font-size: 1.28rem; line-height: 1.12; }
        .hint { margin: 8px 0 0; font-size: 0.88rem; line-height: 1.45; }

        .input-row { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .input-row input, .input-row button {
            min-height: 40px; border-radius: 12px; border: 1px solid rgba(133, 114, 94, 0.66); padding: 0 12px; font-size: 0.92rem;
        }
        .input-row button {
            background: var(--vz-verde) !important; border-color: var(--vz-verde) !important;
            color: var(--vz-crema) !important; font-weight: 700; cursor: pointer;
        }
        .field-material { min-width: 260px; }
        .field-price { width: 150px; }

        .chart-wrap { margin-top: 14px; border: 1px solid rgba(133, 114, 94, 0.28); border-radius: 14px; padding: 12px; background: #fcfbf7; position: relative; }
        .chart-wrap canvas { width: 100% !important; max-height: 280px; }


        .chips { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 8px; }
        .chip { font-size: 0.78rem; border-radius: 999px; border: 1px solid rgba(133, 114, 94, 0.42); background: #f8f5ee; padding: 5px 10px; font-weight: 600; }

        .scatter-wrap { margin-top: 14px; border: 1px solid rgba(133, 114, 94, 0.28); border-radius: 14px; padding: 12px; background: #fcfbf7; }
        .scatter-wrap canvas { width: 100% !important; max-height: 260px; }

        .deviation { margin-top: 14px; border-radius: 14px; border: 1px solid rgba(133, 114, 94, 0.34); border-left-width: 4px; padding: 13px 14px; background: #faf8f2; }
        .deviation strong { display: block; margin-bottom: 7px; font-size: 0.9rem; }
        .deviation.up { border-left-color: #c83c32; background: #fff4f2; }
        .deviation.down { border-left-color: #d4a830; background: #fff9ea; }
        .deviation.ok { border-left-color: var(--vz-verde); background: #f7f8eb; }
        .deviation div + div { margin-top: 4px; }

        .empty { margin-top: 14px; padding: 14px 15px; border-radius: 14px; border: 1px dashed rgba(133, 114, 94, 0.5); background: #faf8f1; font-size: 0.9rem; }

        /* Autocomplete */
        .ac-wrapper { position: relative; flex: 1; min-width: 260px; }
        .ac-wrapper input { width: 100%; }
        .ac-list {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 50;
            max-height: 220px; overflow-y: auto;
            background: #fff; border: 1px solid rgba(133, 114, 94, 0.5); border-top: none;
            border-radius: 0 0 12px 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.12);
            display: none;
        }
        .ac-list.open { display: block; }
        .ac-item {
            padding: 8px 12px; font-size: 0.88rem; cursor: pointer;
            border-bottom: 1px solid rgba(133, 114, 94, 0.15);
        }
        .ac-item:last-child { border-bottom: none; }
        .ac-item:hover, .ac-item.active { background: rgba(133, 114, 94, 0.1); }
        .ac-item small { display: block; font-size: 0.74rem; color: var(--vz-marron2, #85725e); }

        @media (max-width: 980px) { .grid-2 { grid-template-columns: 1fr; } }
        @media (max-width: 768px) {
            .layout { flex-direction: column; }
            .sidebar { width: 100%; flex-direction: row; align-items: center; justify-content: space-between; }
            .sidebar-nav { flex-direction: row; gap: 6px; }
            .analytics-header { padding: 18px 16px; flex-direction: column; }
            .analytics-title { font-size: 1.4rem; }
            .analytics-main { padding: 18px 12px 28px; }
            .field-material, .field-price { width: 100%; min-width: 0; }
            .input-row button { width: 100%; justify-content: center; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/master-detail-theme.css">
</head>
<body>
    <div class="layout">
        <?php $activePage = 'analytics'; include __DIR__ . '/partials/sidebar.php'; ?>

        <div class="main">
            <header class="analytics-header">
                <div class="analytics-header-copy">
                    <p class="analytics-kicker">Panel estrat&eacute;gico</p>
                    <h1 class="analytics-title">Anal&iacute;tica de licitaciones</h1>
                    <p class="analytics-subtitle">KPIs, tendencias de precio, pipeline y comparativas de resultados.</p>
                </div>
                <div class="user-info">
                    <?php
                        $displayName = $fullName !== '' ? $fullName : $email;
                        $initials = '';
                        $parts = explode(' ', trim($displayName));
                        foreach (array_slice($parts, 0, 2) as $p) {
                            if ($p !== '') $initials .= mb_strtoupper(mb_substr($p, 0, 1));
                        }
                    ?>
                    <div class="user-top">
                        <div class="user-avatar"><?php echo $initials; ?></div>
                        <span class="user-name"><?php echo $h($displayName); ?></span>
                    </div>
                    <div class="user-meta">
                        <?php if ($role !== ''): ?>
                            <span class="pill"><?php echo $h($role); ?></span>
                        <?php endif; ?>
                        <a href="logout.php" class="logout-link">Cerrar sesi&oacute;n</a>
                    </div>
                </div>
            </header>

            <main class="analytics-main">

                <?php if ($repoError !== null || $errors !== []): ?>
                    <div class="message-stack">
                        <?php if ($repoError !== null): ?><div class="error"><?php echo $h($repoError); ?></div><?php endif; ?>
                        <?php foreach ($errors as $err): ?><div class="error"><?php echo $h((string)$err); ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Row 1: Tendencia + Pipeline -->
                <section class="grid-2">
                    <article class="card analytics-card analytics-card--trend">
                        <h2>Tendencia de precios</h2>
                        <p class="hint">PVU y PCU hist&oacute;ricos por producto.</p>

                        <form method="get" class="input-row">
                            <div class="ac-wrapper">
                                <input type="text" name="material" id="ac-material" autocomplete="off" placeholder="Nombre del producto..." value="<?php echo $h($materialRaw); ?>">
                                <div class="ac-list" id="ac-material-list"></div>
                            </div>
                            <button type="submit">Cargar</button>
                        </form>

                        <?php if ($materialRaw === ''): ?>
                            <p class="empty">Introduce un producto para ver su tendencia.</p>
                        <?php elseif ($pvuPoints === [] && $pcuPoints === []): ?>
                            <p class="empty">Sin datos para &ldquo;<?php echo $h($materialRaw); ?>&rdquo;.</p>
                        <?php else: ?>
                            <div class="chart-wrap">
                                <canvas id="trendChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </article>

                    <article class="card analytics-card analytics-card--pipeline">
                        <h2>Venta presupuestada vs precio medio</h2>
                        <p class="hint">Pipeline en estado &laquo;En an&aacute;lisis&raquo;. Ajustado / bruto: <strong><?php echo $fmtPercent($riskRatio, 1); ?></strong></p>

                        <div class="chart-wrap">
                            <canvas id="pipelineChart"></canvas>
                        </div>
                    </article>
                </section>

                <!-- Row 2: Sweet Spots scatter + Desviacion -->
                <section class="grid-2">
                    <article class="card analytics-card analytics-card--sweetspots">
                        <h2>Sweet spots</h2>
                        <p class="hint">Ganadas vs no adjudicadas por presupuesto.</p>

                        <?php if ($summaryByEstado !== []): ?>
                            <div class="chips">
                                <?php foreach ($summaryByEstado as $estado => $count): ?>
                                    <span class="chip"><?php echo $h((string)$estado); ?>: <?php echo $h((string)$count); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($sweetSpots === []): ?>
                            <p class="empty">No hay licitaciones cerradas.</p>
                        <?php else: ?>
                            <div class="scatter-wrap">
                                <canvas id="sweetSpotChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </article>

                    <article class="card analytics-card analytics-card--deviation">
                        <h2>Desviaci&oacute;n de precio</h2>
                        <p class="hint">Compara precio actual con media hist&oacute;rica.</p>

                        <form method="get" class="input-row">
                            <div class="ac-wrapper">
                                <input type="text" name="deviation_material" id="ac-deviation" autocomplete="off" placeholder="Producto..." value="<?php echo $h($deviationMaterialRaw); ?>">
                                <div class="ac-list" id="ac-deviation-list"></div>
                            </div>
                            <input type="number" step="0.01" min="0" name="current_price" class="field-price" placeholder="Precio actual" value="<?php echo $h($currentPriceRaw); ?>">
                            <button type="submit">Comprobar</button>
                        </form>

                        <?php if ($priceDeviation !== null): ?>
                            <?php
                                $isDev = (bool)($priceDeviation['is_deviated'] ?? false);
                                $devPct = (float)($priceDeviation['deviation_percentage'] ?? 0);
                                $avgHist = (float)($priceDeviation['historical_avg'] ?? 0);
                                $rec = (string)($priceDeviation['recommendation'] ?? '');
                                $devClass = 'deviation ok';
                                if ($isDev && $devPct > 0) { $devClass = 'deviation up'; }
                                elseif ($isDev && $devPct < 0) { $devClass = 'deviation down'; }
                            ?>
                            <div class="<?php echo $h($devClass); ?>">
                                <strong>Resultado</strong>
                                <div>Desviaci&oacute;n: <?php echo $fmtPercent($devPct, 2); ?></div>
                                <div>Media hist&oacute;rica: <?php echo $fmtEuro($avgHist); ?></div>
                                <div style="margin-top:6px;"><?php echo $h($rec); ?></div>
                            </div>
                        <?php elseif ($deviationMaterialRaw !== '' || $currentPriceRaw !== ''): ?>
                            <p class="empty">No se pudo calcular la desviaci&oacute;n.</p>
                        <?php else: ?>
                            <p class="empty">Introduce producto y precio para comprobar.</p>
                        <?php endif; ?>
                    </article>
                </section>

            </main>
        </div>
    </div>

    <script>
    (function () {
        /* ── Pipeline vertical bar chart ──────────────────────── */
        (function () {
            var ctx = document.getElementById('pipelineChart');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Pipeline bruto', 'Pipeline ajustado'],
                    datasets: [{
                        data: [<?php echo round($riskBruto, 2); ?>, <?php echo round($riskAjustado, 2); ?>],
                        backgroundColor: ['rgba(59, 130, 246, 0.7)', 'rgba(245, 158, 11, 0.7)'],
                        borderColor: ['#3b82f6', '#f59e0b'],
                        borderWidth: 2,
                        borderRadius: 6,
                        barPercentage: 0.5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return ctx.parsed.y.toLocaleString('es-ES') + ' \u20ac';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 12, weight: 'bold' } }
                        },
                        y: {
                            grid: { color: 'rgba(133,114,94,0.12)' },
                            ticks: {
                                callback: function (v) {
                                    if (v >= 1000) return (v / 1000).toFixed(0) + 'k \u20ac';
                                    return v + ' \u20ac';
                                },
                                font: { size: 10 }
                            }
                        }
                    }
                }
            });
        })();

        /* ── Trend Chart (PVU + PCU dual-line) ────────────────── */
        <?php if ($materialRaw !== '' && ($pvuPoints !== [] || $pcuPoints !== [])): ?>
        (function () {
            var pvuData = <?php echo json_encode(array_map(static function (array $p): array {
                return ['x' => (string)($p['time'] ?? ''), 'y' => round((float)($p['value'] ?? 0), 2)];
            }, $pvuPoints), JSON_UNESCAPED_UNICODE); ?>;

            var pcuData = <?php echo json_encode(array_map(static function (array $p): array {
                return ['x' => (string)($p['time'] ?? ''), 'y' => round((float)($p['value'] ?? 0), 2)];
            }, $pcuPoints), JSON_UNESCAPED_UNICODE); ?>;

            // Collect all unique dates and sort
            var dateSet = {};
            pvuData.forEach(function (p) { dateSet[p.x] = true; });
            pcuData.forEach(function (p) { dateSet[p.x] = true; });
            var labels = Object.keys(dateSet).sort();

            // Build sparse datasets indexed by label
            var pvuMap = {};
            pvuData.forEach(function (p) { pvuMap[p.x] = p.y; });
            var pcuMap = {};
            pcuData.forEach(function (p) { pcuMap[p.x] = p.y; });

            var pvuValues = labels.map(function (d) { return pvuMap[d] !== undefined ? pvuMap[d] : null; });
            var pcuValues = labels.map(function (d) { return pcuMap[d] !== undefined ? pcuMap[d] : null; });

            var ctx = document.getElementById('trendChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'PVU',
                                data: pvuValues,
                                borderColor: '#059669',
                                backgroundColor: 'rgba(5, 150, 105, 0.1)',
                                borderWidth: 2,
                                pointRadius: 3,
                                pointHoverRadius: 5,
                                tension: 0.3,
                                spanGaps: true,
                                fill: true
                            },
                            {
                                label: 'PCU',
                                data: pcuValues,
                                borderColor: '#d97706',
                                backgroundColor: 'rgba(217, 119, 6, 0.08)',
                                borderWidth: 2,
                                pointRadius: 3,
                                pointHoverRadius: 5,
                                tension: 0.3,
                                spanGaps: true,
                                fill: true
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'top', labels: { usePointStyle: true, padding: 14 } },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        return ctx.dataset.label + ': ' + (ctx.parsed.y !== null ? ctx.parsed.y.toFixed(2) + ' \u20ac' : '-');
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: { color: 'rgba(133,114,94,0.12)' },
                                ticks: { maxTicksLimit: 10, font: { size: 10 } }
                            },
                            y: {
                                grid: { color: 'rgba(133,114,94,0.12)' },
                                ticks: {
                                    callback: function (v) { return v.toFixed(2) + ' \u20ac'; },
                                    font: { size: 10 }
                                }
                            }
                        }
                    }
                });
            }
        })();
        <?php endif; ?>

        /* ── Sweet Spot Scatter Chart ─────────────────────────── */
        <?php if ($sweetSpots !== []): ?>
        (function () {
            var raw = <?php echo json_encode(array_map(static function (array $r): array {
                $estado = mb_strtolower(trim((string)($r['estado'] ?? '')), 'UTF-8');
                $ganada = (str_contains($estado, 'adjudicad') && !str_contains($estado, 'no adjud')) || str_contains($estado, 'terminad');
                return [
                    'x' => round((float)($r['presupuesto'] ?? 0), 2),
                    'y' => $ganada ? 1 : 0,
                    'label' => (string)($r['cliente'] ?? ''),
                    'estado' => (string)($r['estado'] ?? ''),
                ];
            }, $sweetSpots), JSON_UNESCAPED_UNICODE); ?>;

            var won = [], lost = [];
            raw.forEach(function (p) {
                if (p.y === 1) won.push(p); else lost.push(p);
            });

            var ctx = document.getElementById('sweetSpotChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'scatter',
                    data: {
                        datasets: [
                            {
                                label: 'Ganadas',
                                data: won,
                                backgroundColor: 'rgba(5, 150, 105, 0.7)',
                                borderColor: '#059669',
                                pointRadius: 6,
                                pointHoverRadius: 9
                            },
                            {
                                label: 'No adjudicadas',
                                data: lost,
                                backgroundColor: 'rgba(220, 38, 38, 0.7)',
                                borderColor: '#dc2626',
                                pointRadius: 6,
                                pointHoverRadius: 9
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'top', labels: { usePointStyle: true, padding: 14 } },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var p = ctx.raw;
                                        return p.label + ' \u2014 ' + p.x.toLocaleString('es-ES') + ' \u20ac (' + p.estado + ')';
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                title: { display: true, text: 'Presupuesto (\u20ac)', font: { size: 11 } },
                                grid: { color: 'rgba(133,114,94,0.12)' },
                                ticks: {
                                    callback: function (v) {
                                        if (v >= 1000) return (v / 1000).toFixed(0) + 'k \u20ac';
                                        return v + ' \u20ac';
                                    },
                                    font: { size: 10 }
                                }
                            },
                            y: {
                                title: { display: true, text: 'Resultado', font: { size: 11 } },
                                grid: { color: 'rgba(133,114,94,0.12)' },
                                min: -0.3,
                                max: 1.3,
                                ticks: {
                                    stepSize: 1,
                                    callback: function (v) {
                                        if (v === 0) return 'Perdida';
                                        if (v === 1) return 'Ganada';
                                        return '';
                                    },
                                    font: { size: 10 }
                                }
                            }
                        }
                    }
                });
            }
        })();
        <?php endif; ?>

        /* ── Autocomplete de productos ────────────────────────── */
        function initAutocomplete(inputId, listId) {
            var input = document.getElementById(inputId);
            var list = document.getElementById(listId);
            if (!input || !list) return;

            var debounceTimer = null;
            var activeIdx = -1;
            var items = [];

            function render(results) {
                items = results;
                activeIdx = -1;
                if (results.length === 0) { list.classList.remove('open'); list.innerHTML = ''; return; }
                list.innerHTML = results.map(function (r, i) {
                    var sub = r.nombre_proveedor ? '<small>' + r.nombre_proveedor + '</small>' : '';
                    return '<div class="ac-item" data-idx="' + i + '">' + r.nombre + sub + '</div>';
                }).join('');
                list.classList.add('open');
            }

            function select(idx) {
                if (idx < 0 || idx >= items.length) return;
                input.value = items[idx].nombre;
                list.classList.remove('open');
                list.innerHTML = '';
            }

            input.addEventListener('input', function () {
                var q = input.value.trim();
                clearTimeout(debounceTimer);
                if (q.length < 2) { render([]); return; }
                debounceTimer = setTimeout(function () {
                    fetch('productos-search.php?q=' + encodeURIComponent(q) + '&limit=0')
                        .then(function (r) { return r.json(); })
                        .then(function (data) { if (Array.isArray(data)) render(data); })
                        .catch(function () { render([]); });
                }, 250);
            });

            input.addEventListener('keydown', function (e) {
                if (!list.classList.contains('open')) return;
                if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, items.length - 1); highlight(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); highlight(); }
                else if (e.key === 'Enter' && activeIdx >= 0) { e.preventDefault(); select(activeIdx); }
                else if (e.key === 'Escape') { list.classList.remove('open'); }
            });

            function highlight() {
                var els = list.querySelectorAll('.ac-item');
                els.forEach(function (el, i) { el.classList.toggle('active', i === activeIdx); });
                if (els[activeIdx]) els[activeIdx].scrollIntoView({ block: 'nearest' });
            }

            list.addEventListener('mousedown', function (e) {
                var item = e.target.closest('.ac-item');
                if (item) { e.preventDefault(); select(parseInt(item.dataset.idx, 10)); }
            });

            document.addEventListener('click', function (e) {
                if (!e.target.closest('#' + inputId) && !e.target.closest('#' + listId)) {
                    list.classList.remove('open');
                }
            });
        }

        initAutocomplete('ac-material', 'ac-material-list');
        initAutocomplete('ac-deviation', 'ac-deviation-list');
    })();
    </script>
</body>
</html>
