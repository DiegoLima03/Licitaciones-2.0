<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/SearchRepository.php';

final class SearchController
{
    private SearchRepository $repository;

    public function __construct(string $organizationId)
    {
        $this->repository = new SearchRepository($organizationId);
    }

    /**
     * GET /search?q=...
     * Búsqueda histórica de productos (licitaciones + precios de referencia).
     */
    public function searchProducts(): void
    {
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        if ($q === '') {
            $this->jsonResponse(400, ['error' => 'El parámetro q es obligatorio y no puede estar vacío.']);
            return;
        }

        try {
            $results = $this->repository->searchProducts($q);
            $this->jsonResponse(200, $results);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error en búsqueda.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * GET /reference-prices/{productId}
     * Devuelve el precio medio histórico de referencia de un producto.
     */
    public function getReferencePrice(int $productId): void
    {
        if ($productId <= 0) {
            $this->jsonResponse(400, ['error' => 'productId debe ser un entero positivo.']);
            return;
        }

        try {
            $data = $this->repository->getReferencePrice($productId);
            if (($data['count'] ?? 0) === 0) {
                $this->jsonResponse(404, ['error' => 'No hay precios de referencia para este producto.']);
                return;
            }
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error obteniendo precio de referencia.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
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
     * Envía una respuesta JSON de error incluyendo detalles básicos.
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

