<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repositories/DisponibleRepository.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

/** @var array<string, mixed> $user */
$user     = $_SESSION['user'];
$role     = (string)($user['role'] ?? '');
$fullName = (string)($user['full_name'] ?? '');
$email    = (string)($user['email'] ?? '');

$actionError   = null;
$actionSuccess = null;

// ─── Helpers ──────────────────────────────────────────────────────────────────

$h = static fn ($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

function toDecimalOrNull(string $raw): ?float
{
    $raw = trim(str_replace(',', '.', $raw));
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    return (float)$raw;
}

function toIntOrNull(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }
    return (int)$raw;
}

function toDateOrNull(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
    if ($dt === false || $dt->format('Y-m-d') !== $raw) {
        return null;
    }
    return $raw;
}

/**
 * @return array<string, mixed>
 */
function buildPayloadFromPost(): array
{
    $str = static fn (string $k): ?string => (trim((string)($_POST[$k] ?? '')) !== '') ? trim((string)$_POST[$k]) : null;

    return [
        'foto'                       => $str('foto'),
        'foto1'                      => $str('foto1'),
        'campanya_precios_espec'     => isset($_POST['campanya_precios_espec']) ? 1 : 0,
        'producto_precio_espec'      => isset($_POST['producto_precio_espec']) ? 1 : 0,
        'codigo'                     => $str('codigo'),
        'codigo_rach'                => $str('codigo_rach'),
        'descripcion_rach'           => $str('descripcion_rach'),
        'ean'                        => $str('ean'),
        'id_articulo_agricultor'     => $str('id_articulo_agricultor'),
        'passaporte_fito'            => $str('passaporte_fito'),
        'floricode'                  => $str('floricode'),
        'nombre_floriday'            => $str('nombre_floriday'),
        'clasificacion'              => $str('clasificacion'),
        'calidad'                    => $str('calidad'),
        'descripcion'                => $str('descripcion'),
        'precio_coste_productor'     => toDecimalOrNull((string)($_POST['precio_coste_productor'] ?? '')),
        'descuento_productor'        => toDecimalOrNull((string)($_POST['descuento_productor'] ?? '')),
        'precio_coste_final'         => toDecimalOrNull((string)($_POST['precio_coste_final'] ?? '')),
        'tarifa_mayorista'           => toDecimalOrNull((string)($_POST['tarifa_mayorista'] ?? '')),
        'precio_x_unid'              => toDecimalOrNull((string)($_POST['precio_x_unid'] ?? '')),
        'precio_x_unid_diplad_m7'    => toDecimalOrNull((string)($_POST['precio_x_unid_diplad_m7'] ?? '')),
        'precio_x_unid_almeria'      => toDecimalOrNull((string)($_POST['precio_x_unid_almeria'] ?? '')),
        'precio_t5_directo'          => toDecimalOrNull((string)($_POST['precio_t5_directo'] ?? '')),
        'precio_t5_almeria'          => toDecimalOrNull((string)($_POST['precio_t5_almeria'] ?? '')),
        'precio_t10'                 => toDecimalOrNull((string)($_POST['precio_t10'] ?? '')),
        'precio_t15'                 => toDecimalOrNull((string)($_POST['precio_t15'] ?? '')),
        'precio_dipladen_t25'        => toDecimalOrNull((string)($_POST['precio_dipladen_t25'] ?? '')),
        'precio_t25'                 => toDecimalOrNull((string)($_POST['precio_t25'] ?? '')),
        'formato'                    => $str('formato'),
        'tamanyo_aprox'              => $str('tamanyo_aprox'),
        'observaciones'              => $str('observaciones'),
        'clasificacion_compra_facil' => $str('clasificacion_compra_facil'),
        'color'                      => $str('color'),
        'caracteristicas'            => $str('caracteristicas'),
        'cantidades_minimas'         => toIntOrNull((string)($_POST['cantidades_minimas'] ?? '')),
        'unids_x_piso'               => toIntOrNull((string)($_POST['unids_x_piso'] ?? '')),
        'unids_x_cc'                 => toIntOrNull((string)($_POST['unids_x_cc'] ?? '')),
        'porcentaje_ocupacion'       => toDecimalOrNull((string)($_POST['porcentaje_ocupacion'] ?? '')),
        'zona'                       => $str('zona'),
        'disponible'                 => isset($_POST['disponible']) ? 1 : 0,
        'pedido_x_unid'              => toIntOrNull((string)($_POST['pedido_x_unid'] ?? '')) ?? 0,
        'pedido_x_piso'              => toIntOrNull((string)($_POST['pedido_x_piso'] ?? '')) ?? 0,
        'pedido_x_cc'                => toIntOrNull((string)($_POST['pedido_x_cc'] ?? '')) ?? 0,
        'cod_productor'              => $str('cod_productor'),
        'cod_productor_opc2'         => $str('cod_productor_opc2'),
        'cod_productor_opc3'         => $str('cod_productor_opc3'),
        'nombre_productor'           => $str('nombre_productor'),
        'unids_disponibles'          => toIntOrNull((string)($_POST['unids_disponibles'] ?? '')),
        'fecha_sem_produccion'       => $str('fecha_sem_produccion'),
        'ultimo_cambio'              => toDateOrNull((string)($_POST['ultimo_cambio'] ?? '')),
        'pasado_a_freshportal'       => isset($_POST['pasado_a_freshportal']) ? 1 : 0,
        'total_unids_x_linea'        => toIntOrNull((string)($_POST['total_unids_x_linea'] ?? '')) ?? 0,
        'incremento_precio_x_unid'   => toDecimalOrNull((string)($_POST['incremento_precio_x_unid'] ?? '')),
    ];
}

