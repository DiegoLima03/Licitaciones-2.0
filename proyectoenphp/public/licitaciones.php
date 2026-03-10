<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repositories/TendersRepository.php';
require_once __DIR__ . '/../src/Repositories/CatalogsRepository.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

/** @var array<string, mixed> $user */
$user = $_SESSION['user'];
$email = (string)($user['email'] ?? '');
$fullName = (string)($user['full_name'] ?? '');
$role = (string)($user['role'] ?? '');
$organizationId = (string)($user['organization_id'] ?? '');

$createError = null;
$filterEstadoRaw = trim((string)($_GET['estado'] ?? ''));
$filterPaisRaw = trim((string)($_GET['pais'] ?? ''));

/**
 * Normaliza un país para comparaciones, ignorando mayúsculas, tildes y n/ñ.
 */
function normalizeCountryKey(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $normalized = mb_strtolower($value, 'UTF-8');
    $normalized = strtr($normalized, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
        'ç' => 'c',
    ]);

    $normalized = preg_replace('/\s+/u', ' ', $normalized);
    return trim((string)$normalized);
}

/**
 * Devuelve la etiqueta canónica para evitar variantes como "Espana" / "España".
 */
function canonicalCountryLabel(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $key = normalizeCountryKey($value);
    if ($key === 'espana') {
        return 'España';
    }

    return $value;
}

$filterEstadoId = null;
if ($filterEstadoRaw !== '' && ctype_digit($filterEstadoRaw)) {
    $estadoIdParsed = (int)$filterEstadoRaw;
    if ($estadoIdParsed > 0) {
        $filterEstadoId = $estadoIdParsed;
    }
}

$filterPais = $filterPaisRaw !== '' ? canonicalCountryLabel($filterPaisRaw) : null;
$filterPaisKey = $filterPais !== null ? normalizeCountryKey($filterPais) : null;

// Si viene un POST desde el formulario del modal, crear la licitacion en la BD.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $repoPost = new TendersRepository($organizationId);

        $nombre = trim((string)($_POST['nombre'] ?? ''));
        if ($nombre === '') {
            throw new \InvalidArgumentException('El nombre del proyecto es obligatorio.');
        }

        $pais = canonicalCountryLabel((string)($_POST['pais'] ?? ''));
        $numeroExpediente = (string)($_POST['numero_expediente'] ?? '');
        $enlaceGober = (string)($_POST['enlace_gober'] ?? '');
        $enlaceSharepoint = (string)($_POST['enlace_sharepoint'] ?? '');
        $presMaximoRaw = (string)($_POST['pres_maximo'] ?? '');
        $presMaximo = $presMaximoRaw !== '' ? (float)$presMaximoRaw : 0.0;

        $fechaPresentacion = (string)($_POST['fecha_presentacion'] ?? '');
        $fechaAdjudicacion = (string)($_POST['fecha_adjudicacion'] ?? '');
        $fechaFinalizacion = (string)($_POST['fecha_finalizacion'] ?? '');

        $tipoProcedimiento = (string)($_POST['tipo_procedimiento'] ?? 'ORDINARIO');
        $idTipo = (string)($_POST['id_tipolicitacion'] ?? '');
        $idPadre = (string)($_POST['id_licitacion_padre'] ?? '');
        $descripcion = (string)($_POST['descripcion'] ?? '');
        $crearLotes = (string)($_POST['crear_lotes'] ?? '0');
        $numLotesRaw = (string)($_POST['num_lotes'] ?? '');

        $lotesConfigJson = null;
        if ($crearLotes === '1') {
            $numLotes = (int)$numLotesRaw;
            if ($numLotes < 2) {
                throw new \InvalidArgumentException('Si activas lotes, debes indicar al menos 2.');
            }

            $lotesConfig = [];
            for ($i = 1; $i <= $numLotes; $i++) {
                $lotesConfig[] = [
                    'nombre' => 'Lote ' . $i,
                    'ganado' => true,
                ];
            }
            $lotesConfigJson = json_encode($lotesConfig, JSON_UNESCAPED_UNICODE);
        }

        $row = [
            'nombre' => $nombre,
            'pais' => $pais !== '' ? $pais : null,
            'numero_expediente' => $numeroExpediente !== '' ? $numeroExpediente : null,
            'enlace_gober' => $enlaceGober !== '' ? $enlaceGober : null,
            'enlace_sharepoint' => $enlaceSharepoint !== '' ? $enlaceSharepoint : null,
            'pres_maximo' => $presMaximo,
            'fecha_presentacion' => $fechaPresentacion !== '' ? $fechaPresentacion : null,
            'fecha_adjudicacion' => $fechaAdjudicacion !== '' ? $fechaAdjudicacion : null,
            'fecha_finalizacion' => $fechaFinalizacion !== '' ? $fechaFinalizacion : null,
            'tipo_procedimiento' => $tipoProcedimiento !== '' ? $tipoProcedimiento : 'ORDINARIO',
            'id_tipolicitacion' => $idTipo !== '' ? (int)$idTipo : null,
            'id_licitacion_padre' => $idPadre !== '' ? (int)$idPadre : null,
            // Estado inicial igual que en el proyecto anterior: "En analisis" (id_estado = 3)
            'id_estado' => 3,
            'descripcion' => $descripcion !== '' ? $descripcion : null,
            'lotes_config' => $lotesConfigJson,
        ];

        $repoPost->create($row);

        header('Location: licitaciones.php');
        exit;
    } catch (\Throwable $e) {
        $createError = $e->getMessage();
    }
}

