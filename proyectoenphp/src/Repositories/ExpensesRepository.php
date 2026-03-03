<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseRepository.php';

final class ExpensesRepository extends BaseRepository
{
    private const TABLE_LICITACIONES = 'tbl_licitaciones';
    private const TABLE_GASTOS = 'tbl_gastos_proyecto';

    private const ESTADO_ADJUDICADA = 5;
    private const ESTADOS_PERMITEN_GASTOS = [self::ESTADO_ADJUDICADA];

    public function __construct(string $organizationId)
    {
        parent::__construct($organizationId);
    }

    /**
     * Crea un gasto extraordinario asociado a una licitación.
     *
     * @return array<string, mixed>
     */
    public function createExpense(
        int $licitacionId,
        string $tipoGasto,
        float $importe,
        string $fecha, // YYYY-MM-DD
        string $descripcion,
        string $urlComprobante,
        string $userId
    ): array {
        $estadoLicitacion = $this->getLicitacionEstado($licitacionId);
        if ($estadoLicitacion === null) {
            throw new \InvalidArgumentException('Licitación no encontrada.', 404);
        }

        if (!in_array($estadoLicitacion, self::ESTADOS_PERMITEN_GASTOS, true)) {
            throw new \InvalidArgumentException(
                'No se pueden añadir gastos. La licitación debe estar en ADJUDICADA o EJECUCIÓN (no TERMINADA).',
                400
            );
        }

        $id = $this->generateUuidV4();

        $insert = [
            'id' => $id,
            'id_licitacion' => $licitacionId,
            'id_usuario' => $userId,
            'organization_id' => $this->organizationId,
            'tipo_gasto' => $tipoGasto,
            'importe' => $importe,
            'fecha' => $fecha,
            'descripcion' => $descripcion,
            'url_comprobante' => $urlComprobante,
            'estado' => 'PENDIENTE',
        ];

        $columns = array_keys($insert);
        $placeholders = array_map(
            static fn (string $col): string => ':' . $col,
            $columns
        );

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::TABLE_GASTOS,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $params = [];
        foreach ($insert as $col => $value) {
            $params[':' . $col] = $value;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $expense = $this->getExpenseById($id);
        if ($expense === null) {
            throw new \RuntimeException('Error al recuperar el gasto recién creado.');
        }

        return $expense;
    }

    /**
     * Lista los gastos extraordinarios de una licitación.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByLicitacion(int $licitacionId): array
    {
        if (!$this->licitacionExiste($licitacionId)) {
            throw new \InvalidArgumentException('Licitación no encontrada.', 404);
        }

        $where = $this->getRlsClause() . ' AND id_licitacion = :id_licitacion';
        $params = $this->getRlsParams();
        $params[':id_licitacion'] = $licitacionId;

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY fecha DESC, created_at DESC',
            self::TABLE_GASTOS,
            $where
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = $this->mapRowToExpense($row);
        }

        return $out;
    }

    /**
     * Actualiza estado y/o importe de un gasto.
     *
     * @return array<string, mixed>
     */
    public function updateExpenseStatus(
        string $expenseId,
        ?string $estado,
        ?float $importe
    ): array {
        $updates = [];
        if ($estado !== null) {
            $updates['estado'] = $estado;
        }
        if ($importe !== null) {
            $updates['importe'] = $importe;
        }

        if ($updates === []) {
            throw new \InvalidArgumentException(
                'Debe indicar estado (APROBADO/RECHAZADO) y/o importe.',
                400
            );
        }

        $set = [];
        $params = $this->getRlsParams();
        $params[':id'] = $expenseId;

        foreach ($updates as $col => $value) {
            $placeholder = ':' . $col;
            $set[] = sprintf('%s = %s', $col, $placeholder);
            $params[$placeholder] = $value;
        }

        $where = $this->getRlsClause() . ' AND id = :id';

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            self::TABLE_GASTOS,
            implode(', ', $set),
            $where
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Gasto no encontrado.', 404);
        }

        $expense = $this->getExpenseById($expenseId);
        if ($expense === null) {
            throw new \RuntimeException('Error al recuperar el gasto actualizado.');
        }

        return $expense;
    }

