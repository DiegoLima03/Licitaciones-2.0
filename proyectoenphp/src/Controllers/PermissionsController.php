<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/PermissionsRepository.php';

final class PermissionsController
{
    private PermissionsRepository $repository;

    public function __construct(string $organizationId)
    {
        $this->repository = new PermissionsRepository($organizationId);
    }

    /**
     * GET /permissions/role-matrix
     * Devuelve la matriz completa de permisos por rol.
     */
    public function getRoleMatrix(): void
    {
        try {
            $matrix = $this->repository->getRoleMatrix();
            $this->jsonResponse(200, ['matrix' => $matrix]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error leyendo permisos.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * GET /permissions/role/{role}
     * Devuelve los permisos efectivos de un rol concreto.
     */
    public function getPermissionsForRole(string $role): void
    {
        $role = trim($role);
        if ($role === '') {
            $this->jsonResponse(400, ['error' => 'El parámetro role es obligatorio.']);
            return;
        }

        try {
            $perms = $this->repository->getPermissionsForRole($role);
            $this->jsonResponse(200, [
                'role' => strtolower($role),
                'permissions' => $perms,
            ]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error leyendo permisos de rol.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * PUT /permissions/role-matrix
     * Actualiza la matriz completa de permisos por rol para la organización actual.
     */
    public function updateRoleMatrix(): void
    {
        try {
            $body = $this->readJsonBody();
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
            return;
        }

        $matrix = $body['matrix'] ?? null;
        if (!is_array($matrix)) {
            $this->jsonResponse(400, ['error' => 'El cuerpo debe incluir "matrix" como objeto.']);
            return;
        }

        try {
            /** @var array<string, array<string, mixed>> $matrix */
            $updated = $this->repository->updateRoleMatrix($matrix);
            $this->jsonResponse(200, ['matrix' => $updated]);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() === 400 ? 400 : 500;
            $this->jsonResponse($code, ['error' => $e->getMessage()]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error actualizando permisos.', $e);
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
     * Envía una respuesta JSON de error incluyendo detalles.
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

