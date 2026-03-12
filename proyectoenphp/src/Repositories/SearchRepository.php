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
    private const TABLE_ALBARANES = 'tbl_albaranes';
    private const TABLE_ALBARANES_LINEAS = 'tbl_albaranes_lineas';

    /**
     * Búsqueda unificada: albaranes + licitaciones_detalle + precios_referencia.
     *
     * @return array<string, mixed> {albaranes_venta, albaranes_compra, licitaciones, referencia}
     */
    public function searchHistorico(string $query, int $limit = 200): array
    {
        $query = trim($query);
        if ($query === '') {
            return [
                'albaranes_venta' => [],
                'albaranes_compra' => [],
                'licitaciones' => [],
                'referencia' => [],
            ];
        }

        $albaranesVenta = $this->searchAlbaranes($query, 'VENTA', $limit);
        $albaranesCompra = $this->searchAlbaranes($query, 'COMPRA', $limit);
        $licitaciones = $this->searchLicitacionesDetalle($query, $limit);
        $referencia = $this->searchPreciosRef($query, $limit);

        return [
            'albaranes_venta' => $albaranesVenta,
            'albaranes_compra' => $albaranesCompra,
            'licitaciones' => $licitaciones,
            'referencia' => $referencia,
        ];
    }

    /**
     * Busca en tbl_albaranes_lineas + tbl_albaranes por nombre_articulo o ref_articulo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchAlbaranes(string $query, string $tipo, int $limit): array
    {
        $tokens = $this->tokenize($query);
        if ($tokens === []) {
            return [];
        }

        $where = 'a.tipo_albaran = :tipo';
        $params = [':tipo' => $tipo];

        foreach ($tokens as $i => $tok) {
            $escaped = '%' . $this->escapeLike($tok) . '%';
            $phNom = ':tok_n_' . $i;
            $phRef = ':tok_r_' . $i;
            $params[$phNom] = $escaped;
            $params[$phRef] = $escaped;
            $where .= sprintf(
                " AND (LOWER(COALESCE(l.nombre_articulo, '')) LIKE %s ESCAPE '\\\\'"
                . " OR LOWER(COALESCE(l.ref_articulo, '')) LIKE %s ESCAPE '\\\\')",
                $phNom,
                $phRef
            );
        }

        $sql = sprintf(
            "SELECT
                l.id_linea,
                l.id_producto,
                l.ref_articulo,
                l.nombre_articulo,
                l.familia,
                l.cantidad,
                l.precio_unitario,
                l.descuento_pct,
                l.importe,
                a.numero_albaran,
                a.fecha_albaran,
                a.nombre_contacto,
                a.comercial,
                a.numero_factura
             FROM %s l
             JOIN %s a ON a.id_albaran = l.id_albaran
             WHERE %s
               AND l.precio_unitario IS NOT NULL
               AND l.cantidad > 0
             ORDER BY a.fecha_albaran DESC, l.id_linea DESC
             LIMIT %d",
            self::TABLE_ALBARANES_LINEAS,
            self::TABLE_ALBARANES,
            $where,
            max(1, min(500, $limit))
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $results = [];
        foreach ($rows as $r) {
            $results[] = [
                'ref_articulo' => $r['ref_articulo'] ?? null,
                'nombre_articulo' => $r['nombre_articulo'] ?? null,
                'familia' => $r['familia'] ?? null,
                'cantidad' => $r['cantidad'] !== null ? (float)$r['cantidad'] : null,
                'precio_unitario' => $r['precio_unitario'] !== null ? (float)$r['precio_unitario'] : null,
                'descuento_pct' => $r['descuento_pct'] !== null ? (float)$r['descuento_pct'] : null,
                'importe' => $r['importe'] !== null ? (float)$r['importe'] : null,
                'numero_albaran' => $r['numero_albaran'] ?? null,
                'fecha_albaran' => $r['fecha_albaran'] ?? null,
                'contacto' => $r['nombre_contacto'] ?? null,
                'comercial' => $r['comercial'] ?? null,
                'numero_factura' => $r['numero_factura'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Busca en detalle de licitaciones por nombre de producto.
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchLicitacionesDetalle(string $query, int $limit): array
    {
        $tokens = $this->tokenize($query);
        if ($tokens === []) {
            return [];
        }

        $where = 'd.activo = 1';
        $params = [];

        foreach ($tokens as $i => $tok) {
            $escaped = '%' . $this->escapeLike($tok) . '%';
            $phN = ':tok_n_' . $i;
            $phR = ':tok_r_' . $i;
            $phL = ':tok_l_' . $i;
            $params[$phN] = $escaped;
            $params[$phR] = $escaped;
            $params[$phL] = $escaped;
            $where .= sprintf(
                " AND (LOWER(COALESCE(p.nombre, '')) LIKE %s ESCAPE '\\\\'"
                . " OR LOWER(COALESCE(p.referencia, '')) LIKE %s ESCAPE '\\\\'"
                . " OR LOWER(COALESCE(d.nombre_producto_libre, '')) LIKE %s ESCAPE '\\\\')",
                $phN,
                $phR,
                $phL
            );
        }

        $sql = sprintf(
            "SELECT
                d.id_detalle,
                d.id_producto,
                COALESCE(p.nombre, d.nombre_producto_libre) AS producto,
                p.referencia,
                p.nombre_proveedor,
                d.unidades,
                d.pvu,
                d.pcu,
                d.lote,
                l.nombre AS licitacion_nombre,
                l.numero_expediente,
                l.fecha_presentacion
             FROM %s d
             LEFT JOIN %s p ON p.id = d.id_producto
             JOIN %s l ON l.id_licitacion = d.id_licitacion
             WHERE %s
             ORDER BY l.fecha_presentacion DESC, d.id_detalle DESC
             LIMIT %d",
            self::TABLE_DETALLE,
            self::TABLE_PRODUCTOS,
            self::TABLE_LICITACIONES,
            $where,
            max(1, min(500, $limit))
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            return [];
        }

        // Enrich with PCU/proveedor from tbl_licitaciones_real
        $idDetalles = [];
        foreach ($rows as $row) {
            if (isset($row['id_detalle'])) {
                $idDetalles[] = (int)$row['id_detalle'];
            }
        }
        $pcuByDetalle = [];
        $provByDetalle = [];
        if ($idDetalles !== []) {
            try {
                [$pcuByDetalle, $provByDetalle] = $this->getPcuAndProveedorFromReal($idDetalles);
            } catch (\PDOException $e) {
                // ignore
            }
        }

        $results = [];
        foreach ($rows as $r) {
            $idDet = $r['id_detalle'] ?? null;
            $pcuReal = $idDet !== null ? ($pcuByDetalle[$idDet] ?? null) : null;
            $provReal = $idDet !== null ? ($provByDetalle[$idDet] ?? null) : null;
            $proveedor = $provReal ?? (trim((string)($r['nombre_proveedor'] ?? '')) ?: null);

            $results[] = [
                'producto' => $r['producto'] ?? null,
                'referencia' => $r['referencia'] ?? null,
                'unidades' => $r['unidades'] !== null ? (float)$r['unidades'] : null,
                'pvu' => $r['pvu'] !== null ? (float)$r['pvu'] : null,
                'pcu' => $pcuReal ?? ($r['pcu'] !== null ? (float)$r['pcu'] : null),
                'lote' => $r['lote'] ?? null,
                'licitacion' => $r['licitacion_nombre'] ?? null,
                'expediente' => $r['numero_expediente'] ?? null,
                'fecha' => $r['fecha_presentacion'] ?? null,
                'proveedor' => $proveedor,
            ];
        }

        return $results;
    }

    /**
     * Busca precios de referencia por nombre de producto.
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchPreciosRef(string $query, int $limit): array
    {
        $tokens = $this->tokenize($query);
        if ($tokens === []) {
            return [];
        }

        $where = '1 = 1';
        $params = [];

        foreach ($tokens as $i => $tok) {
            $escaped = '%' . $this->escapeLike($tok) . '%';
            $phN = ':tok_n_' . $i;
            $phR = ':tok_r_' . $i;
            $params[$phN] = $escaped;
            $params[$phR] = $escaped;
            $where .= sprintf(
                " AND (LOWER(COALESCE(p.nombre, '')) LIKE %s ESCAPE '\\\\'"
                . " OR LOWER(COALESCE(p.referencia, '')) LIKE %s ESCAPE '\\\\')",
                $phN,
                $phR
            );
        }

        $sql = sprintf(
            "SELECT
                pr.id_producto,
                p.nombre AS producto,
                p.referencia,
                p.nombre_proveedor,
                pr.pvu,
                pr.pcu,
                pr.unidades,
                pr.proveedor,
                pr.fecha_presupuesto
             FROM %s pr
             JOIN %s p ON p.id = pr.id_producto
             WHERE %s
             ORDER BY pr.fecha_presupuesto DESC
             LIMIT %d",
            self::TABLE_PRECIOS_REFERENCIA,
            self::TABLE_PRODUCTOS,
            $where,
            max(1, min(500, $limit))
        );

        // tbl_precios_referencia may not exist yet
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $results = [];
        foreach ($rows as $r) {
            $provLinea = $r['proveedor'] ?? null;
            $provProd = trim((string)($r['nombre_proveedor'] ?? ''));
            $proveedor = ($provLinea !== null && trim((string)$provLinea) !== '') ? trim((string)$provLinea) : ($provProd !== '' ? $provProd : null);

            $results[] = [
                'producto' => $r['producto'] ?? null,
                'referencia' => $r['referencia'] ?? null,
                'pvu' => $r['pvu'] !== null ? (float)$r['pvu'] : null,
                'pcu' => $r['pcu'] !== null ? (float)$r['pcu'] : null,
                'unidades' => $r['unidades'] !== null ? (float)$r['unidades'] : null,
                'fecha' => $r['fecha_presupuesto'] ?? null,
                'proveedor' => $proveedor,
            ];
        }

        return $results;
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
        $params = [];
        foreach ($detalleIds as $idx => $id) {
            $ph = ':det_' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $id;
        }

        $sql = sprintf(
            'SELECT id_detalle, pcu, proveedor
             FROM %s
             WHERE id_detalle IN (%s)
             ORDER BY id_real DESC',
            self::TABLE_REAL,
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
     * Búsqueda legacy (mantenida por compatibilidad).
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchProducts(string $query): array
    {
        $result = $this->searchHistorico($query, 200);
        $out = [];

        foreach ($result['licitaciones'] as $row) {
            $out[] = [
                'id_producto' => null,
                'producto' => $row['producto'] ?? null,
                'pvu' => $row['pvu'] ?? null,
                'pcu' => $row['pcu'] ?? null,
                'unidades' => $row['unidades'] ?? null,
                'licitacion_nombre' => $row['licitacion'] ?? null,
                'numero_expediente' => $row['expediente'] ?? null,
                'proveedor' => $row['proveedor'] ?? null,
            ];
        }

        foreach ($result['referencia'] as $row) {
            $out[] = [
                'id_producto' => null,
                'producto' => $row['producto'] ?? null,
                'pvu' => $row['pvu'] ?? null,
                'pcu' => $row['pcu'] ?? null,
                'unidades' => $row['unidades'] ?? null,
                'licitacion_nombre' => null,
                'numero_expediente' => null,
                'proveedor' => $row['proveedor'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Calcula el precio medio histórico de referencia de un producto.
     *
     * @return array<string, mixed>
     */
    public function getReferencePrice(int $productId): array
    {
        $sql = sprintf(
            'SELECT
                 AVG(pvu) AS avg_pvu,
                 AVG(pcu) AS avg_pcu,
                 COUNT(*) AS total_rows,
                 MIN(fecha_presupuesto) AS first_date,
                 MAX(fecha_presupuesto) AS last_date
             FROM %s
             WHERE id_producto = :id_producto',
            self::TABLE_PRECIOS_REFERENCIA
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id_producto' => $productId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $row = null;
        }

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
     * @return array<int, string>
     */
    private function tokenize(string $query): array
    {
        $q = mb_strtolower(trim($query), 'UTF-8');
        if ($q === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $q) ?: [];
        $tokens = array_values(array_filter(
            $parts,
            static fn(string $t): bool => mb_strlen($t, 'UTF-8') >= 2
        ));

        return $tokens !== [] ? $tokens : [$q];
    }

    private function escapeLike(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '%' => '\%',
            '_' => '\_',
        ]);
    }
}
