<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseRepository.php';

final class ProductsRepository extends BaseRepository
{
    private const TABLE_PRODUCTOS = 'tbl_productos';
    private const TABLE_PRECIOS_REFERENCIA = 'tbl_precios_referencia';
    private const TABLE_LICITACIONES_DETALLE = 'tbl_licitaciones_detalle';
    private const TABLE_PROVEEDORES = 'tbl_proveedores';

    public function __construct(string $organizationId)
    {
        parent::__construct($organizationId);
    }

    /**
     * Búsqueda de productos por nombre, con opción de restringir a los que tengan precios de referencia.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchProductos(string $query, int $limit = 30, bool $onlyWithPreciosReferencia = false): array
    {
        $limit = max(1, min(100, $limit));
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        /** @var array<int, int>|null $productoIds */
        $productoIds = null;
        if ($onlyWithPreciosReferencia) {
            $productoIds = $this->getProductosConPreciosReferencia();
            if ($productoIds === []) {
                return [];
            }
        }

        $where = $this->getRlsClause() . ' AND nombre LIKE :q';
        $params = $this->getRlsParams();
        $params[':q'] = '%' . $query . '%';

        if ($productoIds !== null) {
            $placeholders = [];
            $index = 0;
            foreach ($productoIds as $id) {
                $ph = ':pid_' . $index;
                $placeholders[] = $ph;
                $params[$ph] = $id;
                $index++;
            }

            if ($placeholders === []) {
                return [];
            }

            $where .= ' AND id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql = sprintf(
            'SELECT id, nombre, id_proveedor, nombre_proveedor
             FROM %s
             WHERE %s
             ORDER BY nombre ASC
             LIMIT %d',
            self::TABLE_PRODUCTOS,
            $where,
            $limit
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll() ?: [];

        $proveedoresById = $this->fetchProveedoresByIds($rows);

        $out = [];
        foreach ($rows as $r) {
            if (!isset($r['id'])) {
                continue;
            }

            $id = (int)$r['id'];
            $nombre = (string)($r['nombre'] ?? '');

            $nombreProveedor = null;
            $idProveedor = $r['id_proveedor'] ?? null;
            if ($idProveedor !== null && $idProveedor !== '') {
                $pid = (int)$idProveedor;
                if (array_key_exists($pid, $proveedoresById)) {
                    $nombreProveedor = $proveedoresById[$pid];
                }
            }

            if ($nombreProveedor === null) {
                $np = trim((string)($r['nombre_proveedor'] ?? ''));
                $nombreProveedor = $np !== '' ? $np : null;
            }

            $out[] = [
                'id' => $id,
                'nombre' => $nombre,
                'nombre_proveedor' => $nombreProveedor,
            ];
        }

        return $out;
    }

    /**
     * Devuelve IDs de productos que tienen al menos un precio de referencia
     * o aparecen en partidas activas de licitaciones.
     *
     * @return array<int, int>
     */
    private function getProductosConPreciosReferencia(): array
    {
        /** @var array<int, int> $ids */
        $ids = [];

        // Productos con precios de referencia
        $whereRef = $this->getRlsClause();
        $paramsRef = $this->getRlsParams();

        $sqlRef = sprintf(
            'SELECT DISTINCT id_producto FROM %s WHERE %s',
            self::TABLE_PRECIOS_REFERENCIA,
            $whereRef
        );

        $stmtRef = $this->pdo->prepare($sqlRef);
        $stmtRef->execute($paramsRef);

        while ($row = $stmtRef->fetch()) {
            $id = $row['id_producto'] ?? null;
            if ($id !== null) {
                $ids[(int)$id] = (int)$id;
            }
        }

        // Productos presupuestados en licitaciones (solo partidas activas)
        $whereDet = $this->getRlsClause() . ' AND activo = :activo';
        $paramsDet = $this->getRlsParams();
        $paramsDet[':activo'] = 1;

        $sqlDet = sprintf(
            'SELECT DISTINCT id_producto FROM %s WHERE %s',
            self::TABLE_LICITACIONES_DETALLE,
            $whereDet
        );

        $stmtDet = $this->pdo->prepare($sqlDet);
        $stmtDet->execute($paramsDet);

        while ($row = $stmtDet->fetch()) {
            $id = $row['id_producto'] ?? null;
            if ($id !== null) {
                $ids[(int)$id] = (int)$id;
            }
        }

        return array_values($ids);
    }

    /**
     * Resuelve nombres de proveedores a partir de los IDs presentes en las filas de productos.
     *
     * @param array<int, array<string, mixed>> $productRows
     * @return array<int, string|null> Mapa [id_proveedor => nombre]
     */
    private function fetchProveedoresByIds(array $productRows): array
    {
        /** @var array<int, int> $ids */
        $ids = [];
        foreach ($productRows as $row) {
            $idProv = $row['id_proveedor'] ?? null;
            if ($idProv !== null && $idProv !== '') {
                $ids[(int)$idProv] = (int)$idProv;
            }
        }

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = $this->getRlsParams();

        $values = array_values($ids);
        foreach ($values as $index => $id) {
            $ph = ':prov_' . $index;
            $placeholders[] = $ph;
            $params[$ph] = $id;
        }

        $where = $this->getRlsClause() . ' AND id IN (' . implode(', ', $placeholders) . ')';

        $sql = sprintf(
            'SELECT id, nombre FROM %s WHERE %s',
            self::TABLE_PROVEEDORES,
            $where
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } catch (\PDOException $e) {
            // Emular comportamiento del código original: si falla, hacemos fallback al nombre_proveedor de productos.
            return [];
        }

        /** @var array<int, string|null> $map */
        $map = [];
        while ($row = $stmt->fetch()) {
            if (!isset($row['id'])) {
                continue;
            }
            $id = (int)$row['id'];
            $nombre = (string)($row['nombre'] ?? '');
            $nombre = trim($nombre);
            $map[$id] = $nombre !== '' ? $nombre : null;
        }

        return $map;
    }
}

