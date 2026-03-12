<?php
declare(strict_types=1);

session_start();

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

$isValidDate = static function (string $value): bool {
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
};

$fechaDesde = $isValidDate($fechaDesdeRaw) ? $fechaDesdeRaw : null;
$fechaHasta = $isValidDate($fechaHastaRaw) ? $fechaHastaRaw : null;

$repoError = null;
/** @var array<string, mixed> $kpis */
$kpis = [];

try {
    $repo = new AnalyticsRepository();
    $kpis = $repo->getKpis($fechaDesde, $fechaHasta);
} catch (\Throwable $e) {
    $repoError = 'No se pudieron cargar los KPIs del dashboard.';
}

$timeline = [];
if (isset($kpis['timeline']) && is_array($kpis['timeline'])) {
    $timeline = $kpis['timeline'];
}
$timeline = array_values(array_filter(
    $timeline,
    static fn ($row): bool => is_array($row)
));

$timelineMaxRows = 24;
$timelineDisplay = array_slice($timeline, 0, $timelineMaxRows);

$utc = new \DateTimeZone('UTC');
$daySeconds = 86400;
$monthLabels = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

$parseTimelineTs = static function (?string $value, \DateTimeZone $utc): ?int {
    if ($value === null) {
        return null;
    }
    $raw = trim($value);
    if ($raw === '') {
        return null;
    }
    $raw = substr($raw, 0, 10);
    $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $utc);
    if (!$dt instanceof \DateTimeImmutable || $dt->format('Y-m-d') !== $raw) {
        return null;
    }
    return $dt->getTimestamp();
};

$fmtTickLabel = static function (int $timestamp, array $monthLabels): string {
    $monthIdx = (int)gmdate('n', $timestamp) - 1;
    $month = $monthLabels[$monthIdx] ?? gmdate('M', $timestamp);
    return gmdate('j', $timestamp) . ' ' . $month;
};

$timelineRows = [];
$timelineDatePoints = [];
foreach ($timelineDisplay as $item) {
    $idLic = (int)($item['id_licitacion'] ?? 0);
    $nombre = trim((string)($item['nombre'] ?? ('Licitacion ' . $idLic)));
    if ($nombre === '') {
        $nombre = 'Licitacion ' . ($idLic > 0 ? $idLic : '-');
    }
    $estado = trim((string)($item['estado_nombre'] ?? 'Desconocido'));
    $fAdj = isset($item['fecha_adjudicacion']) ? (string)$item['fecha_adjudicacion'] : null;
    $fFin = isset($item['fecha_finalizacion']) ? (string)$item['fecha_finalizacion'] : null;
    $pres = (float)($item['pres_maximo'] ?? 0.0);
    $tAdj = $parseTimelineTs($fAdj, $utc);
    $tFin = $parseTimelineTs($fFin, $utc);
    $hasRange = $tAdj !== null && $tFin !== null && $tFin >= $tAdj;
    if ($hasRange) {
        $timelineDatePoints[] = $tAdj;
        $timelineDatePoints[] = $tFin;
    }

    $timelineRows[] = [
        'id_licitacion' => $idLic,
        'nombre' => $nombre,
        'estado_nombre' => $estado !== '' ? $estado : 'Desconocido',
        'fecha_adjudicacion' => $fAdj,
        'fecha_finalizacion' => $fFin,
        'pres_maximo' => $pres,
        'has_range' => $hasRange,
        't_adj' => $tAdj,
        't_fin' => $tFin,
    ];
}

$todayTs = (new \DateTimeImmutable('today', $utc))->getTimestamp();
$dataMinTs = $timelineDatePoints !== [] ? min($timelineDatePoints) : $todayTs;
$dataMaxTs = $timelineDatePoints !== [] ? max($timelineDatePoints) : $todayTs;
$timelineMinTs = min($dataMinTs, $todayTs);
$timelineMaxTs = max($dataMaxTs, $todayTs);
$timelineRange = max(1, $timelineMaxTs - $timelineMinTs);

$timelineTicks = [];
$timelineRangeDays = $timelineRange / $daySeconds;
$minDateUtc = (new \DateTimeImmutable('@' . $timelineMinTs))->setTimezone($utc);
$maxDateUtc = (new \DateTimeImmutable('@' . $timelineMaxTs))->setTimezone($utc);

