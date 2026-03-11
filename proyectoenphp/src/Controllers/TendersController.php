<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/TendersRepository.php';
require_once __DIR__ . '/../Repositories/CatalogsRepository.php';

final class TendersController
{
    private TendersRepository $repository;
    private CatalogsRepository $catalogs;

    public function __construct()
    {
        $this->repository = new TendersRepository();
        $this->catalogs = new CatalogsRepository();
    }

    /**
     * GET /tenders
     * Lista licitaciones con filtros opcionales.
     */
    public function index(): void
    {
        $estadoId = isset($_GET['estado_id']) ? (int)$_GET['estado_id'] : null;
        $nombre = isset($_GET['nombre']) ? (string)$_GET['nombre'] : null;
        $pais = isset($_GET['pais']) ? (string)$_GET['pais'] : null;

        try {
            $data = $this->repository->listTenders($estadoId, $nombre, $pais);
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Database error', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error', $e);
        }
    }

    /**
     * GET /tenders/parents
     * Lista licitaciones que pueden ser padre (AM/SDA) y estÃ¡n adjudicadas.
     */
    public function parents(): void
    {
        try {
            $data = $this->repository->getParentTenders();
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Database error', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error', $e);
        }
    }

    /**
     * GET /tenders/{tender_id}
     * Detalle de licitaciÃ³n con partidas.
     */
    public function show(int $tenderId): void
    {
        try {
            $licitacion = $this->repository->getTenderWithDetails($tenderId);
            $estados = $this->catalogs->getEstados();
            if ($licitacion === null) {
                http_response_code(404);
                $licitacion = null;
                $error = 'LicitaciÃ³n no encontrada.';
            } else {
                $error = null;
            }

            // Renderizar vista HTML en lugar de JSON.
            // La vista puede usar $licitacion y $error.
            /** @var array<string,mixed>|null $licitacion */
            /** @var string|null $error */
            /** @var array<int, array<string,mixed>> $estados */
            require __DIR__ . '/../Views/licitaciones/show.php';
        } catch (\PDOException $e) {
            http_response_code(500);
            $licitacion = null;
            $error = 'Database error: ' . $e->getMessage();
            $estados = [];
            require __DIR__ . '/../Views/licitaciones/show.php';
        } catch (\Throwable $e) {
            http_response_code(500);
            $licitacion = null;
            $error = 'Unexpected error: ' . $e->getMessage();
            $estados = [];
            require __DIR__ . '/../Views/licitaciones/show.php';
        }
    }

    /**
     * POST /licitaciones/{tender_id}/presupuesto
     * Procesa el formulario de presupuesto enviado desde la vista SSR.
     */
    public function updateBudget(int $tenderId): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'MÃ©todo no permitido';
            return;
        }

        $productos = $_POST['productos'] ?? [];
        if (!is_array($productos)) {
            $productos = [];
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // Eliminar todas las partidas existentes de esta licitaciÃ³n.
            $sqlDelete = 'DELETE FROM tbl_licitaciones_detalle WHERE id_licitacion = :tender_id';
            $stmtDelete = $pdo->prepare($sqlDelete);
            $stmtDelete->execute([
                ':tender_id' => $tenderId,
            ]);

            $parseDecimal = static function ($value): ?float {
                if ($value === null) {
                    return null;
                }

                if (!is_string($value) && !is_numeric($value)) {
                    return null;
                }

                $normalized = trim((string)$value);
                if ($normalized === '') {
                    return null;
                }

                $normalized = str_replace(',', '.', $normalized);
                if (!is_numeric($normalized)) {
                    return null;
                }

                return (float)$normalized;
            };

            foreach ($productos as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $concepto = isset($row['concepto']) ? trim((string)$row['concepto']) : '';
                if ($concepto === '') {
                    // Saltar filas vacÃ­as.
                    continue;
                }

                $payload = [
                    'nombre_producto_libre' => $concepto,
                    'unidades' => $parseDecimal($row['unidades'] ?? null),
                    'pmaxu' => $parseDecimal($row['pmaxu'] ?? null),
                    'pvu' => $parseDecimal($row['pvu'] ?? null),
                    'pcu' => $parseDecimal($row['pcu'] ?? null),
                    'activo' => 1,
                ];

                // Insertar la partida usando la lÃ³gica del repositorio.
                $this->repository->addPartida($tenderId, $payload);
            }

            $pdo->commit();

            header('Location: /licitaciones/' . $tenderId);
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            http_response_code(500);
            echo 'Error actualizando presupuesto: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    /**
     * POST /licitaciones/{tender_id}/ejecucion
     * Procesa el formulario de ejecuciÃ³n (entregas / lÃ­neas reales) enviado desde la vista SSR.
     */
    public function updateExecution(int $tenderId): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'MÃ©todo no permitido';
            return;
        }

        $ejecucion = $_POST['ejecucion'] ?? [];
        if (!is_array($ejecucion)) {
            $ejecucion = [];
        }

        $pdo = Database::getConnection();
        $parseDecimal = static function ($value): ?float {
            if ($value === null) {
                return null;
            }

            if (!is_string($value) && !is_numeric($value)) {
                return null;
            }

            $normalized = trim((string)$value);
            if ($normalized === '') {
                return null;
            }

            $normalized = str_replace(',', '.', $normalized);
            if (!is_numeric($normalized)) {
                return null;
            }

            return (float)$normalized;
        };

        $qtyEpsilon = 0.0001;

        $resolveDetalleIdByLinea = static function (int $idReal, int $idEntrega) use ($pdo, $tenderId): ?int {
            $sql = 'SELECT id_detalle
                    FROM tbl_licitaciones_real
                    WHERE id_real = :id_real
                      AND id_entrega = :id_entrega
                      AND id_licitacion = :id_licitacion
                    LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id_real' => $idReal,
                ':id_entrega' => $idEntrega,
                ':id_licitacion' => $tenderId,
            ]);

            $row = $stmt->fetch();
            if ($row === false || $row === null) {
                return null;
            }

            return isset($row['id_detalle']) && $row['id_detalle'] !== null
                ? (int)$row['id_detalle']
                : null;
        };

        $assertCantidadPendiente = static function (?int $idDetalle, float $cantidadNueva, ?int $excludeIdReal = null) use ($pdo, $tenderId, $qtyEpsilon): void {
            if ($idDetalle === null || $idDetalle <= 0 || $cantidadNueva <= 0.0) {
                return;
            }

            $sqlPresu = 'SELECT COALESCE(unidades, 0) AS unidades
                         FROM tbl_licitaciones_detalle
                         WHERE id_licitacion = :id_licitacion
                           AND id_detalle = :id_detalle
                           AND activo = 1
                         LIMIT 1';
            $stmtPresu = $pdo->prepare($sqlPresu);
            $stmtPresu->execute([
                ':id_licitacion' => $tenderId,
                ':id_detalle' => $idDetalle,
            ]);
            $rowPresu = $stmtPresu->fetch();

            if ($rowPresu === false || $rowPresu === null) {
                throw new \InvalidArgumentException(
                    sprintf('La partida #%d no existe o no esta activa en el presupuesto.', $idDetalle),
                    400
                );
            }

            $sqlEntregado = 'SELECT COALESCE(SUM(cantidad), 0) AS cantidad_total
                             FROM tbl_licitaciones_real
                             WHERE id_licitacion = :id_licitacion
                               AND id_detalle = :id_detalle';
            $paramsEntregado = [
                ':id_licitacion' => $tenderId,
                ':id_detalle' => $idDetalle,
            ];

            if ($excludeIdReal !== null && $excludeIdReal > 0) {
                $sqlEntregado .= ' AND id_real <> :id_real_excluir';
                $paramsEntregado[':id_real_excluir'] = $excludeIdReal;
            }

            $stmtEntregado = $pdo->prepare($sqlEntregado);
            $stmtEntregado->execute($paramsEntregado);
            $rowEntregado = $stmtEntregado->fetch();

            $presupuestado = (float)($rowPresu['unidades'] ?? 0.0);
            $entregado = (float)($rowEntregado['cantidad_total'] ?? 0.0);
            $pendiente = max(0.0, $presupuestado - $entregado);

            if (($cantidadNueva - $pendiente) > $qtyEpsilon) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'La partida #%d excede la cantidad pendiente. Pendiente: %s, intentado: %s.',
                        $idDetalle,
                        number_format($pendiente, 2, ',', '.'),
                        number_format($cantidadNueva, 2, ',', '.')
                    ),
                    400
                );
            }
        };

        try {
            $pdo->beginTransaction();

            // Actualizar / eliminar entregas existentes y sus lÃ­neas.
            foreach ($ejecucion as $key => $entrada) {
                if (!is_array($entrada)) {
                    continue;
                }

                // Filas "rÃ¡pidas" nuevas creadas desde JS (sin campo lineas):
                if (!array_key_exists('lineas', $entrada)) {
                    $concepto = isset($entrada['concepto']) ? trim((string)$entrada['concepto']) : '';
                    $cantidad = $parseDecimal($entrada['cantidad'] ?? null);
                    $pcu = $parseDecimal($entrada['pcu'] ?? null);
                    $proveedor = isset($entrada['proveedor']) ? trim((string)$entrada['proveedor']) : '';
                    $estado = isset($entrada['estado']) ? trim((string)$entrada['estado']) : '';
                    $cobrado = isset($entrada['cobrado']) && (string)$entrada['cobrado'] === '1';

                    // Si no hay datos relevantes, saltamos
                    if ($concepto === '' && $proveedor === '' && $cantidad === null && $pcu === null) {
                        continue;
                    }

                    // Crear una nueva cabecera de entrega mÃ­nima para esta fila.
                    $codigo = 'WEB-' . date('Ymd-His');
                    $fechaHoy = date('Y-m-d');

                    $sqlInsertEntrega = 'INSERT INTO tbl_entregas (id_licitacion, fecha_entrega, codigo_albaran, observaciones)
                                         VALUES (:id_licitacion, :fecha_entrega, :codigo_albaran, :observaciones)';
                    $stmtCab = $pdo->prepare($sqlInsertEntrega);
                    $stmtCab->execute([
                        ':id_licitacion' => $tenderId,
                        ':fecha_entrega' => $fechaHoy,
                        ':codigo_albaran' => $codigo,
                        ':observaciones' => '',
                    ]);

                    $idEntregaNueva = (int)$pdo->lastInsertId();

                    $sqlInsertLinea = 'INSERT INTO tbl_licitaciones_real (
                            id_licitacion,
                            id_entrega,
                            id_detalle,
                            fecha_entrega,
                            cantidad,
                            pcu,
                            proveedor,
                            estado,
                            cobrado
                        ) VALUES (
                            :id_licitacion,
                            :id_entrega,
                            :id_detalle,
                            :fecha_entrega,
                            :cantidad,
                            :pcu,
                            :proveedor,
                            :estado,
                            :cobrado
                        )';

                    $stmtLinea = $pdo->prepare($sqlInsertLinea);
                    $stmtLinea->execute([
                        ':id_licitacion' => $tenderId,
                        ':id_entrega' => $idEntregaNueva,
                        ':id_detalle' => null,
                        ':fecha_entrega' => $fechaHoy,
                        ':cantidad' => $cantidad ?? 0.0,
                        ':pcu' => $pcu ?? 0.0,
                        ':proveedor' => $proveedor,
                        ':estado' => $estado !== '' ? $estado : 'EN ESPERA',
                        ':cobrado' => $cobrado ? 1 : 0,
                    ]);

                    continue;
                }

                // Entradas que representan una entrega existente (con posibles lÃ­neas).
                $idEntrega = isset($entrada['id_entrega']) ? (int)$entrada['id_entrega'] : 0;
                if ($idEntrega <= 0) {
                    continue;
                }

                $deletedEntrega = isset($entrada['deleted']) && (string)$entrada['deleted'] === '1';

                // Validar que la entrega pertenece a la licitacion actual.
                $sqlCheck = 'SELECT 1
                             FROM tbl_entregas
                             WHERE id_entrega = :id_entrega
                               AND id_licitacion = :id_licitacion
                             LIMIT 1';
                $stmtCheck = $pdo->prepare($sqlCheck);
                $stmtCheck->execute([
                    ':id_entrega' => $idEntrega,
                    ':id_licitacion' => $tenderId,
                ]);

                if ($stmtCheck->fetchColumn() === false) {
                    // No pertenece a esta licitacion; ignoramos por seguridad.
                    continue;
                }

                if ($deletedEntrega) {
                    // Borrado completo de entrega + lÃ­neas
                    $sqlDelReal = 'DELETE FROM tbl_licitaciones_real
                                   WHERE id_entrega = :id_entrega';
                    $stmtDelReal = $pdo->prepare($sqlDelReal);
                    $stmtDelReal->execute([
                        ':id_entrega' => $idEntrega,
                    ]);

                    $sqlDelEnt = 'DELETE FROM tbl_entregas
                                  WHERE id_entrega = :id_entrega';
                    $stmtDelEnt = $pdo->prepare($sqlDelEnt);
                    $stmtDelEnt->execute([
                        ':id_entrega' => $idEntrega,
                    ]);

                    continue;
                }

                // Actualizar cabecera de entrega
                $fechaEntrega = isset($entrada['fecha_entrega']) ? (string)$entrada['fecha_entrega'] : '';
                $observaciones = isset($entrada['observaciones']) ? (string)$entrada['observaciones'] : '';

                $sqlUpdEnt = 'UPDATE tbl_entregas
                              SET fecha_entrega = :fecha_entrega,
                                  observaciones = :observaciones
                              WHERE id_entrega = :id_entrega
                                AND id_licitacion = :id_licitacion';
                $stmtUpdEnt = $pdo->prepare($sqlUpdEnt);
                $stmtUpdEnt->execute([
                    ':fecha_entrega' => $fechaEntrega,
                    ':observaciones' => $observaciones,
                    ':id_entrega' => $idEntrega,
                    ':id_licitacion' => $tenderId,
                ]);

                // Procesar lÃ­neas de la entrega
                $lineas = isset($entrada['lineas']) && is_array($entrada['lineas'])
                    ? $entrada['lineas']
                    : [];

                foreach ($lineas as $lKey => $lin) {
                    if (!is_array($lin)) {
                        continue;
                    }

                    $idReal = isset($lin['id_real']) ? (int)$lin['id_real'] : 0;
                    $deletedLinea = isset($lin['deleted']) && (string)$lin['deleted'] === '1';

                    if ($idReal > 0) {
                        if ($deletedLinea) {
                            $sqlDelLinea = 'DELETE FROM tbl_licitaciones_real
                                            WHERE id_entrega = :id_entrega
                                              AND id_real = :id_real';
                            $stmtDelLinea = $pdo->prepare($sqlDelLinea);
                            $stmtDelLinea->execute([
                                ':id_entrega' => $idEntrega,
                                ':id_real' => $idReal,
                            ]);
                            continue;
                        }

                        // UPDATE de lÃ­nea existente
                        $cantidad = $parseDecimal($lin['cantidad'] ?? null);
                        $pcu = $parseDecimal($lin['pcu'] ?? null);
                        $estado = isset($lin['estado']) ? trim((string)$lin['estado']) : '';
                        $cobrado = isset($lin['cobrado']) && (string)$lin['cobrado'] === '1';
                        $proveedor = isset($lin['proveedor']) ? trim((string)$lin['proveedor']) : '';

                        $idDetalleLinea = $resolveDetalleIdByLinea($idReal, $idEntrega);
                        $assertCantidadPendiente($idDetalleLinea, $cantidad ?? 0.0, $idReal);

                        $sqlUpdLinea = 'UPDATE tbl_licitaciones_real
                                        SET cantidad = :cantidad,
                                            pcu = :pcu,
                                            proveedor = :proveedor,
                                            estado = :estado,
                                            cobrado = :cobrado
                                        WHERE id_entrega = :id_entrega
                                          AND id_real = :id_real';
                        $stmtUpdLinea = $pdo->prepare($sqlUpdLinea);
                        $stmtUpdLinea->execute([
                            ':cantidad' => $cantidad ?? 0.0,
                            ':pcu' => $pcu ?? 0.0,
                            ':proveedor' => $proveedor,
                            ':estado' => $estado !== '' ? $estado : 'EN ESPERA',
                            ':cobrado' => $cobrado ? 1 : 0,
                            ':id_entrega' => $idEntrega,
                            ':id_real' => $idReal,
                        ]);
                    } else {
                        // Nueva lÃ­nea para una entrega existente
                        $cantidad = $parseDecimal($lin['cantidad'] ?? null);
                        $pcu = $parseDecimal($lin['pcu'] ?? null);
                        $estado = isset($lin['estado']) ? trim((string)$lin['estado']) : '';
                        $cobrado = isset($lin['cobrado']) && (string)$lin['cobrado'] === '1';
                        $proveedor = isset($lin['proveedor']) ? trim((string)$lin['proveedor']) : '';

                        // Si no hay datos relevantes en la nueva lÃ­nea, la ignoramos
                        if ($cantidad === null && $pcu === null && $proveedor === '') {
                            continue;
                        }

                        $sqlInsLinea = 'INSERT INTO tbl_licitaciones_real (
                                id_licitacion,
                                id_entrega,
                                id_detalle,
                                fecha_entrega,
                                cantidad,
                                pcu,
                                proveedor,
                                estado,
                                cobrado
                            ) VALUES (
                                :id_licitacion,
                                :id_entrega,
                                :id_detalle,
                                :fecha_entrega,
                                :cantidad,
                                :pcu,
                                :proveedor,
                                :estado,
                                :cobrado
                            )';

                        $stmtInsLinea = $pdo->prepare($sqlInsLinea);
                        $stmtInsLinea->execute([
                            ':id_licitacion' => $tenderId,
                            ':id_entrega' => $idEntrega,
                            ':id_detalle' => null,
                            ':fecha_entrega' => $fechaEntrega,
                            ':cantidad' => $cantidad ?? 0.0,
                            ':pcu' => $pcu ?? 0.0,
                            ':proveedor' => $proveedor,
                            ':estado' => $estado !== '' ? $estado : 'EN ESPERA',
                            ':cobrado' => $cobrado ? 1 : 0,
                        ]);
                    }
                }
            }

            $pdo->commit();

            header('Location: /licitaciones/' . $tenderId . '?tab=ejecucion');
            exit;
        } catch (\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            http_response_code(400);
            echo 'Error actualizando ejecucion: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            http_response_code(500);
            echo 'Error actualizando ejecuciÃ³n: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    /**
     * POST /licitaciones/{tender_id}/estado
     * Actualiza el estado (id_estado) de una licitaciÃ³n desde la vista SSR.
     */
    public function updateStatus(int $tenderId): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'MÃ©todo no permitido';
            return;
        }

        if (!isset($_POST['estado'])) {
            http_response_code(400);
            echo 'Falta el parÃ¡metro estado.';
            return;
        }

        $estadoRaw = $_POST['estado'];
        if (!is_string($estadoRaw) && !is_numeric($estadoRaw)) {
            http_response_code(400);
            echo 'ParÃ¡metro estado invÃ¡lido.';
            return;
        }

        $estadoId = (int)$estadoRaw;
        if ($estadoId <= 0) {
            http_response_code(400);
            echo 'ParÃ¡metro estado invÃ¡lido.';
            return;
        }

        try {
            // Obtener estado actual respetando RLS
            $actual = $this->repository->getById($tenderId);
            if ($actual === null) {
                http_response_code(404);
                echo 'LicitaciÃ³n no encontrada.';
                return;
            }

            $estadoActual = (int)($actual['id_estado'] ?? 0);

            // Mismo flujo que en el proyecto React original
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
                http_response_code(400);
                echo 'TransiciÃ³n de estado no permitida desde el estado actual.';
                return;
            }

            if ($estadoId === 4) {
                $detallePresentacion = $this->repository->getTenderWithDetails($tenderId);
                $partidasPresentacion = is_array($detallePresentacion['partidas'] ?? null)
                    ? $detallePresentacion['partidas']
                    : [];

                $tienePartidasActivas = false;
                foreach ($partidasPresentacion as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
                    if ($activo) {
                        $tienePartidasActivas = true;
                        break;
                    }
                }

                if (!$tienePartidasActivas) {
                    http_response_code(400);
                    echo 'No puedes pasar a Presentada sin al menos una linea presupuestada.';
                    return;
                }
            }

            if ($estadoId === 5) {
                $detalle = $this->repository->getTenderWithDetails($tenderId);
                $partidas = is_array($detalle['partidas'] ?? null)
                    ? $detalle['partidas']
                    : [];

                /** @var array<int, int> $idsProducto */
                $idsProducto = [];
                foreach ($partidas as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
                    if (!$activo) {
                        continue;
                    }
                    $idProd = isset($p['id_producto']) ? (int)$p['id_producto'] : 0;
                    if ($idProd > 0) {
                        $idsProducto[$idProd] = $idProd;
                    }
                }

                $validProductIds = $this->fetchExistingProductIds(array_values($idsProducto));

                foreach ($partidas as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
                    if (!$activo) {
                        continue;
                    }
                    $idProd = isset($p['id_producto']) ? (int)$p['id_producto'] : 0;
                    $hasValidProduct = $idProd > 0 && isset($validProductIds[$idProd]);
                    if (!$hasValidProduct) {
                        http_response_code(400);
                        echo 'No se puede adjudicar: hay partidas activas sin producto de catalogo valido.';
                        return;
                    }
                }
            }

            if ($estadoId === 7) {
                $detalleFinalizacion = $this->repository->getTenderWithDetails($tenderId);
                $partidasFinalizacion = is_array($detalleFinalizacion['partidas'] ?? null)
                    ? $detalleFinalizacion['partidas']
                    : [];

                $lotesConfigRaw = $detalleFinalizacion['lotes_config'] ?? null;
                $lotesConfig = [];
                if (is_array($lotesConfigRaw)) {
                    $lotesConfig = $lotesConfigRaw;
                } elseif (is_string($lotesConfigRaw) && trim($lotesConfigRaw) !== '') {
                    try {
                        $decodedLotes = json_decode($lotesConfigRaw, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decodedLotes)) {
                            $lotesConfig = $decodedLotes;
                        }
                    } catch (\Throwable) {
                        $lotesConfig = [];
                    }
                }

                $filtrarPorLotesGanados = $lotesConfig !== [];
                /** @var array<string, bool> $lotesGanadosSet */
                $lotesGanadosSet = [];
                if ($filtrarPorLotesGanados) {
                    foreach ($lotesConfig as $loteCfg) {
                        if (!is_array($loteCfg)) {
                            continue;
                        }
                        $nombreLoteCfg = trim((string)($loteCfg['nombre'] ?? ''));
                        if ($nombreLoteCfg === '' || empty($loteCfg['ganado'])) {
                            continue;
                        }
                        $lotesGanadosSet[mb_strtolower($nombreLoteCfg, 'UTF-8')] = true;
                    }
                }

                /** @var array<int, float> $presupuestadoPorDetalle */
                $presupuestadoPorDetalle = [];
                foreach ($partidasFinalizacion as $pFinal) {
                    if (!is_array($pFinal)) {
                        continue;
                    }
                    $activo = array_key_exists('activo', $pFinal) ? (bool)$pFinal['activo'] : true;
                    if (!$activo) {
                        continue;
                    }

                    $idDetalle = isset($pFinal['id_detalle']) ? (int)$pFinal['id_detalle'] : 0;
                    if ($idDetalle <= 0) {
                        continue;
                    }

                    $lote = trim((string)($pFinal['lote'] ?? ''));
                    if ($lote === '') {
                        $lote = 'General';
                    }
                    if ($filtrarPorLotesGanados) {
                        $loteKey = mb_strtolower($lote, 'UTF-8');
                        if (!isset($lotesGanadosSet[$loteKey])) {
                            continue;
                        }
                    }

                    $unidades = isset($pFinal['unidades']) ? (float)$pFinal['unidades'] : 0.0;
                    if ($unidades <= 0.0) {
                        continue;
                    }

                    if (!isset($presupuestadoPorDetalle[$idDetalle])) {
                        $presupuestadoPorDetalle[$idDetalle] = 0.0;
                    }
                    $presupuestadoPorDetalle[$idDetalle] += $unidades;
                }

                $pdoFinalizacion = Database::getConnection();
                $sqlLineasFinalizacion = 'SELECT id_detalle, cantidad, estado, cobrado
                                          FROM tbl_licitaciones_real
                                          WHERE id_licitacion = :id_licitacion
                                            AND id_tipo_gasto IS NULL
                                            AND id_detalle IS NOT NULL';
                $paramsLineasFinalizacion = [
                    ':id_licitacion' => $tenderId,
                ];

                $detallesFiltrados = array_values(array_unique(array_filter(
                    array_map(static fn ($v): int => (int)$v, array_keys($presupuestadoPorDetalle)),
                    static fn (int $v): bool => $v > 0
                )));
                if ($detallesFiltrados !== []) {
                    $placeholders = [];
                    foreach ($detallesFiltrados as $idxDetalle => $idDetalleFinal) {
                        $ph = ':id_det_' . $idxDetalle;
                        $placeholders[] = $ph;
                        $paramsLineasFinalizacion[$ph] = $idDetalleFinal;
                    }
                    $sqlLineasFinalizacion .= ' AND id_detalle IN (' . implode(', ', $placeholders) . ')';
                }

                $stmtLineasFinalizacion = $pdoFinalizacion->prepare($sqlLineasFinalizacion);
                $stmtLineasFinalizacion->execute($paramsLineasFinalizacion);
                $lineasFinalizacion = $stmtLineasFinalizacion->fetchAll() ?: [];

                if ($lineasFinalizacion === []) {
                    http_response_code(400);
                    echo 'No puedes finalizar la licitacion sin lineas de entrega registradas.';
                    return;
                }

                /** @var array<int, float> $entregadoPorDetalle */
                $entregadoPorDetalle = [];
                $lineasConEstadoPendiente = 0;
                $lineasSinCobro = 0;
                $allowedEstados = ['ENTREGADO' => true, 'FACTURADO' => true];

                foreach ($lineasFinalizacion as $linFinal) {
                    if (!is_array($linFinal)) {
                        continue;
                    }
                    $idDetalle = isset($linFinal['id_detalle']) ? (int)$linFinal['id_detalle'] : 0;
                    if ($idDetalle <= 0) {
                        continue;
                    }

                    $cantidad = isset($linFinal['cantidad']) ? (float)$linFinal['cantidad'] : 0.0;
                    if ($cantidad > 0.0) {
                        if (!isset($entregadoPorDetalle[$idDetalle])) {
                            $entregadoPorDetalle[$idDetalle] = 0.0;
                        }
                        $entregadoPorDetalle[$idDetalle] += $cantidad;
                    }

                    $estadoLinea = mb_strtoupper(trim((string)($linFinal['estado'] ?? '')), 'UTF-8');
                    if (!isset($allowedEstados[$estadoLinea])) {
                        $lineasConEstadoPendiente++;
                    }

                    $cobradoRaw = $linFinal['cobrado'] ?? 0;
                    $isCobrado = $cobradoRaw === true
                        || $cobradoRaw === 1
                        || $cobradoRaw === '1';
                    if (!$isCobrado) {
                        $lineasSinCobro++;
                    }
                }

                $qtyEpsilon = 0.0001;
                foreach ($presupuestadoPorDetalle as $idDetalle => $presupuestado) {
                    $entregado = (float)($entregadoPorDetalle[$idDetalle] ?? 0.0);
                    if (($presupuestado - $entregado) > $qtyEpsilon) {
                        http_response_code(400);
                        echo 'No puedes finalizar la licitacion: quedan lineas pendientes de entrega.';
                        return;
                    }
                }

                if ($lineasConEstadoPendiente > 0 || $lineasSinCobro > 0) {
                    http_response_code(400);
                    echo 'No puedes finalizar la licitacion: todas las lineas deben estar entregadas/facturadas y cobradas.';
                    return;
                }
            }

            // Usamos el repositorio para actualizar con las validaciones comunes.
            $this->repository->update($tenderId, [
                'id_estado' => $estadoId,
            ]);

            header('Location: /licitaciones/' . $tenderId);
            exit;
        } catch (\Throwable $e) {
            http_response_code(500);
            echo 'Error actualizando estado: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    /**
     * POST /tenders
     * Placeholder para creaciÃ³n de licitaciÃ³n (lÃ³gica de negocio pendiente de portar).
     */
    public function store(): void
    {
        $this->jsonResponse(501, ['error' => 'Not implemented: crear licitaciÃ³n aÃºn no estÃ¡ portado a PHP.']);
    }

    /**
     * PUT /tenders/{tender_id}
     * Placeholder para actualizaciÃ³n de licitaciÃ³n.
     */
    public function update(int $tenderId): void
    {
        $this->jsonResponse(501, ['error' => 'Not implemented: actualizar licitaciÃ³n aÃºn no estÃ¡ portado a PHP.']);
    }

    /**
     * DELETE /tenders/{tender_id}
     * Placeholder para borrado de licitaciÃ³n.
     */
    public function destroy(int $tenderId): void
    {
        $this->jsonResponse(501, ['error' => 'Not implemented: borrar licitaciÃ³n aÃºn no estÃ¡ portado a PHP.']);
    }

    /**
     * POST /tenders/{tender_id}/change-status
     * Placeholder para cambio de estado.
     */
    public function changeStatus(int $tenderId): void
    {
        $this->jsonResponse(501, ['error' => 'Not implemented: cambio de estado aÃºn no estÃ¡ portado a PHP.']);
    }

    /**
     * POST /tenders/{tender_id}/partidas
     * AÃ±ade una partida a la licitaciÃ³n.
     */
    public function addPartida(int $tenderId): void
    {
        try {
            $payload = $this->readJsonBody();
            $partida = $this->repository->addPartida($tenderId, $payload);
            $this->jsonResponse(201, $partida);
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Database error', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error', $e);
        }
    }

    /**
     * PUT /tenders/{tender_id}/partidas/{detalle_id}
     * Actualiza una partida existente.
     */
    public function updatePartida(int $tenderId, int $detalleId): void
    {
        try {
            $payload = $this->readJsonBody();
            $partida = $this->repository->updatePartida($tenderId, $detalleId, $payload);
            $this->jsonResponse(200, $partida);
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(404, ['error' => $e->getMessage()]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Database error', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error', $e);
        }
    }

    /**
     * DELETE /tenders/{tender_id}/partidas/{detalle_id}
     * Elimina una partida.
     */
    public function deletePartida(int $tenderId, int $detalleId): void
    {
        try {
            $this->repository->deletePartida($tenderId, $detalleId);
            $this->jsonResponse(204, null);
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(404, ['error' => $e->getMessage()]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Database error', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error', $e);
        }
    }

    /**
     * Lee y decodifica el cuerpo JSON de la peticiÃ³n.
     *
     * @return array<string, mixed>
     */
    private function fetchExistingProductIds(array $ids): array
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
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, int> $out */
        $out = [];
        while ($row = $stmt->fetch()) {
            if (!isset($row['id'])) {
                continue;
            }
            $id = (int)$row['id'];
            $out[$id] = $id;
        }

        return $out;
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('JSON invÃ¡lido en el cuerpo de la peticiÃ³n.');
        }

        /** @var array<string, mixed> $data */
        return is_array($data) ? $data : [];
    }

    /**
     * EnvÃ­a una respuesta JSON estÃ¡ndar.
     *
     * @param mixed $data
     */
    private function jsonResponse(int $statusCode, $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        if ($data === null || $statusCode === 204) {
            return;
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * EnvÃ­a una respuesta JSON de error incluyendo, opcionalmente, detalles internos en desarrollo.
     */
    private function jsonError(int $statusCode, string $message, \Throwable $e): void
    {
        $payload = [
            'error' => $message,
        ];

        // PodrÃ­as controlar la exposiciÃ³n de detalles segÃºn entorno.
        $payload['details'] = $e->getMessage();

        $this->jsonResponse($statusCode, $payload);
    }
}