// ─── Acciones POST ────────────────────────────────────────────────────────────

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $action = trim((string)($_POST['_action'] ?? ''));
    try {
        $repoAction = new DisponibleRepository();
        if ($action === 'create') {
            $repoAction->create(buildPayloadFromPost());
            $actionSuccess = 'Producto creado correctamente.';
        } elseif ($action === 'update') {
            $id = (int)($_POST['_id'] ?? 0);
            if ($id <= 0) {
                throw new \InvalidArgumentException('ID inv&aacute;lido.');
            }
            $repoAction->update($id, buildPayloadFromPost());
            $actionSuccess = 'Producto actualizado correctamente.';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['_id'] ?? 0);
            if ($id <= 0) {
                throw new \InvalidArgumentException('ID inv&aacute;lido.');
            }
            $repoAction->delete($id);
            $actionSuccess = 'Producto eliminado.';
        }
    } catch (\Throwable $e) {
        $actionError = $e->getMessage();
    }
}

// ─── Filtros y datos ──────────────────────────────────────────────────────────

$filterZona       = trim((string)($_GET['zona'] ?? ''));
$filterDisponible = (string)($_GET['disponible'] ?? '');
$filterBuscar     = trim((string)($_GET['buscar'] ?? ''));

$disponibleFilter = null;
if ($filterDisponible === '1') {
    $disponibleFilter = true;
} elseif ($filterDisponible === '0') {
    $disponibleFilter = false;
}

$rows      = [];
$zonas     = [];
$loadError = null;

try {
    $repo  = new DisponibleRepository();
    $rows  = $repo->listAll(
        $filterZona !== '' ? $filterZona : null,
        $disponibleFilter,
        $filterBuscar !== '' ? $filterBuscar : null
    );
    $zonas = $repo->getZonas();
} catch (\Throwable $e) {
    $loadError = $e->getMessage();
}

// ─── Helpers de formato ───────────────────────────────────────────────────────

function fmtEur(?float $v): string
{
    return $v !== null ? number_format($v, 2, ',', '.') . '&thinsp;&euro;' : '&mdash;';
}

