<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseRepository.php';

final class SearchRepository extends BaseRepository
{
    private const TABLE_PRODUCTOS = 'tbl_productos';
    private const TABLE_LICITACIONES = 'tbl_licitaciones';
    private const TABLE_DETALLE = 'tbl_licitaciones_detalle';
    private const TABLE_REAL = 'tbl_licitaciones_real';
    private const TABLE_PRECIOS_REFERENCIA = 'tbl_precios_referencia';

    /**
     * Búsqueda de productos con histórico en detalle o precios de referencia.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchProducts(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $productIds = $this->getProductIdsWithHistory($query);
        if ($productIds === []) {
            return [];
        }

        $results = [];

        // 1) Partidas de licitaciones_detalle
        $detalleRows = $this->searchDetalle($productIds);
        $idDetalles = [];
        foreach ($detalleRows as $row) {
            if (isset($row['id_detalle'])) {
                $idDetalles[] = (int)$row['id_detalle'];
            }
        }
        $pcuByDetalle = [];
        $proveedorByDetalle = [];
        if ($idDetalles !== []) {
            [$pcuByDetalle, $proveedorByDetalle] = $this->getPcuAndProveedorFromReal($idDetalles);
        }

        foreach ($detalleRows as $row) {
            $idDetalle = $row['id_detalle'] ?? null;
            $pcu = $idDetalle !== null ? ($pcuByDetalle[$idDetalle] ?? null) : null;
            $provReal = $idDetalle !== null ? ($proveedorByDetalle[$idDetalle] ?? null) : null;

            $productoNombre = (string)($row['producto_nombre'] ?? '');
            $productoNombreProveedor = (string)($row['producto_nombre_proveedor'] ?? '');

            $proveedor = $this->getProveedorDisplay($provReal, $productoNombreProveedor);

            $results[] = [
                'id_producto' => isset($row['id_producto']) ? (int)$row['id_producto'] : null,
                'producto' => $productoNombre,
                'pvu' => isset($row['pvu']) ? (float)$row['pvu'] : null,
                'pcu' => $pcu,
                'unidades' => isset($row['unidades']) ? (float)$row['unidades'] : null,
                'licitacion_nombre' => $row['licitacion_nombre'] ?? null,
                'numero_expediente' => $row['numero_expediente'] ?? null,
                'proveedor' => $proveedor,
            ];
        }

        // 2) Líneas de precios de referencia
        $refRows = $this->searchPreciosReferencia($productIds);
        foreach ($refRows as $r) {
            $productoNombre = (string)($r['producto_nombre'] ?? '');
            $productoNombreProveedor = (string)($r['producto_nombre_proveedor'] ?? '');
            $provLinea = $r['proveedor'] ?? null;
            $proveedor = $this->getProveedorDisplay($provLinea, $productoNombreProveedor);

            $results[] = [
                'id_producto' => isset($r['id_producto']) ? (int)$r['id_producto'] : null,
                'producto' => $productoNombre,
                'pvu' => isset($r['pvu']) ? (float)$r['pvu'] : null,
                'pcu' => isset($r['pcu']) ? (float)$r['pcu'] : null,
                'unidades' => isset($r['unidades']) ? (float)$r['unidades'] : null,
                'licitacion_nombre' => null,
                'numero_expediente' => null,
                'proveedor' => $proveedor,
            ];
        }

        return $results;
    }

    /**
     * Calcula el precio medio histórico de referencia de un producto.
     *
     * @return array<string, mixed> {avg_pvu, avg_pcu, count, first_date, last_date}
     */
    public function getReferencePrice(int $productId): array
    {
        $where = $this->getRlsClause() . ' AND id_producto = :id_producto';
        $params = $this->getRlsParams();
        $params[':id_producto'] = $productId;

        $sql = sprintf(
            'SELECT
                 AVG(pvu) AS avg_pvu,
                 AVG(pcu) AS avg_pcu,
                 COUNT(*) AS total_rows,
                 MIN(fecha_presupuesto) AS first_date,
                 MAX(fecha_presupuesto) AS last_date
             FROM %s
             WHERE %s',
            self::TABLE_PRECIOS_REFERENCIA,
            $where
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row === null || (int)($row['total_rows'] ?? 0) === 0) {
            return [
                'product_id' => $productId,
                'avg_pvu' => null,
                'avg_pcu' => null,
                'count' => 0,
                'first_date' => null,
                'last_date' => null,
            ];
        }

        return [
            'product_id' => $productId,
            'avg_pvu' => $row['avg_pvu'] !== null ? (float)$row['avg_pvu'] : null,
            'avg_pcu' => $row['avg_pcu'] !== null ? (float)$row['avg_pcu'] : null,
            'count' => (int)$row['total_rows'],
            'first_date' => $row['first_date'] !== null ? (string)$row['first_date'] : null,
            'last_date' => $row['last_date'] !== null ? (string)$row['last_date'] : null,
        ];
    }

