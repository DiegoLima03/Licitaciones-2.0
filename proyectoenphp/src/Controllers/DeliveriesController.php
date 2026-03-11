<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/DeliveriesRepository.php';

final class DeliveriesController
{
    private DeliveriesRepository $repository;

    public function __construct()
    {
        $this->repository = new DeliveriesRepository();
    }

    /**
     * GET /deliveries
     * Lista entregas, opcionalmente filtradas por licitación.
     */
    public function index(): void
    {
        $licitacionId = null;
        if (isset($_GET['licitacion_id']) && $_GET['licitacion_id'] !== '') {
            if (!is_numeric($_GET['licitacion_id'])) {
                $this->jsonResponse(400, ['error' => 'El parámetro licitacion_id debe ser numérico.']);
                return;
            }
            $licitacionId = (int)$_GET['licitacion_id'];
        }

        try {
            $data = $this->repository->listDeliveries($licitacionId);
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Database error', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error', $e);
        }
    }

    /**
     * POST /deliveries
     * Crea una entrega (cabecera + líneas).
     */
    public function store(): void
    {
        try {
            $body = $this->readJsonBody();
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
            return;
        }

        // Validación mínima según DeliveryCreate / DeliveryHeaderCreate / DeliveryLineCreate
        if (!isset($body['id_licitacion']) || !is_numeric($body['id_licitacion'])) {
            $this->jsonResponse(400, ['error' => 'id_licitacion es obligatorio y debe ser numérico.']);
            return;
        }
        $idLicitacion = (int)$body['id_licitacion'];

        if (!isset($body['cabecera']) || !is_array($body['cabecera'])) {
            $this->jsonResponse(400, ['error' => 'cabecera es obligatoria y debe ser un objeto.']);
            return;
        }

        /** @var array<string, mixed> $cabecera */
        $cabecera = $body['cabecera'];

        if (!isset($cabecera['fecha']) || !is_string($cabecera['fecha']) || trim($cabecera['fecha']) === '') {
            $this->jsonResponse(400, ['error' => 'cabecera.fecha es obligatoria.']);
            return;
        }
        // Validación sencilla de formato YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cabecera['fecha'])) {
            $this->jsonResponse(400, ['error' => 'cabecera.fecha debe tener formato YYYY-MM-DD.']);
            return;
        }

        if (!isset($cabecera['codigo_albaran']) || !is_string($cabecera['codigo_albaran']) || trim($cabecera['codigo_albaran']) === '') {
            $this->jsonResponse(400, ['error' => 'cabecera.codigo_albaran es obligatorio.']);
            return;
        }

        $lineasRaw = $body['lineas'] ?? [];
        if (!is_array($lineasRaw)) {
            $this->jsonResponse(400, ['error' => 'lineas debe ser una lista.']);
            return;
        }

        $normalizedCabecera = [
            'fecha' => trim($cabecera['fecha']),
            'codigo_albaran' => trim($cabecera['codigo_albaran']),
            'observaciones' => isset($cabecera['observaciones']) ? (string)$cabecera['observaciones'] : '',
            'cliente' => isset($cabecera['cliente']) ? (string)$cabecera['cliente'] : null,
        ];

        $normalizedLineas = [];
        foreach ($lineasRaw as $line) {
            if (!is_array($line)) {
                continue;
            }

            $cantidad = isset($line['cantidad']) ? (float)$line['cantidad'] : 0.0;
            $costeUnit = isset($line['coste_unit']) ? (float)$line['coste_unit'] : 0.0;

            $normalizedLineas[] = [
                'id_producto' => array_key_exists('id_producto', $line) && $line['id_producto'] !== null
                    ? (int)$line['id_producto']
                    : null,
                'id_detalle' => array_key_exists('id_detalle', $line) && $line['id_detalle'] !== null
                    ? (int)$line['id_detalle']
                    : null,
                'id_tipo_gasto' => array_key_exists('id_tipo_gasto', $line) && $line['id_tipo_gasto'] !== null
                    ? (int)$line['id_tipo_gasto']
                    : null,
                'proveedor' => array_key_exists('proveedor', $line) ? (string)$line['proveedor'] : '',
                'cantidad' => $cantidad,
                'coste_unit' => $costeUnit,
            ];
        }

        $payload = [
            'id_licitacion' => $idLicitacion,
            'cabecera' => $normalizedCabecera,
            'lineas' => $normalizedLineas,
        ];

        try {
            $result = $this->repository->createDelivery($payload);
            $this->jsonResponse(201, $result);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode();
            $status = $code === 404 ? 404 : 400;
            $this->jsonResponse($status, ['error' => $e->getMessage()]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Database error', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error', $e);
        }
    }

    /**
     * PATCH /deliveries/lines/{id_real}
     * Actualiza estado y/o cobrado de una línea.
     */
    public function updateLine(int $idReal): void
    {
        try {
            $body = $this->readJsonBody();
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
            return;
        }

        $updates = [];
        if (array_key_exists('estado', $body) && $body['estado'] !== null && $body['estado'] !== '') {
            if (!is_string($body['estado'])) {
                $this->jsonResponse(400, ['error' => 'estado debe ser una cadena.']);
                return;
            }
            $updates['estado'] = (string)$body['estado'];
        }
        if (array_key_exists('cobrado', $body) && $body['cobrado'] !== null) {
            $updates['cobrado'] = (bool)$body['cobrado'];
        }

        try {
            $result = $this->repository->updateDeliveryLine($idReal, $updates);
            $this->jsonResponse(200, $result);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Database error', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error', $e);
        }
    }

    /**
     * DELETE /deliveries/{delivery_id}
     * Elimina una entrega y sus líneas.
     */
    public function destroy(int $deliveryId): void
    {
        try {
            $this->repository->deleteDelivery($deliveryId);
            $this->jsonResponse(204, null);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Database error', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error', $e);
        }
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
            throw new \InvalidArgumentException('El cuerpo de la petición no puede estar vacío.');
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
     * Envía una respuesta JSON de error incluyendo, opcionalmente, detalles internos.
     */
    private function jsonError(int $statusCode, string $message, \Throwable $e): void
    {
        $payload = [
            'error' => $message,
            'details' => $e->getMessage(),
        ];

        $this->jsonResponse($statusCode, $payload);
    }
}