function fmtN($v): string
{
    return $v !== null ? (string)(int)$v : '&mdash;';
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Disponible &mdash; Licitaciones</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: #e5e2dc;
            color: #10180e;
        }
        .layout { display: flex; min-height: 100vh; }
        .sidebar {
            width: 220px;
            flex-shrink: 0;
            background: radial-gradient(circle at top left, #1e293b, #020617);
            border-right: 1px solid #1f2937;
            padding: 16px 14px;
            display: flex;
            flex-direction: column;
        }
        .sidebar-logo { font-weight: 600; font-size: 1rem; margin-bottom: 18px; }
        .sidebar-nav { display: flex; flex-direction: column; gap: 4px; margin-bottom: auto; }
        .nav-link {
            display: block; padding: 8px 10px; border-radius: 8px;
            font-size: 0.9rem; color: #e5e7eb; text-decoration: none;
            border: 1px solid transparent;
        }
        .nav-link:hover { background-color: #111827; }
        .nav-link.active {
            background: linear-gradient(135deg, #22c55e, #14b8a6);
            color: #020617; font-weight: 600;
        }
        .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 20px;
            background: linear-gradient(135deg, #020617, #0f172a);
            border-bottom: 1px solid #1f2937;
        }
        header h1 { margin: 0; font-size: 1.1rem; font-weight: 600; }
        .user-info { font-size: 0.85rem; text-align: right; }
        .pill {
            display: inline-block; padding: 2px 8px; border-radius: 9999px;
            background-color: #1e293b; color: #a5b4fc; font-size: 0.75rem; margin-top: 2px;
        }
        main { max-width: 100%; margin: 0; padding: 20px; }
        .card {
            border-radius: 12px; border: 1px solid #1f2937;
            background: #0f172a;
            box-shadow: 0 18px 35px rgba(15,23,42,.35);
            padding: 18px; overflow: hidden;
        }
        .toolbar {
            display: flex; align-items: flex-end; gap: 10px;
            flex-wrap: wrap; margin-bottom: 14px;
        }
        .toolbar-title { margin: 0 auto 0 0; }
        .toolbar-title h2 { margin: 0; font-size: 1.15rem; }
        .toolbar-title p { margin: 3px 0 0; font-size: 0.85rem; color: #9ca3af; }
        .btn {
            border: 1px solid #334155; border-radius: 9999px;
            background: #1e293b; color: #e2e8f0;
            font-size: 0.82rem; font-weight: 700;
            padding: 7px 14px; cursor: pointer; text-decoration: none;
            white-space: nowrap;
        }
        .btn:hover { filter: brightness(1.08); }
        .btn-primary { background: #8e8b30; border-color: #8e8b30; color: #e5e2dc; }
        .btn-danger { background: #fff5f5; border-color: rgba(200,60,50,.45); color: #8d2b23; }
        .btn-danger:hover { background: #ffe9e8; }
        .btn-edit { background: #1e293b; border-color: #334155; color: #93c5fd; font-size: 0.76rem; padding: 4px 10px; }
        /* Filtros */
        .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 14px; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 0.75rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; }
        .filter-group input,
        .filter-group select {
            height: 34px; border-radius: 8px; border: 1px solid #334155;
            background: #020617; color: #e2e8f0;
            padding: 0 10px; font-size: 0.83rem;
        }
        .filter-group input { min-width: 200px; }
        /* Alertas */
        .alert {
            border-radius: 10px; border: 1px solid #334155; padding: 10px 14px;
            margin-bottom: 12px; font-size: 0.88rem;
        }
        .alert-success { border-color: rgba(22,163,74,.45); background: rgba(22,163,74,.12); color: #bbf7d0; }
        .alert-error   { border-color: rgba(200,60,50,.45); background: rgba(200,60,50,.10); color: #fecaca; }
        .alert-warn    { border-color: rgba(212,168,48,.45); background: rgba(212,168,48,.10); color: #fef08a; }
        /* Tabla */
        .table-wrap { overflow-x: auto; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; background: #0a1020; }
        thead tr { background: #8e8b30; }
        th {
            padding: 9px 10px; font-size: 0.72rem; text-transform: uppercase;
            letter-spacing: .04em; color: #10180e; font-weight: 700;
            white-space: nowrap; text-align: left;
        }
        td { padding: 8px 10px; border-bottom: 1px solid #1f2937; color: #cbd5e1; vertical-align: middle; white-space: nowrap; }
        tbody tr { cursor: pointer; }
        tbody tr:hover td { background: rgba(142,139,48,.12); }
        .td-right { text-align: right; font-variant-numeric: tabular-nums; }
        .td-center { text-align: center; }
        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 9999px;
            font-size: 0.72rem; font-weight: 700; border: 1px solid transparent;
        }
        .badge-si  { background: rgba(22,163,74,.18); color: #86efac; border-color: rgba(22,163,74,.3); }
        .badge-no  { background: rgba(100,116,139,.18); color: #94a3b8; border-color: rgba(100,116,139,.3); }
        .badge-zona { background: rgba(99,102,241,.18); color: #a5b4fc; border-color: rgba(99,102,241,.3); }
        .count-badge { font-size: 0.8rem; color: #94a3b8; align-self: flex-end; margin-left: auto; }
        /* Modal */
        .modal-overlay {
            position: fixed; inset: 0; z-index: 80;
            display: none; align-items: center; justify-content: center;
            padding: 16px; background: rgba(16,24,14,.46); backdrop-filter: blur(3px);
        }
        .modal-overlay.is-open { display: flex; }
        .modal {
            width: min(960px, 100%); max-height: 90vh; overflow-y: auto;
            border-radius: 14px; border: 1px solid var(--vz-marron2);
            background: var(--vz-blanco); box-shadow: 0 14px 30px rgba(16,24,14,.24);
            color: var(--vz-negro);
        }
        .modal-head {
            position: sticky; top: 0; z-index: 5;
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 20px; background: var(--vz-blanco);
            border-bottom: 1px solid rgba(133,114,94,.35);
        }
        .modal-head h3 { margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--vz-negro); }
        .modal-close {
            width: 32px; height: 32px; border-radius: 8px;
            border: 1px solid var(--vz-marron2); background: var(--vz-crema); color: var(--vz-marron1);
            font-size: 1.1rem; cursor: pointer; line-height: 1;
        }
        .modal-close:hover { background: #f0ebe3; }
        .modal-body { padding: 18px 20px; background: var(--vz-blanco); }
        .modal-foot {
            position: sticky; bottom: 0; z-index: 5;
            display: flex; align-items: center; justify-content: flex-end; gap: 10px;
            padding: 12px 20px; background: var(--vz-crema);
            border-top: 1px solid rgba(133,114,94,.35);
        }
        /* Secciones del modal */
        .section-title {
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: var(--vz-marron2);
            padding-bottom: 8px; border-bottom: 1px solid rgba(133,114,94,.3);
            margin: 0 0 12px;
        }
        .section + .section { margin-top: 20px; }
        .form-grid { display: grid; gap: 12px; }
        .fg-2 { grid-template-columns: repeat(2, 1fr); }
        .fg-3 { grid-template-columns: repeat(3, 1fr); }
        .fg-4 { grid-template-columns: repeat(4, 1fr); }
        .fg-col2 { grid-column: span 2; }
        .fg-col3 { grid-column: span 3; }
        .fg-col4 { grid-column: span 4; }
        .field { display: flex; flex-direction: column; gap: 5px; }
        .field label { font-size: 0.78rem; font-weight: 600; color: var(--vz-marron1); }
        .field label .auto-tag { font-size: 0.7rem; color: var(--vz-verde); font-weight: 400; }
        .field input,
        .field select,
        .field textarea {
            height: 34px; border-radius: 8px;
            border: 1px solid var(--vz-marron2);
            background: var(--vz-blanco); color: var(--vz-negro);
            padding: 0 10px; font-size: 0.84rem;
        }
        .field textarea { height: auto; min-height: 60px; padding: 8px 10px; resize: vertical; }
        .field input:focus, .field select:focus, .field textarea:focus {
            outline: none; border-color: var(--vz-verde);
            box-shadow: 0 0 0 1px rgba(142,139,48,.3);
        }
        .field input.auto-bg { background: rgba(142,139,48,.07); border-color: rgba(142,139,48,.4); }
        .field .checkbox-row { display: flex; align-items: center; gap: 8px; height: 34px; }
        .field .checkbox-row input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--vz-verde); cursor: pointer; }
        .field .checkbox-row label { font-size: 0.84rem; color: var(--vz-marron1); cursor: pointer; }
        .ocu-info {
            display: none; margin-top: 8px; padding: 8px 12px;
            border-radius: 8px; border: 1px solid rgba(133,114,94,.35);
            background: var(--vz-crema); color: var(--vz-marron1); font-size: 0.82rem;
        }
        @media (max-width: 900px) {
            .fg-4 { grid-template-columns: repeat(2, 1fr); }
            .fg-col4 { grid-column: span 2; }
        }
        @media (max-width: 640px) {
            .fg-3, .fg-4 { grid-template-columns: 1fr; }
            .fg-col2, .fg-col3, .fg-col4 { grid-column: span 1; }
        }
        @media (max-width: 768px) {
            .layout { flex-direction: column; }
            .sidebar { width: 100%; flex-direction: row; align-items: center; }
            .sidebar-nav { flex-direction: row; gap: 6px; }
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
            <a href="dashboard.php" class="nav-link">Dashboard</a>
            <a href="licitaciones.php" class="nav-link">Licitaciones</a>
            <a href="buscador.php" class="nav-link">Buscador hist&oacute;rico</a>
            <a href="analytics.php" class="nav-link">Anal&iacute;tica</a>
            <a href="disponible.php" class="nav-link active">Disponible</a>
            <a href="disponible-cliente.php" class="nav-link">Vista Cliente</a>
            <a href="pedidos-disponible.php" class="nav-link">Pedidos</a>
            <a href="usuarios.php" class="nav-link">Usuarios</a>
        </nav>
    </aside>

    <div class="main">
        <header>
            <h1>Disponible</h1>
            <div class="user-info">
                <div><?php echo $h($fullName !== '' ? $fullName : $email); ?></div>
                <div class="pill"><?php echo $h($role); ?></div>
                <div><a href="logout.php">Cerrar sesi&oacute;n</a></div>
            </div>
        </header>

        <main>

            <?php if ($actionSuccess !== null): ?>
            <div class="alert alert-success"><?php echo $h($actionSuccess); ?></div>
            <?php endif; ?>

            <?php if ($actionError !== null): ?>
            <div class="alert alert-error"><strong>Error:</strong> <?php echo $h($actionError); ?></div>
            <?php endif; ?>

            <?php if ($loadError !== null): ?>
            <div class="alert alert-warn">
                <strong>Error al cargar datos:</strong> <?php echo $h($loadError); ?><br>
                <small>Ejecuta primero: <code>sql/create_tbl_disponible.sql</code></small>
            </div>
            <?php endif; ?>

            <section class="card">

                <!-- Toolbar -->
                <div class="toolbar">
                    <div class="toolbar-title">
                        <h2>Cat&aacute;logo de productos disponibles</h2>
                        <p>Sustituye el Excel compartido. Haz clic en una fila para editar.</p>
                    </div>
                    <button class="btn btn-primary" onclick="openModal(null)">+ Nuevo producto</button>
                </div>

                <!-- Filtros -->
                <form method="GET" action="disponible.php" class="filters">
                    <div class="filter-group">
                        <label>Buscar</label>
                        <input type="text" name="buscar" value="<?php echo $h($filterBuscar); ?>" placeholder="Nombre, c&oacute;digo, productor&hellip;">
                    </div>
                    <div class="filter-group">
                        <label>Zona</label>
                        <select name="zona" style="min-width:120px">
                            <option value="">Todas</option>
                            <?php foreach ($zonas as $z): ?>
                            <option value="<?php echo $h($z); ?>" <?php echo $filterZona === $z ? 'selected' : ''; ?>>
                                <?php echo $h($z); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Disponible</label>
                        <select name="disponible" style="min-width:100px">
                            <option value="">Todos</option>
                            <option value="1" <?php echo $filterDisponible === '1' ? 'selected' : ''; ?>>S&iacute;</option>
                            <option value="0" <?php echo $filterDisponible === '0' ? 'selected' : ''; ?>>No</option>
                        </select>
                    </div>
                    <button type="submit" class="btn">Filtrar</button>
                    <?php if ($filterZona !== '' || $filterDisponible !== '' || $filterBuscar !== ''): ?>
                    <a href="disponible.php" class="btn">Limpiar</a>
                    <?php endif; ?>
                    <span class="count-badge"><?php echo count($rows); ?> producto<?php echo count($rows) !== 1 ? 's' : ''; ?></span>
                </form>

                <!-- Tabla -->
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Producto / Descripci&oacute;n</th>
                                <th>C&oacute;d. RACH</th>
                                <th>Formato</th>
                                <th class="td-right">P. Coste</th>
                                <th class="td-right">Dto %</th>
                                <th class="td-right">P. Coste Final</th>
                                <th class="td-right">P. x Unid</th>
                                <th class="td-right">T5% Dir.</th>
                                <th class="td-right">T10%</th>
                                <th class="td-right">T15%</th>
                                <th class="td-right">T25%</th>
                                <th class="td-center">Zona</th>
                                <th class="td-center">Disp.</th>
                                <th class="td-right">Ud. Prod.</th>
                                <th>Productor</th>
                                <th>Sem. Prod.</th>
                                <th class="td-right">Ud/Piso</th>
                                <th class="td-right">Ud/CC</th>
                                <th class="td-right">Pedido Ud</th>
                                <th class="td-right">Total Ud</th>
                                <th class="td-center">Freshportal</th>
                                <th class="td-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="22" style="text-align:center;padding:24px;color:#64748b;">
                                No hay productos<?php echo ($filterZona !== '' || $filterDisponible !== '' || $filterBuscar !== '') ? ' con los filtros seleccionados.' : '. Crea el primero con &ldquo;Nuevo producto&rdquo;.'; ?>
                            </td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row):
                            $rowId = (int)($row['id'] ?? 0);
                            $disp  = (bool)($row['disponible'] ?? false);
                            $fresh = (bool)($row['pasado_a_freshportal'] ?? false);
                        ?>
                            <tr onclick="openModal(<?php echo $rowId; ?>)">
                                <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;font-weight:600;color:#e2e8f0;">
                                    <?php echo $h($row['descripcion_rach'] ?: ($row['descripcion'] ?: '—')); ?>
                                </td>
                                <td style="color:#94a3b8;"><?php echo $h($row['codigo_rach'] ?? ''); ?></td>
                                <td><?php echo $h($row['formato'] ?? ''); ?></td>
                                <td class="td-right"><?php echo fmtEur(isset($row['precio_coste_productor']) ? (float)$row['precio_coste_productor'] : null); ?></td>
                                <td class="td-right" style="color:#94a3b8;"><?php echo $row['descuento_productor'] !== null ? $h($row['descuento_productor']) . '%' : '&mdash;'; ?></td>
                                <td class="td-right"><?php echo fmtEur(isset($row['precio_coste_final']) ? (float)$row['precio_coste_final'] : null); ?></td>
                                <td class="td-right" style="font-weight:700;color:#e2e8f0;"><?php echo fmtEur(isset($row['precio_x_unid']) ? (float)$row['precio_x_unid'] : null); ?></td>
                                <td class="td-right"><?php echo fmtEur(isset($row['precio_t5_directo']) ? (float)$row['precio_t5_directo'] : null); ?></td>
                                <td class="td-right"><?php echo fmtEur(isset($row['precio_t10']) ? (float)$row['precio_t10'] : null); ?></td>
                                <td class="td-right"><?php echo fmtEur(isset($row['precio_t15']) ? (float)$row['precio_t15'] : null); ?></td>
                                <td class="td-right"><?php echo fmtEur(isset($row['precio_t25']) ? (float)$row['precio_t25'] : null); ?></td>
                                <td class="td-center">
                                    <?php if (!empty($row['zona'])): ?>
                                    <span class="badge badge-zona"><?php echo $h($row['zona']); ?></span>
                                    <?php else: echo '&mdash;'; endif; ?>
                                </td>
                                <td class="td-center">
                                    <span class="badge <?php echo $disp ? 'badge-si' : 'badge-no'; ?>">
                                        <?php echo $disp ? 'S&Iacute;' : 'NO'; ?>
                                    </span>
                                </td>
                                <td class="td-right" style="font-weight:700;"><?php echo fmtN($row['unids_disponibles'] ?? null); ?></td>
                                <td><?php echo $h($row['nombre_productor'] ?? ''); ?></td>
                                <td style="color:#94a3b8;"><?php echo $h($row['fecha_sem_produccion'] ?? ''); ?></td>
                                <td class="td-right"><?php echo fmtN($row['unids_x_piso'] ?? null); ?></td>
                                <td class="td-right"><?php echo fmtN($row['unids_x_cc'] ?? null); ?></td>
                                <td class="td-right" style="font-weight:700;color:#7dd3fc;"><?php echo fmtN($row['pedido_x_unid'] ?? null); ?></td>
                                <td class="td-right" style="font-weight:700;"><?php echo fmtN($row['total_unids_x_linea'] ?? null); ?></td>
                                <td class="td-center">
                                    <span class="badge <?php echo $fresh ? 'badge-si' : 'badge-no'; ?>">
                                        <?php echo $fresh ? 'S&Iacute;' : 'NO'; ?>
                                    </span>
                                </td>
                                <td class="td-right" onclick="event.stopPropagation()">
                                    <button class="btn btn-edit" onclick="openModal(<?php echo $rowId; ?>)">Editar</button>
                                    <button class="btn btn-danger" style="margin-left:4px;"
                                        onclick="confirmDelete(<?php echo $rowId; ?>, <?php echo htmlspecialchars(json_encode($row['descripcion_rach'] ?: 'este producto'), ENT_QUOTES, 'UTF-8'); ?>)">
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </section>
        </main>
    </div><!-- /.main -->
</div><!-- /.layout -->

<!-- ═══ MODAL ════════════════════════════════════════════════════════════════ -->
<div id="modal-overlay" class="modal-overlay" onclick="closeModalOnOverlay(event)">
<div class="modal">

    <div class="modal-head">
        <h3 id="modal-title">Nuevo producto</h3>
        <button class="modal-close" onclick="closeModal()" title="Cerrar">&times;</button>
    </div>

    <form id="modal-form" method="POST" action="disponible.php">
        <input type="hidden" name="_action" id="f-action" value="create">
        <input type="hidden" name="_id"     id="f-id"     value="">

        <div class="modal-body">

            <!-- ── Identificación ── -->
            <div class="section">
                <p class="section-title">Identificaci&oacute;n</p>
                <div class="form-grid fg-4">
                    <div class="field fg-col2">
                        <label>Descripci&oacute;n RACH</label>
                        <input type="text" name="descripcion_rach" id="f-descripcion_rach" placeholder="Nombre bot&aacute;nico + formato">
                    </div>
                    <div class="field">
                        <label>C&oacute;digo</label>
                        <input type="text" name="codigo" id="f-codigo">
                    </div>
                    <div class="field">
                        <label>C&oacute;digo RACH</label>
                        <input type="text" name="codigo_rach" id="f-codigo_rach">
                    </div>
                    <div class="field">
                        <label>EAN</label>
                        <input type="text" name="ean" id="f-ean">
                    </div>
                    <div class="field">
                        <label>ID Art&iacute;culo Agricultor</label>
                        <input type="text" name="id_articulo_agricultor" id="f-id_articulo_agricultor">
                    </div>
                    <div class="field">
                        <label>Passaporte Fito</label>
                        <input type="text" name="passaporte_fito" id="f-passaporte_fito">
                    </div>
                    <div class="field">
                        <label>Floricode</label>
                        <input type="text" name="floricode" id="f-floricode">
                    </div>
                    <div class="field">
                        <label>Nombre Floriday</label>
                        <input type="text" name="nombre_floriday" id="f-nombre_floriday">
                    </div>
                    <div class="field">
                        <label>Clasificaci&oacute;n</label>
                        <select name="clasificacion" id="f-clasificacion">
                            <option value="">— Sin clasificar —</option>
                            <option value="GARDEN">GARDEN</option>
                            <option value="HOUSE PLANT">HOUSE PLANT</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Calidad</label>
                        <input type="text" name="calidad" id="f-calidad">
                    </div>
                    <div class="field">
                        <label>Formato</label>
                        <input type="text" name="formato" id="f-formato" placeholder="M-5L, M-10L&hellip;">
                    </div>
                    <div class="field">
                        <label>Tama&ntilde;o Aprox</label>
                        <input type="text" name="tamanyo_aprox" id="f-tamanyo_aprox">
                    </div>
                    <div class="field fg-col4">
                        <label>Descripci&oacute;n comercial</label>
                        <input type="text" name="descripcion" id="f-descripcion">
                    </div>
                    <div class="field">
                        <div class="checkbox-row">
                            <input type="checkbox" name="campanya_precios_espec" id="f-campanya_precios_espec">
                            <label for="f-campanya_precios_espec">Campa&ntilde;a / Precios Especiales</label>
                        </div>
                    </div>
                    <div class="field">
                        <div class="checkbox-row">
                            <input type="checkbox" name="producto_precio_espec" id="f-producto_precio_espec">
                            <label for="f-producto_precio_espec">Producto Precio Especial</label>
                        </div>
                    </div>
                    <div class="field">
                        <label>Foto URL</label>
                        <input type="text" name="foto" id="f-foto">
                    </div>
                    <div class="field">
                        <label>Foto 2 URL</label>
                        <input type="text" name="foto1" id="f-foto1">
                    </div>
                </div>
            </div>

            <!-- ── Precios ── -->
            <div class="section">
                <p class="section-title">Precios</p>
                <div class="form-grid fg-4">
                    <div class="field">
                        <label>P. Coste Productor (&euro;)</label>
                        <input type="text" name="precio_coste_productor" id="f-precio_coste_productor" inputmode="decimal" placeholder="0,00" oninput="calcPrecios()">
                    </div>
                    <div class="field">
                        <label>Descuento Productor (%)</label>
                        <input type="text" name="descuento_productor" id="f-descuento_productor" inputmode="decimal" placeholder="0" oninput="calcPrecios()">
                    </div>
                    <div class="field">
                        <label>P. Coste Final (&euro;) <span class="auto-tag">(auto)</span></label>
                        <input type="text" name="precio_coste_final" id="f-precio_coste_final" inputmode="decimal" placeholder="0,00" class="auto-bg">
                    </div>
                    <div class="field">
                        <label>Tarifa Mayorista (&euro;)</label>
                        <input type="text" name="tarifa_mayorista" id="f-tarifa_mayorista" inputmode="decimal" placeholder="0,00">
                    </div>
                    <div class="field">
                        <label>Precio x Unid (&euro;)</label>
                        <input type="text" name="precio_x_unid" id="f-precio_x_unid" inputmode="decimal" placeholder="0,00" style="font-weight:700;">
                    </div>
                    <div class="field">
                        <label>Precio Diplad M7 (&euro;)</label>
                        <input type="text" name="precio_x_unid_diplad_m7" id="f-precio_x_unid_diplad_m7" inputmode="decimal" placeholder="0,00">
                    </div>
                    <div class="field">
                        <label>Precio Salida Almer&iacute;a (&euro;)</label>
                        <input type="text" name="precio_x_unid_almeria" id="f-precio_x_unid_almeria" inputmode="decimal" placeholder="0,00">
                    </div>
                    <div class="field">
                        <label>T5% Carga Directa (&euro;)</label>
                        <input type="text" name="precio_t5_directo" id="f-precio_t5_directo" inputmode="decimal" placeholder="0,00">
                    </div>
                    <div class="field">
                        <label>T5% Salida Almer&iacute;a (&euro;)</label>
                        <input type="text" name="precio_t5_almeria" id="f-precio_t5_almeria" inputmode="decimal" placeholder="0,00">
                    </div>
                    <div class="field">
                        <label>Precio T10% (&euro;)</label>
                        <input type="text" name="precio_t10" id="f-precio_t10" inputmode="decimal" placeholder="0,00">
                    </div>
                    <div class="field">
                        <label>Precio T15% (&euro;)</label>
                        <input type="text" name="precio_t15" id="f-precio_t15" inputmode="decimal" placeholder="0,00">
                    </div>
                    <div class="field">
                        <label>Precio Dipladen T25% (&euro;)</label>
                        <input type="text" name="precio_dipladen_t25" id="f-precio_dipladen_t25" inputmode="decimal" placeholder="0,00">
                    </div>
                    <div class="field">
                        <label>Precio T25% (&euro;)</label>
                        <input type="text" name="precio_t25" id="f-precio_t25" inputmode="decimal" placeholder="0,00">
                    </div>
                    <div class="field">
                        <label>Incremento Precio x Unid (&euro;)</label>
                        <input type="text" name="incremento_precio_x_unid" id="f-incremento_precio_x_unid" inputmode="decimal" placeholder="0,00">
                    </div>
                </div>
            </div>

            <!-- ── Disponibilidad ── -->
            <div class="section">
                <p class="section-title">Disponibilidad y Log&iacute;stica</p>
                <div class="form-grid fg-4">
                    <div class="field">
                        <label>Zona</label>
                        <input type="text" name="zona" id="f-zona" placeholder="NORTE, SUR&hellip;">
                    </div>
                    <div class="field">
                        <label>Unids x Piso</label>
                        <input type="number" name="unids_x_piso" id="f-unids_x_piso" min="0" oninput="calcOcupacion()">
                    </div>
                    <div class="field">
                        <label>Unids x CC</label>
                        <input type="number" name="unids_x_cc" id="f-unids_x_cc" min="0" oninput="calcOcupacion()">
                    </div>
                    <div class="field">
                        <label>% Ocupaci&oacute;n</label>
                        <input type="text" name="porcentaje_ocupacion" id="f-porcentaje_ocupacion" inputmode="decimal" placeholder="100">
                    </div>
                    <div class="field">
                        <label>Cantidades M&iacute;nimas</label>
                        <input type="number" name="cantidades_minimas" id="f-cantidades_minimas" min="0">
                    </div>
                    <div class="field">
                        <label>Unids Disponibles Productor</label>
                        <input type="number" name="unids_disponibles" id="f-unids_disponibles" min="0" style="font-weight:700;">
                    </div>
                    <div class="field">
                        <label>Fecha / Sem. Producci&oacute;n</label>
                        <input type="text" name="fecha_sem_produccion" id="f-fecha_sem_produccion" placeholder="S.38/25">
                    </div>
                    <div class="field">
                        <label>&Uacute;ltimo Cambio</label>
                        <input type="date" name="ultimo_cambio" id="f-ultimo_cambio">
                    </div>
                    <div class="field">
                        <label>Clasif. Compra F&aacute;cil</label>
                        <input type="text" name="clasificacion_compra_facil" id="f-clasificacion_compra_facil">
                    </div>
                    <div class="field">
                        <label>Color</label>
                        <input type="text" name="color" id="f-color">
                    </div>
                    <div class="field">
                        <div class="checkbox-row">
                            <input type="checkbox" name="disponible" id="f-disponible">
                            <label for="f-disponible">Disponible para pedido</label>
                        </div>
                    </div>
                    <div class="field">
                        <div class="checkbox-row">
                            <input type="checkbox" name="pasado_a_freshportal" id="f-pasado_a_freshportal">
                            <label for="f-pasado_a_freshportal">Pasado a Freshportal</label>
                        </div>
                    </div>
                    <div class="field fg-col2">
                        <label>Caracter&iacute;sticas</label>
                        <textarea name="caracteristicas" id="f-caracteristicas" rows="2"></textarea>
                    </div>
                    <div class="field fg-col2">
                        <label>Observaciones</label>
                        <textarea name="observaciones" id="f-observaciones" rows="2"></textarea>
                    </div>
                </div>
            </div>

            <!-- ── Productor ── -->
            <div class="section">
                <p class="section-title">Productor</p>
                <div class="form-grid fg-4">
                    <div class="field fg-col2">
                        <label>Nombre Productor</label>
                        <input type="text" name="nombre_productor" id="f-nombre_productor">
                    </div>
                    <div class="field">
                        <label>C&oacute;d. Productor</label>
                        <input type="text" name="cod_productor" id="f-cod_productor">
                    </div>
                    <div class="field">
                        <label>C&oacute;d. Productor Opc 2</label>
                        <input type="text" name="cod_productor_opc2" id="f-cod_productor_opc2">
                    </div>
                    <div class="field">
                        <label>C&oacute;d. Productor Opc 3</label>
                        <input type="text" name="cod_productor_opc3" id="f-cod_productor_opc3">
                    </div>
                </div>
            </div>

            <!-- ── Pedido ── -->
            <div class="section">
                <p class="section-title">Pedido</p>
                <div class="form-grid fg-4">
                    <div class="field">
                        <label>Pedido x Unidad</label>
                        <input type="number" name="pedido_x_unid" id="f-pedido_x_unid" min="0" oninput="calcOcupacion()">
                    </div>
                    <div class="field">
                        <label>Pedido x Piso <span class="auto-tag">(auto)</span></label>
                        <input type="number" name="pedido_x_piso" id="f-pedido_x_piso" min="0" class="auto-bg">
                    </div>
                    <div class="field">
                        <label>Pedido x CC <span class="auto-tag">(auto)</span></label>
                        <input type="number" name="pedido_x_cc" id="f-pedido_x_cc" min="0" class="auto-bg">
                    </div>
                    <div class="field">
                        <label>Total Unids x L&iacute;nea</label>
                        <input type="number" name="total_unids_x_linea" id="f-total_unids_x_linea" min="0" style="font-weight:700;">
                    </div>
                </div>
                <div id="ocu-info" class="ocu-info">
                    <strong>Ocupaci&oacute;n calculada:</strong>
                    <span id="ocu-pisos">— pisos</span> &middot;
                    <span id="ocu-cc">— CC</span>
                </div>
            </div>

        </div><!-- /.modal-body -->

        <div class="modal-foot">
            <button type="button" class="btn" onclick="closeModal()">Cancelar</button>
            <button type="submit" class="btn btn-primary">Guardar</button>
        </div>
    </form>
</div><!-- /.modal -->
</div><!-- /.modal-overlay -->

<!-- Formulario oculto eliminar -->
<form id="delete-form" method="POST" action="disponible.php" style="display:none;">
    <input type="hidden" name="_action" value="delete">
    <input type="hidden" name="_id" id="delete-id" value="">
</form>

<!-- Datos JSON para el modal -->
<script>
const ROWS = <?php echo json_encode(array_column($rows, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;

const overlay = document.getElementById('modal-overlay');

function openModal(id) {
    document.getElementById('modal-form').reset();
    document.getElementById('ocu-info').style.display = 'none';

    if (id === null) {
        document.getElementById('modal-title').textContent = 'Nuevo producto';
        document.getElementById('f-action').value = 'create';
        document.getElementById('f-id').value = '';
    } else {
        document.getElementById('modal-title').textContent = 'Editar producto';
        document.getElementById('f-action').value = 'update';
        document.getElementById('f-id').value = id;

        const r = ROWS[id];
        if (!r) return;

        const t  = (k) => { const e = document.getElementById('f-' + k); if (e) e.value = r[k] ?? ''; };
        const ck = (k) => { const e = document.getElementById('f-' + k); if (e) e.checked = !!(r[k]); };
        const d  = (v) => v != null ? String(v).replace('.', ',') : '';

        ['descripcion_rach','codigo','codigo_rach','ean','id_articulo_agricultor',
         'passaporte_fito','floricode','nombre_floriday','calidad','descripcion',
         'formato','tamanyo_aprox','foto','foto1','zona','fecha_sem_produccion',
         'ultimo_cambio','clasificacion_compra_facil','color',
         'nombre_productor','cod_productor','cod_productor_opc2','cod_productor_opc3'].forEach(t);

        document.getElementById('f-clasificacion').value = r['clasificacion'] ?? '';

        ['precio_coste_productor','descuento_productor','precio_coste_final','tarifa_mayorista',
         'precio_x_unid','precio_x_unid_diplad_m7','precio_x_unid_almeria','precio_t5_directo',
         'precio_t5_almeria','precio_t10','precio_t15','precio_dipladen_t25','precio_t25',
         'incremento_precio_x_unid'].forEach(k => {
            const e = document.getElementById('f-' + k);
            if (e) e.value = d(r[k]);
        });

        ['unids_x_piso','unids_x_cc','cantidades_minimas','unids_disponibles',
         'pedido_x_unid','pedido_x_piso','pedido_x_cc','total_unids_x_linea',
         'porcentaje_ocupacion'].forEach(t);

        ck('campanya_precios_espec');
        ck('producto_precio_espec');
        ck('disponible');
        ck('pasado_a_freshportal');

        const obs = document.getElementById('f-observaciones');   if (obs)  obs.value  = r['observaciones']  ?? '';
        const car = document.getElementById('f-caracteristicas'); if (car)  car.value  = r['caracteristicas'] ?? '';

        calcOcupacion();
    }

    overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    overlay.classList.remove('is-open');
    document.body.style.overflow = '';
}

function closeModalOnOverlay(e) {
    if (e.target === overlay) closeModal();
}

function parseF(str) {
    if (!str) return null;
    const n = parseFloat(String(str).replace(',', '.'));
    return isNaN(n) ? null : n;
}

function calcPrecios() {
    const coste = parseF(document.getElementById('f-precio_coste_productor').value);
    const dto   = parseF(document.getElementById('f-descuento_productor').value);
    if (coste !== null && dto !== null) {
        document.getElementById('f-precio_coste_final').value =
            (coste * (1 - dto / 100)).toFixed(2).replace('.', ',');
    }
}

function calcOcupacion() {
    const unid  = parseInt(document.getElementById('f-pedido_x_unid').value, 10)  || 0;
    const xPiso = parseInt(document.getElementById('f-unids_x_piso').value, 10)   || 0;
    const xCc   = parseInt(document.getElementById('f-unids_x_cc').value, 10)     || 0;
    let show = false;
    if (xPiso > 0) {
        const p = Math.ceil(unid / xPiso);
        document.getElementById('f-pedido_x_piso').value = p;
        document.getElementById('ocu-pisos').textContent = p + ' pisos';
        show = true;
    }
    if (xCc > 0) {
        const c = Math.ceil(unid / xCc);
        document.getElementById('f-pedido_x_cc').value = c;
        document.getElementById('ocu-cc').textContent = (unid / xCc).toFixed(2) + ' CC';
        show = true;
    }
    document.getElementById('ocu-info').style.display = show && unid > 0 ? 'block' : 'none';
}

function confirmDelete(id, nombre) {
    if (!confirm('¿Eliminar "' + nombre + '"? Esta acción no se puede deshacer.')) return;
    document.getElementById('delete-id').value = id;
    document.getElementById('delete-form').submit();
}
</script>

</body>
</html>
