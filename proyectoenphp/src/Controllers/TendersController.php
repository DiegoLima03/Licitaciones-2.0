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
        $organizationId = $this->resolveOrganizationId();
        $this->repository = new TendersRepository($organizationId);
        $this->catalogs = new CatalogsRepository($organizationId);
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
     * Lista licitaciones que pueden ser padre (AM/SDA) y están adjudicadas.
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
     * Detalle de licitación con partidas.
     */
    public function show(int $tenderId): void
    {
        try {
            $licitacion = $this->repository->getTenderWithDetails($tenderId);
            $estados = $this->catalogs->getEstados();
            if ($licitacion === null) {
                http_response_code(404);
                $licitacion = null;
                $error = 'Licitación no encontrada.';
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
            echo 'Método no permitido';
            return;
        }

        $productos = $_POST['productos'] ?? [];
        if (!is_array($productos)) {
            $productos = [];
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $organizationId = $this->resolveOrganizationId();

            // Eliminar todas las partidas existentes de esta licitación para la organización actual.
            $sqlDelete = 'DELETE FROM tbl_licitaciones_detalle WHERE organization_id = :organization_id AND id_licitacion = :tender_id';
            $stmtDelete = $pdo->prepare($sqlDelete);
            $stmtDelete->execute([
                ':organization_id' => $organizationId,
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
                    // Saltar filas vacías.
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

                // Insertar la partida usando la lógica del repositorio (que inyecta organization_id e id_licitacion).
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
     * Procesa el formulario de ejecución (entregas / líneas reales) enviado desde la vista SSR.
     */
    public function updateExecution(int $tenderId): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Método no permitido';
            return;
        }

        $ejecucion = $_POST['ejecucion'] ?? [];
        if (!is_array($ejecucion)) {
            $ejecucion = [];
        }

        $pdo = Database::getConnection();
        $organizationId = $this->resolveOrganizationId();

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

        try {
            $pdo->beginTransaction();

            // Actualizar / eliminar entregas existentes y sus líneas.
            foreach ($ejecucion as $key => $entrada) {
                if (!is_array($entrada)) {
                    continue;
                }

                // Filas "rápidas" nuevas creadas desde JS (sin campo lineas):
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

                    // Crear una nueva cabecera de entrega mínima para esta fila.
                    $codigo = 'WEB-' . date('Ymd-His');
                    $fechaHoy = date('Y-m-d');

                    $sqlInsertEntrega = 'INSERT INTO tbl_entregas (id_licitacion, organization_id, fecha_entrega, codigo_albaran, observaciones)
                                         VALUES (:id_licitacion, :organization_id, :fecha_entrega, :codigo_albaran, :observaciones)';
                    $stmtCab = $pdo->prepare($sqlInsertEntrega);
                    $stmtCab->execute([
                        ':id_licitacion' => $tenderId,
                        ':organization_id' => $organizationId,
                        ':fecha_entrega' => $fechaHoy,
                        ':codigo_albaran' => $codigo,
                        ':observaciones' => '',
                    ]);

                    $idEntregaNueva = (int)$pdo->lastInsertId();

                    $sqlInsertLinea = 'INSERT INTO tbl_licitaciones_real (
                            id_licitacion,
                            organization_id,
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
                            :organization_id,
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
                        ':organization_id' => $organizationId,
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

                // Entradas que representan una entrega existente (con posibles líneas).
                $idEntrega = isset($entrada['id_entrega']) ? (int)$entrada['id_entrega'] : 0;
                if ($idEntrega <= 0) {
                    continue;
                }

                $deletedEntrega = isset($entrada['deleted']) && (string)$entrada['deleted'] === '1';

                // Validar RLS: la entrega debe pertenecer a la organización y licitación actual.
                $sqlCheck = 'SELECT 1
                             FROM tbl_entregas
                             WHERE id_entrega = :id_entrega
                               AND id_licitacion = :id_licitacion
                               AND organization_id = :organization_id
                             LIMIT 1';
                $stmtCheck = $pdo->prepare($sqlCheck);
                $stmtCheck->execute([
                    ':id_entrega' => $idEntrega,
                    ':id_licitacion' => $tenderId,
                    ':organization_id' => $organizationId,
                ]);

                if ($stmtCheck->fetchColumn() === false) {
                    // No pertenece a esta organización/lici; ignoramos por seguridad.
                    continue;
                }

                if ($deletedEntrega) {
                    // Borrado completo de entrega + líneas
                    $sqlDelReal = 'DELETE FROM tbl_licitaciones_real
                                   WHERE organization_id = :organization_id
                                     AND id_entrega = :id_entrega';
                    $stmtDelReal = $pdo->prepare($sqlDelReal);
                    $stmtDelReal->execute([
                        ':organization_id' => $organizationId,
                        ':id_entrega' => $idEntrega,
                    ]);

                    $sqlDelEnt = 'DELETE FROM tbl_entregas
                                  WHERE organization_id = :organization_id
                                    AND id_entrega = :id_entrega';
                    $stmtDelEnt = $pdo->prepare($sqlDelEnt);
                    $stmtDelEnt->execute([
                        ':organization_id' => $organizationId,
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
                                AND id_licitacion = :id_licitacion
                                AND organization_id = :organization_id';
                $stmtUpdEnt = $pdo->prepare($sqlUpdEnt);
                $stmtUpdEnt->execute([
                    ':fecha_entrega' => $fechaEntrega,
                    ':observaciones' => $observaciones,
                    ':id_entrega' => $idEntrega,
                    ':id_licitacion' => $tenderId,
                    ':organization_id' => $organizationId,
                ]);

                // Procesar líneas de la entrega
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
                                            WHERE organization_id = :organization_id
                                              AND id_entrega = :id_entrega
                                              AND id_real = :id_real';
                            $stmtDelLinea = $pdo->prepare($sqlDelLinea);
                            $stmtDelLinea->execute([
                                ':organization_id' => $organizationId,
                                ':id_entrega' => $idEntrega,
                                ':id_real' => $idReal,
                            ]);
                            continue;
                        }

                        // UPDATE de línea existente
                        $cantidad = $parseDecimal($lin['cantidad'] ?? null);
                        $pcu = $parseDecimal($lin['pcu'] ?? null);
                        $estado = isset($lin['estado']) ? trim((string)$lin['estado']) : '';
                        $cobrado = isset($lin['cobrado']) && (string)$lin['cobrado'] === '1';
                        $proveedor = isset($lin['proveedor']) ? trim((string)$lin['proveedor']) : '';

                        $sqlUpdLinea = 'UPDATE tbl_licitaciones_real
                                        SET cantidad = :cantidad,
                                            pcu = :pcu,
                                            proveedor = :proveedor,
                                            estado = :estado,
                                            cobrado = :cobrado
                                        WHERE organization_id = :organization_id
                                          AND id_entrega = :id_entrega
                                          AND id_real = :id_real';
                        $stmtUpdLinea = $pdo->prepare($sqlUpdLinea);
                        $stmtUpdLinea->execute([
                            ':cantidad' => $cantidad ?? 0.0,
                            ':pcu' => $pcu ?? 0.0,
                            ':proveedor' => $proveedor,
                            ':estado' => $estado !== '' ? $estado : 'EN ESPERA',
                            ':cobrado' => $cobrado ? 1 : 0,
                            ':organization_id' => $organizationId,
                            ':id_entrega' => $idEntrega,
                            ':id_real' => $idReal,
                        ]);
                    } else {
                        // Nueva línea para una entrega existente
                        $cantidad = $parseDecimal($lin['cantidad'] ?? null);
                        $pcu = $parseDecimal($lin['pcu'] ?? null);
                        $estado = isset($lin['estado']) ? trim((string)$lin['estado']) : '';
                        $cobrado = isset($lin['cobrado']) && (string)$lin['cobrado'] === '1';
                        $proveedor = isset($lin['proveedor']) ? trim((string)$lin['proveedor']) : '';

                        // Si no hay datos relevantes en la nueva línea, la ignoramos
                        if ($cantidad === null && $pcu === null && $proveedor === '') {
                            continue;
                        }

                        $sqlInsLinea = 'INSERT INTO tbl_licitaciones_real (
                                id_licitacion,
                                organization_id,
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
                                :organization_id,
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
                            ':organization_id' => $organizationId,
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
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            http_response_code(500);
            echo 'Error actualizando ejecución: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    /**
     * POST /licitaciones/{tender_id}/estado
     * Actualiza el estado (id_estado) de una licitación desde la vista SSR.
     */
    public function updateStatus(int $tenderId): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'Método no permitido';
            return;
        }

        if (!isset($_POST['estado'])) {
            http_response_code(400);
            echo 'Falta el parámetro estado.';
            return;
        }

        $estadoRaw = $_POST['estado'];
        if (!is_string($estadoRaw) && !is_numeric($estadoRaw)) {
            http_response_code(400);
            echo 'Parámetro estado inválido.';
            return;
        }

        $estadoId = (int)$estadoRaw;
        if ($estadoId <= 0) {
            http_response_code(400);
            echo 'Parámetro estado inválido.';
            return;
        }

        try {
            // Obtener estado actual respetando RLS
            $actual = $this->repository->getById($tenderId);
            if ($actual === null) {
                http_response_code(404);
                echo 'Licitación no encontrada.';
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
                echo 'Transición de estado no permitida desde el estado actual.';
                return;
            }

            // Usamos el repositorio para aplicar RLS automáticamente (organization_id).
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
     * Placeholder para creación de licitación (lógica de negocio pendiente de portar).
     */
    public function store(): void
    {
        $this->jsonResponse(501, ['error' => 'Not implemented: crear licitación aún no está portado a PHP.']);
    }

    /**
     * PUT /tenders/{tender_id}
     * Placeholder para actualización de licitación.
     */
    public function update(int $tenderId): void
    {
        $this->jsonResponse(501, ['error' => 'Not implemented: actualizar licitación aún no está portado a PHP.']);
    }

    /**
     * DELETE /tenders/{tender_id}
     * Placeholder para borrado de licitación.
     */
    public function destroy(int $tenderId): void
    {
        $this->jsonResponse(501, ['error' => 'Not implemented: borrar licitación aún no está portado a PHP.']);
    }

    /**
     * POST /tenders/{tender_id}/change-status
     * Placeholder para cambio de estado.
     */
    public function changeStatus(int $tenderId): void
    {
        $this->jsonResponse(501, ['error' => 'Not implemented: cambio de estado aún no está portado a PHP.']);
    }

    /**
     * POST /tenders/{tender_id}/partidas
     * Añade una partida a la licitación.
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
     * Simula resolución de organization_id desde cabecera/JWT.
     */
    private function resolveOrganizationId(): string
    {
        // Ejemplo: intentar leer de una cabecera HTTP personalizada.
        if (isset($_SERVER['HTTP_X_ORGANIZATION_ID']) && $_SERVER['HTTP_X_ORGANIZATION_ID'] !== '') {
            return (string)$_SERVER['HTTP_X_ORGANIZATION_ID'];
        }

        // Fallback de mock: valor fijo para desarrollo.
        return 'demo-organization';
    }

    /**
     * Lee y decodifica el cuerpo JSON de la petición.
     *
     * @return array<string, mixed>
     */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('JSON inválido en el cuerpo de la petición.');
        }

        /** @var array<string, mixed> $data */
        return is_array($data) ? $data : [];
    }

    /**
     * Envía una respuesta JSON estándar.
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
     * Envía una respuesta JSON de error incluyendo, opcionalmente, detalles internos en desarrollo.
     */
    private function jsonError(int $statusCode, string $message, \Throwable $e): void
    {
        $payload = [
            'error' => $message,
        ];

        // Podrías controlar la exposición de detalles según entorno.
        $payload['details'] = $e->getMessage();

        $this->jsonResponse($statusCode, $payload);
    }
}

