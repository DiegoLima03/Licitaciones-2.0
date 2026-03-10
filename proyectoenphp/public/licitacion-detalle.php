<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Repositories/TendersRepository.php';
require_once __DIR__ . '/../src/Repositories/DeliveriesRepository.php';
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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$licitacion = null;
$loadError = null;
$entregas = [];
$tiposGasto = [];
$tiposLicitacion = [];
$selfUrl = (string)($_SERVER['PHP_SELF'] ?? 'licitacion-detalle.php');
$openMapProductsModal = false;
/** @var array<int, array<string,mixed>> $pendingPartidasSinProducto */
$pendingPartidasSinProducto = [];
/** @var array<int, string> $estadosLineaEntrega */
$estadosLineaEntrega = ['EN ESPERA', 'ENTREGADO', 'FACTURADO'];
$estadoBloqueoPresupuestoDesde = 4; // Desde "Presentada" el presupuesto queda bloqueado.
$requestedWith = mb_strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
$acceptHeader = mb_strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$isAjaxRequest = $requestedWith === 'xmlhttprequest'
    || strpos($acceptHeader, 'application/json') !== false;

/**
 * @param array<string, mixed> $payload
 */
function jsonResponseAndExit(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @param array<int, int> $ids
 * @return array<int, int> mapa [id => id]
 */
function fetchExistingProductIds(\PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids, static fn (int $v): bool => $v > 0)));
    if ($ids === []) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($ids as $idx => $idValue) {
        $ph = ':pid_' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $idValue;
    }

    $sql = sprintf(
        'SELECT id FROM tbl_productos WHERE id IN (%s)',
        implode(', ', $placeholders)
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    /** @var array<int, int> $out */
    $out = [];
    while ($row = $stmt->fetch()) {
        if (isset($row['id'])) {
            $id = (int)$row['id'];
            $out[$id] = $id;
        }
    }

    return $out;
}

/**
 * Crea (si no existe) un producto de catalogo a partir de texto libre y devuelve su id.
 */
function ensureCatalogProductIdForFreeText(\PDO $pdo, string $organizationId, string $freeText): int
{
    $nombre = trim($freeText);
    if ($nombre === '') {
        throw new \InvalidArgumentException('El texto libre del producto esta vacio.');
    }

    $sqlFind = 'SELECT id FROM tbl_productos WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(:nombre)) LIMIT 1';
    $stmtFind = $pdo->prepare($sqlFind);
    $stmtFind->execute([
        ':nombre' => $nombre,
    ]);
    $existing = $stmtFind->fetchColumn();
    if ($existing !== false && $existing !== null) {
        return (int)$existing;
    }

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        // Reintento dentro de transaccion para minimizar duplicados en concurrencia.
        $stmtFind->execute([
            ':nombre' => $nombre,
        ]);
        $existingTx = $stmtFind->fetchColumn();
        if ($existingTx !== false && $existingTx !== null) {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return (int)$existingTx;
        }

        $nextId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM tbl_productos')->fetchColumn();
        $nextIdErp = (int)$pdo->query('SELECT COALESCE(MAX(id_erp), 0) + 1 AS next_id_erp FROM tbl_productos')->fetchColumn();

        $sqlInsert = 'INSERT INTO tbl_productos
            (id, id_erp, id_grupo_articulo, id_proveedor, paquete, nombre, organization_id)
            VALUES
            (:id, :id_erp, 0, 0, 0, :nombre, :org)';
        $stmtInsert = $pdo->prepare($sqlInsert);
        $stmtInsert->execute([
            ':id' => $nextId,
            ':id_erp' => $nextIdErp,
            ':nombre' => $nombre,
            ':org' => $organizationId,
        ]);

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        return $nextId;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Fallback: si otro proceso lo insertÃƒÂ³ justo antes, recuperar el id y continuar.
        $stmtFind->execute([
            ':nombre' => $nombre,
        ]);
        $existingAfter = $stmtFind->fetchColumn();
        if ($existingAfter !== false && $existingAfter !== null) {
            return (int)$existingAfter;
        }

        throw $e;
    }
}

/**
 * Devuelve los nombres de lote configurados en lotes_config.
 *
 * @param mixed $raw
 * @return array<int, string>
 */
function extractConfiguredLotes($raw): array
{
    $decoded = null;
    if (is_array($raw)) {
        $decoded = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        try {
            $tmp = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($tmp)) {
                $decoded = $tmp;
            }
        } catch (\Throwable $e) {
            $decoded = null;
        }
    }

    if (!is_array($decoded)) {
        return [];
    }

    $names = [];
    foreach ($decoded as $item) {
        $name = '';
        if (is_array($item)) {
            $name = trim((string)($item['nombre'] ?? ''));
        } elseif (is_string($item) || is_numeric($item)) {
            $name = trim((string)$item);
        }
        if ($name === '') {
            continue;
        }
        $names[mb_strtolower($name)] = $name;
    }

    return array_values($names);
}

