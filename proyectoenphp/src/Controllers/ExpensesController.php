<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/ExpensesRepository.php';

final class ExpensesController
{
    private ExpensesRepository $repository;
    private string $userId;
    private string $role;

    /**
     * @param string $userId         ID (UUID/string) del usuario autenticado.
     * @param string $role           Rol del usuario (admin, member_..., etc.).
     */
    public function __construct(string $userId, string $role)
    {
        $this->repository = new ExpensesRepository();
        $this->userId = $userId;
        $this->role = $role;
    }

    /**
     * GET /expenses/tipos
     * Lista los tipos de gasto disponibles.
     */
    public function listExpenseTypes(): void
    {
        $labels = [
            'ALOJAMIENTO' => 'Alojamiento',
            'TRANSPORTE' => 'Transporte',
            'DIETAS' => 'Dietas',
            'SUMINISTROS' => 'Suministros',
            'COMBUSTIBLE' => 'Combustible',
            'HOTEL' => 'Hotel',
            'OTROS' => 'Otros',
        ];

        $values = array_keys($labels);

        $out = [];
        foreach ($values as $value) {
            $out[] = [
                'value' => $value,
                'label' => $labels[$value] ?? $value,
            ];
        }

        $this->jsonResponse(200, $out);
    }

    /**
     * POST /expenses
     * Crea un gasto extraordinario.
     */
    public function store(): void
    {
        try {
            $body = $this->readJsonBody();
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
            return;
        }

        // ValidaciÃ³n segÃºn ProjectExpenseCreate
        if (!isset($body['id_licitacion']) || !is_numeric($body['id_licitacion'])) {
            $this->jsonResponse(400, ['error' => 'id_licitacion es obligatorio y debe ser numÃ©rico.']);
            return;
        }
        $idLicitacion = (int)$body['id_licitacion'];

        $allowedTipos = [
            'COMBUSTIBLE',
            'HOTEL',
            'ALOJAMIENTO',
            'TRANSPORTE',
            'DIETAS',
            'SUMINISTROS',
            'OTROS',
        ];
        $tipoGasto = isset($body['tipo_gasto']) ? (string)$body['tipo_gasto'] : '';
        if ($tipoGasto === '' || !in_array($tipoGasto, $allowedTipos, true)) {
            $this->jsonResponse(400, ['error' => 'tipo_gasto es obligatorio y debe ser un valor vÃ¡lido.']);
            return;
        }

        if (!isset($body['importe']) || !is_numeric($body['importe'])) {
            $this->jsonResponse(400, ['error' => 'importe es obligatorio y debe ser numÃ©rico.']);
            return;
        }
        $importe = (float)$body['importe'];
        if ($importe <= 0) {
            $this->jsonResponse(400, ['error' => 'importe debe ser mayor que 0.']);
            return;
        }

        if (!isset($body['fecha']) || !is_string($body['fecha']) || trim($body['fecha']) === '') {
            $this->jsonResponse(400, ['error' => 'fecha es obligatoria.']);
            return;
        }
        $fecha = trim($body['fecha']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $this->jsonResponse(400, ['error' => 'fecha debe tener formato YYYY-MM-DD.']);
            return;
        }

        if (!isset($body['url_comprobante']) || !is_string($body['url_comprobante']) || trim($body['url_comprobante']) === '') {
            $this->jsonResponse(400, ['error' => 'url_comprobante es obligatorio.']);
            return;
        }
        $urlComprobante = trim($body['url_comprobante']);

        $descripcion = isset($body['descripcion']) ? (string)$body['descripcion'] : '';

        try {
            $expense = $this->repository->createExpense(
                $idLicitacion,
                $tipoGasto,
                $importe,
                $fecha,
                $descripcion,
                $urlComprobante,
                $this->userId
            );
            $this->jsonResponse(201, $expense);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode();
            $status = $code === 404 ? 404 : 400;
            $this->jsonResponse($status, ['error' => $e->getMessage()]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error creando gasto.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * GET /expenses/licitacion/{id_licitacion}
     * Lista los gastos de una licitaciÃ³n.
     */
    public function listByLicitacion(int $licitacionId): void
    {
        try {
            $data = $this->repository->listByLicitacion($licitacionId);
            $this->jsonResponse(200, $data);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode();
            $status = $code === 404 ? 404 : 400;
            $this->jsonResponse($status, ['error' => $e->getMessage()]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error listando gastos.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * PATCH /expenses/{id}/status
     * Actualiza el estado y/o importe de un gasto (solo admin).
     */
    public function updateStatus(string $expenseId): void
    {
        if ($this->role !== 'admin') {
            $this->jsonResponse(403, ['error' => 'Solo un administrador puede aprobar o rechazar gastos.']);
            return;
        }

        try {
            $body = $this->readJsonBody();
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
            return;
        }

        $estado = null;
        if (array_key_exists('estado', $body) && $body['estado'] !== null && $body['estado'] !== '') {
            if (!is_string($body['estado'])) {
                $this->jsonResponse(400, ['error' => 'estado debe ser una cadena.']);
                return;
            }
            $value = (string)$body['estado'];
            $allowedEstados = ['PENDIENTE', 'APROBADO', 'RECHAZADO'];
            if (!in_array($value, $allowedEstados, true)) {
                $this->jsonResponse(400, ['error' => 'estado debe ser PENDIENTE, APROBADO o RECHAZADO.']);
                return;
            }
            $estado = $value;
        }

        $importe = null;
        if (array_key_exists('importe', $body) && $body['importe'] !== null) {
            if (!is_numeric($body['importe'])) {
                $this->jsonResponse(400, ['error' => 'importe debe ser numÃ©rico.']);
                return;
            }
            $importe = (float)$body['importe'];
            if ($importe <= 0) {
                $this->jsonResponse(400, ['error' => 'importe debe ser mayor que 0.']);
                return;
            }
        }

        try {
            $expense = $this->repository->updateExpenseStatus($expenseId, $estado, $importe);
            $this->jsonResponse(200, $expense);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode();
            if ($code === 404) {
                $this->jsonResponse(404, ['error' => $e->getMessage()]);
            } else {
                $this->jsonResponse(400, ['error' => $e->getMessage()]);
            }
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error actualizando gasto.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * DELETE /expenses/{id}
     * Elimina un gasto (solo si estÃ¡ en estado PENDIENTE).
     */
    public function destroy(string $expenseId): void
    {
        try {
            $this->repository->deleteExpense($expenseId);
            $this->jsonResponse(204, null);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode();
            if ($code === 404) {
                $this->jsonResponse(404, ['error' => $e->getMessage()]);
            } else {
                $this->jsonResponse(400, ['error' => $e->getMessage()]);
            }
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error eliminando gasto.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * Lee y decodifica el cuerpo JSON de la peticiÃ³n.
     *
     * @return array<string, mixed>
     */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            throw new \InvalidArgumentException('El cuerpo de la peticiÃ³n no puede estar vacÃ­o.');
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
     * EnvÃ­a una respuesta JSON de error incluyendo, opcionalmente, detalles internos.
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