    /**
     * Devuelve IDs de productos que coinciden con el texto y que tienen histórico.
     *
     * @return array<int, int>
     */
    private function getProductIdsWithHistory(string $query): array
    {
        $pattern = '%' . $query . '%';
        $paramsNombre = $this->getRlsParams();
        $paramsNombre[':nombre'] = $pattern;

        $sqlNombre = sprintf(
            'SELECT id FROM %s WHERE %s AND nombre LIKE :nombre LIMIT 300',
            self::TABLE_PRODUCTOS,
            $this->getRlsClause()
        );
        $stmtNombre = $this->pdo->prepare($sqlNombre);
        $stmtNombre->execute($paramsNombre);
        $rowsNombre = $stmtNombre->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $paramsRef = $this->getRlsParams();
        $paramsRef[':referencia'] = $pattern;
        $sqlRef = sprintf(
            'SELECT id FROM %s WHERE %s AND referencia LIKE :referencia LIMIT 300',
            self::TABLE_PRODUCTOS,
            $this->getRlsClause()
        );
        $stmtRef = $this->pdo->prepare($sqlRef);
        $stmtRef->execute($paramsRef);
        $rowsRef = $stmtRef->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $candidatos = [];
        foreach (array_merge($rowsNombre, $rowsRef) as $r) {
            if (isset($r['id'])) {
                $candidatos[(int)$r['id']] = (int)$r['id'];
            }
        }
        $candidatos = array_values($candidatos);
        if ($candidatos === []) {
            return [];
        }

        // Filtrar solo los que tienen datos en precios_referencia o detalle (ambos con RLS).
        $placeholders = [];
        $paramsHist = $this->getRlsParams();
        foreach ($candidatos as $idx => $pid) {
            $ph = ':pid_' . $idx;
            $placeholders[] = $ph;
            $paramsHist[$ph] = $pid;
        }

        $sqlHistRef = sprintf(
            'SELECT DISTINCT id_producto
             FROM %s
             WHERE %s AND id_producto IN (%s)',
            self::TABLE_PRECIOS_REFERENCIA,
            $this->getRlsClause(),
            implode(', ', $placeholders)
        );
        $stmtHistRef = $this->pdo->prepare($sqlHistRef);
        $stmtHistRef->execute($paramsHist);
        $histRef = $stmtHistRef->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $sqlHistDet = sprintf(
            'SELECT DISTINCT id_producto
             FROM %s
             WHERE %s AND activo = 1 AND id_producto IN (%s)',
            self::TABLE_DETALLE,
            $this->getRlsClause(),
            implode(', ', $placeholders)
        );
        $stmtHistDet = $this->pdo->prepare($sqlHistDet);
        $stmtHistDet->execute($paramsHist);
        $histDet = $stmtHistDet->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $conHistorico = [];
        foreach (array_merge($histRef, $histDet) as $r) {
            if (isset($r['id_producto'])) {
                $conHistorico[(int)$r['id_producto']] = (int)$r['id_producto'];
            }
        }

        return array_values(array_intersect($candidatos, array_values($conHistorico)));
    }