$tipos = [];
$parentTenders = [];
$estados = [];
$catalogError = '';
/** @var array<int, string> id_estado -> nombre_estado */
$estadoNombreById = [];
/** @var array<int, array<string, mixed>> */
$licitacionesBase = [];
/** @var array<int, string> */
$paisesDisponibles = [];

try {
    $repo = new TendersRepository($organizationId);
    $licitacionesBase = $repo->listTenders();
    /** @var array<int, array<string, mixed>> $licitaciones */
    $licitaciones = $repo->listTenders($filterEstadoId, null, null);
    if ($filterPaisKey !== null && $filterPaisKey !== '') {
        $licitaciones = array_values(array_filter(
            $licitaciones,
            static function (array $row) use ($filterPaisKey): bool {
                $rowPais = trim((string)($row['pais'] ?? ''));
                if ($rowPais === '') {
                    return false;
                }
                return normalizeCountryKey($rowPais) === $filterPaisKey;
            }
        ));
    }
    $parentTenders = $repo->getParentTenders();
} catch (\Throwable $e) {
    $licitacionesBase = [];
    $licitaciones = [];
    $loadError = $e->getMessage();
}

try {
    $catalogs = new CatalogsRepository($organizationId);
    $tipos = $catalogs->getTipos();
    $estados = $catalogs->getEstados();
    foreach ($estados as $e) {
        $id = (int)($e['id_estado'] ?? 0);
        $nombre = (string)($e['nombre_estado'] ?? '');
        if ($id !== 0) {
            $estadoNombreById[$id] = $nombre;
        }
    }
} catch (\Throwable $e) {
    $tipos = [];
    $catalogError = $e->getMessage();
    $currentDb = null;
    try {
        $currentDb = Database::getConnection()->query('SELECT DATABASE()')->fetchColumn();
    } catch (\Throwable $e2) {
        $currentDb = null;
    }
}

// Fallback nombres estado (igual que en el frontend anterior)
$estadoNombreById += [
    2 => 'Descartada',
    3 => 'En analisis',
    4 => 'Presentada',
    5 => 'Adjudicada',
    6 => 'No adjudicada',
    7 => 'Terminada',
];

