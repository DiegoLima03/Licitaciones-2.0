<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$basePath = __DIR__ . '/../src/Repositories/';
require_once $basePath . 'BaseRepository.php';
require_once $basePath . 'ReservasDisponibleRepository.php';

$h        = static fn ($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$email    = (string)($_SESSION['user']['email']     ?? '');
$fullName = (string)($_SESSION['user']['full_name'] ?? '');
$role     = (string)($_SESSION['user']['role']      ?? '');

$repo = new ReservasDisponibleRepository();

// ── AJAX: eliminar una línea ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_linea') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $did = (int)($_POST['disponible_id'] ?? 0);
        $uid = (string)($_POST['user_id'] ?? '');
        if ($did <= 0 || $uid === '') {
            throw new \InvalidArgumentException('Parámetros inválidos');
        }
        $repo->deleteReserva($did, $uid);
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: eliminar todas las líneas de un cliente ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_cliente') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $uid = (string)($_POST['user_id'] ?? '');
        if ($uid === '') {
            throw new \InvalidArgumentException('user_id requerido');
        }
        $reservas = $repo->getReservasByUser($uid);
        foreach ($reservas as $r) {
            $repo->deleteReserva((int)$r['disponible_id'], $uid);
        }
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Cargar todos los pedidos ─────────────────────────────────────────────────
$loadError   = null;
$todasLineas = [];
try {
    $todasLineas = $repo->getAllReservasConDetalle();
} catch (\Throwable $e) {
    $loadError = $e->getMessage();
}

$precioLabels = [
    'precio_x_unid'           => 'Precio base',
    'precio_x_unid_diplad_m7' => 'DIPLAD M7',
    'precio_x_unid_almeria'   => 'Almería',
    'precio_t5_directo'       => 'T5% Directo',
    'precio_t5_almeria'       => 'T5% Almería',
];

// Agrupar por cliente
$porCliente = [];
foreach ($todasLineas as $row) {
    $uid    = $row['user_id'];
    $col    = $row['columna_precio'] ?? 'precio_x_unid';
    $precio = (isset($row[$col]) && $row[$col] !== '' && $row[$col] !== null)
            ? (float)$row[$col] : null;
    $sub    = $precio !== null ? $precio * (int)$row['unids'] : null;

    if (!isset($porCliente[$uid])) {
        $displayName = trim((string)($row['full_name'] ?? ''));
        if ($displayName === '') $displayName = (string)($row['email'] ?? $uid);
        $porCliente[$uid] = [
            'info'        => [
                'user_id'      => $uid,
                'nombre'       => $displayName,
                'email'        => $row['email']       ?? '',
                'role'         => $row['role']         ?? '',
                'tarifa_label' => $precioLabels[$col]  ?? $col,
                'zonas'        => $row['zonas_cliente'] ?? 'TODAS',
            ],
            'lineas'      => [],
            'total'       => 0.0,
            'total_unids' => 0,
        ];
    }

    $porCliente[$uid]['lineas'][] = [
        'disponible_id' => (int)$row['disponible_id'],
        'nombre'        => ($row['nombre_floriday'] !== '' && $row['nombre_floriday'] !== null)
                         ? $row['nombre_floriday'] : ($row['descripcion_rach'] ?? '—'),
        'formato'       => $row['formato']          ?? '',
        'productor'     => $row['nombre_productor'] ?? '',
        'zona'          => $row['zona']             ?? '',
        'unids'         => (int)$row['unids'],
        'precio'        => $precio,
        'subtotal'      => $sub,
        'updated_at'    => $row['updated_at']       ?? '',
    ];

    if ($sub !== null) $porCliente[$uid]['total'] += $sub;
    $porCliente[$uid]['total_unids'] += (int)$row['unids'];
}

$totalClientes = count($porCliente);
$totalLineas   = count($todasLineas);
$totalUnids    = array_sum(array_column($porCliente, 'total_unids'));
$totalImporte  = array_sum(array_column($porCliente, 'total'));

?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pedidos de Clientes &mdash; Disponible</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", system-ui, sans-serif; }

        /* ── Layout ─────────────────────────────────────────────────── */
        .layout { display: flex; min-height: 100vh; }
        .sidebar {
            width: 220px; flex-shrink: 0;
            padding: 16px 14px;
            display: flex; flex-direction: column;
        }
        .sidebar-logo { font-weight: 600; font-size: 1rem; margin-bottom: 18px; }
        .sidebar-nav  { display: flex; flex-direction: column; gap: 4px; margin-bottom: auto; }
        .nav-link {
            display: block; padding: 8px 10px; border-radius: 8px;
            font-size: 0.88rem; text-decoration: none;
            border: 1px solid transparent;
        }
        .main   { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        header  { display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; }
        header h1 { margin: 0; font-size: 1.1rem; font-weight: 700; }
        .user-info { font-size: 0.85rem; text-align: right; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 0.75rem; margin-top: 2px; }
        main  { padding: 20px; }

        /* ── Stats ──────────────────────────────────────────────────── */
        .stats-row { display: flex; gap: 14px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat-card {
            flex: 1; min-width: 140px;
            background: var(--vz-blanco);
            border: 1px solid var(--vz-marron2);
            border-radius: 10px;
            padding: 14px 16px;
        }
        .stat-card.accent { border-left: 3px solid var(--vz-verde); }
        .stat-lbl {
            font-size: 0.7rem; text-transform: uppercase; letter-spacing: .05em;
            color: var(--vz-marron2); font-weight: 700; margin-bottom: 5px;
        }
        .stat-val {
            font-size: 1.8rem; font-weight: 800; line-height: 1;
            color: var(--vz-negro);
        }
        .stat-card.accent .stat-val { color: var(--vz-verde); }
        .stat-sub { font-size: 0.72rem; color: var(--vz-marron2); margin-top: 3px; }

        /* ── Toolbar ────────────────────────────────────────────────── */
        .toolbar {
            display: flex; align-items: center; gap: 10px;
            flex-wrap: wrap; margin-bottom: 12px;
        }
        .toolbar-title h2 { margin: 0; font-size: 1.05rem; font-weight: 700; }
        .toolbar-title p  { margin: 2px 0 0; font-size: 0.82rem; color: var(--vz-marron2); }
        .tb-spacer { flex: 1; }

        /* ── Filter bar ─────────────────────────────────────────────── */
        .filter-bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 16px; }
        .fg { display: flex; flex-direction: column; gap: 4px; }
        .fg label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }

        /* ── Alert ──────────────────────────────────────────────────── */
        .alert { border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; font-size: 0.88rem; border: 1px solid; }
        .alert-warn { border-color: rgba(212,168,48,.5); background: rgba(212,168,48,.1); color: #5a3e00; }

        /* ── Cliente block ───────────────────────────────────────────── */
        .cliente-block {
            background: var(--vz-blanco);
            border: 1px solid rgba(133,114,94,.45);
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 10px;
            box-shadow: 0 1px 4px rgba(16,24,14,.06);
        }
        .cliente-hdr {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 16px;
            background: var(--vz-crema);
            border-bottom: 1px solid rgba(133,114,94,.25);
            cursor: pointer; user-select: none;
            transition: background .12s;
        }
        .cliente-hdr:hover { background: #dedad4; }

        .c-avatar {
            width: 38px; height: 38px; border-radius: 50%;
            background: var(--vz-verde);
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; font-weight: 800; color: var(--vz-blanco); flex-shrink: 0;
        }
        .c-name  { font-size: 0.95rem; font-weight: 700; color: var(--vz-negro); }
        .c-email { font-size: 0.78rem; color: var(--vz-marron2); }
        .c-meta  { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 3px; }

        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 9999px;
            font-size: 0.7rem; font-weight: 700; border: 1px solid;
        }
        .badge-tarifa { background: rgba(142,139,48,.12); color: #4a4a15; border-color: rgba(142,139,48,.35); }
        .badge-zona   { background: rgba(70,51,31,.1);    color: var(--vz-marron1); border-color: rgba(70,51,31,.3); }
        .badge-role   { background: rgba(133,114,94,.12); color: var(--vz-marron2); border-color: rgba(133,114,94,.3); }

        .c-totals { margin-left: auto; text-align: right; flex-shrink: 0; }
        .c-total-val {
            font-size: 1.1rem; font-weight: 800; color: var(--vz-verde);
        }
        .c-total-sub { font-size: 0.75rem; color: var(--vz-marron2); }

        .c-chevron {
            color: var(--vz-marron2); font-size: 11px;
            transition: transform .2s; flex-shrink: 0;
        }
        .cliente-block.open .c-chevron { transform: rotate(90deg); }

        /* Botón borrar cliente */
        .btn-del-cliente {
            padding: 5px 11px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;
            border: 1px solid rgba(200,60,50,.4);
            background: rgba(200,60,50,.07); color: #8d2b23;
            cursor: pointer; white-space: nowrap; transition: background .1s;
        }
        .btn-del-cliente:hover { background: rgba(200,60,50,.15); }

        /* ── Tabla de líneas ────────────────────────────────────────── */
        .lineas-wrap { display: none; overflow-x: auto; }
        .cliente-block.open .lineas-wrap { display: block; }

        /* El tema fuerza: thead verde, th/td texto oscuro, tabla blanca */
        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        th {
            padding: 8px 10px; font-size: 0.7rem; text-transform: uppercase;
            letter-spacing: .04em; font-weight: 700; white-space: nowrap;
        }
        th.r { text-align: right; }
        td { padding: 8px 10px; vertical-align: middle; white-space: nowrap; }
        .td-r { text-align: right; font-variant-numeric: tabular-nums; }

        .name-wrap { white-space: normal; max-width: 220px; }
        .name-main { font-weight: 600; font-size: 0.83rem; }

        .fmt-badge {
            display: inline-block; padding: 1px 7px; border-radius: 10px;
            font-size: 0.7rem; font-weight: 700;
            background: rgba(142,139,48,.15); color: #4a4a15;
            border: 1px solid rgba(142,139,48,.3);
        }
        .precio-val  { font-weight: 700; color: var(--vz-verde); }
        .sub-val     { font-weight: 800; color: var(--vz-negro); }
        .val-null    { color: var(--vz-marron2); }

        .tfoot-row td {
            background: #f5f3ee !important;
            border-top: 2px solid rgba(133,114,94,.35) !important;
            font-weight: 700;
        }
        .tfoot-total { font-size: 1rem; color: var(--vz-verde); }

        .date-chip {
            font-size: 0.7rem; color: var(--vz-marron2);
            background: var(--vz-crema);
            border: 1px solid rgba(133,114,94,.3); border-radius: 5px;
            padding: 1px 6px;
        }

        .btn-del-linea {
            padding: 3px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 600;
            border: 1px solid rgba(200,60,50,.35);
            background: transparent; color: #b04030;
            cursor: pointer; transition: background .1s;
        }
        .btn-del-linea:hover { background: rgba(200,60,50,.1); }

        /* ── Empty state ────────────────────────────────────────────── */
        .empty-state {
            text-align: center; padding: 60px 20px;
            border: 2px dashed rgba(133,114,94,.4); border-radius: 12px;
            color: var(--vz-marron2);
            background: var(--vz-blanco);
        }
        .empty-icon { font-size: 52px; display: block; margin-bottom: 12px; }

        /* ── Toolbar buttons ────────────────────────────────────────── */
        .btn-toolbar {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 14px; border-radius: 9999px;
            font-size: 0.78rem; font-weight: 700; letter-spacing: .02em;
            border: 1px solid rgba(133,114,94,.45);
            background: var(--vz-blanco); color: var(--vz-marron1);
            cursor: pointer; white-space: nowrap;
            transition: background .12s, box-shadow .12s;
            box-shadow: 0 1px 3px rgba(16,24,14,.06);
        }
        .btn-toolbar:hover {
            background: #ece8df;
            box-shadow: 0 2px 6px rgba(16,24,14,.1);
        }
        .btn-toolbar svg { flex-shrink: 0; }

        /* ── Print ──────────────────────────────────────────────────── */
        @media print {
            .sidebar, .user-info, .filter-bar, .btn-del-linea,
            .btn-del-cliente, .toolbar .btn { display: none !important; }
            .layout { display: block; }
            .cliente-block.open .lineas-wrap,
            .lineas-wrap { display: block !important; }
        }

        @media (max-width: 768px) {
            .layout { flex-direction: column; }
            .sidebar { width: 100%; }
            .sidebar-nav { flex-direction: row; flex-wrap: wrap; }
            .stats-row { gap: 8px; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/master-detail-theme.css">
</head>
<body>
<div class="layout">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-logo">Licitaciones</div>
        <nav class="sidebar-nav">
            <a href="dashboard.php"         class="nav-link">Dashboard</a>
            <a href="licitaciones.php"       class="nav-link">Licitaciones</a>
            <a href="buscador.php"           class="nav-link">Buscador hist&oacute;rico</a>
            <a href="lineas-referencia.php"  class="nav-link">A&ntilde;adir l&iacute;neas</a>
            <a href="analytics.php"          class="nav-link">Anal&iacute;tica</a>
            <a href="disponible.php"         class="nav-link">Disponible</a>
            <a href="disponible-cliente.php" class="nav-link">Vista Cliente</a>
            <a href="pedidos-disponible.php" class="nav-link active">Pedidos</a>
            <a href="usuarios.php"           class="nav-link">Usuarios</a>
        </nav>
    </aside>

    <div class="main">
        <header>
            <h1>Pedidos de Clientes</h1>
            <div class="user-info">
                <div><?php echo $h($fullName !== '' ? $fullName : $email); ?></div>
                <div class="pill"><?php echo $h($role); ?></div>
                <div><a href="logout.php">Cerrar sesi&oacute;n</a></div>
            </div>
        </header>

        <main>

            <?php if ($loadError !== null): ?>
            <div class="alert alert-warn">
                <strong>Error al cargar:</strong> <?php echo $h($loadError); ?><br>
                <small>Ejecuta primero <code>sql/create_tbl_reservas_y_config.sql</code></small>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-lbl">Clientes con pedido</div>
                    <div class="stat-val"><?= $totalClientes ?></div>
                    <div class="stat-sub">usuarios activos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Líneas de pedido</div>
                    <div class="stat-val"><?= $totalLineas ?></div>
                    <div class="stat-sub">productos distintos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-lbl">Total unidades</div>
                    <div class="stat-val"><?= number_format($totalUnids, 0, ',', '.') ?></div>
                    <div class="stat-sub">ud reservadas</div>
                </div>
                <div class="stat-card accent">
                    <div class="stat-lbl">Importe estimado</div>
                    <div class="stat-val"><?= number_format($totalImporte, 2, ',', '.') ?> €</div>
                    <div class="stat-sub">según tarifa de cada cliente</div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-title">
                    <h2>Pedidos por cliente</h2>
                    <p><?= $totalClientes ?> cliente<?= $totalClientes !== 1 ? 's' : '' ?> &middot; <?= $totalLineas ?> l&iacute;nea<?= $totalLineas !== 1 ? 's' : '' ?></p>
                </div>
                <div class="tb-spacer"></div>
                <button class="btn-toolbar" onclick="expandAll()">
                    <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v10M3 8h10"/></svg>
                    Expandir todo
                </button>
                <button class="btn-toolbar" onclick="collapseAll()">
                    <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8h10"/></svg>
                    Colapsar todo
                </button>
                <button class="btn-toolbar" onclick="window.print()">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Imprimir
                </button>
            </div>

            <!-- Filtro -->
            <div class="filter-bar">
                <div class="fg">
                    <label>Buscar cliente</label>
                    <input type="text" id="filtro-cliente" placeholder="Nombre, email…"
                           style="min-width:220px;height:34px;border-radius:8px;padding:0 10px;font-size:.83rem;"
                           oninput="filtrarClientes()">
                </div>
                <?php
                $zonasList = [];
                foreach ($porCliente as $c) {
                    foreach (array_map('trim', explode(',', $c['info']['zonas'])) as $z) {
                        $z = strtoupper($z);
                        if ($z !== '' && $z !== 'TODAS' && !in_array($z, $zonasList, true)) $zonasList[] = $z;
                    }
                }
                sort($zonasList);
                if (!empty($zonasList)): ?>
                <div class="fg">
                    <label>Zona</label>
                    <select id="filtro-zona" onchange="filtrarClientes()"
                            style="height:34px;border-radius:8px;padding:0 10px;font-size:.83rem;min-width:130px;">
                        <option value="">Todas</option>
                        <?php foreach ($zonasList as $z): ?>
                        <option value="<?= $h($z) ?>"><?= $h($z) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <!-- Pedidos por cliente -->
            <?php if (empty($porCliente)): ?>
            <div class="empty-state">
                <span class="empty-icon">📭</span>
                <div style="font-size:.95rem;font-weight:600;margin-bottom:6px;">No hay pedidos registrados aún.</div>
                <div style="font-size:.83rem;">Los clientes hacen sus reservas desde la
                    <a href="disponible-cliente.php" style="color:var(--vz-verde);font-weight:600;">Vista Cliente</a>.
                </div>
            </div>
            <?php else: ?>

            <div id="clientes-wrap">
            <?php foreach ($porCliente as $uid => $cliente):
                $info      = $cliente['info'];
                $lineas    = $cliente['lineas'];
                $initial   = mb_strtoupper(mb_substr($info['nombre'], 0, 1, 'UTF-8'), 'UTF-8');
                $zonaDisp  = ($info['zonas'] === 'TODAS') ? 'Todas las zonas' : $info['zonas'];
            ?>
            <div class="cliente-block"
                 data-nombre="<?= $h(strtolower($info['nombre'])) ?>"
                 data-email="<?= $h(strtolower($info['email'])) ?>"
                 data-zonas="<?= $h(strtoupper($info['zonas'])) ?>">

                <div class="cliente-hdr" onclick="toggleCliente(this)">
                    <div class="c-avatar"><?= $h($initial) ?></div>
                    <div style="flex:1;min-width:0;">
                        <div class="c-name"><?= $h($info['nombre']) ?></div>
                        <div class="c-email"><?= $h($info['email']) ?></div>
                        <div class="c-meta">
                            <span class="badge badge-tarifa">🏷 <?= $h($info['tarifa_label']) ?></span>
                            <span class="badge badge-zona">📍 <?= $h($zonaDisp) ?></span>
                            <?php if ($info['role']): ?>
                            <span class="badge badge-role"><?= $h($info['role']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="c-totals">
                        <div class="c-total-val"><?= number_format($cliente['total'], 2, ',', '.') ?> €</div>
                        <div class="c-total-sub">
                            <?= count($lineas) ?> prod. &middot; <?= number_format($cliente['total_unids'], 0, ',', '.') ?> ud
                        </div>
                    </div>
                    <button class="btn-del-cliente"
                            onclick="event.stopPropagation(); deleteCliente('<?= $h($uid) ?>', this)">
                        🗑 Borrar todo
                    </button>
                    <span class="c-chevron">▶</span>
                </div>

                <div class="lineas-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Formato</th>
                                <th>Productor</th>
                                <th>Zona</th>
                                <th class="r">Ud.</th>
                                <th class="r">Precio ud.</th>
                                <th class="r">Subtotal</th>
                                <th>Actualizado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lineas as $l): ?>
                        <tr id="row-<?= $l['disponible_id'] ?>-<?= $h($uid) ?>">
                            <td class="name-wrap">
                                <div class="name-main"><?= $h($l['nombre']) ?></div>
                            </td>
                            <td>
                                <?php if ($l['formato']): ?>
                                    <span class="fmt-badge"><?= $h($l['formato']) ?></span>
                                <?php else: ?>
                                    <span class="val-null">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $l['productor'] ? $h($l['productor']) : '<span class="val-null">—</span>' ?></td>
                            <td><?= $l['zona']      ? $h($l['zona'])      : '<span class="val-null">—</span>' ?></td>
                            <td class="td-r"><strong><?= (int)$l['unids'] ?></strong></td>
                            <td class="td-r">
                                <?php if ($l['precio'] !== null): ?>
                                    <span class="precio-val"><?= number_format($l['precio'], 2, ',', '.') ?> €</span>
                                <?php else: ?>
                                    <span class="val-null">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="td-r">
                                <?php if ($l['subtotal'] !== null): ?>
                                    <span class="sub-val"><?= number_format($l['subtotal'], 2, ',', '.') ?> €</span>
                                <?php else: ?>
                                    <span class="val-null">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($l['updated_at']): ?>
                                    <span class="date-chip"><?= $h(substr($l['updated_at'], 0, 10)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn-del-linea"
                                        onclick="deleteLinea(<?= $l['disponible_id'] ?>, '<?= $h($uid) ?>', this)">
                                    ✕
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="tfoot-row">
                                <td colspan="4" class="val-null">Total del cliente</td>
                                <td class="td-r"><strong><?= number_format($cliente['total_unids'], 0, ',', '.') ?> ud</strong></td>
                                <td></td>
                                <td class="td-r tfoot-total"><?= number_format($cliente['total'], 2, ',', '.') ?> €</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <?php endif; ?>

        </main>
    </div>
</div>

<script>
function toggleCliente(hdr) { hdr.closest('.cliente-block').classList.toggle('open'); }
function expandAll()        { document.querySelectorAll('.cliente-block').forEach(b => b.classList.add('open')); }
function collapseAll()      { document.querySelectorAll('.cliente-block').forEach(b => b.classList.remove('open')); }

function filtrarClientes() {
    const q    = document.getElementById('filtro-cliente').value.toLowerCase().trim();
    const zona = (document.getElementById('filtro-zona') || {value:''}).value.toUpperCase().trim();
    document.querySelectorAll('.cliente-block').forEach(b => {
        const ok = (!q    || b.dataset.nombre.includes(q) || b.dataset.email.includes(q))
                && (!zona || b.dataset.zonas.includes(zona));
        b.style.display = ok ? '' : 'none';
    });
}

function deleteLinea(did, uid, btn) {
    if (!confirm('¿Eliminar esta línea?')) return;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'delete_linea');
    fd.append('disponible_id', did);
    fd.append('user_id', uid);
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) { document.getElementById('row-' + did + '-' + uid)?.remove(); }
            else { btn.disabled = false; alert('Error: ' + d.error); }
        })
        .catch(() => { btn.disabled = false; alert('Error de red.'); });
}

function deleteCliente(uid, btn) {
    if (!confirm('¿Eliminar TODOS los pedidos de este cliente?')) return;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'delete_cliente');
    fd.append('user_id', uid);
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) { btn.closest('.cliente-block')?.remove(); }
            else { btn.disabled = false; alert('Error: ' + d.error); }
        })
        .catch(() => { btn.disabled = false; alert('Error de red.'); });
}
</script>
</body>
</html>