if ($timelineRows !== []) {
    if ($timelineRangeDays <= 14) {
        $stepDays = $timelineRangeDays <= 5 ? 1 : ($timelineRangeDays <= 8 ? 2 : 3);
        $targetCount = min(10, max(3, (int)ceil($timelineRangeDays / $stepDays)));
        $step = max(1, (int)floor($timelineRangeDays / max(1, $targetCount)));
        $cursor = $timelineMinTs;
        while ($cursor <= $timelineMaxTs && count($timelineTicks) < 12) {
            $timelineTicks[] = [
                'ts' => $cursor,
                'label' => $fmtTickLabel($cursor, $monthLabels),
            ];
            $cursor += ($step * $daySeconds);
        }
    } elseif ($timelineRangeDays <= 60) {
        $stepDays = $timelineRangeDays <= 21 ? 7 : 14;
        $cursor = $timelineMinTs;
        while ($cursor <= $timelineMaxTs && count($timelineTicks) < 12) {
            $timelineTicks[] = [
                'ts' => $cursor,
                'label' => $fmtTickLabel($cursor, $monthLabels),
            ];
            $cursor += ($stepDays * $daySeconds);
        }
    } else {
        $monthsDiff = ((int)$maxDateUtc->format('Y') - (int)$minDateUtc->format('Y')) * 12
            + ((int)$maxDateUtc->format('n') - (int)$minDateUtc->format('n')) + 1;
        $monthStep = $monthsDiff > 18 ? 3 : ($monthsDiff > 9 ? 2 : 1);
        $cursor = new \DateTimeImmutable($minDateUtc->format('Y-m-01'), $utc);
        $end = new \DateTimeImmutable($maxDateUtc->format('Y-m-01'), $utc);

        while ($cursor <= $end) {
            $monthOffset = ((int)$cursor->format('Y') - (int)$minDateUtc->format('Y')) * 12
                + ((int)$cursor->format('n') - (int)$minDateUtc->format('n'));
            if ($monthOffset % $monthStep === 0) {
                $ts = $cursor->getTimestamp();
                if ($ts >= $timelineMinTs && $ts <= $timelineMaxTs) {
                    $monthIdx = (int)$cursor->format('n') - 1;
                    $label = ($monthLabels[$monthIdx] ?? $cursor->format('M')) . ' ' . $cursor->format('y');
                    $timelineTicks[] = ['ts' => $ts, 'label' => $label];
                }
            }
            $cursor = $cursor->modify('+1 month');
        }
    }
}

if ($timelineRows !== [] && $timelineTicks === []) {
    $timelineTicks[] = [
        'ts' => $timelineMinTs,
        'label' => $fmtTickLabel($timelineMinTs, $monthLabels),
    ];
}

$toTimelinePercent = static function (int $ts, int $minTs, int $range): float {
    return (($ts - $minTs) / max(1, $range)) * 100.0;
};

$todayLinePct = $toTimelinePercent($todayTs, $timelineMinTs, $timelineRange);
$timelineRowHeight = 34;
$timelineTodayLineHeight = count($timelineRows) * $timelineRowHeight;

$fmtEuro = static function (float $value): string {
    return number_format($value, 0, ',', '.') . ' EUR';
};

$fmtPercent = static function (?float $value, int $decimals = 1): string {
    if ($value === null) {
        return '-';
    }
    return number_format($value, $decimals, ',', '.') . ' %';
};

$fmtRatio = static function (?float $value, int $decimals = 2): string {
    if ($value === null) {
        return '-';
    }
    return number_format($value, $decimals, ',', '.');
};

$fmtDate = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt instanceof \DateTimeImmutable ? $dt->format('d/m/Y') : '-';
};

