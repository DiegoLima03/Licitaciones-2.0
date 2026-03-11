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

$fechaDesdeRaw = trim((string)($_GET['fecha_adjudicacion_desde'] ?? ''));
$fechaHastaRaw = trim((string)($_GET['fecha_adjudicacion_hasta'] ?? ''));
$materialRaw = trim((string)($_GET['material'] ?? ''));
$deviationMaterialRaw = trim((string)($_GET['deviation_material'] ?? ''));
$currentPriceRaw = trim((string)($_GET['current_price'] ?? ''));

$isValidDate = static function (string $value): bool {
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
};

$fechaDesde = $isValidDate($fechaDesdeRaw) ? $fechaDesdeRaw : null;
$fechaHasta = $isValidDate($fechaHastaRaw) ? $fechaHastaRaw : null;

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
$riskPipeline = [[
    'category' => 'Comparativa',
    'pipeline_bruto' => 0.0,
    'pipeline_ajustado' => 0.0,
]];
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
    return number_format($value, 0, ',', '.') . ' EUR';
};

$fmtNumber = static function (float $value, int $decimals = 2): string {
    return number_format($value, $decimals, ',', '.');
};

$fmtPercent = static function (?float $value, int $decimals = 1): string {
    if ($value === null) {
        return '-';
    }
    return number_format($value, $decimals, ',', '.') . ' %';
};

