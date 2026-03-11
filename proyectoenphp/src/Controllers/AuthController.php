<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Repositories/AuthRepository.php';

final class AuthController
{
    /**
     * @var array<int, string>
     */
    private const ROLES_VALIDOS = [
        'admin',
        'admin_planta',
        'admin_licitaciones',
        'member_planta',
        'member_licitaciones',
    ];

    private const DEFAULT_ROLE = 'member_licitaciones';

    /**
     * JerarquÃ­a de borrado de usuarios.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLES_ACTOR_CAN_DELETE = [
        'admin' => ['admin_planta', 'admin_licitaciones', 'member_planta', 'member_licitaciones'],
        'admin_planta' => ['member_planta'],
        'admin_licitaciones' => ['member_licitaciones'],
        'member_planta' => [],
        'member_licitaciones' => [],
    ];

    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * POST /auth/login
     * Inicio de sesiÃ³n con email y contraseÃ±a.
     */
    public function login(): void
    {
        try {
            $body = $this->readJsonBody();
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
            return;
        }

        $email = isset($body['email']) ? trim((string)$body['email']) : '';
        $password = isset($body['password']) ? (string)$body['password'] : '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->jsonResponse(400, ['error' => 'El campo email es obligatorio y debe tener un formato vÃ¡lido.']);
            return;
        }

        if ($password === '') {
            $this->jsonResponse(400, ['error' => 'El campo password es obligatorio.']);
            return;
        }

        try {
            $repo = new AuthRepository();
            $user = $repo->findByEmail($email);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error de base de datos durante el login.', $e);
            return;
        }

        if ($user === null) {
            $this->jsonResponse(401, ['error' => 'Credenciales invÃ¡lidas.']);
            return;
        }

        $hash = $user['password_hash'] ?? null;
        if (!is_string($hash) || $hash === '' || !password_verify($password, $hash)) {
            $this->jsonResponse(401, ['error' => 'Credenciales invÃ¡lidas.']);
            return;
        }

        $userId = (string)($user['id'] ?? '');
        $role = $this->normalizeRole((string)($user['role'] ?? self::DEFAULT_ROLE));
        $fullName = isset($user['full_name']) ? (string)$user['full_name'] : null;
        $emailOut = (string)($user['email'] ?? $email);

        if ($userId === '') {
            $this->jsonResponse(500, ['error' => 'El usuario no tiene un identificador vÃ¡lido.']);
            return;
        }

        $now = time();
        $accessToken = $this->buildAccessToken([
            'sub' => $userId,
            'user_id' => $userId,
            'email' => $emailOut,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + 60 * 60 * 12,
        ]);

        $response = [
            'id' => $userId,
            'email' => $emailOut,
            'role' => $role,
            'rol' => $role,
            'full_name' => $fullName,
            'nombre' => $fullName,
            'access_token' => $accessToken,
            'token_type' => 'bearer',
        ];