if ($estados === []) {
    foreach ($estadoNombreById as $estadoIdFallback => $estadoNombreFallback) {
        $estados[] = [
            'id_estado' => $estadoIdFallback,
            'nombre_estado' => $estadoNombreFallback,
        ];
    }
}

// Opciones de pais para filtro (a partir del listado base sin filtros).
$paisesByKey = [];
foreach ($licitacionesBase as $licBase) {
    $paisRaw = trim((string)($licBase['pais'] ?? ''));
    if ($paisRaw === '') {
        continue;
    }
    $key = normalizeCountryKey($paisRaw);
    $label = canonicalCountryLabel($paisRaw);
    if ($key === '') {
        continue;
    }
    if (!isset($paisesByKey[$key])) {
        $paisesByKey[$key] = $label;
    }
}
if ($filterPaisKey !== null && $filterPaisKey !== '') {
    if (!isset($paisesByKey[$filterPaisKey])) {
        $paisesByKey[$filterPaisKey] = $filterPais;
    }
}
natcasesort($paisesByKey);
$paisesDisponibles = array_values($paisesByKey);

// Mapa id_licitacion -> nombre para "Cuelga de" (licitaciones raiz = sin padre)
$parentNameById = [];
foreach ($licitacionesBase as $lic) {
    $padre = $lic['id_licitacion_padre'] ?? null;
    if ($padre === null || $padre === '' || (is_string($padre) && trim($padre) === '')) {
        $id = (int)($lic['id_licitacion'] ?? 0);
        $nombre = (string)($lic['nombre'] ?? '');
        if ($id !== 0) {
            $parentNameById[$id] = $nombre !== '' ? $nombre : '#' . $id;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mis licitaciones</title>
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
        .sidebar-logo span {
            color: #38bdf8;
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
        .sidebar-footer {
            margin-top: 24px;
            font-size: 0.75rem;
            color: #6b7280;
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
            width: 100%;
            max-width: none;
            margin: 24px 0;
            padding: 0 clamp(16px, 2.4vw, 36px) 32px;
        }
        .card {
            background-color: #020617;
            border-radius: 12px;
            padding: 18px 18px 20px;
            box-shadow: 0 18px 35px rgba(15, 23, 42, 0.65);
            border: 1px solid #1f2937;
        }
        .card-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 6px;
        }
        .card-header-row h2 {
            margin: 0;
        }
        .btn-primary {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 9999px;
            border: none;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            background: linear-gradient(135deg, #10b981, #0ea5e9);
            color: #020617;
            cursor: pointer;
        }
        .btn-primary:hover {
            filter: brightness(1.05);
        }
        .card h2 {
            margin: 0 0 8px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 0.85rem;
        }
        thead {
            background-color: #020617;
        }
        th, td {
            padding: 8px 10px;
            border-bottom: 1px solid #1f2937;
            text-align: left;
            white-space: nowrap;
        }
        th {
            font-weight: 600;
            color: #9ca3af;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        tbody tr:hover {
            background-color: #020617;
        }
        .cell-nombre {
            max-width: 260px;
            white-space: normal;
        }
        .badge-estado {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 0.75rem;
            background-color: #111827;
            color: #e5e7eb;
        }
        .badge-estado.estado-5 {
            background-color: #064e3b;
            color: #bbf7d0;
        }
        .link-external {
            color: #38bdf8;
            text-decoration: none;
        }
        .link-external:hover {
            text-decoration: underline;
        }
        .link-row {
            color: #e5e7eb;
            text-decoration: none;
        }
        .link-row:hover {
            text-decoration: underline;
        }
        .text-muted {
            color: #6b7280;
        }
        .cuelga-de {
            margin-top: 2px;
            font-size: 0.75rem;
            color: #9ca3af;
        }
        .cuelga-de .link-row {
            color: #a5b4fc;
        }
        @keyframes urgent-row {
            0%, 100% {
                background-color: rgba(220, 38, 38, 0.06);
            }
            50% {
                background-color: rgba(220, 38, 38, 0.25);
            }
        }
        @keyframes urgent-pill {
            0%, 100% {
                background-color: rgba(220, 38, 38, 0.2);
                border-color: rgba(220, 38, 38, 0.4);
                color: #fecaca;
            }
            50% {
                background-color: rgba(220, 38, 38, 0.5);
                border-color: rgba(220, 38, 38, 0.8);
                color: #ffffff;
            }
        }
        .fecha-urgente {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 6px;
            border-radius: 6px;
            background: rgba(220, 38, 38, 0.2);
            border: 1px solid rgba(220, 38, 38, 0.4);
            color: #fecaca;
            font-size: 0.8rem;
            animation: urgent-pill 1.8s ease-in-out infinite;
        }
        tr.row-urgent {
            animation: urgent-row 1.8s ease-in-out infinite;
        }
        .badge-estado.estado-3 {
            background-color: #422006;
            color: #fcd34d;
        }
        .badge-estado.estado-descartada {
            background-color: #450a0a;
            color: #fecaca;
        }
        /* Modal popup */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 100;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-overlay.is-open {
            display: flex;
        }
        .modal-dialog {
            background: #0f172a;
            border: 1px solid #1f2937;
            border-radius: 12px;
            max-width: 520px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 18px;
            border-bottom: 1px solid #1f2937;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .modal-close {
            background: none;
            border: none;
            color: #9ca3af;
            font-size: 1.5rem;
            line-height: 1;
            cursor: pointer;
            padding: 0 4px;
        }
        .modal-close:hover {
            color: #e5e7eb;
        }
        .modal-body {
            padding: 18px;
        }
        .modal-body .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 12px;
        }
        .modal-body .field {
            grid-column: 1 / -1;
        }
        .modal-body .field.half {
            grid-column: span 1;
        }
        .modal-body .field label {
            display: block;
            font-size: 0.8rem;
            margin-bottom: 4px;
            color: #9ca3af;
        }
        .modal-body .field input,
        .modal-body .field select,
        .modal-body .field textarea {
            width: 100%;
            border-radius: 8px;
            border: 1px solid #1f2937;
            padding: 7px 10px;
            font-size: 0.9rem;
            background: #020617;
            color: #e5e7eb;
        }
        .modal-body .field textarea {
            min-height: 80px;
            resize: vertical;
        }
        .date-row {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .date-row input[type="date"] {
            flex: 1;
        }
        .modal-actions {
            margin-top: 18px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .modal-actions .btn-secondary,
        .modal-actions .btn-primary {
            border-radius: 8px;
            border: none;
            padding: 8px 16px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
        }
        .modal-actions .btn-secondary {
            background: #1f2937;
            color: #e5e7eb;
        }
        .modal-actions .btn-primary {
            background: linear-gradient(135deg, #10b981, #0ea5e9);
            color: #020617;
        }
        .tenders-filters {
            margin-top: 14px;
            margin-bottom: 12px;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid rgba(133, 114, 94, 0.55);
            background:
                linear-gradient(180deg, #ffffff 0%, #f6f3ec 100%);
            box-shadow:
                0 2px 8px rgba(16, 24, 14, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }
        .tenders-filters-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(190px, 1fr));
            gap: 10px;
            align-items: end;
        }
        .tenders-filters .filter-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-width: 0;
        }
        .tenders-filters .filter-field label {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #85725e;
        }
        .tenders-filters .filter-field select {
            height: 40px;
            border-radius: 8px;
            border: 1px solid rgba(133, 114, 94, 0.65);
            background: #ffffff;
            color: #10180e;
            padding: 7px 10px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        @media (max-width: 980px) {
            .tenders-filters-grid {
                grid-template-columns: 1fr;
            }
        }
        .empty {
            margin-top: 12px;
            font-size: 0.9rem;
            color: #9ca3af;
        }
        .error {
            margin-top: 12px;
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #fecaca;
            background-color: #7f1d1d;
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
        }
    </style>
    <link rel="stylesheet" href="assets/css/master-detail-theme.css">
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-logo">
                Licitaciones
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="licitaciones.php" class="nav-link active">Licitaciones</a>
                <a href="buscador.php" class="nav-link">Buscador historico</a>
                <a href="lineas-referencia.php" class="nav-link">Anadir lineas</a>
                <a href="analytics.php" class="nav-link">Analitica</a>
                <a href="usuarios.php" class="nav-link">Usuarios</a>
            </nav>
            <div class="sidebar-footer">
                <?php echo htmlspecialchars($organizationId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
            </div>
        </aside>
        <div class="main">
            <header>
                <h1>Mis licitaciones</h1>
                <div class="user-info">
                    <div><?php echo htmlspecialchars($fullName !== '' ? $fullName : $email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php if ($role !== ''): ?>
                        <div class="pill"><?php echo htmlspecialchars($role, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <div>
                        <a href="logout.php">Cerrar sesion</a>
                    </div>
                </div>
            </header>

            <main>
                <div class="card">
                    <div class="card-header-row">
                        <div>
                            <h2>Mis licitaciones</h2>
                            <p style="margin:2px 0 0;font-size:0.85rem;color:#9ca3af;">
                                Gestion del pipeline: estados, presupuesto y detalle.
                            </p>
                        </div>
                        <button type="button" class="btn-primary" id="btn-nueva-licitacion">Nueva licitacion</button>
                    </div>

                    <?php if ($createError !== null): ?>
                        <div class="error">
                            Error guardando la licitacion: <?php echo htmlspecialchars($createError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <form method="get" action="licitaciones.php" class="tenders-filters">
                        <div class="tenders-filters-grid">
                            <div class="filter-field">
                                <label for="filter-pais">Pais</label>
                                <select id="filter-pais" name="pais">
                                    <option value="">Todos los paises</option>
                                    <?php foreach ($paisesDisponibles as $paisOption): ?>
                                        <option
                                            value="<?php echo htmlspecialchars($paisOption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                            <?php echo $filterPaisKey !== null && normalizeCountryKey($paisOption) === $filterPaisKey ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars($paisOption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label for="filter-estado">Estado</label>
                                <select id="filter-estado" name="estado">
                                    <option value="">Todos los estados</option>
                                    <?php foreach ($estados as $estadoOption): ?>
                                        <?php
                                        $estadoIdOption = (int)($estadoOption['id_estado'] ?? 0);
                                        if ($estadoIdOption <= 0) {
                                            continue;
                                        }
                                        $estadoNombreOption = (string)($estadoOption['nombre_estado'] ?? ('Estado ' . $estadoIdOption));
                                        ?>
                                        <option
                                            value="<?php echo $estadoIdOption; ?>"
                                            <?php echo $filterEstadoId === $estadoIdOption ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars($estadoNombreOption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </form>

                    <?php if (isset($loadError)): ?>
                        <div class="error">
                            Error cargando licitaciones: <?php echo htmlspecialchars($loadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th style="text-align:center;">Expediente</th>
                                    <th>Nombre proyecto</th>
                                    <th style="text-align:center;">Procedimiento</th>
                                    <th style="text-align:center;">F. Presentacion</th>
                                    <th style="text-align:center;">Estado</th>
                                    <th style="text-align:right;">Presupuesto (EUR)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($licitaciones === []): ?>
                                    <tr>
                                        <td colspan="6" class="empty" style="text-align:center;padding:12px 8px;">
                                            No hay licitaciones. Crea una o ajusta el filtro.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($licitaciones as $lic): ?>
                                        <?php
                                        $idLicitacion = (int)($lic['id_licitacion'] ?? 0);
                                        $idEstado = (int)($lic['id_estado'] ?? 0);
                                        $estadoNombre = $estadoNombreById[$idEstado] ?? ('Estado ' . $idEstado);
                                        $claseEstado = 'badge-estado';
                                        if ($idEstado === 5) {
                                            $claseEstado .= ' estado-5';
                                        } elseif ($idEstado === 3) {
                                            $claseEstado .= ' estado-3';
                                        } elseif ($idEstado === 2 || $idEstado === 6) {
                                            $claseEstado .= ' estado-descartada';
                                        }

                                        $tipoProc = isset($lic['tipo_procedimiento']) ? (string)$lic['tipo_procedimiento'] : 'ORDINARIO';
                                        $tipoLabel = 'Licitacion';
                                        if ($tipoProc === 'ACUERDO_MARCO') {
                                            $tipoLabel = 'AM';
                                        } elseif ($tipoProc === 'SDA') {
                                            $tipoLabel = 'SDA';
                                        } elseif ($tipoProc === 'CONTRATO_BASADO') {
                                            $tipoLabel = 'Basado';
                                        }

                                        $fechaPresRaw = (string)($lic['fecha_presentacion'] ?? '');
                                        if ($fechaPresRaw !== '' && str_contains($fechaPresRaw, ' ')) {
                                            $fechaPresRaw = explode(' ', $fechaPresRaw)[0];
                                        }
                                        $fechaPresFormatted = '-';
                                        $fechaUrgente = false;
                                        if ($fechaPresRaw !== '') {
                                            $parts = explode('-', $fechaPresRaw);
                                            if (count($parts) === 3) {
                                                $fechaPresFormatted = $parts[2] . '/' . $parts[1] . '/' . $parts[0];
                                                $today = new \DateTimeImmutable('today');
                                                $fechaPresDate = \DateTimeImmutable::createFromFormat('Y-m-d', $fechaPresRaw);
                                                if ($fechaPresDate && $idEstado === 3) {
                                                    $diff = $today->diff($fechaPresDate);
                                                    $dias = (int)$diff->format('%r%a');
                                                    $fechaUrgente = $dias >= 0 && $dias <= 5;
                                                }
                                            }
                                        }

                                        $idPadre = $lic['id_licitacion_padre'] ?? null;
                                        $nombrePadre = null;
                                        if ($idPadre !== null && $idPadre !== '' && isset($parentNameById[(int)$idPadre])) {
                                            $nombrePadre = $parentNameById[(int)$idPadre];
                                        }
                                        $detalleUrl = 'licitacion-detalle.php?id=' . $idLicitacion;
                                        ?>
                                        <tr class="<?php echo $fechaUrgente ? 'row-urgent' : ''; ?>">
                                            <td style="text-align:center;">
                                                <a href="<?php echo htmlspecialchars($detalleUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="link-row"><?php echo htmlspecialchars((string)($lic['numero_expediente'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></a>
                                            </td>
                                            <td class="cell-nombre">
                                                <a href="<?php echo htmlspecialchars($detalleUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="link-row"><?php echo htmlspecialchars((string)($lic['nombre'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></a>
                                            </td>
                                            <td style="text-align:center;">
                                                <span class="<?php echo $claseEstado; ?>"><?php echo htmlspecialchars($tipoLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                                <?php if ($nombrePadre !== null): ?>
                                                    <div class="cuelga-de">Cuelga de: <a href="licitacion-detalle.php?id=<?php echo (int)$idPadre; ?>" class="link-row"><?php echo htmlspecialchars($nombrePadre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></a></div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:center;">
                                                    <?php if ($fechaUrgente): ?>
                                                    <span class="fecha-urgente" title="Menos de 5 dias para presentacion">&#9888; <?php echo htmlspecialchars($fechaPresFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($fechaPresFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <span class="<?php echo $claseEstado; ?>"><?php echo htmlspecialchars($estadoNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                            </td>
                                            <td style="text-align:right;">
                                                <?php echo number_format((float)($lic['pres_maximo'] ?? 0), 0, ',', '.'); ?> EUR
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Nueva licitacion -->
    <div class="modal-overlay" id="modal-nueva-licitacion" aria-hidden="true">
        <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="modal-title">
            <div class="modal-header">
                <h3 id="modal-title">Nueva licitacion</h3>
                <button type="button" class="modal-close" id="modal-close-btn" aria-label="Cerrar">&times;</button>
            </div>
            <div class="modal-body">
                <form id="form-nueva-licitacion" method="post" action="licitaciones.php">
                    <div class="form-grid">
                        <div class="field">
                            <label for="modal-nombre">Nombre del proyecto</label>
                            <input id="modal-nombre" name="nombre" type="text" placeholder="Ej: Servicio de limpieza centros educativos" required />
                        </div>
                        <div class="field half">
                            <label for="modal-pais">Pais</label>
                            <select id="modal-pais" name="pais" required>
                                <option value="">Selecciona pais...</option>
                                <option value="España">España</option>
                                <option value="Portugal">Portugal</option>
                            </select>
                        </div>
                        <div class="field half">
                            <label for="modal-numero_expediente">Nro expediente</label>
                            <input id="modal-numero_expediente" name="numero_expediente" type="text" placeholder="EXP-24-001" />
                        </div>
                        <div class="field">
                            <label for="modal-enlace_gober">Enlace Gober</label>
                            <input id="modal-enlace_gober" name="enlace_gober" type="url" placeholder="https://gober.es/... (URL de la licitacion en Gober)" />
                        </div>
                        <div class="field">
                            <label for="modal-enlace_sharepoint">Enlace SharePoint</label>
                            <input id="modal-enlace_sharepoint" name="enlace_sharepoint" type="url" placeholder="https://... (carpeta o sitio con documentacion)" />
                        </div>
                        <div class="field half">
                            <label for="modal-pres_maximo">Presupuesto max. (EUR)</label>
                            <input id="modal-pres_maximo" name="pres_maximo" type="number" step="0.01" min="0" placeholder="0,00" />
                        </div>
                        <div class="field half">
                            <label for="modal-fecha_presentacion">F. presentacion</label>
                            <div class="date-row">
                                <input id="modal-fecha_presentacion" name="fecha_presentacion" type="date" />
                            </div>
                        </div>
                        <div class="field half">
                            <label for="modal-fecha_adjudicacion">F. adjudicacion</label>
                            <div class="date-row">
                                <input id="modal-fecha_adjudicacion" name="fecha_adjudicacion" type="date" />
                            </div>
                        </div>
                        <div class="field half">
                            <label for="modal-fecha_finalizacion">F. finalizacion</label>
                            <div class="date-row">
                                <input id="modal-fecha_finalizacion" name="fecha_finalizacion" type="date" />
                            </div>
                        </div>
                        <div class="field half">
                            <label for="modal-tipo_procedimiento">Tipo de procedimiento</label>
                            <select id="modal-tipo_procedimiento" name="tipo_procedimiento">
                                <option value="ORDINARIO">Licitacion</option>
                                <option value="ACUERDO_MARCO">Acuerdo Marco</option>
                                <option value="SDA">SDA</option>
                                <option value="CONTRATO_BASADO">Contrato Basado</option>
                            </select>
                        </div>
                        <div class="field half">
                            <label for="modal-crear_lotes">Quieres crear lotes</label>
                            <select id="modal-crear_lotes" name="crear_lotes">
                                <option value="0" selected>No</option>
                                <option value="1">Si</option>
                            </select>
                        </div>
                        <div class="field half" id="modal-num-lotes-wrap" style="display:none;">
                            <label for="modal-num_lotes">Cuantos lotes</label>
                            <input id="modal-num_lotes" name="num_lotes" type="number" min="2" step="1" value="2" />
                        </div>
                        <div class="field half">
                            <label for="modal-tipo_id">Tipo de licitacion</label>
                            <select id="modal-tipo_id" name="id_tipolicitacion">
                                <option value=""><?php echo count($tipos) === 0 ? ($catalogError !== '' ? 'Error: ' . htmlspecialchars(mb_substr($catalogError, 0, 60), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'No hay tipos (ejecuta seed_tipos_licitacion.sql)') : 'Selecciona un tipo'; ?></option>
                                <?php foreach ($tipos as $t): ?>
                                    <option value="<?php echo (int)$t['id_tipolicitacion']; ?>"><?php echo htmlspecialchars((string)($t['tipo'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field" id="modal-expediente-padre-wrap" style="display:none;">
                            <label for="modal-id_licitacion_padre">Expediente padre (AM/SDA adjudicado)</label>
                            <select id="modal-id_licitacion_padre" name="id_licitacion_padre">
                                <option value=""><?php echo count($parentTenders) === 0 ? 'No hay AM/SDA adjudicados' : 'Selecciona el expediente padre'; ?></option>
                                <?php foreach ($parentTenders as $p): ?>
                                    <option value="<?php echo (int)$p['id_licitacion']; ?>"><?php echo htmlspecialchars((string)($p['numero_expediente'] ?? '#' . $p['id_licitacion']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> - <?php echo htmlspecialchars((string)($p['nombre'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="modal-descripcion">Notas / Descripcion</label>
                            <textarea id="modal-descripcion" name="descripcion" rows="3" placeholder="Notas internas, matices del pliego, alcance, etc."></textarea>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" id="modal-cancel-btn">Cancelar</button>
                        <button type="submit" class="btn-primary">Guardar licitacion</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var overlay = document.getElementById('modal-nueva-licitacion');
        var btnOpen = document.getElementById('btn-nueva-licitacion');
        var btnClose = document.getElementById('modal-close-btn');
        var btnCancel = document.getElementById('modal-cancel-btn');
        var form = document.getElementById('form-nueva-licitacion');

        function openModal() {
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
        }
        function closeModal() {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
        }

        btnOpen.addEventListener('click', openModal);
        btnClose.addEventListener('click', closeModal);
        btnCancel.addEventListener('click', closeModal);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
        });

        var tipoProc = document.getElementById('modal-tipo_procedimiento');
        var expedientePadreWrap = document.getElementById('modal-expediente-padre-wrap');
        var crearLotes = document.getElementById('modal-crear_lotes');
        var numLotesWrap = document.getElementById('modal-num-lotes-wrap');
        var numLotesInput = document.getElementById('modal-num_lotes');
        function toggleExpedientePadre() {
            expedientePadreWrap.style.display = tipoProc.value === 'CONTRATO_BASADO' ? 'block' : 'none';
        }
        function toggleNumLotes() {
            if (!crearLotes || !numLotesWrap || !numLotesInput) return;
            var enabled = crearLotes.value === '1';
            numLotesWrap.style.display = enabled ? 'block' : 'none';
            numLotesInput.disabled = !enabled;
        }
        tipoProc.addEventListener('change', toggleExpedientePadre);
        if (crearLotes) crearLotes.addEventListener('change', toggleNumLotes);
        toggleExpedientePadre();
        toggleNumLotes();

        // Filtros del listado: aplicar automaticamente al cambiar pais/estado.
        var filtersForm = document.querySelector('form.tenders-filters');
        if (filtersForm) {
            var filters = filtersForm.querySelectorAll('select[name="pais"], select[name="estado"]');
            filters.forEach(function (control) {
                control.addEventListener('change', function () {
                    filtersForm.submit();
                });
            });
        }

        // Enviar el formulario de forma normal (POST a licitaciones.php) para que se guarde en la BD.
        // No interceptamos el submit con AJAX para reutilizar la logica PHP del listado.
    })();
    </script>
</body>
</html>