$buildPolyline = static function (array $points, int $width = 560, int $height = 170): string {
    if (count($points) < 2) {
        return '';
    }

    $values = [];
    foreach ($points as $p) {
        if (isset($p['value']) && is_numeric($p['value'])) {
            $values[] = (float)$p['value'];
        }
    }

    if (count($values) < 2) {
        return '';
    }

    $min = min($values);
    $max = max($values);
    if (abs($max - $min) < 0.000001) {
        $max = $min + 1.0;
    }

    $leftPad = 14;
    $rightPad = 10;
    $topPad = 10;
    $bottomPad = 16;

    $plotW = max(1, $width - $leftPad - $rightPad);
    $plotH = max(1, $height - $topPad - $bottomPad);
    $n = count($values);

    $coords = [];
    for ($i = 0; $i < $n; $i++) {
        $x = $leftPad + ($n > 1 ? ($plotW * $i / ($n - 1)) : 0);
        $norm = (($values[$i] - $min) / ($max - $min));
        $y = $topPad + $plotH * (1.0 - $norm);
        $coords[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
    }

    return implode(' ', $coords);
};

$pvuPoints = (isset($materialTrends['pvu']) && is_array($materialTrends['pvu'])) ? $materialTrends['pvu'] : [];
$pcuPoints = (isset($materialTrends['pcu']) && is_array($materialTrends['pcu'])) ? $materialTrends['pcu'] : [];

$pvuLine = $buildPolyline($pvuPoints);
$pcuLine = $buildPolyline($pcuPoints);

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
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: #020617;
            color: #e5e7eb;
        }
        a { color: inherit; }
        .layout {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 220px;
            padding: 16px 14px;
            display: flex;
            flex-direction: column;
        }
        .sidebar-logo {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 18px;
        }
        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: auto;
        }
        .nav-link {
            display: block;
            padding: 8px 10px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .analytics-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            padding: 22px 24px;
        }
        .analytics-header-copy {
            display: grid;
            gap: 6px;
        }
        .analytics-kicker {
            margin: 0;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(229, 226, 220, 0.82);
        }
        .analytics-title {
            margin: 0;
            font-size: 1.75rem;
            line-height: 1.08;
            font-weight: 700;
        }
        .analytics-subtitle {
            margin: 0;
            max-width: 760px;
            font-size: 0.92rem;
            color: rgba(229, 226, 220, 0.9);
        }
        .user-info {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
            text-align: right;
        }
        .user-name {
            font-size: 0.96rem;
            font-weight: 700;
            line-height: 1.1;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 0 12px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: lowercase;
            letter-spacing: 0.02em;
            border: 1px solid rgba(70, 51, 31, 0.35);
            background: rgba(229, 226, 220, 0.92);
            color: var(--vz-marron1);
        }
        .logout-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 0 14px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid rgba(200, 60, 50, 0.7);
            background: var(--vz-rojo);
            color: var(--vz-blanco);
            box-shadow: 0 6px 16px rgba(200, 60, 50, 0.2);
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }
        .logout-link:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 18px rgba(200, 60, 50, 0.26);
            background: #b9342b;
        }
        .analytics-main {
            max-width: 1330px;
            width: 100%;
            margin: 0 auto;
            padding: 26px 18px 38px;
            display: grid;
            gap: 18px;
        }
        .analytics-toolbar {
            background: var(--vz-blanco);
            border: 1px solid rgba(133, 114, 94, 0.42);
            border-radius: 18px;
            box-shadow: 0 10px 28px rgba(16, 24, 14, 0.08);
            padding: 18px;
            display: grid;
            gap: 14px;
        }
        .analytics-toolbar-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }
        .analytics-toolbar-head h2 {
            margin: 0 0 4px;
            font-size: 1.12rem;
            color: var(--vz-negro);
        }
        .analytics-toolbar-head p {
            margin: 0;
            font-size: 0.85rem;
            color: var(--vz-marron2);
            max-width: 520px;
        }
        .filter-bar {
            display: grid;
            grid-template-columns: repeat(2, minmax(180px, 1fr)) auto auto;
            gap: 10px;
            align-items: end;
            padding: 14px;
            border-radius: 14px;
            border: 1px solid rgba(133, 114, 94, 0.28);
            background:
                linear-gradient(135deg, rgba(16, 24, 14, 0.04), rgba(142, 139, 48, 0.08)),
                var(--vz-crema);
        }
        .filter-bar > div {
            display: grid;
            gap: 6px;
        }
        .filter-bar label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--vz-marron1);
        }
        .filter-bar input,
        .filter-bar button,
        .filter-bar a {
            min-height: 42px;
            border-radius: 12px;
            border: 1px solid rgba(133, 114, 94, 0.66);
            padding: 0 14px;
            font-size: 0.92rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .filter-bar input {
            width: 100%;
            background: var(--vz-blanco);
            color: var(--vz-negro);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.5);
        }
        .filter-bar button,
        .filter-bar a {
            font-weight: 700;
            white-space: nowrap;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }
        .filter-bar button {
            background: var(--vz-verde);
            color: var(--vz-crema);
            border-color: var(--vz-verde);
            box-shadow: 0 8px 16px rgba(142, 139, 48, 0.2);
        }
        .filter-bar button:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 22px rgba(142, 139, 48, 0.26);
        }
        .filter-bar a {
            background: var(--vz-blanco);
            color: var(--vz-marron1);
        }
        .filter-bar a:hover {
            transform: translateY(-1px);
            background: #f7f4ed;
        }
        .message-stack {
            display: grid;
            gap: 10px;
        }
        .error {
            border: 1px solid rgba(200, 60, 50, 0.35);
            background: #fff4f2;
            color: #8c2e24;
            border-radius: 14px;
            padding: 13px 15px;
            font-size: 0.92rem;
            box-shadow: 0 8px 18px rgba(200, 60, 50, 0.08);
        }
        .grid-2 {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .analytics-card {
            position: relative;
            overflow: hidden;
            padding: 18px 18px 16px;
            border-radius: 18px !important;
            box-shadow: 0 12px 28px rgba(16, 24, 14, 0.07) !important;
        }
        .analytics-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: linear-gradient(90deg, var(--vz-verde), #2aa69d);
        }
        .analytics-card--pipeline::before {
            background: linear-gradient(90deg, #2e77d0, #2aa69d);
        }
        .analytics-card--sweetspots::before {
            background: linear-gradient(90deg, var(--vz-verde), #d4a830);
        }
        .analytics-card--deviation::before {
            background: linear-gradient(90deg, #c48a18, #d4a830);
        }
        .analytics-card h2 {
            margin: 0;
            font-size: 1.28rem;
            line-height: 1.12;
            color: var(--vz-negro);
        }
        .hint {
            margin: 8px 0 0;
            font-size: 0.88rem;
            line-height: 1.45;
            color: var(--vz-marron2);
        }
        .input-row {
            margin-top: 14px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .input-row input,
        .input-row button {
            min-height: 40px;
            border-radius: 12px;
            border: 1px solid rgba(133, 114, 94, 0.66);
            background: var(--vz-blanco);
            color: var(--vz-negro);
            padding: 0 12px;
            font-size: 0.92rem;
        }
        .input-row button {
            background: #21304c;
            border-color: #21304c;
            color: var(--vz-blanco);
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .input-row button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 18px rgba(33, 48, 76, 0.2);
        }
        .field-material {
            min-width: 260px;
        }
        .field-price {
            width: 150px;
        }
        .chart-box {
            margin-top: 14px;
            border: 1px solid rgba(133, 114, 94, 0.28);
            border-radius: 14px;
            background:
                linear-gradient(180deg, rgba(142, 139, 48, 0.08), rgba(255, 255, 255, 0)) ,
                #fcfbf7;
            padding: 12px;
        }
        .chart-head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 8px;
            margin-bottom: 10px;
        }
        .chart-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--vz-marron1);
        }
        .chart-meta {
            font-size: 0.76rem;
            color: var(--vz-marron2);
        }
        svg.chart {
            width: 100%;
            height: 170px;
            display: block;
            border-radius: 10px;
            background:
                linear-gradient(to bottom, rgba(133, 114, 94, 0.12) 1px, transparent 1px) 0 0 / 100% 34px,
                linear-gradient(90deg, rgba(133, 114, 94, 0.08) 1px, transparent 1px) 0 0 / 56px 100%,
                #fff;
        }
        .bars {
            margin-top: 16px;
            display: grid;
            gap: 12px;
        }
        .bar-row {
            display: grid;
            grid-template-columns: 150px minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            font-size: 0.88rem;
            color: var(--vz-marron1);
        }
        .bar-track {
            position: relative;
            height: 12px;
            border-radius: 999px;
            border: 1px solid rgba(133, 114, 94, 0.35);
            background: #f3efe6;
            overflow: hidden;
        }
        .bar-fill {
            position: absolute;
            inset: 0 auto 0 0;
            width: 0;
            border-radius: 999px;
            background: linear-gradient(90deg, #3b82f6, #14b8a6);
        }
        .chips {
            margin-top: 14px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .chip {
            font-size: 0.78rem;
            border-radius: 999px;
            border: 1px solid rgba(133, 114, 94, 0.42);
            background: #f8f5ee;
            color: var(--vz-marron1);
            padding: 5px 10px;
            font-weight: 600;
        }
        .analytics-table {
            margin-top: 14px;
            overflow: hidden;
            border-radius: 14px;
            border: 1px solid rgba(133, 114, 94, 0.34);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.87rem;
            background: var(--vz-blanco);
        }
        th, td {
            padding: 10px 10px;
            border-bottom: 1px solid rgba(133, 114, 94, 0.24);
            text-align: left;
            white-space: nowrap;
            color: var(--vz-negro);
        }
        thead th {
            background: var(--vz-verde);
            color: var(--vz-crema);
            font-size: 0.73rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            border-bottom: none;
        }
        tbody tr:nth-child(even) {
            background: #fbfaf6;
        }
        tbody tr:hover {
            background: rgba(142, 139, 48, 0.08);
        }
        .state {
            display: inline-block;
            border-radius: 999px;
            border: 1px solid rgba(133, 114, 94, 0.35);
            background: #f8f5ee;
            color: var(--vz-marron1);
            padding: 4px 10px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .state.ok {
            background: rgba(43, 125, 72, 0.14);
            border-color: rgba(43, 125, 72, 0.28);
            color: #2f6a42;
        }
        .state.warn {
            background: rgba(212, 168, 48, 0.16);
            border-color: rgba(212, 168, 48, 0.28);
            color: #7a5910;
        }
        .state.bad {
            background: rgba(200, 60, 50, 0.14);
            border-color: rgba(200, 60, 50, 0.25);
            color: #8d2b23;
        }
        .deviation {
            margin-top: 14px;
            border-radius: 14px;
            border: 1px solid rgba(133, 114, 94, 0.34);
            border-left-width: 4px;
            padding: 13px 14px;
            background: #faf8f2;
            color: var(--vz-marron1);
        }
        .deviation strong {
            display: block;
            margin-bottom: 7px;
            font-size: 0.9rem;
            color: var(--vz-negro);
        }
        .deviation.up {
            border-left-color: var(--vz-rojo);
            background: #fff4f2;
        }
        .deviation.down {
            border-left-color: var(--vz-amarillo);
            background: #fff9ea;
        }
        .deviation.ok {
            border-left-color: var(--vz-verde);
            background: #f7f8eb;
        }
        .deviation div + div {
            margin-top: 4px;
        }
        .empty {
            margin-top: 14px;
            padding: 14px 15px;
            border-radius: 14px;
            border: 1px dashed rgba(133, 114, 94, 0.5);
            background: #faf8f1;
            color: var(--vz-marron2);
            font-size: 0.9rem;
        }
        @media (max-width: 1120px) {
            .filter-bar {
                grid-template-columns: repeat(2, minmax(180px, 1fr));
            }
        }
        @media (max-width: 980px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
            .analytics-toolbar-head {
                flex-direction: column;
            }
        }
        @media (max-width: 768px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
            .sidebar-nav {
                flex-direction: row;
                gap: 6px;
            }
            .analytics-header {
                padding: 18px 16px;
                flex-direction: column;
            }
            .analytics-title {
                font-size: 1.4rem;
            }
            .analytics-main {
                padding: 18px 12px 28px;
            }
            .filter-bar {
                grid-template-columns: 1fr;
            }
            .bar-row {
                grid-template-columns: 1fr;
            }
            .field-material,
            .field-price {
                width: 100%;
                min-width: 0;
            }
            .input-row button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
    <link rel="stylesheet" href="assets/css/master-detail-theme.css">
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-logo">Licitaciones</div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="licitaciones.php" class="nav-link">Licitaciones</a>
                <a href="buscador.php" class="nav-link">Buscador hist&oacute;rico</a>
                <a href="lineas-referencia.php" class="nav-link">A&ntilde;adir l&iacute;neas</a>
                <a href="analytics.php" class="nav-link active">Anal&iacute;tica</a>
                <a href="usuarios.php" class="nav-link">Usuarios</a>
            </nav>
        </aside>

        <div class="main">
            <header class="analytics-header">
                <div class="analytics-header-copy">
                    <p class="analytics-kicker">Panel estrat&eacute;gico</p>
                    <h1 class="analytics-title">Anal&iacute;tica de licitaciones</h1>
                    <p class="analytics-subtitle">
                        Tendencias de precio, pipeline en an&aacute;lisis y comparativas de resultados cerrados dentro del mismo lenguaje visual del master-detail.
                    </p>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo $h($fullName !== '' ? $fullName : $email); ?></div>
                    <?php if ($role !== ''): ?>
                        <div class="pill"><?php echo $h($role); ?></div>
                    <?php endif; ?>
                    <div><a href="logout.php" class="logout-link">Cerrar sesi&oacute;n</a></div>
                </div>
            </header>

            <main class="analytics-main">
                <section class="analytics-toolbar">
                    <div class="analytics-toolbar-head">
                        <div>
                            <h2>Filtro de adjudicaci&oacute;n</h2>
                            <p>Acota el periodo sobre el que se calculan los bloques anal&iacute;ticos y mant&eacute;n el resto de consultas de la p&aacute;gina sincronizadas.</p>
                        </div>
                    </div>
                    <form method="get" class="filter-bar">
                        <div>
                            <label for="f-desde">F. adjudicaci&oacute;n desde</label>
                            <input id="f-desde" type="date" name="fecha_adjudicacion_desde" value="<?php echo $h($fechaDesdeRaw); ?>">
                        </div>
                        <div>
                            <label for="f-hasta">F. adjudicaci&oacute;n hasta</label>
                            <input id="f-hasta" type="date" name="fecha_adjudicacion_hasta" value="<?php echo $h($fechaHastaRaw); ?>">
                        </div>
                        <button type="submit">Aplicar filtro</button>
                        <?php if ($fechaDesdeRaw !== '' || $fechaHastaRaw !== ''): ?>
                            <a href="analytics.php">Quitar filtro</a>
                        <?php endif; ?>
                    </form>
                </section>

                <?php if ($repoError !== null || $errors !== []): ?>
                    <div class="message-stack">
                        <?php if ($repoError !== null): ?>
                            <div class="error"><?php echo $h($repoError); ?></div>
                        <?php endif; ?>
                        <?php foreach ($errors as $err): ?>
                            <div class="error"><?php echo $h((string)$err); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>


                <section class="grid-2">
                    <article class="card analytics-card analytics-card--trend">
                        <h2>Tendencia de precios (productos)</h2>
                        <p class="hint">PVU y PCU hist&oacute;ricos por producto (referencia + detalle/real).</p>

                        <form method="get" class="input-row">
                            <input type="hidden" name="fecha_adjudicacion_desde" value="<?php echo $h($fechaDesdeRaw); ?>">
                            <input type="hidden" name="fecha_adjudicacion_hasta" value="<?php echo $h($fechaHastaRaw); ?>">
                            <input type="text" name="material" class="field-material" placeholder="Nombre del producto..." value="<?php echo $h($materialRaw); ?>">
                            <button type="submit">Cargar tendencia</button>
                        </form>

                        <?php if ($materialRaw === ''): ?>
                            <p class="empty">Introduce un producto para ver su tendencia.</p>
                        <?php else: ?>
                            <div class="chart-box">
                                <div class="chart-head">
                                    <span class="chart-title">PVU</span>
                                    <span class="chart-meta"><?php echo $h((string)count($pvuPoints)); ?> puntos</span>
                                </div>
                                <?php if ($pvuLine !== ''): ?>
                                    <svg class="chart" viewBox="0 0 560 170" preserveAspectRatio="none">
                                        <polyline fill="none" stroke="#22d3ee" stroke-width="2" points="<?php echo $h($pvuLine); ?>"></polyline>
                                    </svg>
                                <?php else: ?>
                                    <p class="empty">Sin datos PVU para "<?php echo $h($materialRaw); ?>".</p>
                                <?php endif; ?>
                            </div>

                            <div class="chart-box">
                                <div class="chart-head">
                                    <span class="chart-title">PCU</span>
                                    <span class="chart-meta"><?php echo $h((string)count($pcuPoints)); ?> puntos</span>
                                </div>
                                <?php if ($pcuLine !== ''): ?>
                                    <svg class="chart" viewBox="0 0 560 170" preserveAspectRatio="none">
                                        <polyline fill="none" stroke="#f59e0b" stroke-width="2" points="<?php echo $h($pcuLine); ?>"></polyline>
                                    </svg>
                                <?php else: ?>
                                    <p class="empty">Sin datos PCU para "<?php echo $h($materialRaw); ?>".</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>

                    <article class="card analytics-card analytics-card--pipeline">
                        <h2>Venta presupuestada vs venta a precio medio</h2>
                        <p class="hint">Comparativa del pipeline en estado En an&aacute;lisis.</p>

                        <div class="bars">
                            <div class="bar-row">
                                <span>Pipeline bruto</span>
                                <div class="bar-track">
                                    <div class="bar-fill" style="width:100%;"></div>
                                </div>
                                <strong><?php echo $h($fmtEuro($riskBruto)); ?></strong>
                            </div>
                            <div class="bar-row">
                                <span>Pipeline ajustado</span>
                                <div class="bar-track">
                                    <div class="bar-fill" style="width:<?php echo $h($fmtNumber($riskRatio, 2)); ?>%; background: linear-gradient(90deg,#f59e0b,#ef4444);"></div>
                                </div>
                                <strong><?php echo $h($fmtEuro($riskAjustado)); ?></strong>
                            </div>
                        </div>

                        <p class="hint" style="margin-top:10px;">
                            Ajustado / bruto: <strong><?php echo $h($fmtPercent($riskRatio, 1)); ?></strong>
                        </p>
                    </article>
                </section>

                <section class="grid-2">
                    <article class="card analytics-card analytics-card--sweetspots">
                        <h2>Sweet spots (ganadas vs no adjudicadas)</h2>
                        <p class="hint">Distribuci&oacute;n y lista de licitaciones cerradas.</p>

                        <?php if ($summaryByEstado !== []): ?>
                            <div class="chips">
                                <?php foreach ($summaryByEstado as $estado => $count): ?>
                                    <span class="chip"><?php echo $h((string)$estado); ?>: <?php echo $h((string)$count); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($sweetSpots === []): ?>
                            <p class="empty">No hay licitaciones cerradas para mostrar.</p>
                        <?php else: ?>
                            <div class="analytics-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Cliente / Licitaci&oacute;n</th>
                                            <th>Estado</th>
                                            <th>Presupuesto</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($sweetSpots, 0, 24) as $row): ?>
                                            <?php
                                                $estado = trim((string)($row['estado'] ?? 'Desconocido'));
                                                $estadoNorm = mb_strtolower($estado, 'UTF-8');
                                                $estadoClass = 'state';
                                                if (str_contains($estadoNorm, 'adjudicad') && !str_contains($estadoNorm, 'no adjud')) {
                                                    $estadoClass .= ' ok';
                                                } elseif (str_contains($estadoNorm, 'no adjud') || str_contains($estadoNorm, 'descart')) {
                                                    $estadoClass .= ' bad';
                                                } else {
                                                    $estadoClass .= ' warn';
                                                }
                                            ?>
                                            <tr>
                                                <td><?php echo $h((string)($row['cliente'] ?? '')); ?></td>
                                                <td><span class="<?php echo $h($estadoClass); ?>"><?php echo $h($estado); ?></span></td>
                                                <td><?php echo $h($fmtEuro((float)($row['presupuesto'] ?? 0))); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </article>

                    <article class="card analytics-card analytics-card--deviation">
                        <h2>Comprobaci&oacute;n de desviaci&oacute;n de precio</h2>
                        <p class="hint">Compara el precio actual con la media hist&oacute;rica del producto.</p>

                        <form method="get" class="input-row">
                            <input type="hidden" name="fecha_adjudicacion_desde" value="<?php echo $h($fechaDesdeRaw); ?>">
                            <input type="hidden" name="fecha_adjudicacion_hasta" value="<?php echo $h($fechaHastaRaw); ?>">
                            <input type="text" name="deviation_material" class="field-material" placeholder="Producto..." value="<?php echo $h($deviationMaterialRaw); ?>">
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
                                if ($isDev && $devPct > 0) {
                                    $devClass = 'deviation up';
                                } elseif ($isDev && $devPct < 0) {
                                    $devClass = 'deviation down';
                                }
                            ?>
                            <div class="<?php echo $h($devClass); ?>">
                                <strong>Resultado</strong>
                                <div>Desviaci&oacute;n: <?php echo $h($fmtPercent($devPct, 2)); ?></div>
                                <div>Media hist&oacute;rica: <?php echo $h($fmtEuro($avgHist)); ?></div>
                                <div style="margin-top:6px;"><?php echo $h($rec); ?></div>
                            </div>
                        <?php elseif ($deviationMaterialRaw !== '' || $currentPriceRaw !== ''): ?>
                            <p class="empty">No se pudo calcular la desviaci&oacute;n con los valores indicados.</p>
                        <?php else: ?>
                            <p class="empty">Introduce producto y precio actual para lanzar la comprobaci&oacute;n.</p>
                        <?php endif; ?>
                    </article>
                </section>
            </main>
        </div>
    </div>
</body>
</html>
