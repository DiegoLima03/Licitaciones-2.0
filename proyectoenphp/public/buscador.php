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
            background: var(--vz-crema, #e5e2dc);
            border: 1px solid var(--vz-marron2, #85725e);
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
            color: var(--vz-marron2, #85725e) !important;
            transition: all .15s;
        }
        .tab-btn:hover {
            color: var(--vz-marron1, #46331f) !important;
            background: rgba(142, 139, 48, 0.06) !important;
        }
        .tab-btn.active {
            background: var(--vz-blanco, #fff) !important;
            color: var(--vz-negro, #10180e) !important;
            border-color: rgba(133, 114, 94, 0.4) !important;
            box-shadow: 0 1px 3px rgba(16, 24, 14, 0.1) !important;
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
            z-index: 1;
            font-size: 0.73rem;
            text-transform: uppercase;
            letter-spacing: .03em;
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
                <a href="lineas-referencia.php" class="nav-link">A&ntilde;adir l&iacute;neas</a>
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
                    <p>Busca productos y consulta su hist&oacute;rico de precios en albaranes de venta/compra, licitaciones y l&iacute;neas de referencia.</p>
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

<script>
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => document.querySelectorAll(sel);

const fmt = (v, dec = 4) => v != null ? Number(v).toFixed(dec).replace(/\.?0+$/, '') : '-';
const fmtEur = (v) => v != null ? Number(v).toFixed(4) + ' \u20AC' : '-';
const fmtDate = (d) => d || '-';

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
        html += '<tr>'
            + '<td>' + fmtDate(r.fecha_albaran) + '</td>'
            + '<td><span class="badge ' + badgeClass + '">' + (r.numero_albaran || '-') + '</span></td>'
            + '<td>' + (r.ref_articulo || '-') + '</td>'
            + '<td>' + (r.nombre_articulo || '-') + '</td>'
            + '<td>' + (r.familia || '-') + '</td>'
            + '<td class="num">' + fmt(r.cantidad) + '</td>'
            + '<td class="num">' + fmtEur(r.precio_unitario) + '</td>'
            + '<td class="num">' + (r.descuento_pct != null ? fmt(r.descuento_pct, 2) + '%' : '-') + '</td>'
            + '<td class="num">' + fmtEur(r.importe) + '</td>'
            + '<td>' + (r.contacto || '-') + '</td>'
            + '<td>' + (r.comercial || '-') + '</td>'
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
        html += '<tr>'
            + '<td>' + (r.licitacion || '-') + '</td>'
            + '<td>' + (r.expediente || '-') + '</td>'
            + '<td>' + fmtDate(r.fecha) + '</td>'
            + '<td>' + (r.producto || '-') + '</td>'
            + '<td>' + (r.referencia || '-') + '</td>'
            + '<td>' + (r.lote || '-') + '</td>'
            + '<td class="num">' + fmt(r.unidades) + '</td>'
            + '<td class="num">' + fmtEur(r.pvu) + '</td>'
            + '<td class="num">' + fmtEur(r.pcu) + '</td>'
            + '<td>' + (r.proveedor || '-') + '</td>'
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
        html += '<tr>'
            + '<td>' + (r.producto || '-') + '</td>'
            + '<td>' + (r.referencia || '-') + '</td>'
            + '<td>' + fmtDate(r.fecha) + '</td>'
            + '<td class="num">' + fmt(r.unidades) + '</td>'
            + '<td class="num">' + fmtEur(r.pvu) + '</td>'
            + '<td class="num">' + fmtEur(r.pcu) + '</td>'
            + '<td>' + (r.proveedor || '-') + '</td>'
            + '</tr>';
    }

    html += '</tbody></table></div>';
    return html;
}

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

        const venta = data.albaranes_venta || [];
        const compra = data.albaranes_compra || [];
        const licit = data.licitaciones || [];
        const ref = data.referencia || [];
        const total = venta.length + compra.length + licit.length + ref.length;

        $('#loading').style.display = 'none';

        if (total === 0) {
            $('#noResults').style.display = 'block';
            return;
        }

        $('#countVenta').textContent = venta.length ? '(' + venta.length + ')' : '';
        $('#countCompra').textContent = compra.length ? '(' + compra.length + ')' : '';
        $('#countLicit').textContent = licit.length ? '(' + licit.length + ')' : '';
        $('#countRef').textContent = ref.length ? '(' + ref.length + ')' : '';

        $('#panel-venta').innerHTML = venta.length
            ? renderAlbaranes(venta, 'venta')
            : '<p class="empty-msg">Sin resultados de ventas.</p>';
        $('#panel-compra').innerHTML = compra.length
            ? renderAlbaranes(compra, 'compra')
            : '<p class="empty-msg">Sin resultados de compras.</p>';
        $('#panel-licitaciones').innerHTML = licit.length
            ? renderLicitaciones(licit)
            : '<p class="empty-msg">Sin resultados de licitaciones.</p>';
        $('#panel-referencia').innerHTML = ref.length
            ? renderReferencia(ref)
            : '<p class="empty-msg">Sin precios de referencia.</p>';

        $('#results').style.display = 'block';

        if (venta.length) switchTab('venta');
        else if (compra.length) switchTab('compra');
        else if (licit.length) switchTab('licitaciones');
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
