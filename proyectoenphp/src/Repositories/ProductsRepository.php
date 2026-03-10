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
        $limit = max(5, min(120, $limit));
        $query = trim($query);

        if ($query === '' || mb_strlen($query, 'UTF-8') < 4) {
            return [];
        }

        $tokens = $this->tokenizeAutocompleteQuery($query);
        if ($tokens === []) {
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

        $where = $this->getRlsClause();
        $params = $this->getRlsParams();
        $scoreParts = [];

        foreach ($tokens as $i => $tok) {
            $escapedTok = $this->escapeLike($tok);

            $phNombreContains = ':tw_n_' . $i;
            $phReferenciaContains = ':tw_r_' . $i;
            $phCodigoContains = ':tw_c_' . $i;
            $phIdContains = ':tw_id_' . $i;
            $phIdErpContains = ':tw_iderp_' . $i;

            $where .= sprintf(
                ' AND (
                    LOWER(COALESCE(nombre, \'\')) LIKE %1$s ESCAPE \'\\\'
                    OR LOWER(COALESCE(referencia, \'\')) LIKE %2$s ESCAPE \'\\\'
                    OR LOWER(COALESCE(codigo_barras, \'\')) LIKE %3$s ESCAPE \'\\\'
                    OR CAST(id AS CHAR) LIKE %4$s ESCAPE \'\\\'
                    OR CAST(id_erp AS CHAR) LIKE %5$s ESCAPE \'\\\'
                )',
                $phNombreContains,
                $phReferenciaContains,
                $phCodigoContains,
                $phIdContains,
                $phIdErpContains
            );

            $params[$phNombreContains] = '%' . $escapedTok . '%';
            $params[$phReferenciaContains] = '%' . $escapedTok . '%';
            $params[$phCodigoContains] = '%' . $escapedTok . '%';
            $params[$phIdContains] = '%' . $escapedTok . '%';
            $params[$phIdErpContains] = '%' . $escapedTok . '%';

            $phNombreExact = ':teq_' . $i;
            $phNombrePrefix = ':tpfx_' . $i;
            $phNombreWord = ':twrd_' . $i;
            $phNombreContainsScore = ':tcon_' . $i;
            $phReferenciaScore = ':tref_' . $i;
            $phCodigoScore = ':tbar_' . $i;
            $phIdErpExact = ':id_erp_exact_' . $i;
            $phIdErpLike = ':id_erp_like_' . $i;

            $params[$phNombreExact] = $tok;
            $params[$phNombrePrefix] = $escapedTok . '%';
            $params[$phNombreWord] = '% ' . $escapedTok . '%';
            $params[$phNombreContainsScore] = '%' . $escapedTok . '%';
            $params[$phReferenciaScore] = '%' . $escapedTok . '%';
            $params[$phCodigoScore] = '%' . $escapedTok . '%';
            $params[$phIdErpExact] = $tok;
            $params[$phIdErpLike] = '%' . $escapedTok . '%';

            $scoreParts[] = sprintf(
                '(
                    CASE
                        WHEN LOWER(COALESCE(nombre, \'\')) = %1$s THEN 220
                        WHEN LOWER(COALESCE(nombre, \'\')) LIKE %2$s ESCAPE \'\\\' THEN 190
                        WHEN LOWER(COALESCE(nombre, \'\')) LIKE %3$s ESCAPE \'\\\' THEN 165
                        WHEN LOWER(COALESCE(nombre, \'\')) LIKE %4$s ESCAPE \'\\\' THEN 130
                        ELSE 0
                    END
                    + CASE
                        WHEN LOWER(COALESCE(referencia, \'\')) LIKE %5$s ESCAPE \'\\\' THEN 55
                        ELSE 0
                    END
                    + CASE
                        WHEN LOWER(COALESCE(codigo_barras, \'\')) LIKE %6$s ESCAPE \'\\\' THEN 45
                        ELSE 0
                    END
                    + CASE
                        WHEN CAST(id_erp AS CHAR) = %7$s THEN 90
                        WHEN CAST(id_erp AS CHAR) LIKE %8$s ESCAPE \'\\\' THEN 35
                        ELSE 0
                    END
                )',
                $phNombreExact,
                $phNombrePrefix,
                $phNombreWord,
                $phNombreContainsScore,
                $phReferenciaScore,
                $phCodigoScore,
                $phIdErpExact,
                $phIdErpLike
            );
        }

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

        $scoreSql = $scoreParts !== [] ? implode(' + ', $scoreParts) : '0';

        $sql = sprintf(
            'SELECT
                id,
                nombre,
                id_proveedor,
                nombre_proveedor,
                referencia,
                codigo_barras,
                id_erp,
                (%s) AS score
             FROM %s
             WHERE %s
             ORDER BY score DESC, nombre ASC
             LIMIT %d',
            $scoreSql,
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
                'referencia' => isset($r['referencia']) ? (string)$r['referencia'] : null,
                'codigo_barras' => isset($r['codigo_barras']) ? (string)$r['codigo_barras'] : null,
            ];
        }

        return $out;
    }

    /**
     * Convierte una query en tokens para búsqueda multi-palabra.
     *
     * @return array<int, string>
     */
    private function tokenizeAutocompleteQuery(string $query): array
    {
        $q = mb_strtolower(trim($query), 'UTF-8');
        if ($q === '') {
            return [];
        }

        /** @var array<int, string> $parts */
        $parts = preg_split('/\s+/u', $q) ?: [];
        $tokens = array_values(array_filter(
            $parts,
            static fn (string $t): bool => mb_strlen($t, 'UTF-8') >= 2
        ));

        return $tokens !== [] ? $tokens : [$q];
    }

    /**
     * Escapa caracteres especiales para LIKE ... ESCAPE '\\'.
     */
    private function escapeLike(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '%' => '\%',
            '_' => '\_',
        ]);
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

