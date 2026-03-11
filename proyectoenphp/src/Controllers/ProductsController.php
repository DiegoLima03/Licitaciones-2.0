<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/ProductsRepository.php';

final class ProductsController
{
    private ProductsRepository $repository;

    public function __construct()
    {
        $this->repository = new ProductsRepository();
    }

    /**
     * GET /productos/search
     * Búsqueda de productos por nombre.
     */
    public function index(): void
    {
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        if ($q === '') {
            $this->jsonResponse(400, ['error' => 'El parámetro q es obligatorio y no puede estar vacío.']);
            return;
        }

        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 100) {
            $limit = 100;
        }

        $onlyWithPreciosReferencia = false;
        if (isset($_GET['only_with_precios_referencia'])) {
            $raw = $_GET['only_with_precios_referencia'];
            // Soporta valores "true"/"false", "1"/"0", etc.
            $onlyWithPreciosReferencia = filter_var(
                $raw,
                FILTER_VALIDATE_BOOLEAN,
                ['flags' => FILTER_NULL_ON_FAILURE]
            ) ?? false;
        }

        try {
            $result = $this->repository->searchProductos($q, $limit, $onlyWithPreciosReferencia);
            $this->jsonResponse(200, $result);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Database error', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error', $e);
        }
    }

    /**
     * POST /productos
     * Esqueleto para crear producto (no existe endpoint equivalente en el código original).
     */
    public function store(): void
    {
        $this->jsonResponse(501, ['error' => 'Not implemented: creación de producto no está definida en el backend original.']);
    }

    /**
     * PUT /productos/{id}
     * Esqueleto para actualizar producto.
     */
    public function update(int $id): void
    {
        $this->jsonResponse(501, ['error' => 'Not implemented: actualización de producto no está definida en el backend original.']);
    }

    /**
     * DELETE /productos/{id}
     * Esqueleto para eliminar producto.
     */
    public function destroy(int $id): void
    {
        $this->jsonResponse(501, ['error' => 'Not implemented: borrado de producto no está definido en el backend original.']);
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

