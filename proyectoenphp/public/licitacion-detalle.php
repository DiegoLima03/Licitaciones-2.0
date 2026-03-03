<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Repositories/TendersRepository.php';
require_once __DIR__ . '/../src/Repositories/DeliveriesRepository.php';

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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$licitacion = null;
$loadError = null;
$entregas = [];
$tiposGasto = [];
$selfUrl = (string)($_SERVER['PHP_SELF'] ?? 'licitacion-detalle.php');

try {
    if ($id <= 0) {
        throw new \InvalidArgumentException('Id de licitaciÃ³n no vÃ¡lido.');
    }

    $repo = new TendersRepository($organizationId);
    $deliveriesRepo = new DeliveriesRepository($organizationId);

    // Cargar tipos de gasto para gastos extraordinarios
    try {
        $pdoTmp = Database::getConnection();
        $stmtTipos = $pdoTmp->query('SELECT id, codigo, nombre FROM tbl_tipos_gasto ORDER BY id');
        $tiposGasto = $stmtTipos->fetchAll() ?: [];
    } catch (\Throwable $e) {
        $tiposGasto = [];
    }

    // Si viene un POST, puede ser cambio de estado o nueva partida de presupuesto.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // 1) Nuevo albarÃ¡n
        if (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'nuevo_albaran') {
            $fecha = trim((string)($_POST['fecha'] ?? ''));
            $codigoAlbaran = trim((string)($_POST['codigo_albaran'] ?? ''));
            $cliente = trim((string)($_POST['cliente'] ?? ''));
            $observaciones = trim((string)($_POST['observaciones'] ?? ''));
            /** @var array<int, array<string,mixed>>|mixed $lineasPresuRaw */
            $lineasPresuRaw = $_POST['lineas_presu'] ?? [];
            /** @var array<int, array<string,mixed>>|mixed $lineasExtRaw */
            $lineasExtRaw = $_POST['lineas_ext'] ?? [];
            $lineas = [];

            if ($fecha === '' || $codigoAlbaran === '') {
                $loadError = 'La fecha y el cÃ³digo de albarÃ¡n son obligatorios.';
            } elseif (!is_array($lineasPresuRaw) || !is_array($lineasExtRaw)) {
                $loadError = 'Formato de lÃ­neas de albarÃ¡n invÃ¡lido.';
            } else {
                // LÃ­neas presupuestadas
                foreach ($lineasPresuRaw as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $idDet = isset($row['id_detalle']) ? (int)$row['id_detalle'] : 0;
                    $cantidad = isset($row['cantidad']) ? (float)str_replace(',', '.', (string)$row['cantidad']) : 0.0;
                    $coste = isset($row['coste_unit']) ? (float)str_replace(',', '.', (string)$row['coste_unit']) : 0.0;
                    $proveedor = trim((string)($row['proveedor'] ?? ''));

                    if ($idDet <= 0 || ($cantidad <= 0 && $coste <= 0)) {
                        continue;
                    }

                    // Buscar partida para obtener id_producto si existe
                    $partida = $repo->getPartida($id, $idDet);
                    $idProducto = null;
                    if ($partida !== null && isset($partida['id_producto'])) {
                        $idProducto = $partida['id_producto'] !== null ? (int)$partida['id_producto'] : null;
                    }

                    $lineas[] = [
                        'id_producto' => $idProducto,
                        'id_detalle' => $idDet,
                        'id_tipo_gasto' => null,
                        'proveedor' => $proveedor,
                        'cantidad' => $cantidad,
                        'coste_unit' => $coste,
                    ];
                }

                // LÃ­neas de gasto extraordinario
                $observacionesOtros = [];
                foreach ($lineasExtRaw as $idx => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $idTipoGasto = isset($row['id_tipo_gasto']) ? (int)$row['id_tipo_gasto'] : 0;
                    $costeExt = isset($row['coste_unit']) ? (float)str_replace(',', '.', (string)$row['coste_unit']) : 0.0;
                    $textoLibre = trim((string)($row['tipo_gasto_libre'] ?? ''));

                    if ($idTipoGasto <= 0 || $costeExt <= 0) {
                        continue;
                    }

                    $lineas[] = [
                        'id_producto' => null,
                        'id_detalle' => null,
                        'id_tipo_gasto' => $idTipoGasto,
                        'proveedor' => null,
                        'cantidad' => 0,
                        'coste_unit' => $costeExt,
                    ];

                    // Si el tipo de gasto se llama "Otros", aÃ±adimos la nota al campo observaciones
                    if ($textoLibre !== '') {
                        $nombreTipo = '';
                        foreach ($tiposGasto as $tg) {
                            if ((int)($tg['id'] ?? 0) === $idTipoGasto) {
                                $nombreTipo = (string)($tg['nombre'] ?? '');
                                break;
                            }
                        }
                        $esOtros = mb_strtolower(trim($nombreTipo)) === 'otros';
                        if ($esOtros) {
                            $observacionesOtros[] = 'LÃ­nea extra (' . ($idx + 1) . ', Otros): ' . $textoLibre;
                        }
                    }
                }

                if ($lineas === []) {
                    $loadError = 'Debes indicar al menos una lÃ­nea vÃ¡lida de albarÃ¡n.';
                } else {
                    try {
                        $observacionesFinal = trim($observaciones);
                        if ($observacionesOtros !== []) {
                            $extra = implode("\n", $observacionesOtros);
                            $observacionesFinal = $observacionesFinal !== '' ? $observacionesFinal . "\n" . $extra : $extra;
                        }

                        $payload = [
                            'id_licitacion' => $id,
                            'cabecera' => [
                                'fecha' => $fecha,
                                'codigo_albaran' => $codigoAlbaran,
                                'observaciones' => $observacionesFinal,
                                'cliente' => $cliente !== '' ? $cliente : null,
                            ],
                            'lineas' => $lineas,
                        ];
                        $deliveriesRepo->createDelivery($payload);
                        header('Location: ' . $selfUrl . '?id=' . $id . '&tab=ejecucion');
                        exit;
                    } catch (\Throwable $e) {
                        $loadError = 'Error al registrar el albarÃ¡n: ' . $e->getMessage();
                    }
                }
            }
        // 2) Cambio de estado desde el popup
        } elseif (isset($_POST['estado']) && $_POST['estado'] !== '') {
            $estadoRaw = $_POST['estado'];
            if (is_string($estadoRaw) || is_numeric($estadoRaw)) {
                $estadoId = (int)$estadoRaw;
                if ($estadoId > 0) {
                    // Obtener estado actual respetando RLS
                    $actual = $repo->getById($id);
                    if ($actual === null) {
                        $loadError = 'LicitaciÃ³n no encontrada.';
                    } else {
                        $estadoActual = (int)($actual['id_estado'] ?? 0);

                        // Mismo flujo de estados que en el proyecto React original.
                        $transiciones = [];
                        if ($estadoActual === 1 || $estadoActual === 3) {
                            $transiciones = [
                                4 => 'Presentada',
                                2 => 'Descartar',
                            ];
                        } elseif ($estadoActual === 4) {
                            $transiciones = [
                                5 => 'Adjudicada',
                                6 => 'Marcar como Perdida',
                            ];
                        } elseif ($estadoActual === 5) {
                            $transiciones = [
                                7 => 'Finalizada',
                            ];
                        }

                        if (!array_key_exists($estadoId, $transiciones)) {
                            $loadError = 'TransiciÃ³n de estado no permitida desde el estado actual.';
                        } else {
                            $repo->update($id, ['id_estado' => $estadoId]);
                            header('Location: ' . $selfUrl . '?id=' . $id);
                            exit;
                        }
                    }
                } else {
                    $loadError = 'ParÃ¡metro de estado invÃ¡lido.';
                }
            } else {
                $loadError = 'ParÃ¡metro de estado invÃ¡lido.';
            }
        } else {
            // 2) Alta rÃ¡pida de partida de presupuesto
            $nombrePartida = trim((string)($_POST['nombre_partida'] ?? ''));
            $idProductoPost = isset($_POST['id_producto']) ? (int)$_POST['id_producto'] : 0;
            $lote = trim((string)($_POST['lote'] ?? ''));
            $unidadesRaw = (string)($_POST['unidades'] ?? '');
            $pvuRaw = (string)($_POST['pvu'] ?? '');
            $pcuRaw = (string)($_POST['pcu'] ?? '');

            $unidades = $unidadesRaw !== '' ? (float)str_replace(',', '.', $unidadesRaw) : 0.0;
            $pvu = $pvuRaw !== '' ? (float)str_replace(',', '.', $pvuRaw) : 0.0;
            $pcu = $pcuRaw !== '' ? (float)str_replace(',', '.', $pcuRaw) : 0.0;

            if ($nombrePartida !== '' && ($unidades > 0 || $pvu > 0 || $pcu > 0)) {
                $payload = [
                    'lote' => $lote !== '' ? $lote : 'General',
                    'unidades' => $unidades,
                    'pvu' => $pvu,
                    'pcu' => $pcu,
                    'pmaxu' => 0,
                    'activo' => 1,
                ];

                if ($idProductoPost > 0) {
                    $payload['id_producto'] = $idProductoPost;
                    $payload['nombre_producto_libre'] = null;
                } else {
                    $payload['nombre_producto_libre'] = $nombrePartida;
                }

                $repo->addPartida($id, $payload);
                // Redirigir para evitar re-envÃ­o del formulario al refrescar.
                header('Location: ' . $selfUrl . '?id=' . $id);
                exit;
            }
        }
    }

    $licitacion = $repo->getTenderWithDetails($id);
    if ($licitacion === null) {
        $loadError = 'LicitaciÃ³n no encontrada.';
    } else {
        // Cargar entregas (albaranes) para pestaÃ±a de EjecuciÃ³n / Remaining
        $entregas = $deliveriesRepo->listDeliveries($id);
    }
} catch (\Throwable $e) {
    $loadError = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle licitaciÃ³n</title>
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
            width: 1100px;
            max-width: 1100px;
            margin: 32px auto;
            padding: 0 16px 32px;
        }
        .card {
            background-color: #020617;
            border-radius: 12px;
            padding: 18px 18px 20px;
            box-shadow: 0 18px 35px rgba(15, 23, 42, 0.65);
            border: 1px solid #1f2937;
            /* Mantener altura visual constante entre pestaÃ±as */
            min-height: 420px;
            display: flex;
            flex-direction: column;
            /* Asegurar mismo ancho en todas las pestaÃ±as */
            width: 100%;
            box-sizing: border-box;
        }
        .card h2 {
            margin: 0 0 8px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px 20px;
            margin-top: 8px;
            font-size: 0.9rem;
        }
        .meta-label {
            display: block;
            font-size: 0.75rem;
            color: #9ca3af;
            margin-bottom: 2px;
        }
        .meta-value {
            color: #e5e7eb;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            font-size: 0.85rem;
        }
        th, td {
            padding: 8px 10px;
            border-bottom: 1px solid #1f2937;
            text-align: left;
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
        .back-link {
            display: inline-block;
            margin-bottom: 12px;
            font-size: 0.85rem;
            color: #bae6fd;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        /* Tabs estilo similar al frontend React (TabsList / TabsTrigger) */
        .tabs {
            margin-top: 20px;
        }
        .tabs-list {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 36px;
            border-radius: 9999px;
            background-color: #020617;
            padding: 2px;
            font-size: 0.8rem;
            color: #9ca3af;
        }
        .tab-trigger {
            border: none;
            background: transparent;
            padding: 4px 10px;
            border-radius: 9999px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 500;
            color: #9ca3af;
        }
        .tab-trigger:hover {
            color: #e5e7eb;
        }
        .tab-trigger.active {
            background-color: #0b1120;
            color: #e5e7eb;
            box-shadow: 0 0 0 1px #1f2937;
        }
        .tab-content {
            margin-top: 16px;
            display: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
    <link rel="stylesheet" href="assets/css/master-detail-theme.css">
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-logo">
                Licitaciones <span>PHP</span>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="licitaciones.php" class="nav-link active">Licitaciones</a>
                <a href="buscador.php" class="nav-link">Buscador histÃ³rico</a>
                <a href="lineas-referencia.php" class="nav-link">AÃ±adir lÃ­neas</a>
                <a href="analytics.php" class="nav-link">AnalÃ­tica</a>
                <a href="usuarios.php" class="nav-link">Usuarios</a>
            </nav>
            <div class="sidebar-footer">
                <?php echo htmlspecialchars($organizationId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
            </div>
        </aside>
        <div class="main">
            <header>
                <h1>Detalle de licitaciÃ³n</h1>
                <div class="user-info">
                    <div><?php echo htmlspecialchars($fullName !== '' ? $fullName : $email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php if ($role !== ''): ?>
                        <div class="pill"><?php echo htmlspecialchars($role, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <div>
                        <a href="logout.php" style="color:#f97373;text-decoration:none;font-size:0.85rem;">Cerrar sesiÃ³n</a>
                    </div>
                </div>
            </header>

            <main>
                <a href="licitaciones.php" class="back-link">&larr; Volver al listado</a>

                <div class="card">
                    <?php if ($loadError !== null): ?>
                        <p style="color:#fecaca;font-size:0.9rem;">
                            Error cargando la licitaciÃ³n: <?php echo htmlspecialchars($loadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </p>
                    <?php elseif ($licitacion === null): ?>
                        <p style="color:#9ca3af;font-size:0.9rem;">No se encontrÃ³ la licitaciÃ³n solicitada.</p>
                    <?php else: ?>
                        <?php
                        $estadoIdActual = (int)($licitacion['id_estado'] ?? 0);
                        $estadoNombres = [
                            1 => 'Borrador',
                            2 => 'Descartada',
                            3 => 'En anÃ¡lisis',
                            4 => 'Presentada',
                            5 => 'Adjudicada',
                            6 => 'No adjudicada',
                            7 => 'Terminada',
                        ];
                        $estadoActualLabel = $estadoNombres[$estadoIdActual] ?? 'Desconocido';

                        // Colores por estado (pill)
                        $estadoBg = '#0f172a';
                        $estadoBorder = '#1f2937';
                        $estadoText = '#e5e7eb';
                        switch ($estadoIdActual) {
                            case 1: // Borrador
                                $estadoBg = 'rgba(148, 163, 184, 0.2)';
                                $estadoBorder = '#64748b';
                                $estadoText = '#e5e7eb';
                                break;
                            case 3: // En anÃ¡lisis
                                $estadoBg = 'rgba(59, 130, 246, 0.2)';
                                $estadoBorder = '#60a5fa';
                                $estadoText = '#bfdbfe';
                                break;
                            case 4: // Presentada
                                $estadoBg = 'rgba(234, 179, 8, 0.2)';
                                $estadoBorder = '#eab308';
                                $estadoText = '#facc15';
                                break;
                            case 5: // Adjudicada
                                $estadoBg = 'rgba(16, 185, 129, 0.25)';
                                $estadoBorder = '#22c55e';
                                $estadoText = '#bbf7d0';
                                break;
                            case 6: // No adjudicada
                            case 2: // Descartada
                                $estadoBg = 'rgba(248, 113, 113, 0.25)';
                                $estadoBorder = '#f97373';
                                $estadoText = '#fecaca';
                                break;
                            case 7: // Terminada
                                $estadoBg = 'rgba(56, 189, 248, 0.2)';
                                $estadoBorder = '#38bdf8';
                                $estadoText = '#bae6fd';
                                break;
                        }

                        // Misma lÃ³gica de flujo que en React:
                        $transicionesDisponibles = [];
                        if ($estadoIdActual === 1 || $estadoIdActual === 3) {
                            $transicionesDisponibles = [
                                4 => 'Presentada',
                                2 => 'Descartar',
                            ];
                        } elseif ($estadoIdActual === 4) {
                            $transicionesDisponibles = [
                                5 => 'Adjudicada',
                                6 => 'Marcar como Perdida',
                            ];
                        } elseif ($estadoIdActual === 5) {
                            $transicionesDisponibles = [
                                7 => 'Finalizada',
                            ];
                        }
                        ?>
                        <h2 style="margin:0 0 4px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                            <span><?php echo htmlspecialchars((string)($licitacion['nombre'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                            <span style="display:inline-flex;align-items:center;gap:10px;">
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <span style="font-size:0.75rem;color:#9ca3af;">Estado:</span>
                                    <span style="display:inline-block;padding:2px 10px;border-radius:9999px;background-color:<?php echo $estadoBg; ?>;color:<?php echo $estadoText; ?>;font-size:0.75rem;font-weight:500;border:1px solid <?php echo $estadoBorder; ?>;">
                                        <?php echo htmlspecialchars($estadoActualLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                    </span>
                                </span>
                                <?php if ($transicionesDisponibles !== []): ?>
                                    <button
                                        type="button"
                                        id="btn-cambiar-estado"
                                        style="border:1px solid #1f2937;border-radius:9999px;background:#020617;color:#e5e7eb;font-size:0.7rem;font-weight:500;padding:4px 12px;cursor:pointer;"
                                    >
                                        Cambiar estado
                                    </button>
                                <?php endif; ?>
                            </span>
                        </h2>

                        <?php if ($transicionesDisponibles !== []): ?>
                            <div
                                id="modal-cambiar-estado"
                                style="position:fixed;inset:0;background:rgba(15,23,42,0.72);display:none;align-items:center;justify-content:center;z-index:50;"
                            >
                                <div style="background:#020617;border-radius:12px;border:1px solid #1f2937;box-shadow:0 18px 35px rgba(15,23,42,0.9);max-width:420px;width:100%;padding:16px 18px;">
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
                                        <h3 style="margin:0;font-size:0.9rem;font-weight:600;color:#e5e7eb;">Cambiar estado</h3>
                                        <button
                                            type="button"
                                            id="modal-cambiar-estado-close"
                                            style="border:none;background:transparent;color:#9ca3af;font-size:0.9rem;cursor:pointer;"
                                        >âœ•</button>
                                    </div>
                                    <p style="margin:0 0 10px;font-size:0.8rem;color:#9ca3af;">
                                        Selecciona el nuevo estado al que quieres mover la licitaciÃ³n.
                                    </p>
                                    <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px;">
                                    <?php foreach ($transicionesDisponibles as $nuevoId => $label): ?>
                                            <form
                                                action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                method="POST"
                                                style="margin:0;"
                                            >
                                                <input type="hidden" name="estado" value="<?php echo (int)$nuevoId; ?>">
                                                <button
                                                    type="submit"
                                                    style="width:100%;text-align:left;border:1px solid #1f2937;border-radius:8px;background:#020617;color:#e5e7eb;font-size:0.8rem;font-weight:500;padding:6px 10px;cursor:pointer;"
                                                >
                                                    <?php echo htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                    <button
                                        type="button"
                                        id="modal-cambiar-estado-cancel"
                                        style="border:1px solid #374151;border-radius:8px;background:#020617;color:#9ca3af;font-size:0.75rem;padding:4px 10px;cursor:pointer;"
                                    >
                                        Cancelar
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="meta-grid">
                            <div>
                                <span class="meta-label">NÂº expediente</span>
                                <span class="meta-value">
                                    <?php echo htmlspecialchars((string)($licitacion['numero_expediente'] ?? 'â€”'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                </span>
                            </div>
                            <div>
                                <span class="meta-label">Presupuesto mÃ¡ximo</span>
                                <span class="meta-value">
                                    <?php echo number_format((float)($licitacion['pres_maximo'] ?? 0), 0, ',', '.'); ?> â‚¬
                                </span>
                            </div>
                            <div>
                                <span class="meta-label">Fecha presentaciÃ³n</span>
                                <span class="meta-value">
                                    <?php
                                    $fp = (string)($licitacion['fecha_presentacion'] ?? '');
                                    if ($fp !== '' && str_contains($fp, ' ')) {
                                        $fp = explode(' ', $fp)[0];
                                    }
                                    if ($fp !== '' && str_contains($fp, '-')) {
                                        $parts = explode('-', $fp);
                                        if (count($parts) === 3) {
                                            echo htmlspecialchars($parts[2] . '/' . $parts[1] . '/' . $parts[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                        } else {
                                            echo htmlspecialchars($fp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                        }
                                    } else {
                                        echo 'â€”';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div>
                                <span class="meta-label">PaÃ­s</span>
                                <span class="meta-value">
                                    <?php echo htmlspecialchars((string)($licitacion['pais'] ?? 'â€”'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                </span>
                            </div>
                            <div>
                                <span class="meta-label">Tipo procedimiento</span>
                                <span class="meta-value">
                                    <?php echo htmlspecialchars((string)($licitacion['tipo_procedimiento'] ?? 'ORDINARIO'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                </span>
                            </div>
                        </div>

                        <?php
                        /** @var array<int, array<string,mixed>> $partidas */
                        $partidas = is_array($licitacion['partidas'] ?? null) ? $licitacion['partidas'] : [];
                        $idEstado = (int)($licitacion['id_estado'] ?? 0);
                        // A partir de ADJUDICADA (5) mostramos pestaÃ±as de ejecuciÃ³n/remaining como en el frontend antiguo.
                        $showEjecucionRemaining = $idEstado >= 5;

                        // -------------------------
                        // CÃ¡lculos para Remaining
                        // -------------------------
                        // Mapa id_detalle => partida
                        $idToPartida = [];
                        foreach ($partidas as $p) {
                            if (isset($p['id_detalle'])) {
                                $idToPartida[(int)$p['id_detalle']] = $p;
                            }
                        }

                        // itemsPresupuestoAgregado: por lote+descripcion
                        $itemsPresupuestoAgregado = [];
                        foreach ($partidas as $p) {
                            $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
                            if (!$activo) {
                                continue;
                            }
                            $lote = trim((string)($p['lote'] ?? ''));
                            if ($lote === '') {
                                $lote = 'General';
                            }
                            $descripcion = (string)($p['nombre_producto_libre'] ?? ($p['product_nombre'] ?? ''));
                            $unidades = (float)($p['unidades'] ?? 0);
                            $key = $lote . '|' . $descripcion;
                            if (!isset($itemsPresupuestoAgregado[$key])) {
                                $itemsPresupuestoAgregado[$key] = [
                                    'lote' => $lote,
                                    'descripcion' => $descripcion,
                                    'unidades' => 0.0,
                                ];
                            }
                            $itemsPresupuestoAgregado[$key]['unidades'] += $unidades;
                        }

                        // ejecutadoPorPartida: unidades reales entregadas por lote+descripcion
                        $ejecutadoPorPartida = [];
                        foreach ($entregas as $ent) {
                            $lineas = isset($ent['lineas']) && is_array($ent['lineas']) ? $ent['lineas'] : [];
                            foreach ($lineas as $lin) {
                                $idDet = $lin['id_detalle'] ?? null;
                                $idTipoGasto = $lin['id_tipo_gasto'] ?? null;
                                if ($idDet === null || $idTipoGasto !== null) {
                                    // Solo lÃ­neas presupuestadas (no gastos extra)
                                    continue;
                                }
                                $idDet = (int)$idDet;
                                if (!isset($idToPartida[$idDet])) {
                                    continue;
                                }
                                $partida = $idToPartida[$idDet];
                                $lote = trim((string)($partida['lote'] ?? ''));
                                if ($lote === '') {
                                    $lote = 'General';
                                }
                                $descripcion = (string)($partida['nombre_producto_libre'] ?? ($partida['product_nombre'] ?? ''));
                                $key = $lote . '|' . $descripcion;
                                $cant = (float)($lin['cantidad'] ?? 0);
                                if (!isset($ejecutadoPorPartida[$key])) {
                                    $ejecutadoPorPartida[$key] = 0.0;
                                }
                                $ejecutadoPorPartida[$key] += $cant;
                            }
                        }
                        ?>

                        <div class="tabs">
                            <div class="tabs-list">
                                <button type="button" class="tab-trigger active" data-tab="presupuesto">
                                    Presupuesto (Oferta)
                                </button>
                                <?php if ($showEjecucionRemaining): ?>
                                    <button type="button" class="tab-trigger" data-tab="ejecucion">
                                        Entregas (Real / Albaranes)
                                    </button>
                                    <button type="button" class="tab-trigger" data-tab="remaining">
                                        Remaining
                                    </button>
                                <?php endif; ?>
                            </div>

                            <div id="tab-presupuesto" class="tab-content active">
                                <form method="post" action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" style="margin-top:16px;margin-bottom:16px;">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th style="width:40%;">Nuevo concepto</th>
                                                <th style="text-align:right;width:10%;">Lote</th>
                                                <th style="text-align:right;width:10%;">Uds.</th>
                                                <th style="text-align:right;width:10%;">PVU (â‚¬)</th>
                                                <th style="text-align:right;width:10%;">PCU (â‚¬)</th>
                                                <th style="text-align:right;width:10%;"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>
                                                    <input
                                                        type="hidden"
                                                        name="id_producto"
                                                        id="id_producto"
                                                        value=""
                                                    />
                                                    <div style="position:relative;">
                                                        <input
                                                            type="text"
                                                            name="nombre_partida"
                                                            id="nombre_partida"
                                                            placeholder="DescripciÃ³n / producto"
                                                            autocomplete="off"
                                                            style="width:100%;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:6px 8px;font-size:0.85rem;"
                                                        />
                                                        <div
                                                            id="autocomplete_productos"
                                                            style="position:absolute;z-index:30;left:0;right:0;margin-top:2px;background:#020617;border:1px solid #1f2937;border-radius:6px;max-height:220px;overflow-y:auto;display:none;"
                                                        ></div>
                                                    </div>
                                                </td>
                                                <td style="text-align:right;">
                                                    <input
                                                        type="text"
                                                        name="lote"
                                                        placeholder="General"
                                                        style="width:100%;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:6px 8px;font-size:0.85rem;text-align:right;"
                                                    />
                                                </td>
                                                <td style="text-align:right;">
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        name="unidades"
                                                        placeholder="0"
                                                        style="width:100%;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:6px 8px;font-size:0.85rem;text-align:right;"
                                                    />
                                                </td>
                                                <td style="text-align:right;">
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        name="pvu"
                                                        placeholder="0"
                                                        style="width:100%;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:6px 8px;font-size:0.85rem;text-align:right;"
                                                    />
                                                </td>
                                                <td style="text-align:right;">
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        name="pcu"
                                                        placeholder="0"
                                                        style="width:100%;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:6px 8px;font-size:0.85rem;text-align:right;"
                                                    />
                                                </td>
                                                <td style="text-align:right;">
                                                    <button
                                                        type="submit"
                                                        style="border:none;border-radius:6px;background:linear-gradient(135deg,#10b981,#0ea5e9);color:#020617;font-size:0.8rem;font-weight:600;padding:6px 10px;cursor:pointer;"
                                                    >
                                                        AÃ±adir
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </form>

                                <?php if ($partidas === []): ?>
                                    <p style="margin-top:8px;font-size:0.9rem;color:#9ca3af;">
                                        Esta licitaciÃ³n aÃºn no tiene partidas de presupuesto cargadas.
                                    </p>
                                <?php else: ?>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Producto</th>
                                                <th style="text-align:right;">Uds.</th>
                                                <th style="text-align:right;">PVU (â‚¬)</th>
                                                <th style="text-align:right;">PCU (â‚¬)</th>
                                                <th style="text-align:right;">Importe (â‚¬)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($partidas as $p): ?>
                                                <?php
                                                $nombreProd = (string)($p['product_nombre'] ?? ($p['nombre_producto_libre'] ?? ''));
                                                $uds = (float)($p['unidades'] ?? 0);
                                                $pvu = (float)($p['pvu'] ?? 0);
                                                $pcu = (float)($p['pcu'] ?? 0);
                                                $importe = $uds > 0 ? $uds * $pvu : $pvu;
                                                ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($nombreProd, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                    <td style="text-align:right;"><?php echo $uds > 0 ? number_format($uds, 2, ',', '.') : 'â€”'; ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($pvu, 2, ',', '.'); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($pcu, 2, ',', '.'); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($importe, 2, ',', '.'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>

                            <?php if ($showEjecucionRemaining): ?>
                                <div id="tab-ejecucion" class="tab-content">
                                    <div style="margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
                                        <p style="font-size:0.9rem;color:#9ca3af;">
                                            Resumen de entregas y albaranes vinculados a esta licitaciÃ³n.
                                        </p>
                                        <button
                                            type="button"
                                            id="btn-nuevo-albaran"
                                            style="border-radius:9999px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;font-weight:500;padding:4px 10px;cursor:pointer;"
                                        >
                                            âž• Registrar nuevo albarÃ¡n
                                        </button>
                                    </div>
                                    <?php if (empty($entregas)): ?>
                                        <p style="font-size:0.9rem;color:#9ca3af;">
                                            No hay entregas registradas para esta licitaciÃ³n.
                                        </p>
                                    <?php else: ?>
                                        <div style="display:flex;flex-direction:column;gap:10px;">
                                            <?php foreach ($entregas as $ent): ?>
                                                <?php
                                                $codigoAlbaran = (string)($ent['codigo_albaran'] ?? '');
                                                $fechaEntrega = (string)($ent['fecha_entrega'] ?? '');
                                                $obs = (string)($ent['observaciones'] ?? '');
                                                $lineas = isset($ent['lineas']) && is_array($ent['lineas']) ? $ent['lineas'] : [];
                                                ?>
                                                <div style="border-radius:10px;border:1px solid #1f2937;background:#020617;padding:10px 12px;">
                                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
                                                        <div>
                                                            <div style="font-size:0.9rem;font-weight:600;color:#e5e7eb;">
                                                                <?php echo htmlspecialchars($codigoAlbaran !== '' ? $codigoAlbaran : 'Sin cÃ³digo', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                            </div>
                                                            <div style="font-size:0.75rem;color:#9ca3af;">
                                                                Fecha: <?php echo htmlspecialchars($fechaEntrega, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                            </div>
                                                        </div>
                                                        <?php if ($obs !== ''): ?>
                                                            <div style="font-size:0.75rem;color:#6b7280;text-align:right;max-width:260px;white-space:pre-wrap;">
                                                                <?php echo htmlspecialchars($obs, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="overflow-x:auto;">
                                                        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
                                                            <thead>
                                                                <tr style="border-bottom:1px solid #1f2937;font-size:0.7rem;text-transform:uppercase;color:#9ca3af;">
                                                                    <th style="padding:4px 6px;text-align:left;">Concepto</th>
                                                                    <th style="padding:4px 6px;text-align:left;">Proveedor</th>
                                                                    <th style="padding:4px 6px;text-align:right;">Cantidad</th>
                                                                    <th style="padding:4px 6px;text-align:right;">Coste</th>
                                                                    <th style="padding:4px 6px;text-align:center;">Estado</th>
                                                                    <th style="padding:4px 6px;text-align:center;">Cobrado</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php if (empty($lineas)): ?>
                                                                    <tr>
                                                                        <td colspan="6" style="padding:8px 6px;text-align:center;font-size:0.75rem;color:#6b7280;">
                                                                            Sin lÃ­neas
                                                                        </td>
                                                                    </tr>
                                                                <?php else: ?>
                                                                    <?php foreach ($lineas as $idx => $lin): ?>
                                                                        <?php
                                                                        $esGastoExtra = ($lin['id_detalle'] ?? null) === null && isset($lin['id_tipo_gasto']);
                                                                        $concepto = (string)($lin['product_nombre'] ?? 'â€”');
                                                                        $proveedor = (string)($lin['proveedor'] ?? 'â€”');
                                                                        $cantidad = (float)($lin['cantidad'] ?? 0);
                                                                        $pcu = (float)($lin['pcu'] ?? 0);
                                                                        $estadoLin = (string)($lin['estado'] ?? '');
                                                                        $cobrado = (bool)($lin['cobrado'] ?? false);
                                                                        ?>
                                                                        <tr style="border-bottom:1px solid #111827;">
                                                                            <td style="padding:4px 6px;color:#e5e7eb;"><?php echo htmlspecialchars($concepto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                                            <td style="padding:4px 6px;color:#9ca3af;"><?php echo htmlspecialchars($proveedor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                                            <td style="padding:4px 6px;text-align:right;color:#e5e7eb;"><?php echo number_format($cantidad, 2, ',', '.'); ?></td>
                                                                            <td style="padding:4px 6px;text-align:right;color:#e5e7eb;"><?php echo number_format($pcu, 2, ',', '.'); ?> â‚¬</td>
                                                                            <td style="padding:4px 6px;text-align:center;">
                                                                                <?php if ($esGastoExtra): ?>
                                                                                    <span style="font-size:0.7rem;color:#6b7280;">Gasto ext.</span>
                                                                                <?php else: ?>
                                                                                    <span style="font-size:0.7rem;color:#e5e7eb;"><?php echo htmlspecialchars($estadoLin !== '' ? $estadoLin : 'EN ESPERA', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                            <td style="padding:4px 6px;text-align:center;">
                                                                                <?php if ($esGastoExtra): ?>
                                                                                    <span style="font-size:0.7rem;color:#6b7280;">â€”</span>
                                                                                <?php else: ?>
                                                                                    <span style="font-size:0.7rem;color:<?php echo $cobrado ? '#34d399' : '#f97373'; ?>;">
                                                                                        <?php echo $cobrado ? 'SÃ­' : 'No'; ?>
                                                                                    </span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Modal nuevo albarÃ¡n -->
                                    <div
                                        id="modal-nuevo-albaran"
                                        style="position:fixed;inset:0;background:rgba(15,23,42,0.72);display:none;align-items:center;justify-content:center;z-index:60;"
                                    >
                                        <div style="background:#020617;border-radius:12px;border:1px solid #1f2937;box-shadow:0 18px 35px rgba(15,23,42,0.9);max-width:720px;width:100%;padding:16px 18px;">
                                            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
                                                <h3 style="margin:0;font-size:0.9rem;font-weight:600;color:#e5e7eb;">Registrar nuevo albarÃ¡n</h3>
                                                <button
                                                    type="button"
                                                    id="modal-nuevo-albaran-close"
                                                    style="border:none;background:transparent;color:#9ca3af;font-size:0.9rem;cursor:pointer;"
                                                >âœ•</button>
                                            </div>
                                            <p style="margin:0 0 10px;font-size:0.8rem;color:#9ca3af;">
                                                Cabecera del albarÃ¡n y lÃ­neas de entrega (concepto, proveedor, cantidad, coste).
                                            </p>
                                            <form
                                                method="post"
                                                action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                id="form-nuevo-albaran"
                                            >
                                                <input type="hidden" name="form_tipo" value="nuevo_albaran">
                                                <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:10px;">
                                                    <div style="display:flex;flex-direction:column;gap:4px;">
                                                        <label style="font-size:0.75rem;font-weight:600;color:#9ca3af;">Fecha</label>
                                                        <input
                                                            type="date"
                                                            name="fecha"
                                                            value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                            style="height:32px;border-radius:8px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:4px 8px;font-size:0.8rem;"
                                                            required
                                                        />
                                                    </div>
                                                    <div style="display:flex;flex-direction:column;gap:4px;">
                                                        <label style="font-size:0.75rem;font-weight:600;color:#9ca3af;">CÃ³digo albarÃ¡n</label>
                                                        <input
                                                            type="text"
                                                            name="codigo_albaran"
                                                            placeholder="Ej. ALB-001"
                                                            style="height:32px;border-radius:8px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:4px 8px;font-size:0.8rem;"
                                                            required
                                                        />
                                                    </div>
                                                    <div style="display:flex;flex-direction:column;gap:4px;">
                                                        <label style="font-size:0.75rem;font-weight:600;color:#9ca3af;">Cliente (opcional)</label>
                                                        <input
                                                            type="text"
                                                            name="cliente"
                                                            style="height:32px;border-radius:8px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:4px 8px;font-size:0.8rem;"
                                                        />
                                                    </div>
                                                </div>
                                                <div style="margin-bottom:10px;display:flex;flex-direction:column;gap:4px;">
                                                    <label style="font-size:0.75rem;font-weight:600;color:#9ca3af;">Observaciones</label>
                                                    <textarea
                                                        name="observaciones"
                                                        rows="2"
                                                        placeholder="Opcional"
                                                        style="width:100%;border-radius:8px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:6px 8px;font-size:0.8rem;resize:vertical;"
                                                    ></textarea>
                                                </div>

                                                <div style="margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
                                                    <span style="font-size:0.8rem;color:#9ca3af;">LÃ­neas del albarÃ¡n</span>
                                                    <div style="display:inline-flex;border-radius:9999px;border:1px solid #1f2937;overflow:hidden;">
                                                        <button
                                                            type="button"
                                                            id="btn-albaran-tipo-presu"
                                                            style="border:none;background:#0f172a;color:#e5e7eb;font-size:0.75rem;font-weight:500;padding:4px 10px;cursor:pointer;"
                                                        >
                                                            Partidas
                                                        </button>
                                                        <button
                                                            type="button"
                                                            id="btn-albaran-tipo-ext"
                                                            style="border:none;background:transparent;color:#9ca3af;font-size:0.75rem;font-weight:500;padding:4px 10px;cursor:pointer;"
                                                        >
                                                            Gastos extra
                                                        </button>
                                                    </div>
                                                </div>

                                                <div id="albaran-section-presu" style="border-radius:8px;border:1px solid #1f2937;overflow:hidden;">
                                                    <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                                                        <thead>
                                                            <tr style="border-bottom:1px solid #1f2937;font-size:0.7rem;text-transform:uppercase;color:#9ca3af;">
                                                                <th style="padding:4px 6px;text-align:left;">Partida</th>
                                                                <th style="padding:4px 6px;text-align:left;">Proveedor</th>
                                                                <th style="padding:4px 6px;text-align:right;">Cantidad</th>
                                                                <th style="padding:4px 6px;text-align:right;">Coste â‚¬</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php for ($i = 0; $i < 3; $i++): ?>
                                                                <tr style="border-bottom:1px solid #111827;">
                                                                    <td style="padding:4px 6px;min-width:200px;">
                                                                        <select
                                                                            name="lineas_presu[<?php echo $i; ?>][id_detalle]"
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;"
                                                                        >
                                                                            <option value="">Selecciona partidaâ€¦</option>
                                                                            <?php foreach ($partidas as $p): ?>
                                                                                <?php
                                                                                $idDet = (int)($p['id_detalle'] ?? 0);
                                                                                $nombreProd = (string)($p['product_nombre'] ?? ($p['nombre_producto_libre'] ?? ''));
                                                                                $lote = trim((string)($p['lote'] ?? ''));
                                                                                if ($lote === '') $lote = 'General';
                                                                                $label = $lote . ' â€“ ' . $nombreProd;
                                                                                ?>
                                                                                <option value="<?php echo $idDet; ?>">
                                                                                    <?php echo htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </td>
                                                                    <td style="padding:4px 6px;min-width:150px;">
                                                                        <input
                                                                            type="text"
                                                                            name="lineas_presu[<?php echo $i; ?>][proveedor]"
                                                                            placeholder="Proveedor"
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;"
                                                                        />
                                                                    </td>
                                                                    <td style="padding:4px 6px;text-align:right;width:80px;">
                                                                        <input
                                                                            type="number"
                                                                            step="0.01"
                                                                            min="0"
                                                                            name="lineas_presu[<?php echo $i; ?>][cantidad]"
                                                                            placeholder="0"
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;text-align:right;"
                                                                        />
                                                                    </td>
                                                                    <td style="padding:4px 6px;text-align:right;width:90px;">
                                                                        <input
                                                                            type="number"
                                                                            step="0.01"
                                                                            min="0"
                                                                            name="lineas_presu[<?php echo $i; ?>][coste_unit]"
                                                                            placeholder="0,00"
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;text-align:right;"
                                                                        />
                                                                    </td>
                                                                </tr>
                                                            <?php endfor; ?>
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <div id="albaran-section-ext" style="margin-top:12px;margin-bottom:6px;font-size:0.8rem;color:#9ca3af;display:none;">
                                                    <div style="margin-bottom:6px;">Gastos extraordinarios</div>
                                                    <div style="border-radius:8px;border:1px solid #1f2937;overflow:hidden;">
                                                    <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                                                        <thead>
                                                            <tr style="border-bottom:1px solid #1f2937;font-size:0.7rem;text-transform:uppercase;color:#9ca3af;">
                                                                <th style="padding:4px 6px;text-align:left;">Tipo gasto</th>
                                                                <th style="padding:4px 6px;text-align:left;">Detalle (solo para "Otros")</th>
                                                                <th style="padding:4px 6px;text-align:right;">Coste â‚¬</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php for ($j = 0; $j < 2; $j++): ?>
                                                                <tr style="border-bottom:1px solid #111827;">
                                                                    <td style="padding:4px 6px;min-width:160px;">
                                                                        <select
                                                                            name="lineas_ext[<?php echo $j; ?>][id_tipo_gasto]"
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;"
                                                                        >
                                                                            <option value="">Tipo de gastoâ€¦</option>
                                                                            <?php foreach ($tiposGasto as $tg): ?>
                                                                                <?php
                                                                                $idG = (int)($tg['id'] ?? 0);
                                                                                $nombreG = (string)($tg['nombre'] ?? ($tg['codigo'] ?? ''));
                                                                                ?>
                                                                                <option value="<?php echo $idG; ?>">
                                                                                    <?php echo htmlspecialchars($nombreG, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </td>
                                                                    <td style="padding:4px 6px;min-width:200px;">
                                                                        <input
                                                                            type="text"
                                                                            name="lineas_ext[<?php echo $j; ?>][tipo_gasto_libre]"
                                                                            placeholder='Solo si el tipo es "Otros"'
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;"
                                                                        />
                                                                    </td>
                                                                    <td style="padding:4px 6px;text-align:right;width:90px;">
                                                                        <input
                                                                            type="number"
                                                                            step="0.01"
                                                                            min="0"
                                                                            name="lineas_ext[<?php echo $j; ?>][coste_unit]"
                                                                            placeholder="0,00"
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;text-align:right;"
                                                                        />
                                                                    </td>
                                                                </tr>
                                                            <?php endfor; ?>
                                                        </tbody>
                                                    </table>
                                                    </div>
                                                </div>

                                                <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px;">
                                                    <button
                                                        type="button"
                                                        id="modal-nuevo-albaran-cancel"
                                                        style="border:1px solid #374151;border-radius:8px;background:#020617;color:#9ca3af;font-size:0.75rem;padding:4px 10px;cursor:pointer;"
                                                    >
                                                        Cancelar
                                                    </button>
                                                    <button
                                                        type="submit"
                                                        style="border:none;border-radius:8px;background:linear-gradient(135deg,#10b981,#0ea5e9);color:#020617;font-size:0.8rem;font-weight:600;padding:6px 12px;cursor:pointer;"
                                                    >
                                                        Registrar albarÃ¡n
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <div id="tab-remaining" class="tab-content">
                                    <p style="font-size:0.9rem;color:#9ca3af;margin-bottom:10px;">
                                        Comparativa entre unidades presupuestadas y ejecutadas por partida.
                                    </p>
                                    <div style="border-radius:10px;border:1px solid #1f2937;background:#020617;padding:10px 12px;overflow-x:auto;">
                                        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
                                            <thead>
                                                <tr style="border-bottom:1px solid #1f2937;font-size:0.7rem;text-transform:uppercase;color:#9ca3af;">
                                                    <th style="padding:4px 6px;text-align:left;">Lote</th>
                                                    <th style="padding:4px 6px;text-align:left;">Partida</th>
                                                    <th style="padding:4px 6px;text-align:right;">Ud. Presu.</th>
                                                    <th style="padding:4px 6px;text-align:right;">Ud. Real</th>
                                                    <th style="padding:4px 6px;text-align:right;">Pendiente</th>
                                                    <th style="padding:4px 6px;text-align:left;">Progreso</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($itemsPresupuestoAgregado as $key => $item): ?>
                                                    <?php
                                                    $ejecutado = (float)($ejecutadoPorPartida[$key] ?? 0.0);
                                                    $presu = (float)($item['unidades'] ?? 0.0);
                                                    $pendiente = max(0.0, $presu - $ejecutado);
                                                    $progreso = $presu > 0 ? max(0.0, min(100.0, ($ejecutado / $presu) * 100.0)) : 0.0;
                                                    ?>
                                                    <tr style="border-bottom:1px solid #111827;">
                                                        <td style="padding:4px 6px;font-size:0.75rem;color:#9ca3af;"><?php echo htmlspecialchars((string)$item['lote'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                        <td style="padding:4px 6px;font-size:0.85rem;color:#e5e7eb;max-width:260px;">
                                                            <?php echo htmlspecialchars((string)$item['descripcion'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                        </td>
                                                        <td style="padding:4px 6px;text-align:right;font-size:0.8rem;color:#e5e7eb;">
                                                            <?php echo number_format($presu, 2, ',', '.'); ?>
                                                        </td>
                                                        <td style="padding:4px 6px;text-align:right;font-size:0.8rem;color:#e5e7eb;">
                                                            <?php echo number_format($ejecutado, 2, ',', '.'); ?>
                                                        </td>
                                                        <td style="padding:4px 6px;text-align:right;font-size:0.8rem;color:#e5e7eb;">
                                                            <?php echo number_format($pendiente, 2, ',', '.'); ?>
                                                        </td>
                                                        <td style="padding:4px 6px;">
                                                            <div style="height:6px;width:100%;border-radius:9999px;background:#020617;border:1px solid #1f2937;overflow:hidden;">
                                                                <div
                                                                    style="height:6px;border-radius:9999px;background:#10b981;width:<?php echo number_format($progreso, 0); ?>%;"
                                                                ></div>
                                                            </div>
                                                            <div style="margin-top:2px;font-size:0.7rem;color:#9ca3af;">
                                                                <?php echo number_format($progreso, 0); ?> %
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
</body>
<script>
// Tabs simples en cliente para navegar entre Presupuesto / EjecuciÃ³n / Remaining
document.addEventListener('DOMContentLoaded', function () {
    var triggers = Array.prototype.slice.call(document.querySelectorAll('.tab-trigger'));
    var contents = Array.prototype.slice.call(document.querySelectorAll('.tab-content'));
    triggers.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tab = btn.getAttribute('data-tab');
            if (!tab) return;
            triggers.forEach(function (b) { b.classList.remove('active'); });
            contents.forEach(function (c) { c.classList.remove('active'); });
            btn.classList.add('active');
            var target = document.getElementById('tab-' + tab);
            if (target) target.classList.add('active');
        });
    });
});

// Modal "Cambiar estado"
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('btn-cambiar-estado');
    var modal = document.getElementById('modal-cambiar-estado');
    var btnClose = document.getElementById('modal-cambiar-estado-close');
    var btnCancel = document.getElementById('modal-cambiar-estado-cancel');

    if (!btn || !modal) return;

    function openModal() {
        modal.style.display = 'flex';
    }
    function closeModal() {
        modal.style.display = 'none';
    }

    btn.addEventListener('click', openModal);
    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCancel) btnCancel.addEventListener('click', closeModal);

    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            closeModal();
        }
    });
});

// Modal "Nuevo albarÃ¡n"
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('btn-nuevo-albaran');
    var modal = document.getElementById('modal-nuevo-albaran');
    var btnClose = document.getElementById('modal-nuevo-albaran-close');
    var btnCancel = document.getElementById('modal-nuevo-albaran-cancel');
    var btnTipoPresu = document.getElementById('btn-albaran-tipo-presu');
    var btnTipoExt = document.getElementById('btn-albaran-tipo-ext');
    var secPresu = document.getElementById('albaran-section-presu');
    var secExt = document.getElementById('albaran-section-ext');

    if (!btn || !modal) return;

    function openModal() {
        modal.style.display = 'flex';
    }
    function closeModal() {
        modal.style.display = 'none';
    }
    function activarTipo(tipo) {
        if (!btnTipoPresu || !btnTipoExt || !secPresu || !secExt) return;
        if (tipo === 'presu') {
            secPresu.style.display = 'block';
            secExt.style.display = 'none';
            btnTipoPresu.style.background = '#0f172a';
            btnTipoPresu.style.color = '#e5e7eb';
            btnTipoExt.style.background = 'transparent';
            btnTipoExt.style.color = '#9ca3af';
        } else {
            secPresu.style.display = 'none';
            secExt.style.display = 'block';
            btnTipoExt.style.background = '#0f172a';
            btnTipoExt.style.color = '#e5e7eb';
            btnTipoPresu.style.background = 'transparent';
            btnTipoPresu.style.color = '#9ca3af';
        }
    }

    btn.addEventListener('click', openModal);
    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCancel) btnCancel.addEventListener('click', closeModal);
    if (btnTipoPresu) btnTipoPresu.addEventListener('click', function () { activarTipo('presu'); });
    if (btnTipoExt) btnTipoExt.addEventListener('click', function () { activarTipo('ext'); });

    // Tipo por defecto: partidas
    activarTipo('presu');

    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            closeModal();
        }
    });
});

// Autocompletado de productos en "Nuevo concepto" (Presupuesto)
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('nombre_partida');
    var hiddenId = document.getElementById('id_producto');
    var box = document.getElementById('autocomplete_productos');
    if (!input || !hiddenId || !box) return;

    var timer = null;

    function clearBox() {
        box.innerHTML = '';
        box.style.display = 'none';
    }

    function renderSuggestions(items) {
        if (!items || !items.length) {
            clearBox();
            return;
        }
        box.innerHTML = '';
        items.forEach(function (it) {
            var div = document.createElement('div');
            div.style.padding = '6px 8px';
            div.style.cursor = 'pointer';
            div.style.fontSize = '0.8rem';
            div.style.color = '#e5e7eb';
            div.style.borderBottom = '1px solid #111827';
            div.textContent = it.nombre + (it.referencia ? ' (' + it.referencia + ')' : '');
            div.addEventListener('mouseenter', function () {
                div.style.background = '#111827';
            });
            div.addEventListener('mouseleave', function () {
                div.style.background = 'transparent';
            });
            div.addEventListener('click', function () {
                input.value = it.nombre;
                hiddenId.value = String(it.id_producto || '');
                clearBox();
            });
            box.appendChild(div);
        });
        box.style.display = 'block';
    }

    input.addEventListener('input', function () {
        var q = input.value.trim();
        hiddenId.value = '';
        if (timer) window.clearTimeout(timer);
        if (q.length < 2) {
            clearBox();
            return;
        }
        timer = window.setTimeout(function () {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'productos-search.php?q=' + encodeURIComponent(q), true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            renderSuggestions(Array.isArray(data) ? data : []);
                        } catch (e) {
                            clearBox();
                        }
                    } else {
                        clearBox();
                    }
                }
            };
            xhr.send();
        }, 250);
    });

    document.addEventListener('click', function (e) {
        if (!box.contains(e.target) && e.target !== input) {
            clearBox();
        }
    });
});
</script>
</html>


