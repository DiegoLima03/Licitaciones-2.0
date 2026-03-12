<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$basePath = __DIR__ . '/../src/Repositories/';
require_once $basePath . 'BaseRepository.php';
require_once $basePath . 'DisponibleRepository.php';
require_once $basePath . 'ReservasDisponibleRepository.php';

$userId   = (string)($_SESSION['user']['id']        ?? '');
$userName = (string)($_SESSION['user']['full_name'] ?? $_SESSION['user']['email'] ?? 'Cliente');

$reservasRepo = new ReservasDisponibleRepository();
$dispRepo     = new DisponibleRepository();

// ── AJAX: guardar reserva ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_reserva') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $did   = (int)($_POST['disponible_id'] ?? 0);
        $unids = max(0, (int)($_POST['unids'] ?? 0));
        if ($did <= 0) {
            throw new \InvalidArgumentException('ID inválido');
        }
        if ($unids === 0) {
            $reservasRepo->deleteReserva($did, $userId);
        } else {
            $reservasRepo->upsertReserva($did, $userId, $unids);
        }
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Cargar configuración del cliente ────────────────────────────────────────
$config        = $reservasRepo->getClienteConfig($userId);
$zonas         = $config['zonas']          ?? 'TODAS';
$columnaPrecio = $config['columna_precio'] ?? 'precio_x_unid';
$puedeReservar = (bool)($config['puede_reservar'] ?? true);

$zonasArray = ($zonas === 'TODAS')
    ? []
    : array_filter(array_map('trim', explode(',', $zonas)));

// ── Cargar datos ─────────────────────────────────────────────────────────────
$productos = $dispRepo->listForCliente($zonasArray);

$reservasMap = [];
foreach ($reservasRepo->getReservasByUser($userId) as $r) {
    $reservasMap[(int)$r['disponible_id']] = (int)$r['unids'];
}

// ── Helpers ─────────────────────────────────────────────────────────────────
$precioLabels = [
    'precio_x_unid'           => 'Precio',
    'precio_x_unid_diplad_m7' => 'Precio DIPLAD',
    'precio_x_unid_almeria'   => 'Precio Almería',
    'precio_t5_directo'       => 'T5% Directo',
    'precio_t5_almeria'       => 'T5% Almería',
];
$precioLabel = $precioLabels[$columnaPrecio] ?? 'Precio';

function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmtPrecio($v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return number_format((float)$v, 2, ',', '.') . ' €';
}

// Agrupar categorías únicas para el filtro lateral
$categorias = [];
foreach ($productos as $p) {
    $cat = trim((string)($p['clasificacion_compra_facil'] ?? ''));
    if ($cat !== '' && !in_array($cat, $categorias, true)) {
        $categorias[] = $cat;
    }
}
sort($categorias);

