<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/ReferencePricesRepository.php';

final class ReferencePricesController
{
    private ReferencePricesRepository $repository;

    public function __construct(string $organizationId)
    {
        $this->repository = new ReferencePricesRepository($organizationId);
    }

    /**
     * GET /precios-referencia
     */
    public function index(): void
    {
        try {
            $rows = $this->repository->listAll();
            $this->jsonResponse(200, $rows);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error listando precios de referencia.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * POST /precios-referencia
     */
    public function store(): void
    {
        try {
            $body = $this->readJsonBody();
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
            return;
        }

        try {
            $row = $this->repository->create($body);
            $this->jsonResponse(201, $row);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 404) {
                $this->jsonResponse(404, ['error' => $e->getMessage()]);
                return;
            }
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error creando precio de referencia.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            throw new \InvalidArgumentException('El cuerpo de la petición no puede estar vacío.');
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new \InvalidArgumentException('JSON inválido en el cuerpo de la petición.');
        }

        return $data;
    }

    /**
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

    private function jsonError(int $statusCode, string $message, \Throwable $e): void
    {
        $this->jsonResponse($statusCode, [
            'error' => $message,
            'details' => $e->getMessage(),
        ]);
    }
}
