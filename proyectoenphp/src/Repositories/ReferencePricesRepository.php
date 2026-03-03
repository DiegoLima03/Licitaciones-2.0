<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseRepository.php';

final class ReferencePricesRepository extends BaseRepository
{
    private const TABLE_PRECIOS_REFERENCIA = 'tbl_precios_referencia';
    private const TABLE_PRODUCTOS = 'tbl_productos';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $sql = sprintf(
            'SELECT
                pr.id,
                pr.id_producto,
                p.nombre AS product_nombre,
                pr.pvu,
                pr.pcu,
                pr.unidades,
                pr.proveedor,
                pr.notas,
                pr.fecha_presupuesto
             FROM %1$s pr
             LEFT JOIN %2$s p
               ON p.id = pr.id_producto
             WHERE pr.%3$s
             ORDER BY pr.fecha_presupuesto DESC, pr.id DESC',
            self::TABLE_PRECIOS_REFERENCIA,
            self::TABLE_PRODUCTOS,
            $this->getRlsClause()
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->getRlsParams());
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->normalizeRow($r);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $productId = isset($payload['id_producto']) ? (int)$payload['id_producto'] : 0;
        if ($productId <= 0) {
            throw new \InvalidArgumentException('id_producto es obligatorio y debe ser un entero positivo.');
        }

        $sqlProduct = sprintf(
            'SELECT id, nombre
             FROM %s
             WHERE %s AND id = :id_producto
             LIMIT 1',
            self::TABLE_PRODUCTOS,
            $this->getRlsClause()
        );
        $productParams = $this->getRlsParams();
        $productParams[':id_producto'] = $productId;

        $productStmt = $this->pdo->prepare($sqlProduct);
        $productStmt->execute($productParams);
        $product = $productStmt->fetch(\PDO::FETCH_ASSOC);
        if ($product === false || $product === null) {
            throw new \RuntimeException('Producto no encontrado o no pertenece a tu organización.', 404);
        }

        $sqlInsert = sprintf(
            'INSERT INTO %s
             (id_producto, producto, organization_id, pvu, pcu, unidades, proveedor, notas, fecha_presupuesto)
             VALUES
             (:id_producto, :producto, :organization_id, :pvu, :pcu, :unidades, :proveedor, :notas, :fecha_presupuesto)',
            self::TABLE_PRECIOS_REFERENCIA
        );

        $stmt = $this->pdo->prepare($sqlInsert);
        $stmt->execute([
            ':id_producto' => $productId,
            ':producto' => (string)($product['nombre'] ?? ''),
            ':organization_id' => $this->organizationId,
            ':pvu' => $this->normalizeNullableFloat($payload['pvu'] ?? null),
            ':pcu' => $this->normalizeNullableFloat($payload['pcu'] ?? null),
            ':unidades' => $this->normalizeNullableFloat($payload['unidades'] ?? null),
            ':proveedor' => $this->normalizeNullableString($payload['proveedor'] ?? null),
            ':notas' => $this->normalizeNullableString($payload['notas'] ?? null),
            ':fecha_presupuesto' => $this->normalizeNullableString($payload['fecha_presupuesto'] ?? null),
        ]);

        $id = (int)$this->pdo->lastInsertId();
        return $this->getById($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function getById(int $id): array
    {
        $sql = sprintf(
            'SELECT
                pr.id,
                pr.id_producto,
                p.nombre AS product_nombre,
                pr.pvu,
                pr.pcu,
                pr.unidades,
                pr.proveedor,
                pr.notas,
                pr.fecha_presupuesto
             FROM %1$s pr
             LEFT JOIN %2$s p
               ON p.id = pr.id_producto
             WHERE pr.%3$s
               AND pr.id = :id
             LIMIT 1',
            self::TABLE_PRECIOS_REFERENCIA,
            self::TABLE_PRODUCTOS,
            $this->getRlsClause()
        );

        $params = $this->getRlsParams();
        $params[':id'] = $id;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || $row === null) {
            throw new \RuntimeException('No se devolvió la fila creada.');
        }

        return $this->normalizeRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (string)($row['id'] ?? ''),
            'id_producto' => isset($row['id_producto']) ? (int)$row['id_producto'] : 0,
            'product_nombre' => $row['product_nombre'] ?? null,
            'pvu' => $row['pvu'] !== null ? (float)$row['pvu'] : null,
            'pcu' => $row['pcu'] !== null ? (float)$row['pcu'] : null,
            'unidades' => $row['unidades'] !== null ? (float)$row['unidades'] : null,
            'proveedor' => $row['proveedor'] ?? null,
            'notas' => $row['notas'] ?? null,
            'fecha_presupuesto' => $row['fecha_presupuesto'] ?? null,
        ];
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string)$value);
        return $str !== '' ? $str : null;
    }
}