        $this->jsonResponse(200, $response);
    }

    /**
     * GET /auth/me
     */
    public function me(array $tokenPayload): void
    {
        $userId = $this->resolveUserId($tokenPayload);

        if ($userId === '') {
            $this->jsonResponse(401, ['error' => 'Token invÃ¡lido.']);
            return;
        }

        try {
            $repo = new AuthRepository();
            $user = $repo->findById($userId);
            if ($user === null) {
                $this->jsonResponse(404, ['error' => 'Usuario no encontrado.']);
                return;
            }

            $role = $this->normalizeRole((string)($user['role'] ?? self::DEFAULT_ROLE));
            $fullName = isset($user['full_name']) ? (string)$user['full_name'] : null;

            $this->jsonResponse(200, [
                'id' => (string)($user['id'] ?? ''),
                'email' => (string)($user['email'] ?? ''),
                'role' => $role,
                'full_name' => $fullName,
                'nombre' => $fullName,
            ]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error consultando el perfil del usuario.', $e);
        }
    }

    /**
     * PATCH /auth/me/password
     */
    public function updateMyPassword(array $tokenPayload): void
    {
        $userId = $this->resolveUserId($tokenPayload);

        if ($userId === '') {
            $this->jsonResponse(401, ['error' => 'Token invÃ¡lido.']);
            return;
        }

        try {
            $body = $this->readJsonBody();
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
            return;
        }

        $password = isset($body['password']) ? (string)$body['password'] : '';
        if (mb_strlen($password) < 6) {
            $this->jsonResponse(400, ['error' => 'La contraseÃ±a debe tener al menos 6 caracteres.']);
            return;
        }

        try {
            $repo = new AuthRepository();
            $updated = $repo->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
            if (!$updated) {
                $this->jsonResponse(404, ['error' => 'Usuario no encontrado.']);
                return;
            }

            $this->jsonResponse(200, ['message' => 'ContraseÃ±a actualizada correctamente.']);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error actualizando contraseÃ±a.', $e);
        }
    }

    /**
     * POST /auth/users
     */
    public function createUser(string $actorRole): void
    {
        if (!$this->canManageUsers($actorRole)) {
            $this->jsonResponse(403, ['error' => 'No tienes permisos para crear usuarios.']);
            return;
        }

        try {
            $body = $this->readJsonBody();
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
            return;
        }

        $email = isset($body['email']) ? trim((string)$body['email']) : '';
        $password = isset($body['password']) ? (string)$body['password'] : '';
        $fullName = isset($body['full_name']) ? trim((string)$body['full_name']) : null;
        $rawRole = isset($body['role']) ? (string)$body['role'] : '';
        $role = $rawRole !== '' ? mb_strtolower(trim($rawRole)) : self::DEFAULT_ROLE;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->jsonResponse(400, ['error' => 'Email invÃ¡lido.']);
            return;
        }
        if (mb_strlen($password) < 6) {
            $this->jsonResponse(400, ['error' => 'La contraseÃ±a debe tener al menos 6 caracteres.']);
            return;
        }
        if (!in_array($role, self::ROLES_VALIDOS, true)) {
            $this->jsonResponse(400, ['error' => 'Rol invÃ¡lido.']);
            return;
        }

        try {
            $repo = new AuthRepository();
            if ($repo->emailExists($email)) {
                $this->jsonResponse(409, ['error' => 'Ya existe un usuario con ese email.']);
                return;
            }

            $userId = $this->generateUuidV4();
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $repo->createUser($userId, $email, $passwordHash, $role, $fullName);

            $this->jsonResponse(201, [
                'id' => $userId,
                'email' => $email,
                'message' => 'Usuario creado correctamente.',
            ]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error creando usuario.', $e);
        }
    }

    /**
     * GET /auth/users
     */
    public function listUsers(string $actorRole): void
    {
        if (!$this->canManageUsers($actorRole)) {
            $this->jsonResponse(403, ['error' => 'No tienes permisos para listar usuarios.']);
            return;
        }

        try {
            $repo = new AuthRepository();
            $rows = $repo->listUsers();
            $out = [];

            foreach ($rows as $row) {
                $out[] = [
                    'id' => (string)($row['id'] ?? ''),
                    'email' => $row['email'] ?? null,
                    'full_name' => $row['full_name'] ?? null,
                    'role' => $this->normalizeRole((string)($row['role'] ?? self::DEFAULT_ROLE)),
                ];
            }

            $this->jsonResponse(200, $out);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error listando usuarios.', $e);
        }
    }

    /**
     * PATCH /auth/users/{user_id}
     */
    public function updateUserRole(string $actorRole, string $userId): void
    {
        if (!$this->canManageUsers($actorRole)) {
            $this->jsonResponse(403, ['error' => 'No tienes permisos para cambiar roles.']);
            return;
        }

        $userId = trim($userId);
        if ($userId === '') {
            $this->jsonResponse(400, ['error' => 'Identificador de usuario invÃ¡lido.']);
            return;
        }

        try {
            $body = $this->readJsonBody();
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
            return;
        }

        $role = isset($body['role']) ? mb_strtolower(trim((string)$body['role'])) : '';
        if (!in_array($role, self::ROLES_VALIDOS, true)) {
            $this->jsonResponse(400, ['error' => 'Rol invÃ¡lido.']);
            return;
        }

        try {
            $repo = new AuthRepository();
            $target = $repo->findById($userId);
            if ($target === null) {
                $this->jsonResponse(404, ['error' => 'Usuario no encontrado.']);
                return;
            }

            $repo->updateRole($userId, $role);
            $this->jsonResponse(200, ['id' => $userId, 'role' => $role]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error actualizando rol de usuario.', $e);
        }
    }

    /**
     * PATCH /auth/users/{user_id}/password
     */
    public function updateUserPassword(string $actorRole, string $userId): void
    {
        if (!$this->canManageUsers($actorRole)) {
            $this->jsonResponse(403, ['error' => 'No tienes permisos para cambiar contraseÃ±as de otros usuarios.']);
            return;
        }

        try {
            $body = $this->readJsonBody();
        } catch (\InvalidArgumentException $e) {
            $this->jsonResponse(400, ['error' => $e->getMessage()]);
            return;
        }

        $password = isset($body['password']) ? (string)$body['password'] : '';
        if (mb_strlen($password) < 6) {
            $this->jsonResponse(400, ['error' => 'La contraseÃ±a debe tener al menos 6 caracteres.']);
            return;
        }

        try {
            $repo = new AuthRepository();
            $target = $repo->findById($userId);
            if ($target === null) {
                $this->jsonResponse(404, ['error' => 'Usuario no encontrado.']);
                return;
            }

            $repo->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
            $this->jsonResponse(200, [
                'id' => $userId,
                'message' => 'ContraseÃ±a actualizada correctamente.',
            ]);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error actualizando contraseÃ±a.', $e);
        }
    }

    /**
     * DELETE /auth/users/{user_id}
     */
    public function destroyUser(string $actorRole, string $actorUserId, string $userId): void {
        if (!$this->canManageUsers($actorRole)) {
            $this->jsonResponse(403, ['error' => 'No tienes permisos para eliminar usuarios.']);
            return;
        }

        try {
            $repo = new AuthRepository();
            $target = $repo->findById($userId);
            if ($target === null) {
                $this->jsonResponse(404, ['error' => 'Usuario no encontrado.']);
                return;
            }

            $actorRoleNorm = $this->normalizeRole($actorRole);
            $targetRoleNorm = $this->normalizeRole((string)($target['role'] ?? self::DEFAULT_ROLE));
            if (!$this->canDeleteUser($actorRoleNorm, $targetRoleNorm)) {
                $this->jsonResponse(403, ['error' => 'No puedes eliminar a un usuario con tu mismo rol o superior.']);
                return;
            }

            if ($actorUserId !== '' && $actorUserId === $userId) {
                $this->jsonResponse(403, ['error' => 'No puedes eliminar tu propio usuario.']);
                return;
            }

            $repo->deleteUser($userId);
            $this->jsonResponse(204, null);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error eliminando usuario.', $e);
        }
    }

    /**
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
     * Comprueba si el rol puede gestionar usuarios.
     */
    private function canManageUsers(string $role): bool
    {
        $roleNorm = $this->normalizeRole($role);
        if ($roleNorm === 'admin') {
            return true;
        }

        try {
            $repo = new AuthRepository();
            return $repo->canManageUsersByRoleFeature($roleNorm);
        } catch (\Throwable) {
            return false;
        }
    }

    private function canDeleteUser(string $actorRole, string $targetRole): bool
    {
        $allowed = self::ROLES_ACTOR_CAN_DELETE[$actorRole] ?? [];
        return in_array($this->normalizeRole($targetRole), $allowed, true);
    }

    private function normalizeRole(?string $role): string
    {
        if ($role === null || trim($role) === '') {
            return self::DEFAULT_ROLE;
        }

        $roleNorm = mb_strtolower(trim($role));
        if (in_array($roleNorm, self::ROLES_VALIDOS, true)) {
            return $roleNorm;
        }

        if ($roleNorm === 'member') {
            return 'member_licitaciones';
        }

        return self::DEFAULT_ROLE;
    }

    private function resolveUserId(array $tokenPayload): string
    {
        if (isset($tokenPayload['user_id']) && is_scalar($tokenPayload['user_id'])) {
            return (string)$tokenPayload['user_id'];
        }
        if (isset($tokenPayload['sub']) && is_scalar($tokenPayload['sub'])) {
            return (string)$tokenPayload['sub'];
        }
        return '';
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function buildAccessToken(array $claims): string
    {
        $header = ['alg' => 'none', 'typ' => 'JWT'];
        $segments = [
            $this->base64UrlEncode((string)json_encode($header, JSON_UNESCAPED_UNICODE)),
            $this->base64UrlEncode((string)json_encode($claims, JSON_UNESCAPED_UNICODE)),
            '',
        ];

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $plain): string
    {
        return rtrim(strtr(base64_encode($plain), '+/', '-_'), '=');
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
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