try {
    if ($id <= 0) {
        throw new \InvalidArgumentException('Id de licitacion no valido.');
    }

    $repo = new TendersRepository($organizationId);
    $deliveriesRepo = new DeliveriesRepository($organizationId);
    $catalogsRepo = new CatalogsRepository($organizationId);

    try {
        $tiposLicitacion = $catalogsRepo->getTipos();
    } catch (\Throwable $e) {
        $tiposLicitacion = [];
    }

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
        // 1) Nuevo albaran
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
                $loadError = 'La fecha y el codigo de albaran son obligatorios.';
            } elseif (!is_array($lineasPresuRaw) || !is_array($lineasExtRaw)) {
                $loadError = 'Formato de lineas de albaran invalido.';
            } else {
                // Lineas presupuestadas
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

                // Lineas de gasto extraordinario
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

                    // Si el tipo de gasto se llama "Otros", anadimos la nota al campo observaciones
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
                            $observacionesOtros[] = 'Linea extra (' . ($idx + 1) . ', Otros): ' . $textoLibre;
                        }
                    }
                }

                if ($lineas === []) {
                    $loadError = 'Debes indicar al menos una linea valida de albaran.';
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
                        $loadError = 'Error al registrar el albaran: ' . $e->getMessage();
                    }
                }
            }
        // 2) Vincular partidas sin producto de catalogo
        } elseif (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'vincular_productos') {
            $detalleIdsRaw = $_POST['detalle_ids'] ?? [];
            $idProductoMapRaw = $_POST['id_producto_map'] ?? [];

            if (!is_array($detalleIdsRaw) || !is_array($idProductoMapRaw)) {
                $loadError = 'Formato invalido para vincular productos.';
                $openMapProductsModal = true;
            } else {
                /** @var array<int, int> $detalleIds */
                $detalleIds = [];
                foreach ($detalleIdsRaw as $dRaw) {
                    $detalleId = (int)$dRaw;
                    if ($detalleId > 0) {
                        $detalleIds[$detalleId] = $detalleId;
                    }
                }

                if ($detalleIds === []) {
                    $loadError = 'No hay partidas para vincular.';
                    $openMapProductsModal = true;
                } else {
                    /** @var array<int, int> $requestedProductIds */
                    $requestedProductIds = [];
                    foreach ($detalleIds as $detalleId) {
                        $pid = isset($idProductoMapRaw[(string)$detalleId]) ? (int)$idProductoMapRaw[(string)$detalleId] : 0;
                        if ($pid > 0) {
                            $requestedProductIds[$pid] = $pid;
                        }
                    }

                    $pdoProducts = Database::getConnection();
                    $validProductIds = fetchExistingProductIds(
                        $pdoProducts,
                        array_values($requestedProductIds)
                    );

                    /** @var array<int, int> $missingPartidas */
                    $missingPartidas = [];
                    foreach ($detalleIds as $detalleId) {
                        $pid = isset($idProductoMapRaw[(string)$detalleId]) ? (int)$idProductoMapRaw[(string)$detalleId] : 0;
                        if ($pid <= 0 || !isset($validProductIds[$pid])) {
                            $missingPartidas[$detalleId] = $detalleId;
                        }
                    }

                    if ($missingPartidas !== []) {
                        $loadError = 'Debes seleccionar un producto valido para cada partida pendiente.';
                        $openMapProductsModal = true;
                    } else {
                        foreach ($detalleIds as $detalleId) {
                            $pid = (int)$idProductoMapRaw[(string)$detalleId];
                            $partidaActual = $repo->getPartida($id, $detalleId);
                            if ($partidaActual === null) {
                                continue;
                            }
                            $repo->updatePartida($id, $detalleId, [
                                'id_producto' => $pid,
                                'nombre_producto_libre' => null,
                            ]);
                        }

                        header('Location: ' . $selfUrl . '?id=' . $id . '&mapped=1');
                        exit;
                    }
                }
            }
        // 3) Cambio de estado de una linea de entrega
        } elseif (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'actualizar_estado_linea') {
            $idRealLinea = isset($_POST['id_real']) ? (int)$_POST['id_real'] : 0;
            $estadoLineaRaw = trim((string)($_POST['estado_linea'] ?? ''));
            $estadoLinea = mb_strtoupper($estadoLineaRaw);

            if ($idRealLinea <= 0) {
                $loadError = 'Linea de entrega invalida.';
                if ($isAjaxRequest) {
                    jsonResponseAndExit([
                        'ok' => false,
                        'message' => $loadError,
                    ], 422);
                }
            } elseif (!in_array($estadoLinea, $estadosLineaEntrega, true)) {
                $loadError = 'Estado de linea invalido.';
                if ($isAjaxRequest) {
                    jsonResponseAndExit([
                        'ok' => false,
                        'message' => $loadError,
                    ], 422);
                }
            } else {
                try {
                    $deliveriesRepo->updateDeliveryLine($idRealLinea, [
                        'estado' => $estadoLinea,
                    ]);
                    if ($isAjaxRequest) {
                        jsonResponseAndExit([
                            'ok' => true,
                            'message' => 'Estado actualizado.',
                            'id_real' => $idRealLinea,
                            'estado' => $estadoLinea,
                        ]);
                    }
                    header('Location: ' . $selfUrl . '?id=' . $id . '&tab=ejecucion');
                    exit;
                } catch (\Throwable $e) {
                    $loadError = 'Error actualizando el estado de la linea: ' . $e->getMessage();
                    if ($isAjaxRequest) {
                        jsonResponseAndExit([
                            'ok' => false,
                            'message' => $loadError,
                        ], 500);
                    }
                }
            }
        // 4) Cambio de cobrado de una linea de entrega
        } elseif (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'actualizar_cobrado_linea') {
            $idRealLinea = isset($_POST['id_real']) ? (int)$_POST['id_real'] : 0;
            $cobradoLineaRaw = (string)($_POST['cobrado_linea'] ?? '');
            $cobradoLinea = $cobradoLineaRaw === '1' ? 1 : 0;

            if ($idRealLinea <= 0) {
                $loadError = 'Linea de entrega invalida.';
                if ($isAjaxRequest) {
                    jsonResponseAndExit([
                        'ok' => false,
                        'message' => $loadError,
                    ], 422);
                }
            } elseif ($cobradoLineaRaw !== '0' && $cobradoLineaRaw !== '1') {
                $loadError = 'Valor de cobrado invalido.';
                if ($isAjaxRequest) {
                    jsonResponseAndExit([
                        'ok' => false,
                        'message' => $loadError,
                    ], 422);
                }
            } else {
                try {
                    $deliveriesRepo->updateDeliveryLine($idRealLinea, [
                        'cobrado' => $cobradoLinea,
                    ]);
                    if ($isAjaxRequest) {
                        jsonResponseAndExit([
                            'ok' => true,
                            'message' => 'Cobrado actualizado.',
                            'id_real' => $idRealLinea,
                            'cobrado' => $cobradoLinea,
                        ]);
                    }
                    header('Location: ' . $selfUrl . '?id=' . $id . '&tab=ejecucion');
                    exit;
                } catch (\Throwable $e) {
                    $loadError = 'Error actualizando el cobrado de la linea: ' . $e->getMessage();
                    if ($isAjaxRequest) {
                        jsonResponseAndExit([
                            'ok' => false,
                            'message' => $loadError,
                        ], 500);
                    }
                }
            }
        // 5) Acciones de la tabla interactiva de presupuesto (editar/anadir/eliminar en sitio)
        } elseif (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'budget_table_action') {
            $licitacionActual = $repo->getById($id);
            if ($licitacionActual === null) {
                $loadError = 'Licitacion no encontrada.';
            } else {
                $estadoLicitacionActual = (int)($licitacionActual['id_estado'] ?? 0);
                if ($estadoLicitacionActual >= $estadoBloqueoPresupuestoDesde) {
                    header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto&budget_locked=1');
                    exit;
                }

                $idTipoLicitacionActual = isset($licitacionActual['id_tipolicitacion'])
                    ? (int)$licitacionActual['id_tipolicitacion']
                    : 0;
                $showPmaxuActual = in_array($idTipoLicitacionActual, [1, 2, 4, 5], true);
                $showUnidadesActual = !in_array($idTipoLicitacionActual, [2, 4], true);
                $isTipoDescuentoActual = in_array($idTipoLicitacionActual, [2, 5], true);
                $lotesConfiguradosActual = extractConfiguredLotes($licitacionActual['lotes_config'] ?? null);
                $usaLotesActual = count($lotesConfiguradosActual) > 1;

                $parseDecimal = static function ($raw): float {
                    $txt = trim((string)$raw);
                    if ($txt === '') {
                        return 0.0;
                    }
                    return (float)str_replace(',', '.', $txt);
                };
                $parseDiscountPct = static function ($raw) use ($parseDecimal): float {
                    $pct = $parseDecimal($raw);
                    if (!is_finite($pct) || $pct < 0) {
                        return 0.0;
                    }
                    return $pct;
                };
                $calcPvuFromPmaxu = static function (float $pmaxu, float $discountPct): float {
                    if ($pmaxu <= 0.0) {
                        return 0.0;
                    }
                    $factor = 1.0 - ($discountPct / 100.0);
                    if ($factor < 0.0) {
                        $factor = 0.0;
                    }
                    return round($pmaxu * $factor, 2);
                };

                $descuentoGlobalActual = isset($licitacionActual['descuento_global'])
                    ? (float)$licitacionActual['descuento_global']
                    : 0.0;
                if ($isTipoDescuentoActual) {
                    $descuentoGlobalActual = $parseDiscountPct($_POST['descuento_global'] ?? $descuentoGlobalActual);
                    $descuentoGlobalActual = round($descuentoGlobalActual, 2);
                    $descuentoActualDb = isset($licitacionActual['descuento_global'])
                        ? round((float)$licitacionActual['descuento_global'], 2)
                        : null;
                    if ($descuentoActualDb === null || abs($descuentoActualDb - $descuentoGlobalActual) > 0.0001) {
                        $repo->update($id, ['descuento_global' => $descuentoGlobalActual]);
                    }
                }

                // Eliminar fila
                $eliminarId = isset($_POST['eliminar_id']) ? (int)$_POST['eliminar_id'] : 0;
                if ($eliminarId > 0) {
                    try {
                        $repo->deletePartida($id, $eliminarId);
                    } catch (\Throwable $e) {
                        $loadError = 'No se pudo eliminar la linea: ' . $e->getMessage();
                    }
                    if ($loadError === null) {
                        header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto');
                        exit;
                    }
                }

                /** @var array<int|string, mixed> $lineasRaw */
                $lineasRaw = isset($_POST['lineas']) && is_array($_POST['lineas']) ? $_POST['lineas'] : [];
                /** @var array<int|string, mixed> $lineasNuevasRaw */
                $lineasNuevasRaw = isset($_POST['lineas_nuevas']) && is_array($_POST['lineas_nuevas']) ? $_POST['lineas_nuevas'] : [];
                $pdoProducts = Database::getConnection();

                // Guardar una fila existente
                $guardarId = isset($_POST['guardar_id']) ? (int)$_POST['guardar_id'] : 0;
                if ($guardarId > 0) {
                    $linea = isset($lineasRaw[$guardarId]) && is_array($lineasRaw[$guardarId])
                        ? $lineasRaw[$guardarId]
                        : null;

                    if ($linea === null) {
                        $loadError = 'No se encontro la linea a guardar.';
                    } else {
                        $nombreLinea = trim((string)($linea['nombre_partida'] ?? ''));
                        $idProductoLinea = isset($linea['id_producto']) ? (int)$linea['id_producto'] : 0;
                        $loteLinea = trim((string)($linea['lote'] ?? ''));
                        if (!$usaLotesActual) {
                            $loteLinea = 'General';
                        } elseif ($loteLinea === '') {
                            $loteLinea = $lotesConfiguradosActual[0] ?? 'Lote 1';
                        }

                        $unidades = $parseDecimal($linea['unidades'] ?? '');
                        $pmaxu = $parseDecimal($linea['pmaxu'] ?? '');
                        $pvu = $parseDecimal($linea['pvu'] ?? '');
                        $pcu = $parseDecimal($linea['pcu'] ?? '');
                        if (!$showUnidadesActual) {
                            $unidades = 0.0;
                        }
                        if (!$showPmaxuActual) {
                            $pmaxu = 0.0;
                        }
                        if ($isTipoDescuentoActual) {
                            $pvu = $calcPvuFromPmaxu($pmaxu, $descuentoGlobalActual);
                        }

                        $payload = [
                            'lote' => $loteLinea !== '' ? $loteLinea : 'General',
                            'unidades' => $unidades,
                            'pmaxu' => $pmaxu,
                            'pvu' => $pvu,
                            'pcu' => $pcu,
                        ];

                        if ($idProductoLinea > 0) {
                            $validProductIds = fetchExistingProductIds($pdoProducts, [$idProductoLinea]);
                            if (!isset($validProductIds[$idProductoLinea])) {
                                $loadError = 'El producto seleccionado no existe en el catalogo.';
                            } else {
                                $payload['id_producto'] = $idProductoLinea;
                                $payload['nombre_producto_libre'] = null;
                            }
                        } else {
                            $payload['id_producto'] = null;
                            $payload['nombre_producto_libre'] = $nombreLinea !== '' ? $nombreLinea : null;
                        }

                        if ($loadError === null) {
                            $repo->updatePartida($id, $guardarId, $payload);
                            header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto');
                            exit;
                        }
                    }
                }

                // Guardar toda la tabla
                if ($loadError === null && isset($_POST['guardar_todo']) && $_POST['guardar_todo'] !== '') {
                    foreach ($lineasRaw as $idDetalleRaw => $lineaRaw) {
                        if (!is_array($lineaRaw)) {
                            continue;
                        }
                        $idDetalle = (int)$idDetalleRaw;
                        if ($idDetalle <= 0) {
                            continue;
                        }

                        $nombreLinea = trim((string)($lineaRaw['nombre_partida'] ?? ''));
                        $idProductoLinea = isset($lineaRaw['id_producto']) ? (int)$lineaRaw['id_producto'] : 0;
                        $loteLinea = trim((string)($lineaRaw['lote'] ?? ''));
                        if (!$usaLotesActual) {
                            $loteLinea = 'General';
                        } elseif ($loteLinea === '') {
                            $loteLinea = $lotesConfiguradosActual[0] ?? 'Lote 1';
                        }

                        $unidades = $parseDecimal($lineaRaw['unidades'] ?? '');
                        $pmaxu = $parseDecimal($lineaRaw['pmaxu'] ?? '');
                        $pvu = $parseDecimal($lineaRaw['pvu'] ?? '');
                        $pcu = $parseDecimal($lineaRaw['pcu'] ?? '');
                        if (!$showUnidadesActual) {
                            $unidades = 0.0;
                        }
                        if (!$showPmaxuActual) {
                            $pmaxu = 0.0;
                        }
                        if ($isTipoDescuentoActual) {
                            $pvu = $calcPvuFromPmaxu($pmaxu, $descuentoGlobalActual);
                        }

                        $payload = [
                            'lote' => $loteLinea !== '' ? $loteLinea : 'General',
                            'unidades' => $unidades,
                            'pmaxu' => $pmaxu,
                            'pvu' => $pvu,
                            'pcu' => $pcu,
                        ];

                        if ($idProductoLinea > 0) {
                            $validProductIds = fetchExistingProductIds($pdoProducts, [$idProductoLinea]);
                            if (!isset($validProductIds[$idProductoLinea])) {
                                $loadError = 'Hay una linea con producto invalido. Revisa los datos.';
                                break;
                            }
                            $payload['id_producto'] = $idProductoLinea;
                            $payload['nombre_producto_libre'] = null;
                        } else {
                            $payload['id_producto'] = null;
                            $payload['nombre_producto_libre'] = $nombreLinea !== '' ? $nombreLinea : null;
                        }

                        $repo->updatePartida($id, $idDetalle, $payload);
                    }

                    if ($loadError === null) {
                        foreach ($lineasNuevasRaw as $lineaNuevaRaw) {
                            if (!is_array($lineaNuevaRaw)) {
                                continue;
                            }

                            $nombrePartidaNueva = trim((string)($lineaNuevaRaw['nombre_partida'] ?? ''));
                            $idProductoNuevo = isset($lineaNuevaRaw['id_producto']) ? (int)$lineaNuevaRaw['id_producto'] : 0;
                            $loteNuevo = trim((string)($lineaNuevaRaw['lote'] ?? ''));
                            if (!$usaLotesActual) {
                                $loteNuevo = 'General';
                            } elseif ($loteNuevo === '') {
                                $loteNuevo = $lotesConfiguradosActual[0] ?? 'Lote 1';
                            }

                            $unidadesNueva = $parseDecimal($lineaNuevaRaw['unidades'] ?? '');
                            $pmaxuNueva = $parseDecimal($lineaNuevaRaw['pmaxu'] ?? '');
                            $pvuNueva = $parseDecimal($lineaNuevaRaw['pvu'] ?? '');
                            $pcuNueva = $parseDecimal($lineaNuevaRaw['pcu'] ?? '');
                            if (!$showUnidadesActual) {
                                $unidadesNueva = 0.0;
                            }
                            if (!$showPmaxuActual) {
                                $pmaxuNueva = 0.0;
                            }
                            if ($isTipoDescuentoActual) {
                                $pvuNueva = $calcPvuFromPmaxu($pmaxuNueva, $descuentoGlobalActual);
                            }

                            $hasNumericDataNueva = ($showUnidadesActual && $unidadesNueva > 0)
                                || ($showPmaxuActual && $pmaxuNueva > 0)
                                || $pvuNueva > 0
                                || $pcuNueva > 0;

                            if ($nombrePartidaNueva === '' || !$hasNumericDataNueva) {
                                continue;
                            }

                            $payloadNueva = [
                                'lote' => $loteNuevo !== '' ? $loteNuevo : 'General',
                                'unidades' => $unidadesNueva,
                                'pmaxu' => $pmaxuNueva,
                                'pvu' => $pvuNueva,
                                'pcu' => $pcuNueva,
                                'activo' => 1,
                            ];

                            if ($idProductoNuevo > 0) {
                                $validProductIds = fetchExistingProductIds($pdoProducts, [$idProductoNuevo]);
                                if (!isset($validProductIds[$idProductoNuevo])) {
                                    $loadError = 'Hay una nueva linea con producto invalido. Revisa los datos.';
                                    break;
                                }
                                $payloadNueva['id_producto'] = $idProductoNuevo;
                                $payloadNueva['nombre_producto_libre'] = null;
                            } else {
                                $payloadNueva['id_producto'] = null;
                                $payloadNueva['nombre_producto_libre'] = $nombrePartidaNueva;
                            }

                            $repo->addPartida($id, $payloadNueva);
                        }
                    }

                    if ($loadError === null) {
                        header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto');
                        exit;
                    }
                }

            }
        // 6) Cambio de estado de licitacion desde el popup
        } elseif (isset($_POST['estado']) && $_POST['estado'] !== '') {
            $estadoRaw = $_POST['estado'];
            if (is_string($estadoRaw) || is_numeric($estadoRaw)) {
                $estadoId = (int)$estadoRaw;
                if ($estadoId > 0) {
                    // Obtener estado actual respetando RLS
                    $actual = $repo->getById($id);
                    if ($actual === null) {
                        $loadError = 'Licitacion no encontrada.';
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
                            $loadError = 'Transicion de estado no permitida desde el estado actual.';
                        } else {
                            if ($estadoId === 4) {
                                $detallePresentacion = $repo->getTenderWithDetails($id);
                                $partidasPresentacion = is_array($detallePresentacion['partidas'] ?? null)
                                    ? $detallePresentacion['partidas']
                                    : [];

                                $tienePartidasActivas = false;
                                foreach ($partidasPresentacion as $pp) {
                                    if (!is_array($pp)) {
                                        continue;
                                    }
                                    $activo = array_key_exists('activo', $pp) ? (bool)$pp['activo'] : true;
                                    if ($activo) {
                                        $tienePartidasActivas = true;
                                        break;
                                    }
                                }

                                if (!$tienePartidasActivas) {
                                    $loadError = 'No puedes pasar a Presentada sin al menos una linea presupuestada.';
                                } else {
                                    $repo->update($id, ['id_estado' => $estadoId]);
                                    header('Location: ' . $selfUrl . '?id=' . $id);
                                    exit;
                                }
                            } elseif ($estadoId === 5) {
                                $detalle = $repo->getTenderWithDetails($id);
                                $partidasAdjudicacion = is_array($detalle['partidas'] ?? null)
                                    ? $detalle['partidas']
                                    : [];

                                // Conversion automatica de texto libre a producto de catalogo.
                                $pdoProducts = Database::getConnection();
                                foreach ($partidasAdjudicacion as $p) {
                                    if (!is_array($p)) {
                                        continue;
                                    }
                                    $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
                                    if (!$activo) {
                                        continue;
                                    }
                                    $idDetallePartida = isset($p['id_detalle']) ? (int)$p['id_detalle'] : 0;
                                    $idProdPartida = $p['id_producto'] ?? null;
                                    if ($idDetallePartida <= 0 || ($idProdPartida !== null && (int)$idProdPartida > 0)) {
                                        continue;
                                    }

                                    $nombreLibre = trim((string)($p['nombre_producto_libre'] ?? ''));
                                    if ($nombreLibre === '') {
                                        continue;
                                    }

                                    try {
                                        $productIdCreated = ensureCatalogProductIdForFreeText(
                                            $pdoProducts,
                                            $organizationId,
                                            $nombreLibre
                                        );
                                        $repo->updatePartida($id, $idDetallePartida, [
                                            'id_producto' => $productIdCreated,
                                            'nombre_producto_libre' => null,
                                        ]);
                                    } catch (\Throwable $e) {
                                        // Si falla la creacion automatica, luego se pedira mapeo manual.
                                    }
                                }

                                // Recargar detalle tras intentar convertir texto libre.
                                $detalle = $repo->getTenderWithDetails($id);
                                $partidasAdjudicacion = is_array($detalle['partidas'] ?? null)
                                    ? $detalle['partidas']
                                    : [];

                                /** @var array<int, array<string,mixed>> $sinProducto */
                                $sinProducto = [];
                                foreach ($partidasAdjudicacion as $p) {
                                    if (!is_array($p)) {
                                        continue;
                                    }
                                    $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
                                    if (!$activo) {
                                        continue;
                                    }
                                    $idProdPartida = $p['id_producto'] ?? null;
                                    if ($idProdPartida === null || (int)$idProdPartida <= 0) {
                                        $sinProducto[] = $p;
                                    }
                                }

                                if ($sinProducto !== []) {
                                    $pendingPartidasSinProducto = $sinProducto;
                                    $openMapProductsModal = true;
                                    $loadError = 'Para adjudicar, todas las lineas activas deben tener un producto del catalogo (id_producto). '
                                        . 'Vincula las partidas pendientes y vuelve a intentar.';
                                } else {
                                    $repo->update($id, ['id_estado' => $estadoId]);
                                    header('Location: ' . $selfUrl . '?id=' . $id);
                                    exit;
                                }
                            } else {
                                $repo->update($id, ['id_estado' => $estadoId]);
                                header('Location: ' . $selfUrl . '?id=' . $id);
                                exit;
                            }
                        }
                    }
                } else {
                    $loadError = 'Parametro de estado invalido.';
                }
            } else {
                $loadError = 'Parametro de estado invalido.';
            }
        } else {
            // 6) Alta rapida de partida de presupuesto
            $licitacionActual = $repo->getById($id);
            $estadoLicitacionActual = (int)($licitacionActual['id_estado'] ?? 0);
            if ($estadoLicitacionActual >= $estadoBloqueoPresupuestoDesde) {
                header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto&budget_locked=1');
                exit;
            }

            $nombrePartida = trim((string)($_POST['nombre_partida'] ?? ''));
            $idProductoPost = isset($_POST['id_producto']) ? (int)$_POST['id_producto'] : 0;
            $lote = trim((string)($_POST['lote'] ?? ''));
            $idTipoLicitacionActual = isset($licitacionActual['id_tipolicitacion'])
                ? (int)$licitacionActual['id_tipolicitacion']
                : 0;
            $showPmaxuActual = in_array($idTipoLicitacionActual, [1, 2, 4, 5], true);
            $showUnidadesActual = !in_array($idTipoLicitacionActual, [2, 4], true);
            $isTipoDescuentoActual = in_array($idTipoLicitacionActual, [2, 5], true);
            $lotesConfiguradosActual = extractConfiguredLotes($licitacionActual['lotes_config'] ?? null);
            $usaLotesActual = count($lotesConfiguradosActual) > 1;
            if (!$usaLotesActual) {
                $lote = 'General';
            } elseif ($lote === '') {
                $lote = $lotesConfiguradosActual[0] ?? 'Lote 1';
            }
            $unidadesRaw = (string)($_POST['unidades'] ?? '');
            $pmaxuRaw = (string)($_POST['pmaxu'] ?? '');
            $pvuRaw = (string)($_POST['pvu'] ?? '');
            $pcuRaw = (string)($_POST['pcu'] ?? '');

            $unidades = $unidadesRaw !== '' ? (float)str_replace(',', '.', $unidadesRaw) : 0.0;
            $pmaxu = $pmaxuRaw !== '' ? (float)str_replace(',', '.', $pmaxuRaw) : 0.0;
            $pvu = $pvuRaw !== '' ? (float)str_replace(',', '.', $pvuRaw) : 0.0;
            $pcu = $pcuRaw !== '' ? (float)str_replace(',', '.', $pcuRaw) : 0.0;
            if (!$showUnidadesActual) {
                $unidades = 0.0;
            }
            if (!$showPmaxuActual) {
                $pmaxu = 0.0;
            }
            if ($isTipoDescuentoActual) {
                $descuentoGlobalActual = isset($licitacionActual['descuento_global'])
                    ? (float)$licitacionActual['descuento_global']
                    : 0.0;
                if (isset($_POST['descuento_global'])) {
                    $descuentoGlobalActual = (float)str_replace(',', '.', (string)$_POST['descuento_global']);
                    if (!is_finite($descuentoGlobalActual) || $descuentoGlobalActual < 0.0) {
                        $descuentoGlobalActual = 0.0;
                    }
                    $descuentoGlobalActual = round($descuentoGlobalActual, 2);
                }
                $factorDescuento = 1.0 - ($descuentoGlobalActual / 100.0);
                if ($factorDescuento < 0.0) {
                    $factorDescuento = 0.0;
                }
                $pvu = $pmaxu > 0.0 ? round($pmaxu * $factorDescuento, 2) : 0.0;
            }

            $hasNumericData = ($showUnidadesActual && $unidades > 0)
                || ($showPmaxuActual && $pmaxu > 0)
                || $pvu > 0
                || $pcu > 0;

            if ($nombrePartida !== '' && $hasNumericData) {
                $payload = [
                    'lote' => $lote !== '' ? $lote : 'General',
                    'unidades' => $unidades,
                    'pvu' => $pvu,
                    'pcu' => $pcu,
                    'pmaxu' => $pmaxu,
                    'activo' => 1,
                ];

                if ($idProductoPost > 0) {
                    $pdoProducts = Database::getConnection();
                    $validProductIds = fetchExistingProductIds($pdoProducts, [$idProductoPost]);
                    if (!isset($validProductIds[$idProductoPost])) {
                        $loadError = 'El producto seleccionado no existe en el catalogo.';
                    } else {
                        $payload['id_producto'] = $idProductoPost;
                        $payload['nombre_producto_libre'] = null;
                    }
                } else {
                    $payload['nombre_producto_libre'] = $nombrePartida;
                }

                if ($loadError === null) {
                    $repo->addPartida($id, $payload);
                    // Redirigir para evitar re-envio del formulario al refrescar.
                    header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto');
                    exit;
                }
            }
        }
    }

    $licitacion = $repo->getTenderWithDetails($id);
    if ($licitacion === null) {
        $loadError = 'Licitacion no encontrada.';
    } else {
        // Cargar entregas (albaranes) para pestana de Ejecucion / Remaining
        $entregas = $deliveriesRepo->listDeliveries($id);
    }
} catch (\Throwable $e) {
    $loadError = $e->getMessage();
}

