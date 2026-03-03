<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseRepository.php';

final class AuthRepository extends BaseRepository
{
    private const TABLE_PROFILES = 'profiles';
    private const TABLE_ROLE_PERMISSIONS = 'role_permissions';

    /**
     * Obtiene un usuario por email (sin filtro de organization_id).
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $sql = sprintf(
            'SELECT id, email, password_hash, organization_id, role, full_name
             FROM %s
             WHERE email = :email
             LIMIT 1',
            self::TABLE_PROFILES
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row === null) {
            return null;
        }

        return $row;
    }

    /**
     * Obtiene un usuario por ID dentro de la organización actual.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $userId): ?array
    {
        $sql = sprintf(
            'SELECT id, email, organization_id, role, full_name, password_hash
             FROM %s
             WHERE %s AND id = :id
             LIMIT 1',
            self::TABLE_PROFILES,
            $this->getRlsClause()
        );

        $params = $this->getRlsParams();
        $params[':id'] = $userId;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row === null) {
            return null;
        }

        return $row;
    }

    /**
     * Devuelve true si existe un usuario con ese email.
     */
    public function emailExists(string $email): bool
    {
        $sql = sprintf(
            'SELECT 1
             FROM %s
             WHERE email = :email
             LIMIT 1',
            self::TABLE_PROFILES
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Crea un usuario dentro de la organización actual.
     */
    public function createUser(
        string $id,
        string $email,
        string $passwordHash,
        string $role,
        ?string $fullName
    ): void {
        $sql = sprintf(
            'INSERT INTO %s (id, email, password_hash, organization_id, role, full_name)
             VALUES (:id, :email, :password_hash, :organization_id, :role, :full_name)',
            self::TABLE_PROFILES
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':organization_id' => $this->organizationId,
            ':role' => $role,
            ':full_name' => $fullName !== null && trim($fullName) !== '' ? trim($fullName) : null,
        ]);
    }

    /**
     * Lista usuarios de la organización actual.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listUsers(): array
    {
        $sql = sprintf(
            'SELECT id, email, full_name, role
             FROM %s
             WHERE %s
             ORDER BY full_name ASC, email ASC',
            self::TABLE_PROFILES,
            $this->getRlsClause()
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->getRlsParams());

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return $rows;
    }

    /**
     * Actualiza rol de un usuario de la organización actual.
     */
    public function updateRole(string $userId, string $role): bool
    {
        $sql = sprintf(
            'UPDATE %s
             SET role = :role
             WHERE %s AND id = :id',
            self::TABLE_PROFILES,
            $this->getRlsClause()
        );

        $params = $this->getRlsParams();
        $params[':id'] = $userId;
        $params[':role'] = $role;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Actualiza contraseña de un usuario de la organización actual.
     */
    public function updatePassword(string $userId, string $passwordHash): bool
    {
        $sql = sprintf(
            'UPDATE %s
             SET password_hash = :password_hash
             WHERE %s AND id = :id',
            self::TABLE_PROFILES,
            $this->getRlsClause()
        );

        $params = $this->getRlsParams();
        $params[':id'] = $userId;
        $params[':password_hash'] = $passwordHash;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Elimina usuario de la organización actual.
     */
    public function deleteUser(string $userId): bool
    {
        $sql = sprintf(
            'DELETE FROM %s
             WHERE %s AND id = :id',
            self::TABLE_PROFILES,
            $this->getRlsClause()
        );

        $params = $this->getRlsParams();
        $params[':id'] = $userId;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Comprueba permiso "usuarios" en role_permissions para el rol actual.
     */
    public function canManageUsersByRoleFeature(string $role): bool
    {
        $sql = sprintf(
            'SELECT allowed
             FROM %s
             WHERE %s
               AND role = :role
               AND feature = :feature
             LIMIT 1',
            self::TABLE_ROLE_PERMISSIONS,
            $this->getRlsClause()
        );

        $params = $this->getRlsParams();
        $params[':role'] = $role;
        $params[':feature'] = 'usuarios';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row === null) {
            return false;
        }

        return (bool)($row['allowed'] ?? false);
    }
}
