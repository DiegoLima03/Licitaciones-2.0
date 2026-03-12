<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

/** @var array<string, mixed> $user */
$user = $_SESSION['user'];
$email = (string)($user['email'] ?? '');
$fullName = (string)($user['full_name'] ?? '');
$role = (string)($user['role'] ?? '');

$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Buscador historico</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; }
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
            font-size: 0.9rem;
            text-decoration: none;
        }
        .sidebar-footer {
            margin-top: 24px;
            font-size: 0.75rem;
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
            font-size: 0.75rem;
            margin-top: 2px;
        }
        main {
            flex: 1;
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 20px 32px;
            width: 100%;
        }
        .card {
            border-radius: 12px;
            padding: 18px 18px 20px;
        }
        .card h2 {
            margin: 0 0 8px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .card p {
            margin: 0 0 12px;
            font-size: 0.9rem;
        }
        .search-row {
            margin-top: 8px;
            display: flex;
            gap: 8px;
        }
        .search-row input {
            flex: 1;
            border-radius: 9999px;
            padding: 8px 14px;
            font-size: 0.9rem;
        }
        .search-row button {
            border-radius: 9999px !important;
            padding: 8px 18px !important;
            font-size: 0.9rem;
            cursor: pointer;
        }

        /* Loading */
        .loading-wrap { text-align: center; padding: 32px 0; display: none; }
        .spinner {
            display: inline-block;
            width: 32px; height: 32px;
            border: 3px solid var(--vz-marron2, #85725e);
            border-top-color: var(--vz-verde, #8e8b30);
            border-radius: 50%;
            animation: spin .6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Tabs */
        .tabs-bar {
            display: flex;
            gap: 4px;
            background: var(--vz-verde, #8e8b30);
            border: 1px solid var(--vz-verde, #8e8b30);
            border-radius: 10px;
            padding: 4px;
            margin-bottom: 16px;
        }
        .tab-btn {
            flex: 1;
            border: 1px solid transparent !important;
            border-radius: 8px !important;
            padding: 8px 10px !important;
            font-size: 0.85rem !important;
            font-weight: 600 !important;
            cursor: pointer;
            background: transparent !important;
            color: var(--vz-crema, #e5e2dc) !important;
            transition: all .15s;
        }
        .tab-btn:hover {
            color: #fff !important;
            background: rgba(255, 255, 255, 0.12) !important;
        }
        .tab-btn.active {
            background: var(--vz-blanco, #fff) !important;
            color: var(--vz-negro, #10180e) !important;
            border-color: transparent !important;
            box-shadow: 0 1px 3px rgba(16, 24, 14, 0.15) !important;
        }
        .tab-panel { display: none; }
        .tab-panel.visible { display: block; }

        /* Summary */
        .summary-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .summary-box {
            background: var(--vz-blanco, #fff);
            border: 1px solid rgba(133, 114, 94, 0.4);
            border-radius: 10px;
            padding: 10px 14px;
            min-width: 120px;
        }
        .summary-label {
            font-size: 0.68rem;
            color: var(--vz-marron2, #85725e) !important;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .04em;
        }
        .summary-value {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--vz-negro, #10180e) !important;
            margin-top: 2px;
            font-variant-numeric: tabular-nums;
        }

        /* Table */
        .tbl-wrap {
            overflow-x: auto;
            max-height: 520px;
            overflow-y: auto;
            border: 1px solid rgba(133, 114, 94, 0.45);
            border-radius: 10px;
        }
        .tbl {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }
        .tbl th {
            font-weight: 700 !important;
            text-align: left;
            padding: 9px 10px !important;
            position: sticky;
            top: 0;
            z-index: 2;
            font-size: 0.73rem;
            text-transform: uppercase;
            letter-spacing: .03em;
            background: var(--vz-verde, #8e8b30) !important;
            color: var(--vz-crema, #e5e2dc) !important;
        }
        .tbl td {
            padding: 7px 10px !important;
        }
        .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .badge-green {
            background: rgba(142, 139, 48, 0.15);
            color: #4f4b1c !important;
            border: 1px solid rgba(142, 139, 48, 0.35);
        }
        .badge-blue {
            background: rgba(70, 51, 31, 0.1);
            color: var(--vz-marron1, #46331f) !important;
            border: 1px solid rgba(70, 51, 31, 0.3);
        }

        .no-results {
            text-align: center;
            padding: 32px 0;
            color: var(--vz-marron2, #85725e);
            display: none;
        }

        #results { display: none; }

        .empty-msg {
            color: var(--vz-marron2, #85725e);
            text-align: center;
            padding: 16px 0;
            font-size: 0.88rem;
        }

        /* Clickable product name */
        .product-link {
            color: var(--vz-verde, #8e8b30);
            cursor: pointer;
            text-decoration: underline;
            text-decoration-style: dotted;
            text-underline-offset: 2px;
        }
        .product-link:hover {
            color: var(--vz-marron1, #46331f);
            text-decoration-style: solid;
        }

        /* Chart modal */
        .chart-overlay {
            position: fixed;
            inset: 0;
            z-index: 90;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(16, 24, 14, 0.46);
            backdrop-filter: blur(3px);
        }
        .chart-overlay.is-open {
            display: flex;
        }
        .chart-dialog {
            width: min(96vw, 960px);
            max-height: 92vh;
            overflow-y: auto;
            border-radius: 14px;
            border: 1px solid var(--vz-marron2, #85725e);
            background: var(--vz-blanco, #fff);
            box-shadow: 0 14px 30px rgba(16, 24, 14, 0.24);
            padding: 20px;
        }
        .chart-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(133, 114, 94, 0.35);
        }
        .chart-head h3 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--vz-negro, #10180e);
            line-height: 1.2;
        }
        .chart-head-sub {
            margin: 4px 0 0;
            font-size: 0.82rem;
            color: var(--vz-marron2, #85725e);
        }
        .chart-close {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid var(--vz-marron2, #85725e) !important;
            background: var(--vz-crema, #e5e2dc) !important;
            color: var(--vz-marron1, #46331f) !important;
            font-weight: 700;
            cursor: pointer;
            font-size: 1.1rem;
            line-height: 1;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .chart-close:hover {
            background: #f4f0e8 !important;
        }
        .chart-stats {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .chart-stat {
            flex: 1;
            min-width: 110px;
            background: var(--vz-crema, #e5e2dc);
            border: 1px solid rgba(133, 114, 94, 0.35);
            border-radius: 10px;
            padding: 10px 12px;
        }
        .chart-stat-label {
            font-size: 0.66rem;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .04em;
            color: var(--vz-marron2, #85725e);
        }
        .chart-stat-value {
            font-size: 1.08rem;
            font-weight: 800;
            color: var(--vz-negro, #10180e);
            margin-top: 2px;
            font-variant-numeric: tabular-nums;
        }
        .chart-stat-value.is-pvu { color: var(--vz-verde, #8e8b30); }
        .chart-stat-value.is-pcu { color: #b45309; }
        .chart-canvas-wrap {
            position: relative;
            width: 100%;
            height: 320px;
            border: 1px solid rgba(133, 114, 94, 0.3);
            border-radius: 10px;
            background: var(--vz-blanco, #fff);
            padding: 10px;
        }
        .chart-legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 10px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--vz-marron1, #46331f);
        }
        .chart-legend-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
            vertical-align: middle;
        }
        .chart-no-data {
            text-align: center;
            padding: 40px 20px;
            color: var(--vz-marron2, #85725e);
            font-size: 0.9rem;
        }
        .chart-trend {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .chart-trend.up {
            background: rgba(200, 60, 50, 0.12);
            color: #8d2b23;
            border: 1px solid rgba(200, 60, 50, 0.35);
        }
        .chart-trend.down {
            background: rgba(142, 139, 48, 0.12);
            color: #4f4b1c;
            border: 1px solid rgba(142, 139, 48, 0.35);
        }
        .chart-trend.flat {
            background: rgba(133, 114, 94, 0.12);
            color: var(--vz-marron1, #46331f);
            border: 1px solid rgba(133, 114, 94, 0.35);
        }

        @media (max-width: 768px) {
            .layout { flex-direction: column; }
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
            .tabs-bar { flex-wrap: wrap; }
            .chart-canvas-wrap { height: 240px; }
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
                <a href="buscador.php" class="nav-link active">Buscador hist&oacute;rico</a>
                <a href="analytics.php" class="nav-link">Anal&iacute;tica</a>
                <a href="disponible.php" class="nav-link">Disponible</a>
                <a href="disponible-cliente.php" class="nav-link">Vista Cliente</a>
                <a href="pedidos-disponible.php" class="nav-link">Pedidos</a>
                <a href="usuarios.php" class="nav-link">Usuarios</a>
            </nav>
        </aside>

        <div class="main">
            <header>
                <h1>Buscador hist&oacute;rico de precios</h1>
                <div class="user-info">
                    <div><?php echo $h($fullName !== '' ? $fullName : $email); ?></div>
                    <?php if ($role !== ''): ?>
                        <div class="pill"><?php echo $h($role); ?></div>
                    <?php endif; ?>
                    <div><a href="logout.php">Cerrar sesi&oacute;n</a></div>
                </div>
            </header>

            <main>
                <div class="card" style="margin-bottom:20px;">
                    <h2>Buscador hist&oacute;rico de precios</h2>
                    <p>Busca productos y consulta su hist&oacute;rico de precios en albaranes de venta/compra, licitaciones y l&iacute;neas de referencia. Haz clic en el nombre de un art&iacute;culo para ver su gr&aacute;fico de evoluci&oacute;n.</p>
                    <div class="search-row">
                        <input type="text" id="q" placeholder="Nombre, referencia, c&oacute;digo..." autofocus />
                        <button type="button" id="btnSearch">Buscar</button>
                    </div>
                    <p class="hint">M&iacute;nimo 2 caracteres. Se busca por nombre de art&iacute;culo, referencia o c&oacute;digo.</p>
                </div>

                <!-- Loading -->
                <div class="loading-wrap" id="loading">
                    <div class="spinner"></div>
                    <p style="margin-top:8px;font-size:.85rem;">Buscando...</p>
                </div>

                <!-- No results -->
                <div class="no-results" id="noResults">No se encontraron resultados.</div>

                <!-- Results -->
                <div id="results">
                    <div class="tabs-bar" id="tabs">
                        <button data-tab="venta" class="tab-btn active">Ventas <span id="countVenta"></span></button>
                        <button data-tab="compra" class="tab-btn">Compras <span id="countCompra"></span></button>
                        <button data-tab="licitaciones" class="tab-btn">Licitaciones <span id="countLicit"></span></button>
                        <button data-tab="referencia" class="tab-btn">Referencia <span id="countRef"></span></button>
                    </div>

                    <div id="panel-venta" class="tab-panel visible"></div>
                    <div id="panel-compra" class="tab-panel"></div>
                    <div id="panel-licitaciones" class="tab-panel"></div>
                    <div id="panel-referencia" class="tab-panel"></div>
                </div>
            </main>
        </div>
    </div>

    <!-- Chart Modal -->
    <div class="chart-overlay" id="chartOverlay">
        <div class="chart-dialog">
            <div class="chart-head">
                <div>
                    <h3 id="chartTitle">Evoluci&oacute;n del precio</h3>
                    <p class="chart-head-sub" id="chartSubtitle"></p>
                </div>
                <button class="chart-close" id="chartClose">&times;</button>
            </div>
            <div class="chart-stats" id="chartStats"></div>
            <div class="chart-canvas-wrap">
                <canvas id="priceChart"></canvas>
            </div>
            <div class="chart-legend">
                <span><span class="chart-legend-dot" style="background:#8e8b30;"></span> PVU (Precio venta)</span>
                <span><span class="chart-legend-dot" style="background:#b45309;"></span> PCU (Precio coste)</span>
            </div>
        </div>
    </div>

<script>
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => document.querySelectorAll(sel);

// Store search results globally for chart building
let lastVenta = [];
let lastCompra = [];
let lastLicit = [];
let lastRef = [];

const fmt = (v, dec = 4) => v != null ? Number(v).toFixed(dec).replace(/\.?0+$/, '') : '-';
const fmtEur = (v) => v != null ? Number(v).toFixed(4) + ' \u20AC' : '-';
const fmtDate = (d) => d || '-';

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function switchTab(tab) {
    $$('.tab-btn').forEach(b => b.classList.remove('active'));
    const active = document.querySelector('[data-tab="' + tab + '"]');
    if (active) active.classList.add('active');
    $$('.tab-panel').forEach(p => p.classList.remove('visible'));
    const panel = $('#panel-' + tab);
    if (panel) panel.classList.add('visible');
}

$('#tabs').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-tab]');
    if (btn) switchTab(btn.dataset.tab);
});

function computeSummary(rows, priceField) {
    if (!rows.length) return null;
    const prices = rows.map(r => r[priceField]).filter(v => v != null);
    if (!prices.length) return null;
    const min = Math.min(...prices);
    const max = Math.max(...prices);
    const avg = prices.reduce((s, v) => s + v, 0) / prices.length;
    return { min, max, avg, count: prices.length };
}

function summaryHTML(summary, label) {
    if (!summary) return '';
    return '<div class="summary-row">'
        + '<div class="summary-box"><div class="summary-label">' + label + ' min</div><div class="summary-value">' + summary.min.toFixed(4) + ' \u20AC</div></div>'
        + '<div class="summary-box"><div class="summary-label">' + label + ' max</div><div class="summary-value">' + summary.max.toFixed(4) + ' \u20AC</div></div>'
        + '<div class="summary-box"><div class="summary-label">' + label + ' medio</div><div class="summary-value">' + summary.avg.toFixed(4) + ' \u20AC</div></div>'
        + '<div class="summary-box"><div class="summary-label">Registros</div><div class="summary-value">' + summary.count + '</div></div>'
        + '</div>';
}

function renderAlbaranes(rows, tipo) {
    const label = tipo === 'venta' ? 'PVU' : 'PCU';
    const badgeClass = tipo === 'venta' ? 'badge-green' : 'badge-blue';
    const summary = computeSummary(rows, 'precio_unitario');

    let html = summaryHTML(summary, label);
    html += '<div class="tbl-wrap"><table class="tbl">'
        + '<thead><tr>'
        + '<th>Fecha</th>'
        + '<th>Albaran</th>'
        + '<th>Ref. articulo</th>'
        + '<th>Articulo</th>'
        + '<th>Familia</th>'
        + '<th class="num">Cantidad</th>'
        + '<th class="num">' + label + '</th>'
        + '<th class="num">Dto %</th>'
        + '<th class="num">Importe</th>'
        + '<th>' + (tipo === 'venta' ? 'Cliente' : 'Proveedor') + '</th>'
        + '<th>Comercial</th>'
        + '</tr></thead><tbody>';

    for (const r of rows) {
        const nameHtml = r.nombre_articulo
            ? '<span class="product-link" data-product="' + escHtml(r.nombre_articulo) + '">' + escHtml(r.nombre_articulo) + '</span>'
            : '-';
        html += '<tr>'
            + '<td>' + fmtDate(r.fecha_albaran) + '</td>'
            + '<td><span class="badge ' + badgeClass + '">' + (r.numero_albaran || '-') + '</span></td>'
            + '<td>' + escHtml(r.ref_articulo || '-') + '</td>'
            + '<td>' + nameHtml + '</td>'
            + '<td>' + escHtml(r.familia || '-') + '</td>'
            + '<td class="num">' + fmt(r.cantidad) + '</td>'
            + '<td class="num">' + fmtEur(r.precio_unitario) + '</td>'
            + '<td class="num">' + (r.descuento_pct != null ? fmt(r.descuento_pct, 2) + '%' : '-') + '</td>'
            + '<td class="num">' + fmtEur(r.importe) + '</td>'
            + '<td>' + escHtml(r.contacto || '-') + '</td>'
            + '<td>' + escHtml(r.comercial || '-') + '</td>'
            + '</tr>';
    }

    html += '</tbody></table></div>';
    return html;
}

function renderLicitaciones(rows) {
    const summary = computeSummary(rows, 'pvu');
    let html = summaryHTML(summary, 'PVU');

    html += '<div class="tbl-wrap"><table class="tbl">'
        + '<thead><tr>'
        + '<th>Licitacion</th>'
        + '<th>Expediente</th>'
        + '<th>Fecha</th>'
        + '<th>Producto</th>'
        + '<th>Referencia</th>'
        + '<th>Lote</th>'
        + '<th class="num">Uds</th>'
        + '<th class="num">PVU</th>'
        + '<th class="num">PCU</th>'
        + '<th>Proveedor</th>'
        + '</tr></thead><tbody>';

    for (const r of rows) {
        const nameHtml = r.producto
            ? '<span class="product-link" data-product="' + escHtml(r.producto) + '">' + escHtml(r.producto) + '</span>'
            : '-';
        html += '<tr>'
            + '<td>' + escHtml(r.licitacion || '-') + '</td>'
            + '<td>' + escHtml(r.expediente || '-') + '</td>'
            + '<td>' + fmtDate(r.fecha) + '</td>'
            + '<td>' + nameHtml + '</td>'
            + '<td>' + escHtml(r.referencia || '-') + '</td>'
            + '<td>' + escHtml(r.lote || '-') + '</td>'
            + '<td class="num">' + fmt(r.unidades) + '</td>'
            + '<td class="num">' + fmtEur(r.pvu) + '</td>'
            + '<td class="num">' + fmtEur(r.pcu) + '</td>'
            + '<td>' + escHtml(r.proveedor || '-') + '</td>'
            + '</tr>';
    }

    html += '</tbody></table></div>';
    return html;
}

function renderReferencia(rows) {
    const summary = computeSummary(rows, 'pvu');
    let html = summaryHTML(summary, 'PVU');

    html += '<div class="tbl-wrap"><table class="tbl">'
        + '<thead><tr>'
        + '<th>Producto</th>'
        + '<th>Referencia</th>'
        + '<th>Fecha</th>'
        + '<th class="num">Uds</th>'
        + '<th class="num">PVU</th>'
        + '<th class="num">PCU</th>'
        + '<th>Proveedor</th>'
        + '</tr></thead><tbody>';

    for (const r of rows) {
        const nameHtml = r.producto
            ? '<span class="product-link" data-product="' + escHtml(r.producto) + '">' + escHtml(r.producto) + '</span>'
            : '-';
        html += '<tr>'
            + '<td>' + nameHtml + '</td>'
            + '<td>' + escHtml(r.referencia || '-') + '</td>'
            + '<td>' + fmtDate(r.fecha) + '</td>'
            + '<td class="num">' + fmt(r.unidades) + '</td>'
            + '<td class="num">' + fmtEur(r.pvu) + '</td>'
            + '<td class="num">' + fmtEur(r.pcu) + '</td>'
            + '<td>' + escHtml(r.proveedor || '-') + '</td>'
            + '</tr>';
    }

    html += '</tbody></table></div>';
    return html;
}

// ── Chart logic ──────────────────────────────────────────────────────────

let priceChartInstance = null;

function buildChartData(productName) {
    const norm = productName.toLowerCase().trim();
    const pvuPoints = []; // {date, price}
    const pcuPoints = [];

    // PVU from venta albaranes
    for (const r of lastVenta) {
        if ((r.nombre_articulo || '').toLowerCase().trim() === norm && r.precio_unitario != null && r.fecha_albaran) {
            pvuPoints.push({ date: r.fecha_albaran, price: Number(r.precio_unitario) });
        }
    }

    // PCU from compra albaranes
    for (const r of lastCompra) {
        if ((r.nombre_articulo || '').toLowerCase().trim() === norm && r.precio_unitario != null && r.fecha_albaran) {
            pcuPoints.push({ date: r.fecha_albaran, price: Number(r.precio_unitario) });
        }
    }

    // PVU & PCU from licitaciones
    for (const r of lastLicit) {
        if ((r.producto || '').toLowerCase().trim() === norm && r.fecha) {
            if (r.pvu != null) pvuPoints.push({ date: r.fecha, price: Number(r.pvu) });
            if (r.pcu != null) pcuPoints.push({ date: r.fecha, price: Number(r.pcu) });
        }
    }

    // PVU & PCU from referencia
    for (const r of lastRef) {
        if ((r.producto || '').toLowerCase().trim() === norm && r.fecha) {
            if (r.pvu != null) pvuPoints.push({ date: r.fecha, price: Number(r.pvu) });
            if (r.pcu != null) pcuPoints.push({ date: r.fecha, price: Number(r.pcu) });
        }
    }

    // Sort by date
    pvuPoints.sort((a, b) => a.date.localeCompare(b.date));
    pcuPoints.sort((a, b) => a.date.localeCompare(b.date));

    return { pvuPoints, pcuPoints };
}

function calcStats(points) {
    if (!points.length) return null;
    const prices = points.map(p => p.price);
    const min = Math.min(...prices);
    const max = Math.max(...prices);
    const avg = prices.reduce((s, v) => s + v, 0) / prices.length;
    const first = prices[0];
    const last = prices[prices.length - 1];
    let trendPct = 0;
    if (first > 0) trendPct = ((last - first) / first) * 100;
    return { min, max, avg, count: prices.length, first, last, trendPct };
}

function openChart(productName) {
    const { pvuPoints, pcuPoints } = buildChartData(productName);
    const totalPoints = pvuPoints.length + pcuPoints.length;

    $('#chartTitle').textContent = productName;
    const overlay = $('#chartOverlay');

    if (totalPoints === 0) {
        $('#chartStats').innerHTML = '';
        const wrap = document.querySelector('.chart-canvas-wrap');
        wrap.innerHTML = '<div class="chart-no-data">No hay datos de precio con fecha para este producto.</div>';
        $('#chartSubtitle').textContent = '';
        overlay.classList.add('is-open');
        return;
    }

    // Restore canvas if it was replaced
    const wrap = document.querySelector('.chart-canvas-wrap');
    if (!$('#priceChart')) {
        wrap.innerHTML = '<canvas id="priceChart"></canvas>';
    }

    // Build stats
    const pvuStats = calcStats(pvuPoints);
    const pcuStats = calcStats(pcuPoints);

    let statsHtml = '';
    if (pvuStats) {
        statsHtml += '<div class="chart-stat"><div class="chart-stat-label">PVU medio</div><div class="chart-stat-value is-pvu">' + pvuStats.avg.toFixed(4) + ' \u20AC</div></div>';
        statsHtml += '<div class="chart-stat"><div class="chart-stat-label">PVU min / max</div><div class="chart-stat-value">' + pvuStats.min.toFixed(4) + ' / ' + pvuStats.max.toFixed(4) + '</div></div>';
    }
    if (pcuStats) {
        statsHtml += '<div class="chart-stat"><div class="chart-stat-label">PCU medio</div><div class="chart-stat-value is-pcu">' + pcuStats.avg.toFixed(4) + ' \u20AC</div></div>';
        statsHtml += '<div class="chart-stat"><div class="chart-stat-label">PCU min / max</div><div class="chart-stat-value">' + pcuStats.min.toFixed(4) + ' / ' + pcuStats.max.toFixed(4) + '</div></div>';
    }

    // Trend badge
    const mainStats = pvuStats || pcuStats;
    if (mainStats && mainStats.count >= 2) {
        const pct = mainStats.trendPct;
        const cls = pct > 1 ? 'up' : pct < -1 ? 'down' : 'flat';
        const arrow = pct > 1 ? '\u2191' : pct < -1 ? '\u2193' : '\u2192';
        const label = pct > 1 ? 'Subida' : pct < -1 ? 'Bajada' : 'Estable';
        statsHtml += '<div class="chart-stat"><div class="chart-stat-label">Tendencia</div>'
            + '<div style="margin-top:4px;"><span class="chart-trend ' + cls + '">'
            + arrow + ' ' + Math.abs(pct).toFixed(1) + '% ' + label
            + '</span></div></div>';
    }

    $('#chartStats').innerHTML = statsHtml;
    $('#chartSubtitle').textContent = (pvuPoints.length + pcuPoints.length) + ' registros con fecha';

    // Build Chart.js datasets
    // Merge all dates for x-axis
    const allDatesSet = new Set();
    pvuPoints.forEach(p => allDatesSet.add(p.date));
    pcuPoints.forEach(p => allDatesSet.add(p.date));
    const allDates = [...allDatesSet].sort();

    // Build maps: date -> avg price (in case multiple entries same date)
    function avgByDate(points) {
        const map = {};
        for (const p of points) {
            if (!map[p.date]) map[p.date] = [];
            map[p.date].push(p.price);
        }
        const result = {};
        for (const d in map) {
            result[d] = map[d].reduce((s, v) => s + v, 0) / map[d].length;
        }
        return result;
    }

    const pvuByDate = avgByDate(pvuPoints);
    const pcuByDate = avgByDate(pcuPoints);

    const pvuData = allDates.map(d => pvuByDate[d] !== undefined ? pvuByDate[d] : null);
    const pcuData = allDates.map(d => pcuByDate[d] !== undefined ? pcuByDate[d] : null);

    // Format dates for display
    const dateLabels = allDates.map(d => {
        const parts = d.split('-');
        if (parts.length === 3) return parts[2] + '/' + parts[1] + '/' + parts[0].slice(2);
        return d;
    });

    // Destroy previous chart
    if (priceChartInstance) {
        priceChartInstance.destroy();
        priceChartInstance = null;
    }

    const ctx = $('#priceChart').getContext('2d');
    priceChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: dateLabels,
            datasets: [
                {
                    label: 'PVU (Precio venta)',
                    data: pvuData,
                    borderColor: '#8e8b30',
                    backgroundColor: 'rgba(142, 139, 48, 0.1)',
                    borderWidth: 2.5,
                    pointRadius: pvuData.length > 30 ? 0 : 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#8e8b30',
                    fill: false,
                    tension: 0.2,
                    spanGaps: true,
                },
                {
                    label: 'PCU (Precio coste)',
                    data: pcuData,
                    borderColor: '#b45309',
                    backgroundColor: 'rgba(180, 83, 9, 0.1)',
                    borderWidth: 2.5,
                    pointRadius: pcuData.length > 30 ? 0 : 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#b45309',
                    fill: false,
                    tension: 0.2,
                    spanGaps: true,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(16, 24, 14, 0.92)',
                    titleColor: '#e5e2dc',
                    bodyColor: '#e5e2dc',
                    borderColor: 'rgba(133, 114, 94, 0.5)',
                    borderWidth: 1,
                    cornerRadius: 8,
                    padding: 10,
                    callbacks: {
                        label: function(ctx) {
                            if (ctx.parsed.y == null) return null;
                            return ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(4) + ' \u20AC';
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        maxRotation: 45,
                        font: { size: 10 },
                        color: '#85725e',
                        maxTicksLimit: 15,
                    },
                    grid: {
                        color: 'rgba(133, 114, 94, 0.15)',
                    }
                },
                y: {
                    ticks: {
                        font: { size: 11 },
                        color: '#85725e',
                        callback: function(v) { return v.toFixed(2) + ' \u20AC'; }
                    },
                    grid: {
                        color: 'rgba(133, 114, 94, 0.15)',
                    }
                }
            }
        }
    });

    overlay.classList.add('is-open');
}

function closeChart() {
    $('#chartOverlay').classList.remove('is-open');
    if (priceChartInstance) {
        priceChartInstance.destroy();
        priceChartInstance = null;
    }
}

// Close modal handlers
$('#chartClose').addEventListener('click', closeChart);
$('#chartOverlay').addEventListener('mousedown', (e) => {
    if (e.target === $('#chartOverlay')) closeChart();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeChart();
});

// Delegate click on product links
document.addEventListener('click', (e) => {
    const link = e.target.closest('.product-link');
    if (!link) return;
    const product = link.dataset.product;
    if (product) openChart(product);
});

// ── Search ──────────────────────────────────────────────────────────────

async function doSearch() {
    const q = $('#q').value.trim();
    if (q.length < 2) return;

    $('#loading').style.display = 'block';
    $('#results').style.display = 'none';
    $('#noResults').style.display = 'none';

    try {
        const resp = await fetch('buscador-api.php?q=' + encodeURIComponent(q));
        if (!resp.ok) throw new Error('Error ' + resp.status);
        const data = await resp.json();

        lastVenta = data.albaranes_venta || [];
        lastCompra = data.albaranes_compra || [];
        lastLicit = data.licitaciones || [];
        lastRef = data.referencia || [];

        const total = lastVenta.length + lastCompra.length + lastLicit.length + lastRef.length;

        $('#loading').style.display = 'none';

        if (total === 0) {
            $('#noResults').style.display = 'block';
            return;
        }

        $('#countVenta').textContent = lastVenta.length ? '(' + lastVenta.length + ')' : '';
        $('#countCompra').textContent = lastCompra.length ? '(' + lastCompra.length + ')' : '';
        $('#countLicit').textContent = lastLicit.length ? '(' + lastLicit.length + ')' : '';
        $('#countRef').textContent = lastRef.length ? '(' + lastRef.length + ')' : '';

        $('#panel-venta').innerHTML = lastVenta.length
            ? renderAlbaranes(lastVenta, 'venta')
            : '<p class="empty-msg">Sin resultados de ventas.</p>';
        $('#panel-compra').innerHTML = lastCompra.length
            ? renderAlbaranes(lastCompra, 'compra')
            : '<p class="empty-msg">Sin resultados de compras.</p>';
        $('#panel-licitaciones').innerHTML = lastLicit.length
            ? renderLicitaciones(lastLicit)
            : '<p class="empty-msg">Sin resultados de licitaciones.</p>';
        $('#panel-referencia').innerHTML = lastRef.length
            ? renderReferencia(lastRef)
            : '<p class="empty-msg">Sin precios de referencia.</p>';

        $('#results').style.display = 'block';

        if (lastVenta.length) switchTab('venta');
        else if (lastCompra.length) switchTab('compra');
        else if (lastLicit.length) switchTab('licitaciones');
        else switchTab('referencia');

    } catch (err) {
        $('#loading').style.display = 'none';
        alert('Error al buscar: ' + err.message);
    }
}

$('#btnSearch').addEventListener('click', doSearch);
$('#q').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') doSearch();
});
</script>
</body>
</html>