?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Catálogo · Veraleza</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --g:    #5e6b1e;   /* verde principal */
            --g-dk: #48531a;   /* verde oscuro */
            --g-lt: #eef0e0;   /* verde muy claro */
            --g-md: #c8d080;   /* verde medio */
            --bg:   #f2f4ed;   /* fondo general */
            --w:    #ffffff;
            --ink:  #1c1f0e;
            --muted:#6b6e5a;
            --bdr:  #dde0d4;
            --sh:   rgba(20,25,10,.10);
            --red:  #c83c32;
            --red-lt:#fde8e8;
            --rad:  8px;
        }

        html { scroll-behavior: smooth; }
        body {
            background: var(--bg);
            color: var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }

        /* ─── TOPBAR ──────────────────────────────────────────────────────── */
        .topbar {
            position: sticky; top: 0; z-index: 200;
            background: var(--g); color: #fff;
            display: grid;
            grid-template-columns: auto 1fr auto auto;
            align-items: center;
            gap: 0;
            height: 54px;
            padding: 0 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.25);
        }
        .tb-brand {
            display: flex; align-items: center; gap: 10px;
            padding-right: 24px; border-right: 1px solid rgba(255,255,255,.2);
            margin-right: 20px;
        }
        .tb-logo {
            width: 32px; height: 32px; border-radius: 7px;
            background: rgba(255,255,255,.2);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 15px;
        }
        .tb-title { font-weight: 700; font-size: 15px; white-space: nowrap; }
        .tb-sub   { font-size: 11px; opacity: .7; }

        .tb-search {
            position: relative; flex: 1; max-width: 420px;
        }
        .tb-search input {
            width: 100%; padding: 8px 14px 8px 36px;
            border-radius: 20px; border: none;
            background: rgba(255,255,255,.15);
            color: #fff; font-size: 13px; outline: none;
            transition: background .2s;
        }
        .tb-search input::placeholder { color: rgba(255,255,255,.6); }
        .tb-search input:focus { background: rgba(255,255,255,.25); }
        .tb-search svg { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); opacity: .7; }

        .tb-right {
            display: flex; align-items: center; gap: 12px;
            padding-left: 24px; margin-left: 16px;
        }
        .tb-user {
            font-size: 13px; opacity: .8; white-space: nowrap;
        }
        .tb-logout {
            font-size: 12px; color: rgba(255,255,255,.8);
            text-decoration: none; padding: 5px 12px;
            border: 1px solid rgba(255,255,255,.3); border-radius: 20px;
            transition: all .15s; white-space: nowrap;
        }
        .tb-logout:hover { background: rgba(255,255,255,.15); color: #fff; }

        /* Carrito en topbar */
        .tb-cart {
            display: flex; align-items: center; gap: 10px;
            background: rgba(255,255,255,.15); border-radius: 22px;
            padding: 6px 16px 6px 12px; cursor: pointer;
            transition: background .15s; user-select: none;
            border: 1px solid rgba(255,255,255,.25);
        }
        .tb-cart:hover { background: rgba(255,255,255,.25); }
        .tb-cart-icon { font-size: 18px; }
        .tb-cart-count {
            font-size: 12px; font-weight: 700;
            background: #fff; color: var(--g-dk);
            border-radius: 20px; padding: 1px 7px;
            min-width: 22px; text-align: center;
        }
        .tb-cart-total { font-size: 14px; font-weight: 700; white-space: nowrap; }
        .tb-cart-cta {
            font-size: 12px; opacity: .8; white-space: nowrap;
        }

        /* ─── SUBBAR ──────────────────────────────────────────────────────── */
        .subbar {
            background: var(--w); border-bottom: 1px solid var(--bdr);
            padding: 8px 20px;
            display: flex; align-items: center; gap: 12px;
            flex-wrap: wrap;
        }
        .subbar-left { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; flex: 1; }
        .zone-chip {
            padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 600;
            border: 1.5px solid var(--bdr); cursor: pointer; background: var(--w);
            transition: all .12s; white-space: nowrap;
        }
        .zone-chip.active {
            background: var(--g); color: #fff; border-color: var(--g);
        }
        .zone-chip:hover:not(.active) { border-color: var(--g); color: var(--g); }

        .cat-select {
            padding: 5px 10px; border: 1.5px solid var(--bdr); border-radius: var(--rad);
            font-size: 12px; background: var(--w); outline: none; cursor: pointer;
            transition: border-color .12s;
        }
        .cat-select:focus { border-color: var(--g); }

        .subbar-right { display: flex; align-items: center; gap: 12px; }
        .result-count { font-size: 12px; color: var(--muted); }

        .view-toggle { display: flex; gap: 2px; }
        .view-btn {
            width: 30px; height: 30px; border: 1.5px solid var(--bdr);
            border-radius: 6px; background: var(--w); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            color: var(--muted); transition: all .12s;
        }
        .view-btn.active { background: var(--g); border-color: var(--g); color: #fff; }

        /* ─── LAYOUT ──────────────────────────────────────────────────────── */
        .layout {
            display: flex; align-items: flex-start;
            max-width: 1440px; margin: 0 auto; padding: 20px;
            gap: 20px;
        }
        .catalog { flex: 1; min-width: 0; }

        /* ─── LIST VIEW ───────────────────────────────────────────────────── */
        .product-list {
            background: var(--w);
            border: 1px solid var(--bdr); border-radius: var(--rad);
            overflow: hidden;
        }
        .list-thead {
            display: grid;
            grid-template-columns: 60px 1fr 90px 100px 130px 90px;
            gap: 0;
            background: #f5f6f0;
            border-bottom: 2px solid var(--bdr);
            padding: 0 16px;
        }
        .list-th {
            padding: 9px 8px;
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .05em; color: var(--muted);
        }
        .list-th.right { text-align: right; }
        .list-th.center { text-align: center; }

        .prow {
            display: grid;
            grid-template-columns: 60px 1fr 90px 100px 130px 90px;
            align-items: center; gap: 0;
            padding: 0 16px;
            border-bottom: 1px solid #f0f1ea;
            transition: background .1s;
            cursor: default;
        }
        .prow:last-child { border-bottom: none; }
        .prow:hover { background: #fafbf5; }
        .prow.in-cart { background: var(--g-lt); }
        .prow.hidden  { display: none; }

        .prow-thumb {
            padding: 8px 8px 8px 0;
        }
        .thumb-box {
            width: 48px; height: 48px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; flex-shrink: 0;
            background: linear-gradient(135deg, var(--g-lt), #dde2b8);
        }

        .prow-info { padding: 10px 8px; min-width: 0; }
        .prow-name {
            font-size: 13px; font-weight: 600; line-height: 1.3;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .prow-meta {
            font-size: 11px; color: var(--muted); margin-top: 2px;
            display: flex; gap: 8px; flex-wrap: wrap;
        }
        .prow-meta span { display: flex; align-items: center; gap: 3px; }
        .badge-fmt {
            display: inline-block; padding: 1px 7px; border-radius: 10px;
            font-size: 10px; font-weight: 700;
            background: var(--g-lt); color: var(--g-dk);
        }

        .prow-avail {
            padding: 10px 8px; text-align: right;
        }
        .avail-val { font-size: 13px; font-weight: 600; }
        .avail-sem { font-size: 10px; color: var(--muted); }
        .avail-none { color: #bbb; font-size: 12px; }

        .prow-price {
            padding: 10px 8px; text-align: right;
        }
        .price-val { font-size: 16px; font-weight: 700; color: var(--g-dk); }
        .price-null { font-size: 13px; color: #ccc; }
        .price-lbl  { font-size: 10px; color: var(--muted); }

        .prow-qty { padding: 8px; }
        .qty-row { display: flex; align-items: center; gap: 4px; }
        .qty-btn {
            width: 30px; height: 30px;
            border: 1.5px solid var(--bdr); border-radius: 6px;
            background: var(--w); cursor: pointer; font-size: 17px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; transition: all .1s; color: var(--ink); line-height: 1;
        }
        .qty-btn:hover { background: var(--g-lt); border-color: var(--g); color: var(--g-dk); }
        .qty-btn.minus:hover,
        .qty-btn.minus.act { background: var(--red-lt); border-color: var(--red); color: var(--red); }
        .qty-input {
            width: 46px; height: 30px; text-align: center;
            border: 1.5px solid var(--bdr); border-radius: 6px;
            font-size: 14px; font-weight: 600; outline: none;
            -moz-appearance: textfield; transition: border-color .1s;
        }
        .qty-input:focus { border-color: var(--g); }
        .qty-input::-webkit-inner-spin-button,
        .qty-input::-webkit-outer-spin-button { -webkit-appearance: none; }

        .prow-sub {
            padding: 10px 8px 10px 0; text-align: right;
        }
        .sub-val { font-size: 13px; font-weight: 700; color: var(--g-dk); }
        .sub-null { font-size: 12px; color: #ccc; }
        .sub-hint { font-size: 10px; color: var(--muted); }

        /* ─── GRID VIEW ───────────────────────────────────────────────────── */
        .product-grid-view {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 14px;
        }
        .pcard {
            background: var(--w); border: 1px solid var(--bdr);
            border-radius: 10px; overflow: hidden;
            display: flex; flex-direction: column;
            transition: box-shadow .2s, transform .15s;
        }
        .pcard:hover { box-shadow: 0 6px 20px var(--sh); transform: translateY(-2px); }
        .pcard.in-cart { border-color: var(--g); border-top: 3px solid var(--g); }
        .pcard.hidden  { display: none; }

        .pcard-img {
            height: 110px;
            background: linear-gradient(135deg, var(--g-lt), #d8dfaa);
            display: flex; align-items: center; justify-content: center;
            font-size: 44px;
        }
        .pcard-body { padding: 12px; flex: 1; display: flex; flex-direction: column; gap: 6px; }
        .pcard-name { font-size: 13px; font-weight: 600; line-height: 1.3;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .pcard-meta2 { font-size: 11px; color: var(--muted); }
        .pcard-price-row {
            margin-top: auto; padding-top: 8px; border-top: 1px solid var(--bdr);
            display: flex; justify-content: space-between; align-items: flex-end;
        }
        .pcard-price { font-size: 18px; font-weight: 700; color: var(--g-dk); }
        .pcard-qty { padding: 0 12px 12px; }

        /* ─── EMPTY ───────────────────────────────────────────────────────── */
        .empty-state {
            padding: 60px 20px; text-align: center; color: var(--muted);
            background: var(--w); border-radius: var(--rad);
        }
        .empty-icon { font-size: 52px; display: block; margin-bottom: 12px; }

        /* ─── CART PANEL ──────────────────────────────────────────────────── */
        .cart-panel {
            width: 310px; flex-shrink: 0;
            position: sticky; top: 74px;
            background: var(--w); border: 1px solid var(--bdr);
            border-radius: 10px;
            box-shadow: 0 4px 20px var(--sh);
            display: flex; flex-direction: column;
            max-height: calc(100vh - 94px);
        }
        .cp-head {
            padding: 14px 16px;
            border-bottom: 1px solid var(--bdr);
            display: flex; align-items: center; gap: 10px;
        }
        .cp-title { font-weight: 700; font-size: 14px; flex: 1; }
        .cp-badge {
            background: var(--g); color: #fff;
            border-radius: 20px; font-size: 11px; font-weight: 700;
            padding: 2px 9px; transition: transform .2s;
        }
        .cp-badge.bump { transform: scale(1.4); }

        .cp-body { flex: 1; overflow-y: auto; padding: 8px 0; }
        .cp-empty {
            padding: 28px 16px; text-align: center; color: var(--muted);
        }
        .cp-empty-icon { font-size: 36px; display: block; margin-bottom: 8px; }
        .cp-empty-txt  { font-size: 12px; line-height: 1.5; }

        .cp-item {
            display: flex; align-items: flex-start; gap: 8px;
            padding: 8px 16px; border-bottom: 1px solid #f0f1ea;
        }
        .cp-item:last-child { border-bottom: none; }
        .cp-item-dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--g-md); flex-shrink: 0; margin-top: 5px;
        }
        .cp-item-info { flex: 1; min-width: 0; }
        .cp-item-name {
            font-size: 12px; font-weight: 500; line-height: 1.3;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .cp-item-sub { font-size: 11px; color: var(--muted); }
        .cp-item-price { font-size: 12px; font-weight: 700; white-space: nowrap; }

        .cp-foot {
            padding: 14px 16px; border-top: 2px solid var(--bdr);
            background: #fafbf5; border-radius: 0 0 10px 10px;
        }
        .cp-total-row {
            display: flex; justify-content: space-between; align-items: baseline;
            margin-bottom: 12px;
        }
        .cp-total-lbl { font-size: 12px; color: var(--muted); font-weight: 600; }
        .cp-total-val { font-size: 22px; font-weight: 800; color: var(--g-dk); }

        .btn-enviar {
            width: 100%; padding: 13px;
            background: var(--g); color: #fff;
            border: none; border-radius: var(--rad);
            font-size: 14px; font-weight: 700; cursor: pointer;
            letter-spacing: .03em;
            transition: background .15s, transform .1s;
        }
        .btn-enviar:hover { background: var(--g-dk); transform: translateY(-1px); }
        .btn-enviar:active { transform: none; }
        .btn-enviar:disabled { background: #c0c0b0; cursor: not-allowed; transform: none; }
        .cp-note { font-size: 11px; color: var(--muted); text-align: center; margin-top: 8px; }

        /* Save indicator */
        .save-dot {
            width: 7px; height: 7px; border-radius: 50%; display: inline-block;
            background: transparent; transition: background .3s;
        }
        .save-dot.saving { background: #f59e0b; }
        .save-dot.saved  { background: #22c55e; }
        .save-dot.error  { background: var(--red); }

        /* ─── OVERLAY ─────────────────────────────────────────────────────── */
        .overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,.55); backdrop-filter: blur(4px);
            z-index: 999; display: none;
            align-items: center; justify-content: center;
        }
        .overlay.show { display: flex; }
        .overlay-box {
            background: var(--w); border-radius: 16px;
            padding: 40px 32px; max-width: 440px; width: 90%;
            text-align: center;
            box-shadow: 0 24px 60px rgba(0,0,0,.3);
            animation: pop .22s ease;
        }
        @keyframes pop { from { transform: scale(.88); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .ov-icon  { font-size: 60px; display: block; margin-bottom: 14px; }
        .ov-title { font-size: 22px; font-weight: 800; color: var(--g-dk); margin-bottom: 6px; }
        .ov-text  { font-size: 13px; color: var(--muted); line-height: 1.6; margin-bottom: 18px; }
        .ov-summary {
            background: var(--g-lt); border-radius: 8px;
            padding: 12px 16px; font-size: 12px; text-align: left;
            max-height: 180px; overflow-y: auto; margin-bottom: 18px;
        }
        .ov-row { display: flex; justify-content: space-between; padding: 3px 0; }
        .ov-row.total { border-top: 1px solid var(--g-md); margin-top: 6px; padding-top: 6px; font-weight: 700; }
        .btn-ok {
            background: var(--g); color: #fff; border: none;
            border-radius: var(--rad); padding: 11px 36px;
            font-size: 14px; font-weight: 700; cursor: pointer;
        }
        .btn-ok:hover { background: var(--g-dk); }
    </style>
</head>
<body>

<!-- ═══ TOPBAR ════════════════════════════════════════════════════════════════ -->
<header class="topbar">
    <div class="tb-brand">
        <div class="tb-logo">V</div>
        <div>
            <div class="tb-title">Veraleza</div>
            <div class="tb-sub">Catálogo Disponible</div>
        </div>
    </div>

    <div class="tb-search">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
        </svg>
        <input id="buscador" type="text" placeholder="Buscar producto, productor, formato…" autocomplete="off">
    </div>

    <div class="tb-right">
        <div class="tb-cart" onclick="scrollToCart()">
            <span class="tb-cart-icon">🛒</span>
            <span class="tb-cart-count" id="tb-count">0</span>
            <span class="tb-cart-total" id="tb-total">0,00 €</span>
            <span class="tb-cart-cta">→ Enviar</span>
        </div>
        <div class="tb-user">👤 <?= h($userName) ?></div>
        <a class="tb-logout" href="logout.php">Salir</a>
    </div>
</header>

<!-- ═══ SUBBAR ════════════════════════════════════════════════════════════════ -->
<div class="subbar">
    <div class="subbar-left">
        <!-- Zona chips -->
        <div id="zone-chips">
            <div class="zone-chip active" data-zona="">Todas las zonas</div>
            <?php foreach (array_unique(array_map('strtoupper', $zonasArray)) as $z): ?>
            <div class="zone-chip" data-zona="<?= h($z) ?>"><?= h($z) ?></div>
            <?php endforeach; ?>
        </div>

        <!-- Categoría -->
        <?php if (!empty($categorias)): ?>
        <select id="cat-filter" class="cat-select">
            <option value="">Todas las categorías</option>
            <?php foreach ($categorias as $cat): ?>
            <option value="<?= h(strtolower($cat)) ?>"><?= h($cat) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <!-- Tarifa y zona asignadas -->
        <span style="font-size:11px;color:var(--muted);margin-left:4px;">
            <?php if (!empty($zonasArray)): ?>
            📍 <?= h(implode(', ', $zonasArray)) ?> ·
            <?php endif; ?>
            🏷️ Tarifa: <strong><?= h($precioLabel) ?></strong>
        </span>
    </div>

    <div class="subbar-right">
        <span class="save-dot" id="save-dot"></span>
        <span class="result-count" id="result-count"><?= count($productos) ?> productos</span>
        <!-- Vista -->
        <div class="view-toggle">
            <button class="view-btn active" id="btn-list" title="Vista lista" onclick="setView('list')">
                <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor">
                    <rect x="0" y="1" width="16" height="2" rx="1"/>
                    <rect x="0" y="7" width="16" height="2" rx="1"/>
                    <rect x="0" y="13" width="16" height="2" rx="1"/>
                </svg>
            </button>
            <button class="view-btn" id="btn-grid" title="Vista cuadrícula" onclick="setView('grid')">
                <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor">
                    <rect x="0"  y="0"  width="7" height="7" rx="1"/>
                    <rect x="9"  y="0"  width="7" height="7" rx="1"/>
                    <rect x="0"  y="9"  width="7" height="7" rx="1"/>
                    <rect x="9"  y="9"  width="7" height="7" rx="1"/>
                </svg>
            </button>
        </div>
    </div>
</div>

<!-- ═══ MAIN LAYOUT ══════════════════════════════════════════════════════════ -->
<div class="layout">

    <!-- ── CATALOG ── -->
    <main class="catalog">

        <?php if (empty($productos)): ?>
        <div class="empty-state">
            <span class="empty-icon">🌱</span>
            <div>No hay productos disponibles en este momento.</div>
        </div>

        <?php else: ?>

        <!-- ── LIST VIEW ── -->
        <div id="view-list">
            <div class="product-list">
                <div class="list-thead">
                    <div class="list-th"></div>
                    <div class="list-th">Producto</div>
                    <div class="list-th right">Disponible</div>
                    <div class="list-th right"><?= h($precioLabel) ?></div>
                    <div class="list-th center">Cantidad</div>
                    <div class="list-th right">Subtotal</div>
                </div>

                <?php foreach ($productos as $p):
                    $pid        = (int)$p['id'];
                    $precio     = $p[$columnaPrecio] ?? null;
                    $nombre     = ($p['nombre_floriday'] !== '' && $p['nombre_floriday'] !== null)
                                ? $p['nombre_floriday']
                                : ($p['descripcion_rach'] ?? 'Sin nombre');
                    $minQty     = max(1, (int)($p['cantidades_minimas'] ?? 1));
                    $availUd    = $p['unids_disponibles'] ?? null;
                    $unidsPiso  = (int)($p['unids_x_piso'] ?? 0);
                    $currentQty = $reservasMap[$pid] ?? 0;
                    $inCart     = $currentQty > 0;
                    $precioFloat= $precio !== null && $precio !== '' ? (float)$precio : 0.0;
                    $subtotal   = $currentQty > 0 ? $currentQty * $precioFloat : 0.0;

                    $cat = strtolower((string)($p['clasificacion_compra_facil'] ?? ''));
                    $caract = strtolower((string)($p['caracteristicas'] ?? ''));
                    $emoji = '🌿';
                    if (str_contains($caract, 'vivaces'))    $emoji = '🌸';
                    elseif (str_contains($caract, 'arbol'))  $emoji = '🌳';
                    elseif (str_contains($caract, 'palm'))   $emoji = '🌴';

                    $search = mb_strtolower(implode(' ', [
                        $nombre,
                        $p['descripcion_rach'] ?? '',
                        $p['nombre_productor'] ?? '',
                        $p['formato'] ?? '',
                        $p['zona'] ?? '',
                        $p['clasificacion_compra_facil'] ?? '',
                    ]), 'UTF-8');
                ?>
                <div class="prow <?= $inCart ? 'in-cart' : '' ?>"
                     data-id="<?= $pid ?>"
                     data-nombre="<?= h($nombre) ?>"
                     data-precio="<?= h($precio ?? '0') ?>"
                     data-min="<?= $minQty ?>"
                     data-zona="<?= h(strtoupper((string)($p['zona'] ?? ''))) ?>"
                     data-cat="<?= h($cat) ?>"
                     data-search="<?= h($search) ?>">

                    <div class="prow-thumb">
                        <div class="thumb-box"><?= $emoji ?></div>
                    </div>

                    <div class="prow-info">
                        <div class="prow-name" title="<?= h($nombre) ?>"><?= h($nombre) ?></div>
                        <div class="prow-meta">
                            <?php if ($p['formato']): ?>
                            <span><span class="badge-fmt"><?= h($p['formato']) ?></span></span>
                            <?php endif; ?>
                            <?php if ($p['nombre_productor']): ?>
                            <span>
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                                </svg>
                                <?= h($p['nombre_productor']) ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($p['caracteristicas']): ?>
                            <span><?= h($p['caracteristicas']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="prow-avail">
                        <?php if ($availUd !== null): ?>
                            <div class="avail-val"><?= (int)$availUd ?> ud</div>
                        <?php else: ?>
                            <div class="avail-none">—</div>
                        <?php endif; ?>
                        <?php if ($p['fecha_sem_produccion']): ?>
                            <div class="avail-sem">Sem. <?= h($p['fecha_sem_produccion']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="prow-price">
                        <div class="price-lbl"><?= h($precioLabel) ?></div>
                        <?php if ($precio !== null && $precio !== ''): ?>
                            <div class="price-val"><?= fmtPrecio($precio) ?></div>
                        <?php else: ?>
                            <div class="price-null">—</div>
                        <?php endif; ?>
                        <?php if ($unidsPiso > 0): ?>
                            <div class="price-lbl">Piso: <?= $unidsPiso ?> ud</div>
                        <?php endif; ?>
                    </div>

                    <div class="prow-qty">
                        <?php if ($puedeReservar): ?>
                        <div class="qty-row">
                            <button class="qty-btn minus <?= $currentQty > 0 ? 'act' : '' ?>"
                                    onclick="updateQty(<?= $pid ?>, -1)">−</button>
                            <input  class="qty-input" type="number" min="0"
                                    id="qty-<?= $pid ?>"
                                    value="<?= $currentQty ?>"
                                    onchange="setQtyFromInput(<?= $pid ?>, this.value)">
                            <button class="qty-btn plus"
                                    onclick="updateQty(<?= $pid ?>, 1)">+</button>
                        </div>
                        <div style="font-size:10px;color:var(--muted);text-align:center;margin-top:3px;">
                            Mín: <?= $minQty ?> ud
                        </div>
                        <?php else: ?>
                        <span style="font-size:11px;color:var(--muted);">Solo consulta</span>
                        <?php endif; ?>
                    </div>

                    <div class="prow-sub">
                        <div class="sub-val" id="sub-<?= $pid ?>">
                            <?= $currentQty > 0 && $precioFloat > 0 ? fmtPrecio($subtotal) : '—' ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div><!-- /product-list -->
        </div><!-- /view-list -->

        <!-- ── GRID VIEW ── -->
        <div id="view-grid" style="display:none">
            <div class="product-grid-view">
                <?php foreach ($productos as $p):
                    $pid        = (int)$p['id'];
                    $precio     = $p[$columnaPrecio] ?? null;
                    $nombre     = ($p['nombre_floriday'] !== '' && $p['nombre_floriday'] !== null)
                                ? $p['nombre_floriday']
                                : ($p['descripcion_rach'] ?? 'Sin nombre');
                    $minQty     = max(1, (int)($p['cantidades_minimas'] ?? 1));
                    $availUd    = $p['unids_disponibles'] ?? null;
                    $currentQty = $reservasMap[$pid] ?? 0;
                    $caract     = strtolower((string)($p['caracteristicas'] ?? ''));
                    $emoji      = '🌿';
                    if (str_contains($caract, 'vivaces'))   $emoji = '🌸';
                    elseif (str_contains($caract, 'arbol')) $emoji = '🌳';
                    elseif (str_contains($caract, 'palm'))  $emoji = '🌴';
                ?>
                <div class="pcard <?= $currentQty > 0 ? 'in-cart' : '' ?>"
                     data-id-g="<?= $pid ?>">
                    <div class="pcard-img"><?= $emoji ?></div>
                    <div class="pcard-body">
                        <div class="pcard-name" title="<?= h($nombre) ?>"><?= h($nombre) ?></div>
                        <div class="pcard-meta2">
                            <?php if ($p['formato']): ?><span class="badge-fmt"><?= h($p['formato']) ?></span><?php endif; ?>
                            <?= h($p['nombre_productor'] ?? '') ?>
                        </div>
                        <?php if ($availUd !== null): ?>
                        <div class="pcard-meta2">Disponible: <strong><?= (int)$availUd ?> ud</strong></div>
                        <?php endif; ?>
                        <div class="pcard-price-row">
                            <div class="pcard-price"><?= fmtPrecio($precio) ?></div>
                            <?php if ($p['fecha_sem_produccion']): ?>
                            <span style="font-size:10px;color:var(--muted);">S. <?= h($p['fecha_sem_produccion']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($puedeReservar): ?>
                    <div class="pcard-qty">
                        <div class="qty-row">
                            <button class="qty-btn minus <?= $currentQty > 0 ? 'act' : '' ?>"
                                    onclick="updateQty(<?= $pid ?>, -1)">−</button>
                            <input  class="qty-input" type="number" min="0"
                                    id="qty2-<?= $pid ?>"
                                    value="<?= $currentQty ?>"
                                    onchange="setQtyFromInput(<?= $pid ?>, this.value)">
                            <button class="qty-btn plus"
                                    onclick="updateQty(<?= $pid ?>, 1)">+</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div><!-- /view-grid -->

        <?php endif; // empty check ?>

        <div class="empty-state" id="no-results" style="display:none;margin-top:16px;">
            <span class="empty-icon">🔍</span>
            <div>No hay productos que coincidan con la búsqueda.</div>
        </div>

    </main><!-- /catalog -->

    <!-- ── CART PANEL ── -->
    <aside class="cart-panel" id="cart-panel">
        <div class="cp-head">
            <span>🛒</span>
            <span class="cp-title">Mi Pedido</span>
            <span class="cp-badge" id="cp-badge">0</span>
        </div>
        <div class="cp-body" id="cp-body">
            <div class="cp-empty" id="cp-empty">
                <span class="cp-empty-icon">🌿</span>
                <div class="cp-empty-txt">Tu pedido está vacío.<br>Añade productos del catálogo.</div>
            </div>
            <div id="cp-items"></div>
        </div>
        <div class="cp-foot" id="cp-foot" style="display:none">
            <div class="cp-total-row">
                <span class="cp-total-lbl">Total estimado</span>
                <span class="cp-total-val" id="cp-total">0,00 €</span>
            </div>
            <button class="btn-enviar" onclick="submitPedido()">Enviar Pedido</button>
            <div class="cp-note">Guardado automáticamente</div>
        </div>
    </aside>

</div><!-- /layout -->

<!-- ─── OVERLAY CONFIRMACIÓN ─────────────────────────────────────────────────── -->
<div class="overlay" id="overlay">
    <div class="overlay-box">
        <span class="ov-icon">✅</span>
        <div class="ov-title">¡Pedido enviado!</div>
        <div class="ov-text">Tu solicitud ha llegado al equipo de Veraleza.<br>Nos pondremos en contacto para confirmarlo.</div>
        <div class="ov-summary" id="ov-summary"></div>
        <button class="btn-ok" onclick="document.getElementById('overlay').classList.remove('show')">
            Entendido
        </button>
    </div>
</div>

<!-- ═══ JAVASCRIPT ════════════════════════════════════════════════════════════ -->
<script>
'use strict';

// ── Estado ────────────────────────────────────────────────────────────────────
const cartData = {};

// Precargar reservas guardadas
<?php foreach ($reservasMap as $did => $unids):
    $prod = null;
    foreach ($productos as $p) { if ((int)$p['id'] === $did) { $prod = $p; break; } }
    if (!$prod) continue;
    $pr = (float)($prod[$columnaPrecio] ?? 0);
    $nm = $prod['nombre_floriday'] ?: $prod['descripcion_rach'] ?: '';
?>
cartData[<?= (int)$did ?>] = {
    unids:  <?= (int)$unids ?>,
    nombre: <?= json_encode($nm) ?>,
    precio: <?= $pr ?>,
    min:    <?= max(1, (int)($prod['cantidades_minimas'] ?? 1)) ?>
};
<?php endforeach; ?>

const debounceTimers = {};
const $saveDot = document.getElementById('save-dot');
let currentView = 'list';

// ── Cantidades ────────────────────────────────────────────────────────────────
function updateQty(id, delta) {
    const input  = document.getElementById('qty-' + id) || document.getElementById('qty2-' + id);
    const minQty = getMin(id);
    let val = parseInt(input.value) || 0;
    val = delta > 0 ? (val === 0 ? minQty : val + 1) : (val <= minQty ? 0 : val - 1);
    input.value = val;
    syncOtherInput(id, val);
    applyQty(id, val, minQty);
}

function setQtyFromInput(id, rawVal) {
    const minQty = getMin(id);
    let val = parseInt(rawVal) || 0;
    if (val < 0) val = 0;
    if (val > 0 && val < minQty) val = minQty;
    document.getElementById('qty-' + id)  && (document.getElementById('qty-' + id).value = val);
    document.getElementById('qty2-' + id) && (document.getElementById('qty2-' + id).value = val);
    applyQty(id, val, minQty);
}

function getMin(id) {
    const row = document.querySelector('.prow[data-id="' + id + '"]');
    if (row) return parseInt(row.dataset.min) || 1;
    return 1;
}

function syncOtherInput(id, val) {
    const a = document.getElementById('qty-' + id);
    const b = document.getElementById('qty2-' + id);
    if (a && parseInt(a.value) !== val) a.value = val;
    if (b && parseInt(b.value) !== val) b.value = val;
}

function applyQty(id, val, minQty) {
    const row   = document.querySelector('.prow[data-id="' + id + '"]');
    const card  = document.querySelector('.pcard[data-id-g="' + id + '"]');
    const nombre = row ? row.dataset.nombre : (card ? card.querySelector('.pcard-name').textContent.trim() : '');
    const precio = row ? parseFloat(row.dataset.precio) || 0 : 0;

    if (val > 0) {
        row  && row.classList.add('in-cart');
        card && card.classList.add('in-cart');
        row  && row.querySelector('.qty-btn.minus') && row.querySelector('.qty-btn.minus').classList.add('act');
        card && card.querySelector('.qty-btn.minus') && card.querySelector('.qty-btn.minus').classList.add('act');
        cartData[id] = { unids: val, nombre, precio, min: minQty };
    } else {
        row  && row.classList.remove('in-cart');
        card && card.classList.remove('in-cart');
        row  && row.querySelector('.qty-btn.minus') && row.querySelector('.qty-btn.minus').classList.remove('act');
        card && card.querySelector('.qty-btn.minus') && card.querySelector('.qty-btn.minus').classList.remove('act');
        delete cartData[id];
    }

    // Actualizar subtotal en fila lista
    const subEl = document.getElementById('sub-' + id);
    if (subEl) {
        subEl.textContent = val > 0 && precio > 0 ? fmtN(val * precio) + ' €' : '—';
    }

    renderCart();
    scheduleSave(id, val);
}

// ── Guardado automático ───────────────────────────────────────────────────────
function scheduleSave(id, unids) {
    setSaveDot('saving');
    clearTimeout(debounceTimers[id]);
    debounceTimers[id] = setTimeout(() => saveReserva(id, unids), 900);
}

function saveReserva(id, unids) {
    const fd = new FormData();
    fd.append('action', 'save_reserva');
    fd.append('disponible_id', id);
    fd.append('unids', unids);
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => setSaveDot(d.ok ? 'saved' : 'error'))
        .catch(() => setSaveDot('error'));
}

function setSaveDot(s) {
    $saveDot.className = 'save-dot ' + s;
    if (s === 'saved') setTimeout(() => { $saveDot.className = 'save-dot'; }, 2500);
}

// ── Renderizar carrito ────────────────────────────────────────────────────────
function renderCart() {
    const ids   = Object.keys(cartData);
    const badge = document.getElementById('cp-badge');
    const tbCnt = document.getElementById('tb-count');
    const tbTot = document.getElementById('tb-total');
    const empty = document.getElementById('cp-empty');
    const items = document.getElementById('cp-items');
    const foot  = document.getElementById('cp-foot');
    const total = document.getElementById('cp-total');

    badge.textContent = ids.length;
    badge.classList.add('bump');
    setTimeout(() => badge.classList.remove('bump'), 220);

    tbCnt.textContent = ids.length;

    if (ids.length === 0) {
        empty.style.display = '';
        items.innerHTML = '';
        foot.style.display = 'none';
        tbTot.textContent = '0,00 €';
        return;
    }

    empty.style.display = 'none';
    foot.style.display = '';

    let sum = 0, html = '';
    ids.forEach(id => {
        const it = cartData[id];
        const sub = it.unids * it.precio;
        sum += sub;
        html += `<div class="cp-item">
            <div class="cp-item-dot"></div>
            <div class="cp-item-info">
                <div class="cp-item-name">${escH(it.nombre)}</div>
                <div class="cp-item-sub">${it.unids} ud × ${fmtN(it.precio)} €</div>
            </div>
            <div class="cp-item-price">${fmtN(sub)} €</div>
        </div>`;
    });

    items.innerHTML = html;
    const fmtd = fmtN(sum) + ' €';
    total.textContent   = fmtd;
    tbTot.textContent   = fmtd;
}

// ── Enviar pedido ─────────────────────────────────────────────────────────────
function submitPedido() {
    const ids = Object.keys(cartData);
    if (!ids.length) return;
    ids.forEach(id => { clearTimeout(debounceTimers[id]); saveReserva(id, cartData[id].unids); });

    let sum = 0, html = '';
    ids.forEach(id => {
        const it = cartData[id], sub = it.unids * it.precio;
        sum += sub;
        html += `<div class="ov-row"><span>${escH(it.nombre)} × ${it.unids} ud</span><strong>${fmtN(sub)} €</strong></div>`;
    });
    html += `<div class="ov-row total"><span>Total estimado</span><strong>${fmtN(sum)} €</strong></div>`;
    document.getElementById('ov-summary').innerHTML = html;
    document.getElementById('overlay').classList.add('show');
}

function scrollToCart() {
    document.getElementById('cart-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Vista lista / cuadrícula ──────────────────────────────────────────────────
function setView(v) {
    currentView = v;
    document.getElementById('view-list').style.display = v === 'list' ? '' : 'none';
    document.getElementById('view-grid').style.display = v === 'grid' ? '' : 'none';
    document.getElementById('btn-list').classList.toggle('active', v === 'list');
    document.getElementById('btn-grid').classList.toggle('active', v === 'grid');
}

// ── Filtros ───────────────────────────────────────────────────────────────────
let activeZona = '', activeCat = '';

document.getElementById('buscador').addEventListener('input', applyFilters);

document.getElementById('zone-chips').addEventListener('click', function(e) {
    const chip = e.target.closest('.zone-chip');
    if (!chip) return;
    document.querySelectorAll('.zone-chip').forEach(c => c.classList.remove('active'));
    chip.classList.add('active');
    activeZona = chip.dataset.zona;
    applyFilters();
});

const catSel = document.getElementById('cat-filter');
if (catSel) catSel.addEventListener('change', function() { activeCat = this.value; applyFilters(); });

function applyFilters() {
    const q = document.getElementById('buscador').value.toLowerCase().trim();
    let count = 0;

    document.querySelectorAll('.prow').forEach(row => {
        const ok = matchRow(row, q);
        row.classList.toggle('hidden', !ok);
        if (ok) count++;
    });
    document.querySelectorAll('.pcard').forEach(card => {
        const id = card.dataset.idG;
        const row = document.querySelector('.prow[data-id="' + id + '"]');
        card.classList.toggle('hidden', row ? row.classList.contains('hidden') : false);
    });

    document.getElementById('result-count').textContent = count + ' productos';
    const noRes = document.getElementById('no-results');
    noRes.style.display = count === 0 && <?= count($productos) ?> > 0 ? '' : 'none';
}

function matchRow(row, q) {
    if (q && !row.dataset.search.includes(q)) return false;
    if (activeZona && row.dataset.zona !== activeZona) return false;
    if (activeCat  && row.dataset.cat !== activeCat)   return false;
    return true;
}

// ── Utils ─────────────────────────────────────────────────────────────────────
function fmtN(v) { return parseFloat(v).toFixed(2).replace('.', ','); }
function escH(s) { const d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }

// ── Init ──────────────────────────────────────────────────────────────────────
renderCart();
</script>
</body>
</html>