    /**
     * Elimina un gasto si está en estado PENDIENTE.
     */
    public function deleteExpense(string $expenseId): void
    {
        $where = $this->getRlsClause() . ' AND id = :id';
        $params = $this->getRlsParams();
        $params[':id'] = $expenseId;

        $sqlSelect = sprintf(
            'SELECT id, estado FROM %s WHERE %s LIMIT 1',
            self::TABLE_GASTOS,
            $where
        );

        $stmtSel = $this->pdo->prepare($sqlSelect);
        $stmtSel->execute($params);
        $existing = $stmtSel->fetch(\PDO::FETCH_ASSOC);

        if ($existing === false || $existing === null) {
            throw new \InvalidArgumentException('Gasto no encontrado.', 404);
        }

        if (($existing['estado'] ?? '') !== 'PENDIENTE') {
            throw new \InvalidArgumentException(
                'Solo se pueden eliminar gastos en estado PENDIENTE.',
                400
            );
        }

        $sqlDelete = sprintf(
            'DELETE FROM %s WHERE %s',
            self::TABLE_GASTOS,
            $where
        );

        $stmtDel = $this->pdo->prepare($sqlDelete);
        $stmtDel->execute($params);
    }

    /**
     * Obtiene un gasto por ID respetando RLS.
     *
     * @return array<string, mixed>|null
     */
    public function getExpenseById(string $expenseId): ?array
    {
        $where = $this->getRlsClause() . ' AND id = :id';
        $params = $this->getRlsParams();
        $params[':id'] = $expenseId;

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s LIMIT 1',
            self::TABLE_GASTOS,
            $where
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row === null) {
            return null;
        }

        return $this->mapRowToExpense($row);
    }

    /**
     * Devuelve el estado de una licitación (id_estado) respetando RLS.
     */
    private function getLicitacionEstado(int $licitacionId): ?int
    {
        $rls = str_replace(
            'organization_id',
            self::TABLE_LICITACIONES . '.organization_id',
            $this->getRlsClause()
        );

        $where = $rls . ' AND ' . self::TABLE_LICITACIONES . '.id_licitacion = :id_licitacion';
        $params = $this->getRlsParams();
        $params[':id_licitacion'] = $licitacionId;

        $sql = sprintf(
            'SELECT id_estado FROM %s WHERE %s LIMIT 1',
            self::TABLE_LICITACIONES,
            $where
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row === null) {
            return null;
        }

        return isset($row['id_estado']) ? (int)$row['id_estado'] : null;
    }

    /**
     * Comprueba si existe una licitación para esta organización.
     */
    private function licitacionExiste(int $licitacionId): bool
    {
        $rls = str_replace(
            'organization_id',
            self::TABLE_LICITACIONES . '.organization_id',
            $this->getRlsClause()
        );

        $where = $rls . ' AND ' . self::TABLE_LICITACIONES . '.id_licitacion = :id_licitacion';
        $params = $this->getRlsParams();
        $params[':id_licitacion'] = $licitacionId;

        $sql = sprintf(
            'SELECT id_licitacion FROM %s WHERE %s LIMIT 1',
            self::TABLE_LICITACIONES,
            $where
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false && $row !== null;
    }

    /**
     * Normaliza una fila de la BD al formato de respuesta.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRowToExpense(array $row): array
    {
        return [
            'id' => (string)($row['id'] ?? ''),
            'id_licitacion' => isset($row['id_licitacion']) ? (int)$row['id_licitacion'] : 0,
            'id_usuario' => (string)($row['id_usuario'] ?? ''),
            'organization_id' => (string)($row['organization_id'] ?? $this->organizationId),
            'tipo_gasto' => (string)($row['tipo_gasto'] ?? ''),
            'importe' => isset($row['importe']) ? (float)$row['importe'] : 0.0,
            'fecha' => (string)($row['fecha'] ?? ''),
            'descripcion' => (string)($row['descripcion'] ?? ''),
            'url_comprobante' => (string)($row['url_comprobante'] ?? ''),
            'estado' => (string)($row['estado'] ?? 'PENDIENTE'),
            'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : '',
        ];
    }

    /**
     * Genera un UUID v4 simple en formato string.
     */
    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        // Ajustar bits para versión y variante según RFC 4122
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

