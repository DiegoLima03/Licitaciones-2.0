<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/CatalogsRepository.php';

final class CatalogsController
{
    private CatalogsRepository $repository;

    public function __construct()
    {
        $this->repository = new CatalogsRepository();
    }

    /**
     * GET /estados
     * Lista todos los estados.
     */
    public function getEstados(): void
    {
        try {
            $data = $this->repository->getEstados();
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error listando estados.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * GET /tipos
     * Lista todos los tipos de licitaciÃ³n.
     */
    public function getTipos(): void
    {
        try {
            $data = $this->repository->getTipos();
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error listando tipos.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * GET /tipos-gasto
     * Lista todos los tipos de gasto.
     */
    public function getTiposGasto(): void
    {
        try {
            $data = $this->repository->getTiposGasto();
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error listando tipos de gasto.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
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


