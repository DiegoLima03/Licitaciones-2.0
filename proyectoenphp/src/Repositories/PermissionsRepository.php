<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseRepository.php';

final class PermissionsRepository extends BaseRepository
{
    private const TABLE_ROLE_PERMISSIONS = 'role_permissions';
    /**
     * @var array<int, string>
     */
    private const FEATURE_KEYS = [
        'dashboard',
        'licitaciones',
        'buscador',
        'lineas',
        'analytics',
        'usuarios',
    ];

    /**
     * Devuelve la matriz de permisos para la organización actual.
     *
     * @return array<string, array<string, bool>> role => feature => allowed
     */
    public function getRoleMatrix(): array
    {
        $matrix = $this->getDefaultMatrix();

        // Intentar leer configuración guardada; si la tabla no existe, devolvemos defaults.
        try {
            $sql = sprintf(
                'SELECT role, feature, allowed
                 FROM %s
                 WHERE %s',
                self::TABLE_ROLE_PERMISSIONS,
                $this->getRlsClause()
            );
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->getRlsParams());
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            if ($this->isMissingTableError($e)) {
                return $matrix;
            }
            throw $e;
        }

        if ($rows === []) {
            return $matrix;
        }

        foreach ($rows as $row) {
            $role = strtolower(trim((string)($row['role'] ?? '')));
            $feature = trim((string)($row['feature'] ?? ''));
            $allowed = (bool)($row['allowed'] ?? false);

            if ($role === '' || !isset($matrix[$role])) {
                // Si el rol no está en la matriz por defecto, inicializamos todas las features a false.
                if ($role === '') {
                    continue;
                }
                $matrix[$role] = array_fill_keys(array_keys($this->getDefaultMatrix()['admin']), false);
            }

            if ($feature !== '') {
                $matrix[$role][$feature] = $allowed;
            }
        }

        return $matrix;
    }

    /**
     * Devuelve los permisos (features) efectivos para un rol concreto.
     *
     * @return array<string, bool> feature => allowed
     */
    public function getPermissionsForRole(string $role): array
    {
        $role = strtolower(trim($role));
        $matrix = $this->getRoleMatrix();

        if ($role === '' || !isset($matrix[$role])) {
            // Rol desconocido: sin permisos
            return array_fill_keys(array_keys($this->getDefaultMatrix()['admin']), false);
        }

        return $matrix[$role];
    }

    /**
     * Reemplaza la matriz de permisos de la organización por la enviada.
     *
     * @param array<string, array<string, mixed>> $matrix
     * @return array<string, array<string, bool>>
     */
    public function updateRoleMatrix(array $matrix): array
    {
        $rowsToInsert = [];
        foreach ($matrix as $role => $permissions) {
            $roleNorm = strtolower(trim((string)$role));
            if ($roleNorm === '' || !is_array($permissions)) {
                continue;
            }

            foreach ($permissions as $feature => $allowed) {
                $featureNorm = trim((string)$feature);
                if (!in_array($featureNorm, self::FEATURE_KEYS, true)) {
                    continue;
                }
                $rowsToInsert[] = [
                    'role' => $roleNorm,
                    'feature' => $featureNorm,
                    'allowed' => (bool)$allowed,
                ];
            }
        }

        $this->pdo->beginTransaction();
        try {
            $deleteSql = sprintf(
                'DELETE FROM %s WHERE %s',
                self::TABLE_ROLE_PERMISSIONS,
                $this->getRlsClause()
            );
            $deleteStmt = $this->pdo->prepare($deleteSql);
            $deleteStmt->execute($this->getRlsParams());

            if ($rowsToInsert !== []) {
                $insertSql = sprintf(
                    'INSERT INTO %s (organization_id, role, feature, allowed)
                     VALUES (:organization_id, :role, :feature, :allowed)',
                    self::TABLE_ROLE_PERMISSIONS
                );
                $insertStmt = $this->pdo->prepare($insertSql);

                foreach ($rowsToInsert as $row) {
                    $insertStmt->execute([
                        ':organization_id' => $this->organizationId,
                        ':role' => $row['role'],
                        ':feature' => $row['feature'],
                        ':allowed' => $row['allowed'] ? 1 : 0,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($this->isMissingTableError($e)) {
                throw new \RuntimeException(
                    "La tabla 'role_permissions' no existe aún en la base de datos.",
                    400,
                    $e
                );
            }
            throw $e;
        }

        return $this->getRoleMatrix();
    }

    /**
     * Matriz de permisos por defecto (copiada de backend/routers/permissions.py).
     *
     * @return array<string, array<string, bool>>
     */
    private function getDefaultMatrix(): array
    {
        return [
            'admin' => [
                'dashboard' => true,
                'licitaciones' => true,
                'buscador' => true,
                'lineas' => true,
                'analytics' => true,
                'usuarios' => true,
            ],
            'admin_licitaciones' => [
                'dashboard' => true,
                'licitaciones' => true,
                'buscador' => true,
                'lineas' => true,
                'analytics' => true,
                'usuarios' => false,
            ],
            'member_licitaciones' => [
                'dashboard' => true,
                'licitaciones' => true,
                'buscador' => true,
                'lineas' => true,
                'analytics' => false,
                'usuarios' => false,
            ],
            'admin_planta' => [
                'dashboard' => false,
                'licitaciones' => true,
                'buscador' => true,
                'lineas' => true,
                'analytics' => false,
                'usuarios' => false,
            ],
            'member_planta' => [
                'dashboard' => false,
                'licitaciones' => true,
                'buscador' => true,
                'lineas' => false,
                'analytics' => false,
                'usuarios' => false,
            ],
        ];
    }

    /**
     * Detecta si el error viene de que la tabla role_permissions no existe.
     */
    private function isMissingTableError(\PDOException $e): bool
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'role_permissions') !== false && stripos($msg, 'doesn\'t exist') !== false) {
            return true;
        }
        if (stripos($msg, 'role_permissions') !== false && stripos($msg, 'could not find the table') !== false) {
            return true;
        }
        return false;
    }
}