    /**
     * Busca en detalle de licitaciones un conjunto de productos.
     *
     * @param array<int, int> $productIds
     * @return array<int, array<string, mixed>>
     */
    private function searchDetalle(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = [];
        $params = $this->getRlsParams();
        foreach ($productIds as $idx => $pid) {
            $ph = ':pid_' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $pid;
        }

        $sql = sprintf(
            'SELECT
                 d.id_detalle,
                 d.id_producto,
                 d.unidades,
                 d.pvu,
                 l.nombre AS licitacion_nombre,
                 l.numero_expediente,
                 p.nombre AS producto_nombre,
                 p.nombre_proveedor AS producto_nombre_proveedor
             FROM %1$s d
             JOIN %2$s l
               ON l.id_licitacion = d.id_licitacion
             JOIN %3$s p
               ON p.id = d.id_producto
             WHERE %4$s
               AND d.activo = 1
               AND d.id_producto IN (%5$s)',
            self::TABLE_DETALLE,
            self::TABLE_LICITACIONES,
            self::TABLE_PRODUCTOS,
            $this->getRlsClause(),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Obtiene PCU y proveedor desde tbl_licitaciones_real por id_detalle.
     *
     * @param array<int, int> $detalleIds
     * @return array{0: array<int, float>, 1: array<int, ?string>}
     */
    private function getPcuAndProveedorFromReal(array $detalleIds): array
    {
        if ($detalleIds === []) {
            return [[], []];
        }

        $placeholders = [];
        $params = $this->getRlsParams();
        foreach ($detalleIds as $idx => $id) {
            $ph = ':det_' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $id;
        }

        $sql = sprintf(
            'SELECT id_detalle, pcu, proveedor
             FROM %s
             WHERE %s AND id_detalle IN (%s)
             ORDER BY id_real DESC',
            self::TABLE_REAL,
            $this->getRlsClause(),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $pcuById = [];
        $provById = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $idDet = $r['id_detalle'] ?? null;
            if ($idDet === null || isset($pcuById[$idDet])) {
                continue;
            }
            if ($r['pcu'] !== null) {
                $pcuById[$idDet] = (float)$r['pcu'];
            }
            $prov = $r['proveedor'] ?? null;
            $provById[$idDet] = $prov !== null && trim((string)$prov) !== '' ? (string)$prov : null;
        }

        return [$pcuById, $provById];
    }

    /**
     * Busca precios de referencia para un conjunto de productos.
     *
     * @param array<int, int> $productIds
     * @return array<int, array<string, mixed>>
     */
    private function searchPreciosReferencia(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = [];
        $params = $this->getRlsParams();
        foreach ($productIds as $idx => $pid) {
            $ph = ':pid_' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $pid;
        }

        $sql = sprintf(
            'SELECT
                 pr.id_producto,
                 pr.pvu,
                 pr.pcu,
                 pr.unidades,
                 pr.proveedor,
                 p.nombre AS producto_nombre,
                 p.nombre_proveedor AS producto_nombre_proveedor
             FROM %1$s pr
             JOIN %2$s p
               ON p.id = pr.id_producto
             WHERE %3$s
               AND pr.id_producto IN (%4$s)',
            self::TABLE_PRECIOS_REFERENCIA,
            self::TABLE_PRODUCTOS,
            $this->getRlsClause(),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Determina el proveedor a mostrar: línea si existe, sino el del producto.
     */
    private function getProveedorDisplay(?string $proveedorLinea, string $productoNombreProveedor): ?string
    {
        if ($proveedorLinea !== null && trim($proveedorLinea) !== '') {
            return trim($proveedorLinea);
        }
        $nom = trim($productoNombreProveedor);
        return $nom !== '' ? $nom : null;
    }
}