$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$totalOportunidadesUds = (int)($kpis['total_oportunidades_uds'] ?? 0);
$totalOportunidadesEuros = (float)($kpis['total_oportunidades_euros'] ?? 0);
$totalOfertadoUds = (int)($kpis['total_ofertado_uds'] ?? 0);
$totalOfertadoEuros = (float)($kpis['total_ofertado_euros'] ?? 0);
$ratioOfertaUds = (float)($kpis['ratio_ofertado_oportunidades_uds'] ?? 0);
$ratioOfertaEuros = (float)($kpis['ratio_ofertado_oportunidades_euros'] ?? 0);
$ratioAdjTerm = (float)($kpis['ratio_adjudicadas_terminadas_ofertado'] ?? 0);
$ratioAdjudicacion = (float)($kpis['ratio_adjudicacion'] ?? 0);
$margenPres = isset($kpis['margen_medio_ponderado_presupuestado']) ? (float)$kpis['margen_medio_ponderado_presupuestado'] : null;
$margenReal = isset($kpis['margen_medio_ponderado_real']) ? (float)$kpis['margen_medio_ponderado_real'] : null;
$pctDescUds = isset($kpis['pct_descartadas_uds']) ? (float)$kpis['pct_descartadas_uds'] : null;
$pctDescEuros = isset($kpis['pct_descartadas_euros']) ? (float)$kpis['pct_descartadas_euros'] : null;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: #020617;
            color: #e5e7eb;
        }
        .layout {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 220px;
            background: radial-gradient(circle at top left, #1e293b, #020617);
            border-right: 1px solid #1f2937;
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
            font-size: 0.9rem;
            color: #e5e7eb;
            text-decoration: none;
        }
        .nav-link:hover {
            background-color: #111827;
        }
        .nav-link.active {
            background: linear-gradient(135deg, #22c55e, #14b8a6);
            color: #020617;
            font-weight: 600;
        }
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            background: linear-gradient(135deg, #020617, #0f172a);
            border-bottom: 1px solid #1f2937;
        }
        header h1 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .user-info {
            font-size: 0.85rem;
            text-align: right;
        }
        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            background-color: #1e293b;
            color: #a5b4fc;
            font-size: 0.75rem;
            margin-top: 2px;
        }
        main {
            max-width: 1180px;
            margin: 24px auto;
            padding: 0 16px 30px;
            width: 100%;
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }
        .toolbar-title {
            margin: 0;
            font-size: 1.5rem;
            line-height: 1.2;
        }
        .toolbar-sub {
            margin: 3px 0 0;
            font-size: 0.9rem;
            opacity: 0.85;
        }
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid var(--vz-marron2);
            background: var(--vz-blanco);
            box-shadow: 0 2px 8px rgba(16, 24, 14, 0.08);
        }
        .filter-form label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 2px;
            color: var(--vz-marron2);
        }
        .filter-form input[type="date"] {
            height: 36px;
            min-width: 150px;
            border-radius: 8px;
            border: 1px solid var(--vz-marron2);
            background: var(--vz-blanco);
            color: var(--vz-negro);
            padding: 0 10px;
        }
        .filter-form button {
            height: 36px;
            border-radius: 8px;
            border: 1px solid var(--vz-verde);
            background: var(--vz-verde);
            color: var(--vz-crema);
            padding: 0 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
        }
        .filter-form button:hover {
            filter: brightness(1.05);
        }
        .filter-form a {
            height: 36px;
            border-radius: 8px;
            border: 1px solid var(--vz-marron2);
            background: var(--vz-crema);
            color: var(--vz-marron1);
            padding: 0 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            font-size: 0.82rem;
            font-weight: 600;
        }
        .filter-form a:hover {
            background: #ddd7ce;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-top: 18px;
        }
        .kpi-card {
            border-radius: 12px;
            border: 1px solid var(--vz-marron2);
            background: var(--vz-blanco);
            box-shadow: 0 2px 8px rgba(16, 24, 14, 0.08);
            padding: 14px 16px;
        }
        .kpi-title {
            margin: 0 0 6px;
            font-size: 13px;
            color: var(--vz-marron2);
            font-weight: 600;
        }
        .kpi-value {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            line-height: 1.15;
            letter-spacing: 0.01em;
            color: var(--vz-negro);
        }
        .kpi-sub {
            margin: 4px 0 0;
            font-size: 0.95rem;
            color: var(--vz-marron2);
        }
        .panel {
            margin-top: 14px;
            border-radius: 12px;
            border: 1px solid rgba(133, 114, 94, 0.35);
            background: var(--vz-blanco);
            padding: 14px;
        }
        .panel h2 {
            margin: 0;
            font-size: 1rem;
            color: var(--vz-negro);
        }
        .panel .hint {
            margin: 4px 0 0;
            font-size: 0.82rem;
            color: var(--vz-marron2);
        }
        .timeline-chart {
            margin-top: 12px;
            overflow-x: auto;
        }
        .timeline-inner {
            min-width: 760px;
        }
        .timeline-layout {
            display: flex;
            align-items: stretch;
        }
        .timeline-labels {
            width: 250px;
            flex: 0 0 250px;
            border-right: 1px solid rgba(133, 114, 94, 0.35);
            padding-right: 10px;
        }
        .timeline-label-row {
            display: flex;
            align-items: center;
            min-height: 34px;
            border-bottom: 1px solid rgba(133, 114, 94, 0.24);
            padding-right: 8px;
        }
        .timeline-label-link,
        .timeline-label-text {
            font-size: 0.82rem;
            color: #5a4b2f;
            text-decoration: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
            display: block;
        }
        .timeline-label-block {
            width: 100%;
            min-width: 0;
        }
        .timeline-label-meta {
            display: block;
            font-size: 0.68rem;
            color: #7a6a56;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 1px;
        }
        .timeline-label-link:hover {
            text-decoration: underline;
            color: #3e3120;
        }
        .timeline-label-axis {
            min-height: 38px;
            display: flex;
            align-items: flex-end;
            padding-top: 8px;
            border-top: 1px solid rgba(133, 114, 94, 0.35);
            font-size: 0.66rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--vz-marron2);
        }
        .timeline-plot {
            position: relative;
            flex: 1;
            min-width: 0;
            padding-left: 8px;
        }
        .timeline-row {
            position: relative;
            min-height: 34px;
            border-bottom: 1px solid rgba(133, 114, 94, 0.24);
        }
        .timeline-grid-line {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 1px;
            background: rgba(133, 114, 94, 0.16);
            pointer-events: none;
        }
        .timeline-bar {
            position: absolute;
            top: 8px;
            height: 18px;
            border-radius: 999px;
            background: linear-gradient(135deg, #1fa296, #137970);
            box-shadow: 0 2px 6px rgba(16, 24, 14, 0.2);
            min-width: 2px;
            display: block;
        }
        .timeline-bar:hover {
            filter: brightness(1.05);
        }
        .timeline-no-dates {
            position: absolute;
            inset: 7px 8px;
            border-radius: 8px;
            background: rgba(133, 114, 94, 0.12);
            color: #7a6a56;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .timeline-axis {
            position: relative;
            margin-top: 6px;
            min-height: 38px;
            border-top: 1px solid rgba(133, 114, 94, 0.35);
        }
        .timeline-axis-tick {
            position: absolute;
            top: 0;
            bottom: 14px;
            width: 1px;
            background: rgba(133, 114, 94, 0.2);
            pointer-events: none;
        }
        .timeline-axis-label {
            position: absolute;
            bottom: 0;
            transform: translateX(-50%);
            font-size: 0.68rem;
            color: #6b5c49;
            white-space: nowrap;
        }
        .timeline-today-line {
            position: absolute;
            top: 0;
            width: 2px;
            background: #c83c32;
            opacity: 0.9;
            z-index: 8;
            pointer-events: none;
        }
        .empty {
            margin: 8px 0 0;
            color: var(--vz-marron2);
            font-size: 0.9rem;
        }
        .error {
            border: 1px solid rgba(200, 60, 50, 0.38);
            background: rgba(200, 60, 50, 0.14);
            color: #7a2722;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 12px;
            font-size: 0.9rem;
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
            .filter-form {
                width: 100%;
            }
            .filter-form input[type="date"] {
                min-width: 0;
                width: 100%;
            }
            .timeline-inner {
                min-width: 640px;
            }
            .timeline-labels {
                width: 200px;
                flex-basis: 200px;
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
                <a href="dashboard.php" class="nav-link active">Dashboard</a>
                <a href="licitaciones.php" class="nav-link">Licitaciones</a>
                <a href="buscador.php" class="nav-link">Buscador hist&oacute;rico</a>
                <a href="analytics.php" class="nav-link">Anal&iacute;tica</a>
                <a href="disponible.php" class="nav-link">Disponible</a>
                <a href="disponible-cliente.php" class="nav-link">Vista Cliente</a>
                <a href="pedidos-disponible.php" class="nav-link">Pedidos</a>
                <a href="usuarios.php" class="nav-link">Usuarios</a>
            </nav>
        </aside>

        <div class="main">
            <header>
                <h1>Panel de licitaciones</h1>
                <div class="user-info">
                    <div><?php echo $h($fullName !== '' ? $fullName : $email); ?></div>
                    <?php if ($role !== ''): ?>
                        <div class="pill"><?php echo $h($role); ?></div>
                    <?php endif; ?>
                    <div><a href="logout.php">Cerrar sesi&oacute;n</a></div>
                </div>
            </header>

            <main>
                <div class="toolbar">
                    <div>
                        <h2 class="toolbar-title">Dashboard</h2>
                        <p class="toolbar-sub">KPIs y timeline de licitaciones (criterio del proyecto anterior).</p>
                    </div>

                    <form method="get" class="filter-form">
                        <div>
                            <label for="f-desde">Adjudicaci&oacute;n desde</label>
                            <input id="f-desde" type="date" name="fecha_adjudicacion_desde" value="<?php echo $h($fechaDesdeRaw); ?>">
                        </div>
                        <div>
                            <label for="f-hasta">Adjudicaci&oacute;n hasta</label>
                            <input id="f-hasta" type="date" name="fecha_adjudicacion_hasta" value="<?php echo $h($fechaHastaRaw); ?>">
                        </div>
                        <button type="submit">Aplicar filtro</button>
                        <?php if ($fechaDesdeRaw !== '' || $fechaHastaRaw !== ''): ?>
                            <a href="dashboard.php">Quitar filtro</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if ($repoError !== null): ?>
                    <div class="error"><?php echo $h($repoError); ?></div>
                <?php endif; ?>

                <section class="panel">
                    <h2>Timeline</h2>
                    <p class="hint">Cada barra representa una licitaci&oacute;n desde la fecha de adjudicaci&oacute;n hasta la fecha de finalizaci&oacute;n.</p>

                    <?php if ($timelineRows === []): ?>
                        <p class="empty">No hay licitaciones para mostrar con los filtros actuales.</p>
                    <?php else: ?>
                        <div class="timeline-chart">
                            <div class="timeline-inner">
                                <div class="timeline-layout">
                                    <div class="timeline-labels">
                                        <?php foreach ($timelineRows as $row): ?>
                                            <?php
                                                $detalleUrl = 'licitacion-detalle.php?id=' . (int)$row['id_licitacion'];
                                                $lineTitle = $row['nombre'] . ' (' . $row['estado_nombre'] . ')';
                                            ?>
                                            <div class="timeline-label-row">
                                                <div class="timeline-label-block">
                                                    <?php if ((int)$row['id_licitacion'] > 0): ?>
                                                        <a href="<?php echo $h($detalleUrl); ?>" class="timeline-label-link" title="<?php echo $h($lineTitle); ?>">
                                                            <?php echo $h((string)$row['nombre']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="timeline-label-text" title="<?php echo $h($lineTitle); ?>">
                                                            <?php echo $h((string)$row['nombre']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="timeline-label-meta">
                                                        <?php echo $h((string)$row['estado_nombre']); ?> · <?php echo $h($fmtEuro((float)$row['pres_maximo'])); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="timeline-label-axis">Licitaciones</div>
                                    </div>

                                    <div class="timeline-plot">
                                        <span
                                            class="timeline-today-line"
                                            style="left: <?php echo $h(number_format($todayLinePct, 3, '.', '')); ?>%; height: <?php echo (int)$timelineTodayLineHeight; ?>px;"
                                            title="Hoy: <?php echo $h(gmdate('d/m/Y', $todayTs)); ?>"
                                        ></span>

                                        <?php foreach ($timelineRows as $row): ?>
                                            <div class="timeline-row">
                                                <?php foreach ($timelineTicks as $tick): ?>
                                                    <?php $tickPct = $toTimelinePercent((int)$tick['ts'], $timelineMinTs, $timelineRange); ?>
                                                    <span class="timeline-grid-line" style="left: <?php echo $h(number_format($tickPct, 3, '.', '')); ?>%;"></span>
                                                <?php endforeach; ?>

                                                <?php if ($row['has_range']): ?>
                                                    <?php
                                                        $leftPct = $toTimelinePercent((int)$row['t_adj'], $timelineMinTs, $timelineRange);
                                                        $rawWidthPct = (((int)$row['t_fin'] - (int)$row['t_adj']) / max(1, $timelineRange)) * 100.0;
                                                        $widthPct = max($rawWidthPct, 2.0);
                                                        $barTitle = $fmtDate((string)$row['fecha_adjudicacion']) . ' -> ' . $fmtDate((string)$row['fecha_finalizacion']);
                                                    ?>
                                                    <?php if ((int)$row['id_licitacion'] > 0): ?>
                                                        <a
                                                            href="<?php echo $h('licitacion-detalle.php?id=' . (int)$row['id_licitacion']); ?>"
                                                            class="timeline-bar"
                                                            style="left: <?php echo $h(number_format($leftPct, 3, '.', '')); ?>%; width: <?php echo $h(number_format($widthPct, 3, '.', '')); ?>%;"
                                                            title="<?php echo $h($barTitle); ?>"
                                                        ></a>
                                                    <?php else: ?>
                                                        <span
                                                            class="timeline-bar"
                                                            style="left: <?php echo $h(number_format($leftPct, 3, '.', '')); ?>%; width: <?php echo $h(number_format($widthPct, 3, '.', '')); ?>%;"
                                                            title="<?php echo $h($barTitle); ?>"
                                                        ></span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="timeline-no-dates">Sin fechas de adjudicaci&oacute;n/finalizaci&oacute;n</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>

                                        <div class="timeline-axis">
                                            <?php foreach ($timelineTicks as $tick): ?>
                                                <?php $tickPct = $toTimelinePercent((int)$tick['ts'], $timelineMinTs, $timelineRange); ?>
                                                <span class="timeline-axis-tick" style="left: <?php echo $h(number_format($tickPct, 3, '.', '')); ?>%;"></span>
                                                <span class="timeline-axis-label" style="left: <?php echo $h(number_format($tickPct, 3, '.', '')); ?>%;">
                                                    <?php echo $h((string)$tick['label']); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (count($timeline) > $timelineMaxRows): ?>
                            <p class="hint">Mostrando <?php echo (int)$timelineMaxRows; ?> de <?php echo (int)count($timeline); ?> licitaciones.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <section class="kpi-grid">
                    <article class="kpi-card">
                        <p class="kpi-title">Total oportunidades</p>
                        <p class="kpi-value"><?php echo $h((string)$totalOportunidadesUds); ?></p>
                        <p class="kpi-sub"><?php echo $h($fmtEuro($totalOportunidadesEuros)); ?></p>
                    </article>

                    <article class="kpi-card">
                        <p class="kpi-title">Total ofertado</p>
                        <p class="kpi-value"><?php echo $h((string)$totalOfertadoUds); ?></p>
                        <p class="kpi-sub"><?php echo $h($fmtEuro($totalOfertadoEuros)); ?></p>
                    </article>

                    <article class="kpi-card">
                        <p class="kpi-title">Ratio ofertado/oportunidades</p>
                        <p class="kpi-value"><?php echo $h($fmtPercent($ratioOfertaUds)); ?></p>
                        <p class="kpi-sub"><?php echo $h($fmtPercent($ratioOfertaEuros)); ?> (euros)</p>
                    </article>

                    <article class="kpi-card">
                        <p class="kpi-title">Ratio (Adj.+Term.) / ofertado</p>
                        <p class="kpi-value"><?php echo $h($fmtPercent($ratioAdjTerm)); ?></p>
                        <p class="kpi-sub">En n&uacute;mero de licitaciones</p>
                    </article>

                    <article class="kpi-card">
                        <p class="kpi-title">Ratio adjudicaci&oacute;n</p>
                        <p class="kpi-value"><?php echo $h($fmtRatio($ratioAdjudicacion, 2)); ?></p>
                        <p class="kpi-sub">Valor entre 0 y 1</p>
                    </article>

                    <article class="kpi-card">
                        <p class="kpi-title">Margen medio ponderado (presup.)</p>
                        <p class="kpi-value"><?php echo $h($fmtPercent($margenPres)); ?></p>
                        <p class="kpi-sub">Partidas presupuestadas</p>
                    </article>

                    <article class="kpi-card">
                        <p class="kpi-title">Margen medio ponderado (real)</p>
                        <p class="kpi-value"><?php echo $h($fmtPercent($margenReal)); ?></p>
                        <p class="kpi-sub">Entregas / ejecuci&oacute;n real</p>
                    </article>

                    <article class="kpi-card">
                        <p class="kpi-title">% descartadas</p>
                        <p class="kpi-value"><?php echo $h($fmtPercent($pctDescUds)); ?></p>
                        <p class="kpi-sub"><?php echo $h($fmtPercent($pctDescEuros)); ?> (euros)</p>
                    </article>
                </section>
            </main>
        </div>
    </div>
</body>
</html>