$mappedSuccess = isset($_GET['mapped']) && (string)$_GET['mapped'] === '1';
/** @var array<int, array<string,mixed>> $partidasSinProductoCatalogo */
$partidasSinProductoCatalogo = [];
if ($licitacion !== null) {
    $partidasTmp = is_array($licitacion['partidas'] ?? null) ? $licitacion['partidas'] : [];
    foreach ($partidasTmp as $p) {
        if (!is_array($p)) {
            continue;
        }
        $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
        if (!$activo) {
            continue;
        }
        $idProdPartida = $p['id_producto'] ?? null;
        if ($idProdPartida === null || (int)$idProdPartida <= 0) {
            $partidasSinProductoCatalogo[] = $p;
        }
    }
}
if ($pendingPartidasSinProducto === []) {
    $pendingPartidasSinProducto = $partidasSinProductoCatalogo;
}

// Mostrar aviso de vinculación ERP solo cuando realmente se requiere (al intentar adjudicar).
$showMissingProductsBanner = $openMapProductsModal && $partidasSinProductoCatalogo !== [];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle licitacion</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: var(--vz-crema);
            color: var(--vz-negro);
        }
        .layout {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 220px;
            background: linear-gradient(180deg, #5c472f, var(--vz-marron1) 52%, var(--vz-negro));
            border-right: 1px solid rgba(229, 226, 220, 0.25);
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
            color: #efe7bf;
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
            color: var(--vz-crema);
            text-decoration: none;
        }
        .nav-link:hover {
            background-color: rgba(229, 226, 220, 0.14);
        }
        .nav-link.active {
            background: var(--vz-crema);
            color: var(--vz-negro);
            font-weight: 600;
        }
        .sidebar-footer {
            margin-top: 24px;
            font-size: 0.75rem;
            color: rgba(229, 226, 220, 0.7);
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
            background: var(--vz-verde);
            border-bottom: 1px solid rgba(16, 24, 14, 0.24);
        }
        header h1 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--vz-crema);
        }
        .user-info {
            font-size: 0.85rem;
            text-align: right;
            color: var(--vz-crema);
        }
        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            background-color: rgba(229, 226, 220, 0.96);
            color: var(--vz-marron1);
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
            background-color: var(--vz-blanco);
            border-radius: 12px;
            padding: 18px 18px 20px;
            box-shadow: 0 2px 8px rgba(16, 24, 14, 0.08);
            border: 1px solid var(--vz-marron2);
            /* Mantener altura visual constante entre pestanas */
            min-height: 420px;
            display: flex;
            flex-direction: column;
            /* Asegurar mismo ancho en todas las pestanas */
            width: 100%;
            box-sizing: border-box;
        }
        .card h2 {
            margin: 0 0 8px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .detail-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 6px;
        }
        .detail-title {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .detail-head-right {
            margin-left: auto;
            min-width: 350px;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
        }
        .detail-status-row {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }
        .detail-status-label {
            font-size: 0.8rem;
            color: #6b7280;
            font-weight: 600;
        }
        .detail-change-btn {
            border: 1px solid #1f2937;
            border-radius: 9999px;
            background: #020617;
            color: #e5e7eb;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 6px 14px;
            cursor: pointer;
            transition: background-color 140ms ease, transform 140ms ease;
        }
        .detail-change-btn:hover {
            background: #0b1324;
            transform: translateY(-1px);
        }
        .detail-type-cards {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .detail-type-card {
            min-width: 190px;
            border: 1px solid rgba(133, 114, 94, 0.45);
            border-radius: 12px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(245, 242, 235, 0.98) 100%);
            padding: 8px 12px;
            box-shadow: 0 2px 8px rgba(16, 24, 14, 0.06);
        }
        .detail-type-label {
            display: block;
            margin: 0 0 2px;
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--vz-marron2);
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .detail-type-value {
            display: block;
            font-size: 1rem;
            font-weight: 600;
            color: var(--vz-negro);
            line-height: 1.2;
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
            color: var(--vz-marron2);
            margin-bottom: 2px;
        }
        .meta-value {
            color: var(--vz-negro);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            font-size: 0.85rem;
        }
        th, td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(133, 114, 94, 0.35);
            text-align: left;
        }
        th {
            font-weight: 600;
            color: var(--vz-marron2);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        tbody tr:hover {
            background-color: var(--vz-verde-suave);
        }
        .back-link {
            display: inline-block;
            margin-bottom: 12px;
            font-size: 0.85rem;
            color: #5a4b2f;
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
            background-color: var(--vz-crema);
            padding: 2px;
            font-size: 0.8rem;
            color: var(--vz-marron2);
        }
        .tab-trigger {
            border: none;
            background: transparent;
            padding: 4px 10px;
            border-radius: 9999px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--vz-marron1);
        }
        .tab-trigger:hover {
            color: var(--vz-negro);
        }
        .tab-trigger.active {
            background-color: var(--vz-blanco);
            color: var(--vz-negro);
            box-shadow: 0 0 0 1px rgba(133, 114, 94, 0.4);
        }
        .tab-content {
            margin-top: 16px;
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        @media (max-width: 980px) {
            .detail-head {
                flex-direction: column;
                gap: 12px;
            }
            .detail-head-right {
                width: 100%;
                min-width: 0;
                align-items: flex-start;
                margin-left: 0;
            }
            .detail-status-row {
                justify-content: flex-start;
            }
            .detail-type-cards {
                width: 100%;
                justify-content: flex-start;
            }
            .detail-type-card {
                min-width: 210px;
                flex: 1 1 210px;
            }
            .detail-title {
                font-size: 1.5rem;
            }
        }
    </style>
    <link
        rel="stylesheet"
        href="assets/css/master-detail-theme.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/assets/css/master-detail-theme.css')); ?>"
    >
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
                <h1>Detalle de licitacion</h1>
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
                <a href="licitaciones.php" class="back-link">&larr; Volver al listado</a>

                <div class="card">
                    <?php if ($loadError !== null): ?>
                        <p style="color:#fecaca;font-size:0.9rem;">
                            Error cargando la licitacion: <?php echo htmlspecialchars($loadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </p>
                    <?php elseif ($licitacion === null): ?>
                        <p style="color:#9ca3af;font-size:0.9rem;">No se encontro la licitacion solicitada.</p>
                    <?php else: ?>
                        <?php
                        $estadoIdActual = (int)($licitacion['id_estado'] ?? 0);
                        $estadoNombres = [
                            1 => 'Borrador',
                            2 => 'Descartada',
                            3 => 'En analisis',
                            4 => 'Presentada',
                            5 => 'Adjudicada',
                            6 => 'No adjudicada',
                            7 => 'Terminada',
                        ];
                        $estadoActualLabel = $estadoNombres[$estadoIdActual] ?? 'Desconocido';

                        // Colores por estado (pill)
                        $estadoBg = 'rgba(71, 85, 105, 0.16)';
                        $estadoBorder = '#475569';
                        $estadoText = '#1f2937';
                        switch ($estadoIdActual) {
                            case 1: // Borrador
                                $estadoBg = 'rgba(148, 163, 184, 0.24)';
                                $estadoBorder = '#64748b';
                                $estadoText = '#1f2937';
                                break;
                            case 3: // En analisis
                                $estadoBg = 'rgba(59, 130, 246, 0.18)';
                                $estadoBorder = '#2563eb';
                                $estadoText = '#1e3a8a';
                                break;
                            case 4: // Presentada
                                $estadoBg = 'rgba(234, 179, 8, 0.24)';
                                $estadoBorder = '#b45309';
                                $estadoText = '#5f370e';
                                break;
                            case 5: // Adjudicada
                                $estadoBg = 'rgba(34, 197, 94, 0.22)';
                                $estadoBorder = '#15803d';
                                $estadoText = '#14532d';
                                break;
                            case 6: // No adjudicada
                            case 2: // Descartada
                                $estadoBg = 'rgba(239, 68, 68, 0.2)';
                                $estadoBorder = '#b91c1c';
                                $estadoText = '#7f1d1d';
                                break;
                            case 7: // Terminada
                                $estadoBg = 'rgba(6, 182, 212, 0.2)';
                                $estadoBorder = '#0e7490';
                                $estadoText = '#164e63';
                                break;
                        }

                        // Misma logica de flujo que en React:
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

                        $tipoProcedimiento = trim((string)($licitacion['tipo_procedimiento'] ?? 'ORDINARIO'));
                        if ($tipoProcedimiento === '') {
                            $tipoProcedimiento = 'ORDINARIO';
                        }

                        $idTipoLicitacion = isset($licitacion['id_tipolicitacion'])
                            ? (int)$licitacion['id_tipolicitacion']
                            : 0;
                        $tipoLicitacionFallbackById = [
                            1 => 'Unidades y Precio Maximo',
                            2 => 'Precio Unitario Max. (sin unidades, descuentos)',
                            3 => 'Unidades (sin precio unitario)',
                            4 => 'Precio Unitario Max. (sin unidades)',
                            5 => 'Unidades y Precio Maximo (Descuentos)',
                        ];
                        $tipoLicitacionNombre = '';
                        foreach ($tiposLicitacion as $tipoItem) {
                            if (!is_array($tipoItem)) {
                                continue;
                            }
                            $idTipoItem = isset($tipoItem['id_tipolicitacion'])
                                ? (int)$tipoItem['id_tipolicitacion']
                                : 0;
                            if ($idTipoItem === $idTipoLicitacion) {
                                $tipoLicitacionNombre = trim((string)($tipoItem['tipo'] ?? ''));
                                break;
                            }
                        }
                        $nombrePareceMojibake = preg_match('/[\x{00C2}\x{00C3}]/u', $tipoLicitacionNombre) === 1;
                        if ($idTipoLicitacion > 0 && ($tipoLicitacionNombre === '' || $nombrePareceMojibake) && isset($tipoLicitacionFallbackById[$idTipoLicitacion])) {
                            $tipoLicitacionNombre = $tipoLicitacionFallbackById[$idTipoLicitacion];
                        }
                        if ($tipoLicitacionNombre === '') {
                            if ($idTipoLicitacion > 0) {
                                $tipoLicitacionNombre = 'Tipo #' . $idTipoLicitacion;
                            } else {
                                $tipoLicitacionNombre = '-';
                            }
                        }
                        ?>
                        <div class="detail-head">
                            <h2 class="detail-title"><?php echo htmlspecialchars((string)($licitacion['nombre'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h2>
                            <div class="detail-head-right">
                                <div class="detail-status-row">
                                    <span class="detail-status-label">Estado:</span>
                                    <span
                                        style="display:inline-block;padding:3px 11px;border-radius:9999px;background-color:<?php echo $estadoBg; ?>;color:<?php echo $estadoText; ?>;font-size:0.9rem;font-weight:700;letter-spacing:0.01em;border:1px solid <?php echo $estadoBorder; ?>;"
                                    >
                                        <?php echo htmlspecialchars($estadoActualLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                    </span>
                                    <?php if ($transicionesDisponibles !== []): ?>
                                        <button
                                            type="button"
                                            id="btn-cambiar-estado"
                                            class="detail-change-btn"
                                        >
                                            Cambiar estado
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="detail-type-cards">
                                    <div class="detail-type-card">
                                        <span class="detail-type-label">Tipo licitacion</span>
                                        <span class="detail-type-value">
                                            <?php echo htmlspecialchars($tipoLicitacionNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                    <div class="detail-type-card">
                                        <span class="detail-type-label">Tipo procedimiento</span>
                                        <span class="detail-type-value">
                                            <?php echo htmlspecialchars($tipoProcedimiento, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

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
                                        >&times;</button>
                                    </div>
                                    <p style="margin:0 0 10px;font-size:0.8rem;color:#9ca3af;">
                                        Selecciona el nuevo estado al que quieres mover la licitacion.
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
                        <?php if ($pendingPartidasSinProducto !== []): ?>
                            <div
                                id="modal-vincular-productos"
                                data-open="<?php echo $openMapProductsModal ? '1' : '0'; ?>"
                                class="map-products-modal"
                                style="display:none;"
                            >
                                <div class="map-products-dialog">
                                    <div class="map-products-header">
                                        <h3>Vincular productos ERP</h3>
                                        <button type="button" id="modal-vincular-productos-close" class="map-products-close">&times;</button>
                                    </div>
                                    <p class="map-products-note">
                                        Para adjudicar, cada partida activa debe tener un producto de catalogo.
                                    </p>
                                    <form method="post" action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                        <input type="hidden" name="form_tipo" value="vincular_productos">
                                        <div class="map-products-list">
                                            <?php foreach ($pendingPartidasSinProducto as $pp): ?>
                                                <?php
                                                $detalleIdMap = (int)($pp['id_detalle'] ?? 0);
                                                if ($detalleIdMap <= 0) {
                                                    continue;
                                                }
                                                $loteMap = trim((string)($pp['lote'] ?? ''));
                                                if ($loteMap === '') {
                                                    $loteMap = 'General';
                                                }
                                                $nombreLibreMap = trim((string)($pp['nombre_producto_libre'] ?? ''));
                                                $prefillMap = $nombreLibreMap !== '' ? $nombreLibreMap : '';
                                                ?>
                                                <div class="map-products-row">
                                                    <input type="hidden" name="detalle_ids[]" value="<?php echo $detalleIdMap; ?>">
                                                    <input type="hidden" name="id_producto_map[<?php echo $detalleIdMap; ?>]" id="map-id-<?php echo $detalleIdMap; ?>" value="">
                                                    <div class="map-products-row-top">
                                                        <span>Lote: <?php echo htmlspecialchars($loteMap, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                                        <span>Partida #<?php echo $detalleIdMap; ?></span>
                                                    </div>
                                                    <div class="map-products-row-text">
                                                        Texto libre: <?php echo htmlspecialchars($nombreLibreMap !== '' ? $nombreLibreMap : '(sin texto)', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                    </div>
                                                    <div class="map-products-input-wrap">
                                                        <input
                                                            type="text"
                                                            class="map-product-input"
                                                            data-target-id="map-id-<?php echo $detalleIdMap; ?>"
                                                            data-detail-id="<?php echo $detalleIdMap; ?>"
                                                            value="<?php echo htmlspecialchars($prefillMap, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                            placeholder="Buscar producto en ERP..."
                                                            autocomplete="off"
                                                        />
                                                        <div id="map-suggest-<?php echo $detalleIdMap; ?>" class="map-product-suggestions"></div>
                                                        <div id="map-status-<?php echo $detalleIdMap; ?>" class="ac-status map-ac-status"></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="map-products-actions">
                                            <button type="button" id="modal-vincular-productos-cancel" class="map-products-cancel">Cancelar</button>
                                            <button type="submit" class="map-products-save">Guardar vinculos</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="meta-grid">
                            <div>
                                <span class="meta-label">Nro expediente</span>
                                <span class="meta-value">
                                    <?php echo htmlspecialchars((string)($licitacion['numero_expediente'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                </span>
                            </div>
                            <div>
                                <span class="meta-label">Presupuesto maximo</span>
                                <span class="meta-value">
                                    <?php echo number_format((float)($licitacion['pres_maximo'] ?? 0), 0, ',', '.'); ?> EUR
                                </span>
                            </div>
                            <div>
                                <span class="meta-label">Fecha presentacion</span>
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
                                        echo '-';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div>
                                <span class="meta-label">Pais</span>
                                <span class="meta-value">
                                    <?php echo htmlspecialchars((string)($licitacion['pais'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                </span>
                            </div>
                        </div>

                        <?php
                        /** @var array<int, array<string,mixed>> $partidas */
                        $partidas = is_array($licitacion['partidas'] ?? null) ? $licitacion['partidas'] : [];
                        $idEstado = (int)($licitacion['id_estado'] ?? 0);
                        $idTipoLicitacionVista = isset($licitacion['id_tipolicitacion'])
                            ? (int)$licitacion['id_tipolicitacion']
                            : 0;
                        $showPmaxuPresupuesto = in_array($idTipoLicitacionVista, [1, 2, 4, 5], true);
                        $showUnidadesPresupuesto = !in_array($idTipoLicitacionVista, [2, 4], true);
                        $isTipoDescuentoPresupuesto = in_array($idTipoLicitacionVista, [2, 5], true);
                        $presupuestoBloqueado = $idEstado >= $estadoBloqueoPresupuestoDesde;
                        $descuentoGlobalPresupuesto = isset($licitacion['descuento_global'])
                            ? (float)$licitacion['descuento_global']
                            : 0.0;
                        if ($isTipoDescuentoPresupuesto && $descuentoGlobalPresupuesto <= 0.0) {
                            $sumPmaxu = 0.0;
                            $sumPvu = 0.0;
                            foreach ($partidas as $pDesc) {
                                if (!is_array($pDesc)) {
                                    continue;
                                }
                                $activaDesc = array_key_exists('activo', $pDesc) ? (bool)$pDesc['activo'] : true;
                                if (!$activaDesc) {
                                    continue;
                                }
                                $pmaxuDesc = (float)($pDesc['pmaxu'] ?? 0.0);
                                $pvuDesc = (float)($pDesc['pvu'] ?? 0.0);
                                if ($pmaxuDesc <= 0.0 || $pvuDesc <= 0.0) {
                                    continue;
                                }
                                $sumPmaxu += $pmaxuDesc;
                                $sumPvu += $pvuDesc;
                            }
                            if ($sumPmaxu > 0.0 && $sumPvu > 0.0) {
                                $factorDesc = max(0.0, min(1.0, $sumPvu / $sumPmaxu));
                                $descuentoGlobalPresupuesto = round((1.0 - $factorDesc) * 100.0, 2);
                            }
                        }
                        $lotesConfigurados = extractConfiguredLotes($licitacion['lotes_config'] ?? null);
                        $usaLotesPresupuesto = count($lotesConfigurados) > 1;
                        if (!$usaLotesPresupuesto) {
                            $lotesDetectados = [];
                            foreach ($partidas as $pLote) {
                                if (!is_array($pLote)) {
                                    continue;
                                }
                                $activoLote = array_key_exists('activo', $pLote) ? (bool)$pLote['activo'] : true;
                                if (!$activoLote) {
                                    continue;
                                }
                                $nombreLote = trim((string)($pLote['lote'] ?? ''));
                                if ($nombreLote === '') {
                                    $nombreLote = 'General';
                                }
                                $lotesDetectados[mb_strtolower($nombreLote)] = true;
                            }
                            $usaLotesPresupuesto = count($lotesDetectados) > 1;
                        }
                        // A partir de ADJUDICADA (5) mostramos pestanas de ejecucion/remaining como en el frontend antiguo.
                        $showEjecucionRemaining = $idEstado >= 5;

                        // -------------------------
                        // Calculos para Remaining
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
                        // ejecutadoPorDetalle: unidades reales entregadas por id_detalle
                        $ejecutadoPorPartida = [];
                        $ejecutadoPorDetalle = [];
                        foreach ($entregas as $ent) {
                            $lineas = isset($ent['lineas']) && is_array($ent['lineas']) ? $ent['lineas'] : [];
                            foreach ($lineas as $lin) {
                                $idDet = $lin['id_detalle'] ?? null;
                                $idTipoGasto = $lin['id_tipo_gasto'] ?? null;
                                if ($idDet === null || $idTipoGasto !== null) {
                                    // Solo lineas presupuestadas (no gastos extra)
                                    continue;
                                }
                                $idDet = (int)$idDet;
                                $cant = (float)($lin['cantidad'] ?? 0);

                                if (!isset($ejecutadoPorDetalle[$idDet])) {
                                    $ejecutadoPorDetalle[$idDet] = 0.0;
                                }
                                $ejecutadoPorDetalle[$idDet] += $cant;

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
                                <?php if ($mappedSuccess): ?>
                                    <div class="mapped-success-banner">
                                        Productos vinculados correctamente.
                                    </div>
                                <?php endif; ?>
                                <?php if ($showMissingProductsBanner): ?>
                                    <div class="missing-products-banner">
                                        <span>
                                            Hay <?php echo count($partidasSinProductoCatalogo); ?> partida(s) activa(s) sin producto ERP.
                                        </span>
                                        <button type="button" id="btn-vincular-productos" class="btn-vincular-productos">
                                            Vincular productos ERP
                                        </button>
                                    </div>
                                <?php endif; ?>
                                <?php
                                /** @var array<int, array<string,mixed>> $partidasActivas */
                                $partidasActivas = [];
                                foreach ($partidas as $pActiva) {
                                    if (!is_array($pActiva)) {
                                        continue;
                                    }
                                    $activa = array_key_exists('activo', $pActiva) ? (bool)$pActiva['activo'] : true;
                                    if (!$activa) {
                                        continue;
                                    }
                                    $partidasActivas[] = $pActiva;
                                }
                                $colsSoloLectura = 1
                                    + ($usaLotesPresupuesto ? 1 : 0)
                                    + ($showUnidadesPresupuesto ? 1 : 0)
                                    + ($showPmaxuPresupuesto ? 1 : 0)
                                    + 3;
                                $colsEditable = $colsSoloLectura + 1;
                                ?>
                                <?php if (!$presupuestoBloqueado): ?>
                                    <form
                                        method="post"
                                        action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id . '&tab=presupuesto', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                        class="budget-table-form"
                                    >
                                        <input type="hidden" name="form_tipo" value="budget_table_action">
                                        <?php if ($isTipoDescuentoPresupuesto): ?>
                                            <div
                                                class="budget-discount-toolbar"
                                                style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;padding:8px 10px;border:1px solid #d8d2c4;border-radius:10px;background:#f8f6ef;"
                                            >
                                                <span style="font-size:0.8rem;font-weight:600;color:#6b5d47;">Descuentos sobre precio maximo</span>
                                                <div style="display:flex;align-items:center;gap:8px;">
                                                    <span style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.04em;color:#7c6f58;">Descuento global</span>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        name="descuento_global"
                                                        id="descuento-global-input"
                                                        value="<?php echo htmlspecialchars((string)$descuentoGlobalPresupuesto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                        class="budget-input budget-input-right"
                                                        style="width:88px;"
                                                    />
                                                    <span style="font-size:0.8rem;color:#7c6f58;">%</span>
                                                    <button
                                                        type="button"
                                                        id="btn-aplicar-descuento-global"
                                                        class="btn-row-save"
                                                        style="padding:6px 10px;"
                                                    >
                                                        Aplicar a PVU
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <table
                                            class="budget-lines-table budget-lines-table-editable"
                                            data-show-unidades="<?php echo $showUnidadesPresupuesto ? '1' : '0'; ?>"
                                            data-show-pmaxu="<?php echo $showPmaxuPresupuesto ? '1' : '0'; ?>"
                                            data-tipo-descuento="<?php echo $isTipoDescuentoPresupuesto ? '1' : '0'; ?>"
                                        >
                                            <thead>
                                                <tr>
                                                    <th>Producto</th>
                                                    <?php if ($usaLotesPresupuesto): ?>
                                                        <th class="is-right">Lote</th>
                                                    <?php endif; ?>
                                                    <?php if ($showUnidadesPresupuesto): ?>
                                                        <th class="is-right">Uds.</th>
                                                    <?php endif; ?>
                                                    <?php if ($showPmaxuPresupuesto): ?>
                                                        <th class="is-right">PMAXU (EUR)</th>
                                                    <?php endif; ?>
                                                    <th class="is-right">PVU (EUR)</th>
                                                    <th class="is-right">PCU (EUR)</th>
                                                    <th class="is-right">Importe (EUR)</th>
                                                    <th class="is-right">Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($partidasActivas as $p): ?>
                                                    <?php
                                                    $detalleId = (int)($p['id_detalle'] ?? 0);
                                                    if ($detalleId <= 0) {
                                                        continue;
                                                    }
                                                    $nombreProd = (string)($p['product_nombre'] ?? ($p['nombre_producto_libre'] ?? ''));
                                                    $idProducto = isset($p['id_producto']) ? (int)$p['id_producto'] : 0;
                                                    $lotePartida = trim((string)($p['lote'] ?? ''));
                                                    if ($lotePartida === '') {
                                                        $lotePartida = 'General';
                                                    }
                                                    $uds = (float)($p['unidades'] ?? 0);
                                                    $pmaxu = (float)($p['pmaxu'] ?? 0);
                                                    $pvu = (float)($p['pvu'] ?? 0);
                                                    $pcu = (float)($p['pcu'] ?? 0);
                                                    $importe = $showUnidadesPresupuesto ? ($uds > 0 ? $uds * $pvu : $pvu) : $pvu;
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <input type="hidden" name="lineas[<?php echo $detalleId; ?>][id_producto]" value="<?php echo $idProducto > 0 ? $idProducto : ''; ?>" />
                                                            <input
                                                                type="text"
                                                                name="lineas[<?php echo $detalleId; ?>][nombre_partida]"
                                                                value="<?php echo htmlspecialchars($nombreProd, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                class="budget-input"
                                                                <?php echo $idProducto > 0 ? 'readonly' : ''; ?>
                                                            />
                                                        </td>
                                                        <?php if ($usaLotesPresupuesto): ?>
                                                            <td class="budget-cell-num">
                                                                <?php if ($lotesConfigurados !== []): ?>
                                                                    <select name="lineas[<?php echo $detalleId; ?>][lote]" class="budget-input budget-input-right">
                                                                        <?php foreach ($lotesConfigurados as $loteConfigNombre): ?>
                                                                            <option
                                                                                value="<?php echo htmlspecialchars($loteConfigNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                                <?php echo $lotePartida === $loteConfigNombre ? 'selected' : ''; ?>
                                                                            >
                                                                                <?php echo htmlspecialchars($loteConfigNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                <?php else: ?>
                                                                    <input
                                                                        type="text"
                                                                        name="lineas[<?php echo $detalleId; ?>][lote]"
                                                                        value="<?php echo htmlspecialchars($lotePartida, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                        class="budget-input budget-input-right"
                                                                    />
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php if ($showUnidadesPresupuesto): ?>
                                                            <td class="budget-cell-num">
                                                                <input type="number" step="0.01" min="0" name="lineas[<?php echo $detalleId; ?>][unidades]" value="<?php echo $uds > 0 ? htmlspecialchars((string)$uds, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>" class="budget-input budget-input-right" />
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php if ($showPmaxuPresupuesto): ?>
                                                            <td class="budget-cell-num">
                                                                <input type="number" step="0.01" min="0" name="lineas[<?php echo $detalleId; ?>][pmaxu]" value="<?php echo $pmaxu > 0 ? htmlspecialchars((string)$pmaxu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>" class="budget-input budget-input-right" />
                                                            </td>
                                                        <?php endif; ?>
                                                        <td class="budget-cell-num">
                                                            <input
                                                                type="number"
                                                                step="0.01"
                                                                min="0"
                                                                name="lineas[<?php echo $detalleId; ?>][pvu]"
                                                                value="<?php echo $pvu > 0 ? htmlspecialchars((string)$pvu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>"
                                                                class="budget-input budget-input-right"
                                                                <?php echo $isTipoDescuentoPresupuesto ? 'readonly tabindex="-1" data-auto-pvu="1"' : ''; ?>
                                                            />
                                                        </td>
                                                        <td class="budget-cell-num">
                                                            <input type="number" step="0.01" min="0" name="lineas[<?php echo $detalleId; ?>][pcu]" value="<?php echo $pcu > 0 ? htmlspecialchars((string)$pcu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>" class="budget-input budget-input-right" />
                                                        </td>
                                                        <td class="is-right"><?php echo number_format($importe, 2, ',', '.'); ?></td>
                                                        <td class="budget-cell-actions">
                                                            <button
                                                                type="submit"
                                                                name="eliminar_id"
                                                                value="<?php echo $detalleId; ?>"
                                                                class="btn-row-trash"
                                                                title="Eliminar linea"
                                                                aria-label="Eliminar linea"
                                                                onclick="return window.confirm('Se eliminara esta linea de presupuesto.');"
                                                            >
                                                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                                    <path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 12h12l1-13H5l1 13Z" fill="currentColor" />
                                                                </svg>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr class="budget-new-row js-budget-new-row" data-new-index="0">
                                                    <td class="budget-cell-concept">
                                                        <input type="hidden" name="lineas_nuevas[0][id_producto]" class="js-budget-product-id" value="" />
                                                        <div class="budget-autocomplete-wrap">
                                                            <input
                                                                type="text"
                                                                name="lineas_nuevas[0][nombre_partida]"
                                                                class="budget-input js-budget-concept-input"
                                                                placeholder="Anadir nuevo concepto..."
                                                                autocomplete="off"
                                                            />
                                                            <div class="budget-autocomplete-list js-budget-suggest-box"></div>
                                                            <div class="ac-status budget-ac-status js-budget-ac-status"></div>
                                                        </div>
                                                    </td>
                                                    <?php if ($usaLotesPresupuesto): ?>
                                                        <td class="budget-cell-num">
                                                            <?php if ($lotesConfigurados !== []): ?>
                                                                <select name="lineas_nuevas[0][lote]" class="budget-input budget-input-right">
                                                                    <?php foreach ($lotesConfigurados as $loteConfigNombre): ?>
                                                                        <option value="<?php echo htmlspecialchars($loteConfigNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                                                            <?php echo htmlspecialchars($loteConfigNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            <?php else: ?>
                                                                <input type="text" name="lineas_nuevas[0][lote]" placeholder="Lote 1" class="budget-input budget-input-right" />
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endif; ?>
                                                    <?php if ($showUnidadesPresupuesto): ?>
                                                        <td class="budget-cell-num">
                                                            <input type="number" step="0.01" min="0" name="lineas_nuevas[0][unidades]" placeholder="0" class="budget-input budget-input-right" />
                                                        </td>
                                                    <?php endif; ?>
                                                    <?php if ($showPmaxuPresupuesto): ?>
                                                        <td class="budget-cell-num">
                                                            <input type="number" step="0.01" min="0" name="lineas_nuevas[0][pmaxu]" placeholder="0" class="budget-input budget-input-right" />
                                                        </td>
                                                    <?php endif; ?>
                                                    <td class="budget-cell-num">
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            name="lineas_nuevas[0][pvu]"
                                                            placeholder="0"
                                                            class="budget-input budget-input-right"
                                                            <?php echo $isTipoDescuentoPresupuesto ? 'readonly tabindex="-1" data-auto-pvu="1"' : ''; ?>
                                                        />
                                                    </td>
                                                    <td class="budget-cell-num">
                                                        <input type="number" step="0.01" min="0" name="lineas_nuevas[0][pcu]" placeholder="0" class="budget-input budget-input-right" />
                                                    </td>
                                                    <td class="is-right budget-new-importe">-</td>
                                                    <td class="budget-cell-actions">
                                                        <span class="budget-auto-add-hint">Se crea otra fila automaticamente</span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <div class="budget-table-actions">
                                            <button type="submit" name="guardar_todo" value="1" class="btn-save-budget-table">Guardar toda la tabla</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <?php if ($partidasActivas === []): ?>
                                        <p class="budget-empty">
                                            Esta licitacion aun no tiene partidas de presupuesto cargadas.
                                        </p>
                                    <?php else: ?>
                                        <table class="budget-lines-table">
                                            <thead>
                                                <tr>
                                                    <th>Producto</th>
                                                    <?php if ($usaLotesPresupuesto): ?>
                                                        <th class="is-right">Lote</th>
                                                    <?php endif; ?>
                                                    <?php if ($showUnidadesPresupuesto): ?>
                                                        <th class="is-right">Uds.</th>
                                                    <?php endif; ?>
                                                    <?php if ($showPmaxuPresupuesto): ?>
                                                        <th class="is-right">PMAXU (EUR)</th>
                                                    <?php endif; ?>
                                                    <th class="is-right">PVU (EUR)</th>
                                                    <th class="is-right">PCU (EUR)</th>
                                                    <th class="is-right">Importe (EUR)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($partidasActivas as $p): ?>
                                                    <?php
                                                    $nombreProd = (string)($p['product_nombre'] ?? ($p['nombre_producto_libre'] ?? ''));
                                                    $lotePartida = trim((string)($p['lote'] ?? ''));
                                                    if ($lotePartida === '') {
                                                        $lotePartida = 'General';
                                                    }
                                                    $uds = (float)($p['unidades'] ?? 0);
                                                    $pmaxu = (float)($p['pmaxu'] ?? 0);
                                                    $pvu = (float)($p['pvu'] ?? 0);
                                                    $pcu = (float)($p['pcu'] ?? 0);
                                                    $importe = $showUnidadesPresupuesto ? ($uds > 0 ? $uds * $pvu : $pvu) : $pvu;
                                                    ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($nombreProd, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                        <?php if ($usaLotesPresupuesto): ?>
                                                            <td class="is-right"><?php echo htmlspecialchars($lotePartida, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                        <?php endif; ?>
                                                        <?php if ($showUnidadesPresupuesto): ?>
                                                            <td class="is-right"><?php echo $uds > 0 ? number_format($uds, 2, ',', '.') : '-'; ?></td>
                                                        <?php endif; ?>
                                                        <?php if ($showPmaxuPresupuesto): ?>
                                                            <td class="is-right"><?php echo $pmaxu > 0 ? number_format($pmaxu, 2, ',', '.') : '-'; ?></td>
                                                        <?php endif; ?>
                                                        <td class="is-right"><?php echo number_format($pvu, 2, ',', '.'); ?></td>
                                                        <td class="is-right"><?php echo number_format($pcu, 2, ',', '.'); ?></td>
                                                        <td class="is-right"><?php echo number_format($importe, 2, ',', '.'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <?php if ($showEjecucionRemaining): ?>
                                <div id="tab-ejecucion" class="tab-content">
                                    <div class="deliveries-toolbar">
                                        <p class="deliveries-intro">
                                            Resumen de entregas y albaranes vinculados a esta licitacion.
                                        </p>
                                        <button
                                            type="button"
                                            id="btn-nuevo-albaran"
                                            class="btn-new-delivery"
                                        >
                                            + Registrar nuevo albaran
                                        </button>
                                    </div>
                                    <?php if (empty($entregas)): ?>
                                        <p class="deliveries-empty">
                                            No hay entregas registradas para esta licitacion.
                                        </p>
                                    <?php else: ?>
                                        <div class="deliveries-list">
                                            <?php foreach ($entregas as $ent): ?>
                                                <?php
                                                $codigoAlbaran = (string)($ent['codigo_albaran'] ?? '');
                                                $fechaEntrega = (string)($ent['fecha_entrega'] ?? '');
                                                $obs = (string)($ent['observaciones'] ?? '');
                                                $lineas = isset($ent['lineas']) && is_array($ent['lineas']) ? $ent['lineas'] : [];
                                                ?>
                                                <div class="delivery-card">
                                                    <div class="delivery-head">
                                                        <div>
                                                            <div class="delivery-code">
                                                                <?php echo htmlspecialchars($codigoAlbaran !== '' ? $codigoAlbaran : 'Sin codigo', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                            </div>
                                                            <div class="delivery-date">
                                                                Fecha: <?php echo htmlspecialchars($fechaEntrega, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                            </div>
                                                        </div>
                                                        <?php if ($obs !== ''): ?>
                                                            <div class="delivery-note">
                                                                <?php echo htmlspecialchars($obs, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="delivery-lines-wrap">
                                                        <table class="delivery-lines-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>Concepto</th>
                                                                    <th>Proveedor</th>
                                                                    <th class="is-right">Cantidad</th>
                                                                    <th class="is-right">Coste</th>
                                                                    <th class="is-center">Estado</th>
                                                                    <th class="is-center">Cobrado</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php if (empty($lineas)): ?>
                                                                    <tr>
                                                                        <td colspan="6" class="delivery-empty-row">
                                                                            Sin lineas
                                                                        </td>
                                                                    </tr>
                                                                <?php else: ?>
                                                                    <?php foreach ($lineas as $lin): ?>
                                                                        <?php
                                                                        $esGastoExtra = ($lin['id_detalle'] ?? null) === null && isset($lin['id_tipo_gasto']);
                                                                        $concepto = (string)($lin['product_nombre'] ?? '-');
                                                                        $proveedor = (string)($lin['proveedor'] ?? '-');
                                                                        $cantidad = (float)($lin['cantidad'] ?? 0);
                                                                        $pcu = (float)($lin['pcu'] ?? 0);
                                                                        $estadoLin = (string)($lin['estado'] ?? '');
                                                                        if ($estadoLin === '' || !in_array($estadoLin, $estadosLineaEntrega, true)) {
                                                                            $estadoLin = 'EN ESPERA';
                                                                        }
                                                                        $cobrado = (bool)($lin['cobrado'] ?? false);
                                                                        $cobradoValue = $cobrado ? 1 : 0;
                                                                        $idRealLinea = isset($lin['id_real']) ? (int)$lin['id_real'] : 0;
                                                                        $classCobrado = $cobrado ? 'linea-tag-cobrado-si' : 'linea-tag-cobrado-no';
                                                                        ?>
                                                                        <tr>
                                                                            <td><?php echo htmlspecialchars($concepto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                                            <td><?php echo htmlspecialchars($proveedor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                                            <td class="is-right"><?php echo number_format($cantidad, 2, ',', '.'); ?></td>
                                                                            <td class="is-right"><?php echo number_format($pcu, 2, ',', '.'); ?> EUR</td>
                                                                            <td class="is-center">
                                                                                <?php if ($esGastoExtra): ?>
                                                                                    <span class="linea-tag-extra">Gasto ext.</span>
                                                                                <?php elseif ($idRealLinea > 0): ?>
                                                                                    <form
                                                                                        method="post"
                                                                                        action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id . '&tab=ejecucion', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                                        class="delivery-state-form"
                                                                                    >
                                                                                        <input type="hidden" name="form_tipo" value="actualizar_estado_linea">
                                                                                        <input type="hidden" name="id_real" value="<?php echo $idRealLinea; ?>">
                                                                                        <select
                                                                                            name="estado_linea"
                                                                                            class="delivery-state-select"
                                                                                        >
                                                                                            <?php foreach ($estadosLineaEntrega as $estadoOpt): ?>
                                                                                                <option
                                                                                                    value="<?php echo htmlspecialchars($estadoOpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                                                    <?php echo $estadoLin === $estadoOpt ? 'selected' : ''; ?>
                                                                                                >
                                                                                                    <?php echo htmlspecialchars($estadoOpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                                                                </option>
                                                                                            <?php endforeach; ?>
                                                                                        </select>
                                                                                    </form>
                                                                                <?php else: ?>
                                                                                    <span class="linea-tag-estado"><?php echo htmlspecialchars($estadoLin !== '' ? $estadoLin : 'EN ESPERA', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                            <td class="is-center">
                                                                                <?php if ($esGastoExtra): ?>
                                                                                    <span class="linea-tag-extra">-</span>
                                                                                <?php elseif ($idRealLinea > 0): ?>
                                                                                    <form
                                                                                        method="post"
                                                                                        action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id . '&tab=ejecucion', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                                        class="delivery-cobrado-form"
                                                                                    >
                                                                                        <input type="hidden" name="form_tipo" value="actualizar_cobrado_linea">
                                                                                        <input type="hidden" name="id_real" value="<?php echo $idRealLinea; ?>">
                                                                                        <select
                                                                                            name="cobrado_linea"
                                                                                            class="delivery-cobrado-select"
                                                                                        >
                                                                                            <option value="0" <?php echo $cobradoValue === 0 ? 'selected' : ''; ?>>No</option>
                                                                                            <option value="1" <?php echo $cobradoValue === 1 ? 'selected' : ''; ?>>Si</option>
                                                                                        </select>
                                                                                    </form>
                                                                                <?php else: ?>
                                                                                    <span class="<?php echo $classCobrado; ?>">
                                                                                        <?php echo $cobrado ? 'Si' : 'No'; ?>
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

                                    <!-- Modal nuevo albaran -->
                                    <div
                                        id="modal-nuevo-albaran"
                                        style="position:fixed;inset:0;background:rgba(15,23,42,0.72);display:none;align-items:center;justify-content:center;z-index:60;"
                                    >
                                        <div class="albaran-modal-dialog" style="background:#020617;border-radius:12px;border:1px solid #1f2937;box-shadow:0 18px 35px rgba(15,23,42,0.9);max-width:720px;width:100%;padding:16px 18px;">
                                            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
                                                <h3 style="margin:0;font-size:0.9rem;font-weight:600;color:#e5e7eb;">Registrar nuevo albaran</h3>
                                                <button
                                                    type="button"
                                                    id="modal-nuevo-albaran-close"
                                                    style="border:none;background:transparent;color:#9ca3af;font-size:0.9rem;cursor:pointer;"
                                                >&times;</button>
                                            </div>
                                            <p style="margin:0 0 10px;font-size:0.8rem;color:#9ca3af;">
                                                Cabecera del albaran y lineas de entrega (concepto, proveedor, cantidad, coste).
                                            </p>
                                            <form
                                                method="post"
                                                action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id . '&tab=ejecucion', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
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
                                                        <label style="font-size:0.75rem;font-weight:600;color:#9ca3af;">Codigo albaran</label>
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
                                                    <span style="font-size:0.8rem;color:#9ca3af;">Lineas del albaran</span>
                                                    <div class="albaran-type-switch" style="display:inline-flex;border-radius:9999px;border:1px solid #1f2937;overflow:hidden;">
                                                        <button
                                                            type="button"
                                                            id="btn-albaran-tipo-presu"
                                                            class="albaran-tab-btn is-active"
                                                            style="border:none;background:#0f172a;color:#e5e7eb;font-size:0.75rem;font-weight:500;padding:4px 10px;cursor:pointer;"
                                                        >
                                                            Partidas
                                                        </button>
                                                        <button
                                                            type="button"
                                                            id="btn-albaran-tipo-ext"
                                                            class="albaran-tab-btn"
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
                                                                <th style="padding:4px 6px;text-align:right;">Coste EUR</th>
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
                                                                            <option value="">Selecciona partida...</option>
                                                                            <?php foreach ($partidas as $p): ?>
                                                                                <?php
                                                                                $idDet = (int)($p['id_detalle'] ?? 0);
                                                                                $nombreProd = (string)($p['product_nombre'] ?? ($p['nombre_producto_libre'] ?? ''));
                                                                                $lote = trim((string)($p['lote'] ?? ''));
                                                                                if ($lote === '') $lote = 'General';
                                                                                $udsPresu = (float)($p['unidades'] ?? 0.0);
                                                                                $udsEntregadas = (float)($ejecutadoPorDetalle[$idDet] ?? 0.0);
                                                                                $udsRestantes = $udsPresu > 0 ? max(0.0, $udsPresu - $udsEntregadas) : 0.0;
                                                                                $restanteTxt = $udsPresu > 0
                                                                                    ? number_format($udsRestantes, 2, ',', '.')
                                                                                    : '-';
                                                                                $baseLabel = $usaLotesPresupuesto
                                                                                    ? ($lote . ' - ' . $nombreProd)
                                                                                    : $nombreProd;
                                                                                $label = $baseLabel . ' (restante por entregar: ' . $restanteTxt . ')';
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
                                                    <div style="border-radius:8px;border:1px solid #1f2937;overflow:hidden;">
                                                    <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                                                        <thead>
                                                            <tr style="border-bottom:1px solid #1f2937;font-size:0.7rem;text-transform:uppercase;color:#9ca3af;">
                                                                <th style="padding:4px 6px;text-align:left;">Tipo gasto</th>
                                                                <th style="padding:4px 6px;text-align:left;">Detalle (solo para "Otros")</th>
                                                                <th style="padding:4px 6px;text-align:right;">Coste EUR</th>
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
                                                                            <option value="">Tipo de gasto...</option>
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
                                                        class="albaran-cancel-btn"
                                                        style="border:1px solid #374151;border-radius:8px;background:#020617;color:#9ca3af;font-size:0.75rem;padding:4px 10px;cursor:pointer;"
                                                    >
                                                        Cancelar
                                                    </button>
                                                    <button
                                                        type="submit"
                                                        class="albaran-submit-btn"
                                                        style="border:none;border-radius:8px;background:linear-gradient(135deg,#10b981,#0ea5e9);color:#020617;font-size:0.8rem;font-weight:600;padding:6px 12px;cursor:pointer;"
                                                    >
                                                        Registrar albaran
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <div id="tab-remaining" class="tab-content">
                                    <p class="remaining-intro">
                                        Comparativa entre unidades presupuestadas y ejecutadas por partida.
                                    </p>
                                    <div class="remaining-card">
                                        <table class="remaining-table">
                                            <thead>
                                                <tr>
                                                    <th style="text-align:left;">Lote</th>
                                                    <th style="text-align:left;">Partida</th>
                                                    <th style="text-align:right;">Ud. Presu.</th>
                                                    <th style="text-align:right;">Ud. Real</th>
                                                    <th style="text-align:right;">Pendiente</th>
                                                    <th style="text-align:left;">Progreso</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($itemsPresupuestoAgregado as $key => $item): ?>
                                                    <?php
                                                    $ejecutado = (float)($ejecutadoPorPartida[$key] ?? 0.0);
                                                    $presu = (float)($item['unidades'] ?? 0.0);
                                                    $pendiente = max(0.0, $presu - $ejecutado);
                                                    $progreso = $presu > 0 ? max(0.0, min(100.0, ($ejecutado / $presu) * 100.0)) : 0.0;
                                                    $progresoRounded = (int)round($progreso);
                                                    $progresoVisual = $progresoRounded > 0 ? max(4, $progresoRounded) : 0;
                                                    $progresoClass = 'remaining-progress-fill is-low';
                                                    if ($progresoRounded >= 80) {
                                                        $progresoClass = 'remaining-progress-fill is-high';
                                                    } elseif ($progresoRounded >= 40) {
                                                        $progresoClass = 'remaining-progress-fill is-mid';
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td class="remaining-lote"><?php echo htmlspecialchars((string)$item['lote'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                        <td class="remaining-partida">
                                                            <?php echo htmlspecialchars((string)$item['descripcion'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                        </td>
                                                        <td class="remaining-num">
                                                            <?php echo number_format($presu, 2, ',', '.'); ?>
                                                        </td>
                                                        <td class="remaining-num">
                                                            <?php echo number_format($ejecutado, 2, ',', '.'); ?>
                                                        </td>
                                                        <td class="remaining-num">
                                                            <?php echo number_format($pendiente, 2, ',', '.'); ?>
                                                        </td>
                                                        <td class="remaining-progress-cell">
                                                            <div class="remaining-progress-track">
                                                                <div
                                                                    class="<?php echo $progresoClass; ?>"
                                                                    style="width:<?php echo $progresoVisual; ?>%;"
                                                                ></div>
                                                            </div>
                                                            <div class="remaining-progress-value">
                                                                <?php echo $progresoRounded; ?> %
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
// Tabs simples en cliente para navegar entre Presupuesto / Ejecucion / Remaining
document.addEventListener('DOMContentLoaded', function () {
    var triggers = Array.prototype.slice.call(document.querySelectorAll('.tab-trigger'));
    var contents = Array.prototype.slice.call(document.querySelectorAll('.tab-content'));
    function activarTab(tab) {
        if (!tab) return false;
        var btn = triggers.find(function (b) { return b.getAttribute('data-tab') === tab; });
        if (!btn) return false;
        triggers.forEach(function (b) { b.classList.remove('active'); });
        contents.forEach(function (c) { c.classList.remove('active'); });
        btn.classList.add('active');
        var target = document.getElementById('tab-' + tab);
        if (target) target.classList.add('active');
        return true;
    }
    triggers.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tab = btn.getAttribute('data-tab');
            if (!tab) return;
            activarTab(tab);
        });
    });

    // Mantener pestana segun query param (?tab=ejecucion|remaining|presupuesto)
    try {
        var params = new URLSearchParams(window.location.search || '');
        var tabFromUrl = params.get('tab');
        if (tabFromUrl) {
            activarTab(tabFromUrl);
        }
    } catch (e) {
        // Ignorar errores de parseo y mantener pestana por defecto.
    }
});

// Presupuesto: mantener siempre una fila nueva vacia al final (sin boton "Anadir")
document.addEventListener('DOMContentLoaded', function () {
    var table = document.querySelector('.budget-lines-table-editable');
    if (!table) return;

    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    var showUnidades = table.getAttribute('data-show-unidades') === '1';
    var showPmaxu = table.getAttribute('data-show-pmaxu') === '1';
    var isTipoDescuento = table.getAttribute('data-tipo-descuento') === '1';
    var descuentoInput = document.getElementById('descuento-global-input');
    var btnAplicarDescuento = document.getElementById('btn-aplicar-descuento-global');

    function parseNumber(v) {
        var txt = String(v == null ? '' : v).trim().replace(',', '.');
        if (txt === '') return 0;
        var n = parseFloat(txt);
        return Number.isFinite(n) ? n : 0;
    }

    function getDiscountPct() {
        if (!isTipoDescuento || !descuentoInput) return 0;
        var pct = parseNumber(descuentoInput.value);
        return pct >= 0 ? pct : 0;
    }

    function calcPvuFromDiscount(base) {
        if (!Number.isFinite(base) || base <= 0) return 0;
        var factor = 1 - getDiscountPct() / 100;
        if (factor < 0) factor = 0;
        return Math.round(base * factor * 100) / 100;
    }

    function recalcRowDiscountPvu(row) {
        if (!isTipoDescuento || !showPmaxu || !row) return;
        var pmaxu = row.querySelector('input[name*=\"[pmaxu]\"]');
        var pvu = row.querySelector('input[name*=\"[pvu]\"]');
        if (!pmaxu || !pvu) return;
        var base = parseNumber(pmaxu.value);
        var calc = calcPvuFromDiscount(base);
        pvu.value = calc > 0 ? String(calc) : '';
    }

    function recalcAllDiscountRows() {
        if (!isTipoDescuento) return;
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        rows.forEach(function (row) {
            recalcRowDiscountPvu(row);
            updateNewRowImporte(row);
        });
    }

    function getNewRows() {
        return Array.prototype.slice.call(tbody.querySelectorAll('.js-budget-new-row'));
    }

    function rowHasData(row) {
        if (!row) return false;
        var concept = row.querySelector('input[name*=\"[nombre_partida]\"]');
        var idProducto = row.querySelector('input[name*=\"[id_producto]\"]');
        var unidades = row.querySelector('input[name*=\"[unidades]\"]');
        var pmaxu = row.querySelector('input[name*=\"[pmaxu]\"]');
        var pvu = row.querySelector('input[name*=\"[pvu]\"]');
        var pcu = row.querySelector('input[name*=\"[pcu]\"]');

        var conceptTxt = concept ? String(concept.value || '').trim() : '';
        var productId = idProducto ? String(idProducto.value || '').trim() : '';
        var unidadesVal = unidades ? parseNumber(unidades.value) : 0;
        var pmaxuVal = pmaxu ? parseNumber(pmaxu.value) : 0;
        var pvuVal = pvu ? parseNumber(pvu.value) : 0;
        var pcuVal = pcu ? parseNumber(pcu.value) : 0;

        return conceptTxt !== ''
            || productId !== ''
            || unidadesVal > 0
            || pmaxuVal > 0
            || pvuVal > 0
            || pcuVal > 0;
    }

    function updateNewRowImporte(row) {
        var importeCell = row.querySelector('.budget-new-importe');
        if (!importeCell) return;
        var unidades = row.querySelector('input[name*=\"[unidades]\"]');
        var pvu = row.querySelector('input[name*=\"[pvu]\"]');
        var unidadesVal = unidades ? parseNumber(unidades.value) : 0;
        var pvuVal = pvu ? parseNumber(pvu.value) : 0;
        var importe = showUnidades ? (unidadesVal > 0 ? unidadesVal * pvuVal : pvuVal) : pvuVal;
        if (importe > 0) {
            importeCell.textContent = importe.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
            importeCell.textContent = '-';
        }
    }

    function bindNewRow(row) {
        if (!row || row.getAttribute('data-budget-bound') === '1') return;
        row.setAttribute('data-budget-bound', '1');
        var fields = row.querySelectorAll('input, select');
        fields.forEach(function (field) {
            field.addEventListener('input', function () {
                recalcRowDiscountPvu(row);
                updateNewRowImporte(row);
                ensureTrailingEmptyRow();
            });
            field.addEventListener('change', function () {
                recalcRowDiscountPvu(row);
                updateNewRowImporte(row);
                ensureTrailingEmptyRow();
            });
        });
    }

    function renumberRow(row, idx) {
        row.setAttribute('data-new-index', String(idx));
        var fields = row.querySelectorAll('input, select, textarea');
        fields.forEach(function (field) {
            var name = field.getAttribute('name');
            if (name) {
                field.setAttribute('name', name.replace(/lineas_nuevas\[\d+\]/, 'lineas_nuevas[' + idx + ']'));
            }
        });
    }

    function cloneAsNewRow(fromRow, idx) {
        var clone = fromRow.cloneNode(true);
        clone.setAttribute('data-budget-bound', '0');
        clone.classList.add('js-budget-new-row');
        renumberRow(clone, idx);

        var textInputs = clone.querySelectorAll('input[type=\"text\"], input[type=\"number\"], input[type=\"hidden\"]');
        textInputs.forEach(function (el) {
            if (el.type === 'hidden') {
                el.value = '';
            } else {
                el.value = '';
            }
        });

        var selects = clone.querySelectorAll('select');
        selects.forEach(function (sel) {
            sel.selectedIndex = 0;
        });

        var suggest = clone.querySelector('.js-budget-suggest-box');
        if (suggest) {
            suggest.innerHTML = '';
        }

        var hint = clone.querySelector('.budget-auto-add-hint');
        if (hint) {
            hint.textContent = 'Se crea otra fila automaticamente';
        }

        updateNewRowImporte(clone);
        return clone;
    }

    function ensureTrailingEmptyRow() {
        var rows = getNewRows();
        if (rows.length === 0) return;

        rows.forEach(function (row, idx) {
            renumberRow(row, idx);
            bindNewRow(row);
            updateNewRowImporte(row);
        });

        var last = rows[rows.length - 1];
        if (rowHasData(last)) {
            var newRow = cloneAsNewRow(last, rows.length);
            tbody.appendChild(newRow);
            bindNewRow(newRow);
            updateNewRowImporte(newRow);
        }
    }

    getNewRows().forEach(function (row) {
        bindNewRow(row);
        updateNewRowImporte(row);
    });
    ensureTrailingEmptyRow();

    if (isTipoDescuento && descuentoInput) {
        descuentoInput.addEventListener('input', function () {
            recalcAllDiscountRows();
        });
        descuentoInput.addEventListener('change', function () {
            recalcAllDiscountRows();
        });
    }
    if (isTipoDescuento && btnAplicarDescuento) {
        btnAplicarDescuento.addEventListener('click', function (ev) {
            ev.preventDefault();
            recalcAllDiscountRows();
        });
    }
    recalcAllDiscountRows();
});

// Guardado asincrono para estado/cobrado de lineas de entrega
document.addEventListener('DOMContentLoaded', function () {
    var forms = Array.prototype.slice.call(
        document.querySelectorAll('.delivery-state-form, .delivery-cobrado-form')
    );
    if (forms.length === 0) return;

    function setSavingState(select, isSaving) {
        if (!select) return;
        if (isSaving) {
            select.classList.add('is-saving');
            select.setAttribute('aria-busy', 'true');
            select.disabled = true;
            return;
        }
        select.classList.remove('is-saving');
        select.removeAttribute('aria-busy');
        select.disabled = false;
    }

    function markSaved(select) {
        if (!select) return;
        select.classList.add('is-saved');
        window.setTimeout(function () {
            select.classList.remove('is-saved');
        }, 700);
    }

    function showInlineError(form, message) {
        if (!form) return;
        var old = form.querySelector('.delivery-inline-error');
        if (old && old.parentNode) {
            old.parentNode.removeChild(old);
        }
        var node = document.createElement('div');
        node.className = 'delivery-inline-error';
        node.textContent = message || 'No se pudo actualizar la linea.';
        form.appendChild(node);
        window.setTimeout(function () {
            if (node.parentNode) {
                node.parentNode.removeChild(node);
            }
        }, 3200);
    }

    function submitFormAjax(form) {
        var select = form.querySelector('select');
        if (!select) return;

        var prevValue = select.getAttribute('data-prev-value');
        if (prevValue === null) {
            prevValue = select.value;
            select.setAttribute('data-prev-value', prevValue);
        }

        var formData = new FormData(form);
        if (select.name && !formData.has(select.name)) {
            formData.set(select.name, select.value);
        }
        formData.append('ajax', '1');
        setSavingState(select, true);

        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (data) {
                    return {
                        ok: response.ok,
                        data: data
                    };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data || result.data.ok !== true) {
                    var errorMessage = (result.data && result.data.message)
                        ? String(result.data.message)
                        : 'No se pudo actualizar la linea.';
                    throw new Error(errorMessage);
                }
                select.setAttribute('data-prev-value', select.value);
                markSaved(select);
            })
            .catch(function (error) {
                if (prevValue !== null) {
                    select.value = prevValue;
                }
                showInlineError(form, error && error.message ? error.message : 'No se pudo actualizar la linea.');
            })
            .finally(function () {
                setSavingState(select, false);
            });
    }

    forms.forEach(function (form) {
        var select = form.querySelector('select');
        if (!select) return;

        select.setAttribute('data-prev-value', select.value);
        select.addEventListener('focus', function () {
            select.setAttribute('data-prev-value', select.value);
        });
        select.addEventListener('change', function () {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }
            var evt = document.createEvent('Event');
            evt.initEvent('submit', true, true);
            form.dispatchEvent(evt);
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitFormAjax(form);
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

// Modal "Nuevo albaran"
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

    function limpiarEtiquetaGastosExtra() {
        if (!secExt) return;

        var textoObjetivo = 'gastos extraordinarios';
        var nodes = secExt.childNodes;
        for (var i = nodes.length - 1; i >= 0; i--) {
            var node = nodes[i];
            if (node.nodeType === 3) {
                var textoNode = (node.nodeValue || '').trim().toLowerCase();
                if (textoNode === textoObjetivo && node.parentNode) {
                    node.parentNode.removeChild(node);
                }
                continue;
            }

            if (node.nodeType !== 1) continue;
            var tagName = (node.tagName || '').toUpperCase();
            var textoElemento = (node.textContent || '').trim().toLowerCase();
            var esEtiquetaSimple = tagName === 'DIV' || tagName === 'SPAN' || tagName === 'P' || tagName === 'LABEL' || tagName === 'LEGEND';
            var contieneTabla = typeof node.querySelector === 'function' && node.querySelector('table') !== null;
            if (esEtiquetaSimple && !contieneTabla && textoElemento === textoObjetivo) {
                node.remove();
            }
        }

        var legends = secExt.querySelectorAll('legend');
        for (var j = legends.length - 1; j >= 0; j--) {
            var legend = legends[j];
            if ((legend.textContent || '').trim().toLowerCase() === textoObjetivo) {
                legend.remove();
            }
        }
    }

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
            btnTipoPresu.classList.add('is-active');
            btnTipoExt.classList.remove('is-active');
        } else {
            secPresu.style.display = 'none';
            secExt.style.display = 'block';
            btnTipoExt.classList.add('is-active');
            btnTipoPresu.classList.remove('is-active');
        }
    }

    btn.addEventListener('click', openModal);
    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCancel) btnCancel.addEventListener('click', closeModal);
    if (btnTipoPresu) btnTipoPresu.addEventListener('click', function () { activarTipo('presu'); });
    if (btnTipoExt) btnTipoExt.addEventListener('click', function () { activarTipo('ext'); });

    // Tipo por defecto: partidas
    activarTipo('presu');
    limpiarEtiquetaGastosExtra();

    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            closeModal();
        }
    });
});

// Modal "Vincular productos ERP"
document.addEventListener('DOMContentLoaded', function () {
    var btnOpen = document.getElementById('btn-vincular-productos');
    var modal = document.getElementById('modal-vincular-productos');
    var btnClose = document.getElementById('modal-vincular-productos-close');
    var btnCancel = document.getElementById('modal-vincular-productos-cancel');

    if (!modal) return;

    function openModal() {
        modal.style.display = 'flex';
    }
    function closeModal() {
        modal.style.display = 'none';
    }

    if (btnOpen) btnOpen.addEventListener('click', openModal);
    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCancel) btnCancel.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            closeModal();
        }
    });

    if (modal.getAttribute('data-open') === '1') {
        openModal();
    }

    var inputs = Array.prototype.slice.call(modal.querySelectorAll('.map-product-input'));
    inputs.forEach(function (input) {
        var targetId = input.getAttribute('data-target-id');
        var detailId = input.getAttribute('data-detail-id');
        if (!targetId || !detailId) return;
        var hidden = document.getElementById(targetId);
        var box = document.getElementById('map-suggest-' + detailId);
        if (!hidden || !box) return;

        var timer = null;

        function clearBox() {
            box.innerHTML = '';
            box.style.display = 'none';
        }

        function renderSuggestions(items) {
            if (!Array.isArray(items) || items.length === 0) {
                clearBox();
                return;
            }
            box.innerHTML = '';
            items.forEach(function (it) {
                var row = document.createElement('button');
                row.type = 'button';
                row.className = 'map-product-option';
                row.textContent = it.nombre + (it.referencia ? ' (' + it.referencia + ')' : '');
                row.addEventListener('click', function () {
                    input.value = it.nombre || '';
                    hidden.value = String(it.id_producto || '');
                    clearBox();
                });
                box.appendChild(row);
            });
            box.style.display = 'block';
        }

        input.addEventListener('input', function () {
            hidden.value = '';
            var q = input.value.trim();
            if (timer) window.clearTimeout(timer);
            if (q.length < 2) {
                clearBox();
                return;
            }
            timer = window.setTimeout(function () {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', 'productos-search.php?q=' + encodeURIComponent(q), true);
                xhr.onreadystatechange = function () {
                    if (xhr.readyState !== 4) return;
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
                };
                xhr.send();
            }, 220);
        });

        document.addEventListener('click', function (e) {
            if (!box.contains(e.target) && e.target !== input) {
                clearBox();
            }
        });
    });
});

// Autocompletado de productos en "Nuevo concepto" (Presupuesto)
document.addEventListener('DOMContentLoaded', function () {
    var table = document.querySelector('.budget-lines-table-editable');
    if (!table) return;
    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    function bindRowAutocomplete(row) {
        if (!row || row.getAttribute('data-budget-ac-bound') === '1') {
            return;
        }
        var input = row.querySelector('.js-budget-concept-input');
        var hiddenId = row.querySelector('.js-budget-product-id');
        var box = row.querySelector('.js-budget-suggest-box');
        if (!input || !hiddenId || !box) {
            return;
        }

        row.setAttribute('data-budget-ac-bound', '1');
        var timer = null;
        var requestId = 0;
        var suggestions = [];
        var activeIndex = -1;

        function clearBox() {
            box.innerHTML = '';
            box.style.display = 'none';
            suggestions = [];
            activeIndex = -1;
            row.classList.remove('is-suggest-open');
        }

        function renderInfoRow(texto) {
            box.innerHTML = '';
            suggestions = [];
            activeIndex = -1;
            var info = document.createElement('div');
            info.style.padding = '7px 8px';
            info.style.fontSize = '0.8rem';
            info.style.color = '#85725e';
            info.textContent = texto;
            box.appendChild(info);
            box.style.display = 'block';
            row.classList.add('is-suggest-open');
        }

        function updateActiveOption() {
            var rows = Array.prototype.slice.call(box.querySelectorAll('.product-suggest-option'));
            rows.forEach(function (optRow, idx) {
                if (idx === activeIndex) {
                    optRow.style.background = 'rgba(142,139,48,0.12)';
                } else {
                    optRow.style.background = '#fff';
                }
            });
        }

        function aplicarSeleccion(it) {
            input.value = typeof it.nombre === 'string' ? it.nombre : '';
            hiddenId.value = String(it.id_producto || '');
            clearBox();
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function renderSuggestions(items) {
            suggestions = Array.isArray(items) ? items : [];
            activeIndex = -1;
            if (suggestions.length === 0) {
                renderInfoRow('Sin coincidencias');
                return;
            }
            box.innerHTML = '';
            suggestions.forEach(function (it, idx) {
                var opt = document.createElement('button');
                opt.type = 'button';
                opt.className = 'product-suggest-option';
                opt.style.width = '100%';
                opt.style.border = 'none';
                opt.style.borderBottom = '1px solid rgba(133,114,94,0.25)';
                opt.style.background = '#fff';
                opt.style.textAlign = 'left';
                opt.style.color = '#10180e';
                opt.style.fontSize = '0.8rem';
                opt.style.padding = '7px 8px';
                opt.style.cursor = 'pointer';
                opt.textContent = (it.nombre || '') + (it.referencia ? ' (' + it.referencia + ')' : '');

                opt.addEventListener('mouseenter', function () {
                    activeIndex = idx;
                    updateActiveOption();
                });
                opt.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    aplicarSeleccion(it);
                });

                box.appendChild(opt);
            });
            updateActiveOption();
            box.style.display = 'block';
            row.classList.add('is-suggest-open');
        }

        function lanzarBusqueda(forceImmediate) {
            var q = input.value.trim();
            hiddenId.value = '';
            if (timer) window.clearTimeout(timer);
            if (q.length < 1) {
                clearBox();
                return;
            }

            var execute = function () {
                var currentRequestId = ++requestId;
                renderInfoRow('Buscando...');
                var xhr = new XMLHttpRequest();
                xhr.open('GET', 'productos-search.php?q=' + encodeURIComponent(q) + '&limit=12', true);
                xhr.onreadystatechange = function () {
                    if (xhr.readyState !== 4) return;
                    if (currentRequestId !== requestId) return;
                    if (xhr.status === 200) {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            renderSuggestions(Array.isArray(data) ? data : []);
                        } catch (e) {
                            renderInfoRow('No se pudo procesar la busqueda');
                        }
                    } else {
                        renderInfoRow('No se pudo cargar sugerencias');
                    }
                };
                xhr.send();
            };

            if (forceImmediate) {
                execute();
                return;
            }
            timer = window.setTimeout(execute, 180);
        }

        input.addEventListener('input', function () {
            lanzarBusqueda(false);
        });

        input.addEventListener('focus', function () {
            if (input.value.trim().length > 0) {
                lanzarBusqueda(true);
            }
        });

        input.addEventListener('keydown', function (e) {
            if (box.style.display === 'none' || suggestions.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = activeIndex < suggestions.length - 1 ? activeIndex + 1 : 0;
                updateActiveOption();
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = activeIndex > 0 ? activeIndex - 1 : suggestions.length - 1;
                updateActiveOption();
                return;
            }
            if (e.key === 'Enter' && activeIndex >= 0 && suggestions[activeIndex]) {
                e.preventDefault();
                aplicarSeleccion(suggestions[activeIndex]);
                return;
            }
            if (e.key === 'Escape') {
                clearBox();
            }
        });

        document.addEventListener('click', function (e) {
            if (!box.contains(e.target) && e.target !== input) {
                clearBox();
            }
        });
    }

    Array.prototype.slice.call(tbody.querySelectorAll('.js-budget-new-row')).forEach(bindRowAutocomplete);

    var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            Array.prototype.slice.call(mutation.addedNodes).forEach(function (node) {
                if (!node || node.nodeType !== 1) return;
                if (node.classList && node.classList.contains('js-budget-new-row')) {
                    bindRowAutocomplete(node);
                }
            });
        });
    });
    observer.observe(tbody, { childList: true });
});
</script>
</html>



