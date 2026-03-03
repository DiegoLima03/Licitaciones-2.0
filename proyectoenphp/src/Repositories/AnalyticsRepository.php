<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseRepository.php';

final class AnalyticsRepository extends BaseRepository
{
    private const TABLE_LICITACIONES = 'tbl_licitaciones';
    private const TABLE_DETALLE = 'tbl_licitaciones_detalle';
    private const TABLE_REAL = 'tbl_licitaciones_real';
    private const TABLE_PRECIOS_REFERENCIA = 'tbl_precios_referencia';
    private const TABLE_PRODUCTOS = 'tbl_productos';
    private const TABLE_ENTREGAS = 'tbl_entregas';
    private const TABLE_GASTOS_PROYECTO = 'tbl_gastos_proyecto';
    private const TABLE_ESTADOS = 'tbl_estados';

    // Tipos de procedimiento incluidos en el dashboard
    private const TIPOS_INCLUIDOS_DASHBOARD = ['ORDINARIO', 'CONTRATO_BASADO', 'ESPECIFICO_SDA'];

    /**
     * Devuelve KPIs de dashboard y timeline de licitaciones.
     *
     * @return array<string, mixed>
     */
    public function getKpis(?string $fechaAdjudicacionDesde, ?string $fechaAdjudicacionHasta): array
    {
        // Mapa de estados id -> nombre
        $estadosIdMap = $this->getEstadosIdMap();

        // Licitaciones de la organización
        $where = $this->getRlsClause();
        $params = $this->getRlsParams();

        if ($fechaAdjudicacionDesde !== null && $fechaAdjudicacionDesde !== '') {
            $where .= ' AND fecha_adjudicacion >= :fecha_desde';
            $params[':fecha_desde'] = $fechaAdjudicacionDesde;
        }
        if ($fechaAdjudicacionHasta !== null && $fechaAdjudicacionHasta !== '') {
            $where .= ' AND fecha_adjudicacion <= :fecha_hasta';
            $params[':fecha_hasta'] = $fechaAdjudicacionHasta;
        }

        $sql = sprintf(
            'SELECT id_licitacion, nombre, pres_maximo, id_estado, tipo_procedimiento,
                    fecha_presentacion, fecha_adjudicacion, fecha_finalizacion
             FROM %s
             WHERE %s',
            self::TABLE_LICITACIONES,
            $where
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if ($rows === []) {
            return $this->emptyKpis();
        }

        // Normalizar estados y tipo_procedimiento
        foreach ($rows as &$row) {
            $row['pres_maximo'] = (float)($row['pres_maximo'] ?? 0.0);
            $idEstado = $row['id_estado'] ?? null;
            $row['estado_nombre'] = $estadosIdMap[$idEstado] ?? 'Desconocido';
            $row['_estado_norm'] = mb_strtolower(trim((string)$row['estado_nombre']));
            $tipoProc = $row['tipo_procedimiento'] ?? '';
            $row['_tipo_norm'] = mb_strtoupper(trim((string)$tipoProc));
        }
        unset($row);

        // Filtrar por tipos incluidos en el dashboard
        $facturables = array_values(array_filter(
            $rows,
            static fn (array $r): bool => in_array($r['_tipo_norm'], self::TIPOS_INCLUIDOS_DASHBOARD, true)
        ));

        if ($facturables === []) {
            return $this->emptyKpis();
        }

        // Timeline: devolver registros completos de facturables
        $timeline = [];
        foreach ($facturables as $r) {
            $timeline[] = [
                'id_licitacion' => (int)$r['id_licitacion'],
                'nombre' => (string)($r['nombre'] ?? ('Licitación ' . $r['id_licitacion'])),
                'fecha_adjudicacion' => $this->normalizeDateString($r['fecha_adjudicacion'] ?? null),
                'fecha_finalizacion' => $this->normalizeDateString($r['fecha_finalizacion'] ?? null),
                'estado_nombre' => $r['estado_nombre'],
                'pres_maximo' => $r['pres_maximo'],
            ];
        }

        // Estados según lógica Python
        $estadosOfertado = ['adjudicada', 'no adjudicada', 'presentada', 'terminada'];
        $estadosAdjTerminadas = ['adjudicada', 'terminada'];
        $estadosDescartada = ['descartada'];
        $estadosEnAnalisis = ['en análisis', 'en analisis'];

        $totalOportunidadesUds = count($facturables);
        $totalOportunidadesEuros = array_sum(array_column($facturables, 'pres_maximo'));

        // Ofertado: estados ofertados
        $ofertado = array_values(array_filter(
            $facturables,
            static fn (array $r): bool => in_array($r['_estado_norm'], $estadosOfertado, true)
        ));
        $totalOfertadoUds = count($ofertado);
        $totalOfertadoEuros = array_sum(array_column($ofertado, 'pres_maximo'));

        $ratioOfertadoOportunidadesUds = $totalOportunidadesUds > 0
            ? ($totalOfertadoUds / $totalOportunidadesUds) * 100.0
            : 0.0;
        $ratioOfertadoOportunidadesEuros = $totalOportunidadesEuros > 0
            ? ($totalOfertadoEuros / $totalOportunidadesEuros) * 100.0
            : 0.0;

        // Adjudicadas + Terminadas
        $adjTer = array_values(array_filter(
            $facturables,
            static fn (array $r): bool => in_array($r['_estado_norm'], $estadosAdjTerminadas, true)
        ));
        $countAdjTer = count($adjTer);
        $ratioAdjTerOfertado = $totalOfertadoUds > 0
            ? ($countAdjTer / $totalOfertadoUds) * 100.0
            : 0.0;

        // IDs adjudicadas/terminadas para margen ponderado
        $idsAdjTer = array_map(
            static fn (array $r): int => (int)$r['id_licitacion'],
            $adjTer
        );

        $margenPresu = $this->computeMargenPonderadoPresupuestado($idsAdjTer);
        $margenReal = $this->computeMargenPonderadoReal($idsAdjTer);

        // % descartadas
        $descartadas = array_values(array_filter(
            $facturables,
            static fn (array $r): bool => in_array($r['_estado_norm'], $estadosDescartada, true)
        ));
        $enAnalisis = array_values(array_filter(
            $facturables,
            static fn (array $r): bool => in_array($r['_estado_norm'], $estadosEnAnalisis, true)
        ));

        $countDes = count($descartadas);
        $denomUds = $totalOportunidadesUds - count($enAnalisis);
        $pctDescartadasUds = ($denomUds > 0)
            ? ($countDes / $denomUds) * 100.0
            : null;

        $eurosDes = array_sum(array_column($descartadas, 'pres_maximo'));
        $facturablesSinAnalisis = array_values(array_filter(
            $facturables,
            static fn (array $r): bool => !in_array($r['_estado_norm'], $estadosEnAnalisis, true)
        ));
        $eurosTotalMenosEnAnalisis = array_sum(array_column($facturablesSinAnalisis, 'pres_maximo'));
        $pctDescartadasEuros = ($eurosTotalMenosEnAnalisis > 0.0)
            ? ($eurosDes / $eurosTotalMenosEnAnalisis) * 100.0
            : null;

        // Ratio adjudicación = (Adjudicadas+Terminadas) / ofertado
        $ratioAdjudicacion = $totalOfertadoUds > 0
            ? ($ratioAdjTerOfertado / 100.0)
            : 0.0;

        return [
            'timeline' => $timeline,
            'total_oportunidades_uds' => $totalOportunidadesUds,
            'total_oportunidades_euros' => round($totalOportunidadesEuros, 2),
            'total_ofertado_uds' => $totalOfertadoUds,
            'total_ofertado_euros' => round($totalOfertadoEuros, 2),
            'ratio_ofertado_oportunidades_uds' => round($ratioOfertadoOportunidadesUds, 2),
            'ratio_ofertado_oportunidades_euros' => round($ratioOfertadoOportunidadesEuros, 2),
            'ratio_adjudicadas_terminadas_ofertado' => round($ratioAdjTerOfertado, 2),
            'margen_medio_ponderado_presupuestado' => $margenPresu !== null ? round($margenPresu, 2) : null,
            'margen_medio_ponderado_real' => $margenReal !== null ? round($margenReal, 2) : null,
            'pct_descartadas_uds' => $pctDescartadasUds !== null ? round($pctDescartadasUds, 2) : null,
            'pct_descartadas_euros' => $pctDescartadasEuros !== null ? round($pctDescartadasEuros, 2) : null,
            'ratio_adjudicacion' => round($ratioAdjudicacion, 4),
        ];
    }

    /**
     * Material trends: PVU (pvu) y PCU (pcu) por fecha.
     *
     * @return array<string, array<int, array<string, float|string>>>
     */
    public function getMaterialTrends(string $materialName): array
    {
        $orgParams = $this->getRlsParams();

        // Productos que contienen el nombre
        $sqlProd = sprintf(
            'SELECT id FROM %s WHERE %s AND nombre LIKE :nombre',
            self::TABLE_PRODUCTOS,
            $this->getRlsClause()
        );
        $stmtProd = $this->pdo->prepare($sqlProd);
        $stmtProd->execute([
            ':organization_id' => $orgParams[':organization_id'],
            ':nombre' => '%' . $materialName . '%',
        ]);
        $productIds = array_map(
            static fn (array $r): int => (int)$r['id'],
            $stmtProd->fetchAll(\PDO::FETCH_ASSOC) ?: []
        );

        if ($productIds === []) {
            return ['pvu' => [], 'pcu' => []];
        }

        // PVU/PCU desde precios_referencia
        $pvuPoints = [];
        $pcuPoints = [];

        $placeholders = [];
        $paramsRef = $this->getRlsParams();
        foreach ($productIds as $idx => $pid) {
            $ph = ':pid_' . $idx;
            $placeholders[] = $ph;
            $paramsRef[$ph] = $pid;
        }
        $sqlRef = sprintf(
            'SELECT pvu, pcu, fecha_presupuesto
             FROM %s
             WHERE %s AND id_producto IN (%s)
             ORDER BY fecha_presupuesto',
            self::TABLE_PRECIOS_REFERENCIA,
            $this->getRlsClause(),
            implode(', ', $placeholders)
        );
        $stmtRef = $this->pdo->prepare($sqlRef);
        $stmtRef->execute($paramsRef);
        $refRows = $stmtRef->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($refRows as $r) {
            $timeStr = $this->normalizeDateString($r['fecha_presupuesto'] ?? null);
            if ($timeStr === null) {
                continue;
            }
            if ($r['pvu'] !== null) {
                $pvuPoints[] = [
                    'time' => $timeStr,
                    'value' => round((float)$r['pvu'], 2),
                ];
            }
            if ($r['pcu'] !== null) {
                $pcuPoints[] = [
                    'time' => $timeStr,
                    'value' => round((float)$r['pcu'], 2),
                ];
            }
        }

        // PVU desde licitaciones_detalle con fecha de licitación
        $placeholdersProd = [];
        $paramsDet = $this->getRlsParams();
        foreach ($productIds as $idx => $pid) {
            $ph = ':prod_' . $idx;
            $placeholdersProd[] = $ph;
            $paramsDet[$ph] = $pid;
        }
        $sqlDet = sprintf(
            'SELECT id_licitacion, pvu
             FROM %s
             WHERE %s AND activo = 1 AND id_producto IN (%s)',
            self::TABLE_DETALLE,
            $this->getRlsClause(),
            implode(', ', $placeholdersProd)
        );
        $stmtDet = $this->pdo->prepare($sqlDet);
        $stmtDet->execute($paramsDet);
        $detRows = $stmtDet->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $licitacionIds = [];
        foreach ($detRows as $r) {
            if ($r['id_licitacion'] !== null) {
                $licitacionIds[(int)$r['id_licitacion']] = (int)$r['id_licitacion'];
            }
        }
        $licitacionIds = array_values($licitacionIds);

        $licFechas = [];
        if ($licitacionIds !== []) {
            $placeholdersLic = [];
            $paramsLic = $this->getRlsParams();
            foreach ($licitacionIds as $idx => $lid) {
                $ph = ':lic_' . $idx;
                $placeholdersLic[] = $ph;
                $paramsLic[$ph] = $lid;
            }
            $sqlLic = sprintf(
                'SELECT id_licitacion, fecha_presentacion, fecha_adjudicacion
                 FROM %s
                 WHERE %s AND id_licitacion IN (%s)',
                self::TABLE_LICITACIONES,
                $this->getRlsClause(),
                implode(', ', $placeholdersLic)
            );
            $stmtLic = $this->pdo->prepare($sqlLic);
            $stmtLic->execute($paramsLic);
            foreach ($stmtLic->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $lid = (int)$row['id_licitacion'];
                $timeStr = $this->normalizeDateString(
                    $row['fecha_adjudicacion'] ?? $row['fecha_presentacion'] ?? null
                );
                if ($timeStr !== null) {
                    $licFechas[$lid] = $timeStr;
                }
            }
        }

        foreach ($detRows as $r) {
            $pvu = $r['pvu'] ?? null;
            $lid = $r['id_licitacion'] ?? null;
            if ($pvu === null || $lid === null) {
                continue;
            }
            $timeStr = $licFechas[(int)$lid] ?? null;
            if ($timeStr === null) {
                continue;
            }
            $pvuPoints[] = [
                'time' => $timeStr,
                'value' => round((float)$pvu, 2),
            ];
        }

        // PCU desde licitaciones_real con fecha de entrega
        $placeholdersDet = [];
        $paramsDetReal = $this->getRlsParams();
        foreach ($productIds as $idx => $pid) {
            $ph = ':pdet_' . $idx;
            $placeholdersDet[] = $ph;
            $paramsDetReal[$ph] = $pid;
        }
        $sqlDetForReal = sprintf(
            'SELECT id_detalle
             FROM %s
             WHERE %s AND id_producto IN (%s)',
            self::TABLE_DETALLE,
            $this->getRlsClause(),
            implode(', ', $placeholdersDet)
        );
        $stmtDetForReal = $this->pdo->prepare($sqlDetForReal);
        $stmtDetForReal->execute($paramsDetReal);
        $detalleIds = array_map(
            static fn (array $r): int => (int)$r['id_detalle'],
            $stmtDetForReal->fetchAll(\PDO::FETCH_ASSOC) ?: []
        );

        if ($detalleIds !== []) {
            $placeholdersReal = [];
            $paramsReal = $this->getRlsParams();
            foreach ($detalleIds as $idx => $did) {
                $ph = ':det_' . $idx;
                $placeholdersReal[] = $ph;
                $paramsReal[$ph] = $did;
            }
            $sqlReal = sprintf(
                'SELECT id_detalle, id_entrega, pcu
                 FROM %s
                 WHERE %s AND id_detalle IN (%s)',
                self::TABLE_REAL,
                $this->getRlsClause(),
                implode(', ', $placeholdersReal)
            );
            $stmtReal = $this->pdo->prepare($sqlReal);
            $stmtReal->execute($paramsReal);
            $realRows = $stmtReal->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $entregaIds = [];
            foreach ($realRows as $r) {
                if ($r['id_entrega'] !== null) {
                    $entregaIds[$r['id_entrega']] = $r['id_entrega'];
                }
            }
            $entregaIds = array_values($entregaIds);

            $entFechas = [];
            if ($entregaIds !== []) {
                $placeholdersEnt = [];
                $paramsEnt = $this->getRlsParams();
                foreach ($entregaIds as $idx => $eid) {
                    $ph = ':ent_' . $idx;
                    $placeholdersEnt[] = $ph;
                    $paramsEnt[$ph] = $eid;
                }
                $sqlEnt = sprintf(
                    'SELECT id_entrega, fecha_entrega
                     FROM %s
                     WHERE %s AND id_entrega IN (%s)',
                    self::TABLE_ENTREGAS,
                    $this->getRlsClause(),
                    implode(', ', $placeholdersEnt)
                );
                $stmtEnt = $this->pdo->prepare($sqlEnt);
                $stmtEnt->execute($paramsEnt);
                foreach ($stmtEnt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                    $eid = $row['id_entrega'];
                    $t = $this->normalizeDateString($row['fecha_entrega'] ?? null);
                    if ($eid !== null && $t !== null) {
                        $entFechas[$eid] = $t;
                    }
                }
            }

            foreach ($realRows as $r) {
                $pcu = $r['pcu'] ?? null;
                $eid = $r['id_entrega'] ?? null;
                if ($pcu === null || $eid === null) {
                    continue;
                }
                $timeStr = $entFechas[$eid] ?? null;
                if ($timeStr === null) {
                    continue;
                }
                $pcuPoints[] = [
                    'time' => $timeStr,
                    'value' => round((float)$pcu, 2),
                ];
            }
        }

        // Deduplicar por fecha, quedándonos con último valor por día
        $pvuPoints = $this->dedupAndSortPoints($pvuPoints);
        $pcuPoints = $this->dedupAndSortPoints($pcuPoints);

        return [
            'pvu' => $pvuPoints,
            'pcu' => $pcuPoints,
        ];
    }

    /**
     * Risk-adjusted pipeline: pipeline bruto vs ajustado por precio medio.
     *
     * @return array<int, array<string, float|string>>
     */
    public function getRiskAdjustedPipeline(int $idEstadoEnAnalisis): array
    {
        // Licitaciones en estado EN ANÁLISIS
        $whereLic = $this->getRlsClause() . ' AND id_estado = :id_estado';
        $paramsLic = $this->getRlsParams();
        $paramsLic[':id_estado'] = $idEstadoEnAnalisis;

        $sqlLic = sprintf(
            'SELECT id_licitacion FROM %s WHERE %s',
            self::TABLE_LICITACIONES,
            $whereLic
        );
        $stmtLic = $this->pdo->prepare($sqlLic);
        $stmtLic->execute($paramsLic);
        $licRows = $stmtLic->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $idLics = array_map(
            static fn (array $r): int => (int)$r['id_licitacion'],
            $licRows
        );

        if ($idLics === []) {
            return [[
                'category' => 'Comparativa',
                'pipeline_bruto' => 0.0,
                'pipeline_ajustado' => 0.0,
            ]];
        }

        // Detalles activos de esas licitaciones
        $placeholdersLic = [];
        $paramsDet = $this->getRlsParams();
        foreach ($idLics as $idx => $lid) {
            $ph = ':lic_' . $idx;
            $placeholdersLic[] = $ph;
            $paramsDet[$ph] = $lid;
        }
        $sqlDet = sprintf(
            'SELECT id_producto, pvu, unidades
             FROM %s
             WHERE %s AND activo = 1 AND id_licitacion IN (%s)',
            self::TABLE_DETALLE,
            $this->getRlsClause(),
            implode(', ', $placeholdersLic)
        );
        $stmtDet = $this->pdo->prepare($sqlDet);
        $stmtDet->execute($paramsDet);
        $rows = $stmtDet->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if ($rows === []) {
            return [[
                'category' => 'Comparativa',
                'pipeline_bruto' => 0.0,
                'pipeline_ajustado' => 0.0,
            ]];
        }

        $ventaPresupuestada = 0.0;
        $lineas = [];
        foreach ($rows as $r) {
            $pvu = (float)($r['pvu'] ?? 0.0);
            $ud = (float)($r['unidades'] ?? 0.0);
            $ventaPresupuestada += $pvu * $ud;
            if ($r['id_producto'] !== null) {
                $lineas[] = [
                    'id_producto' => (int)$r['id_producto'],
                    'pvu' => $pvu,
                    'unidades' => $ud,
                ];
            }
        }

        $productIds = array_values(array_unique(array_column($lineas, 'id_producto')));

        // Medias de precio por producto desde precios_referencia
        $placeholdersProd = [];
        $paramsRef = $this->getRlsParams();
        foreach ($productIds as $idx => $pid) {
            $ph = ':pid_' . $idx;
            $placeholdersProd[] = $ph;
            $paramsRef[$ph] = $pid;
        }
        $sqlRef = sprintf(
            'SELECT id_producto, pvu
             FROM %s
             WHERE %s AND id_producto IN (%s)',
            self::TABLE_PRECIOS_REFERENCIA,
            $this->getRlsClause(),
            implode(', ', $placeholdersProd)
        );
        $stmtRef = $this->pdo->prepare($sqlRef);
        $stmtRef->execute($paramsRef);
        $refRows = $stmtRef->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $pvuPorProducto = [];
        foreach ($refRows as $ref) {
            $pid = $ref['id_producto'] ?? null;
            $pvuVal = $ref['pvu'] ?? null;
            if ($pid === null || $pvuVal === null) {
                continue;
            }
            $pid = (int)$pid;
            $pvuPorProducto[$pid][] = (float)$pvuVal;
        }

        $avgPvu = [];
        foreach ($pvuPorProducto as $pid => $vals) {
            if ($vals !== []) {
                $avgPvu[$pid] = array_sum($vals) / count($vals);
            }
        }

        $ventaPrecioMedio = 0.0;
        foreach ($lineas as $ln) {
            $pid = $ln['id_producto'];
            $ud = $ln['unidades'];
            $pvuMedio = $avgPvu[$pid] ?? $ln['pvu'];
            $ventaPrecioMedio += $pvuMedio * $ud;
        }

        return [[
            'category' => 'Comparativa',
            'pipeline_bruto' => round($ventaPresupuestada, 2),
            'pipeline_ajustado' => round($ventaPrecioMedio, 2),
        ]];
    }

    /**
     * Sweet spots: licitaciones cerradas con presupuesto y estado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSweetSpots(array $idsEstadosCerrados): array
    {
        if ($idsEstadosCerrados === []) {
            return [];
        }

        $placeholders = [];
        $params = $this->getRlsParams();
        foreach ($idsEstadosCerrados as $idx => $idEst) {
            $ph = ':est_' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $idEst;
        }

        $sql = sprintf(
            'SELECT id_licitacion, nombre, numero_expediente, pres_maximo, id_estado
             FROM %s
             WHERE %s AND id_estado IN (%s)',
            self::TABLE_LICITACIONES,
            $this->getRlsClause(),
            implode(', ', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $estadosIdMap = $this->getEstadosIdMap();

        $result = [];
        foreach ($rows as $r) {
            $idLic = $r['id_licitacion'] ?? null;
            $pres = (float)($r['pres_maximo'] ?? 0.0);
            $idEstado = $r['id_estado'] ?? null;
            $estadoNombre = $estadosIdMap[$idEstado] ?? 'Desconocido';
            $cliente = (string)($r['nombre'] ?? $r['numero_expediente'] ?? $idLic);

            $result[] = [
                'id' => (string)$idLic,
                'presupuesto' => round($pres, 2),
                'estado' => $estadoNombre,
                'cliente' => $cliente,
            ];
        }

        return $result;
    }

    /**
     * Devuelve el id_estado para un nombre de estado (comparación case-insensitive).
     */
    public function getEstadoIdByName(string $estadoNombre): ?int
    {
        $estadoNombre = trim($estadoNombre);
        if ($estadoNombre === '') {
            return null;
        }

        $sql = sprintf(
            'SELECT id_estado
             FROM %s
             WHERE LOWER(TRIM(nombre_estado)) = LOWER(TRIM(:nombre_estado))
             LIMIT 1',
            self::TABLE_ESTADOS
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':nombre_estado' => $estadoNombre]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row === null || !isset($row['id_estado'])) {
            return null;
        }

        return (int)$row['id_estado'];
    }

    /**
     * @param array<int, string> $estadoNombres
     * @return array<int, int>
     */
    public function getEstadoIdsByNames(array $estadoNombres): array
    {
        $normalized = [];
        foreach ($estadoNombres as $name) {
            $value = trim($name);
            if ($value !== '') {
                $normalized[] = mb_strtolower($value);
            }
        }

        if ($normalized === []) {
            return [];
        }

        $params = [];
        $placeholders = [];
        foreach ($normalized as $idx => $name) {
            $ph = ':estado_' . $idx;
            $params[$ph] = $name;
            $placeholders[] = $ph;
        }

        $sql = sprintf(
            'SELECT id_estado, nombre_estado
             FROM %s
             WHERE LOWER(TRIM(nombre_estado)) IN (%s)',
            self::TABLE_ESTADOS,
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $ids = [];
        foreach ($rows as $row) {
            if (isset($row['id_estado'])) {
                $ids[] = (int)$row['id_estado'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Analíticas avanzadas por producto.
     *
     * @return array<string, mixed>|null
     */
    public function getProductAnalytics(int $productId): ?array
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return null;
        }

        $sqlProduct = sprintf(
            'SELECT id, nombre
             FROM %s
             WHERE %s AND id = :id_producto
             LIMIT 1',
            self::TABLE_PRODUCTOS,
            $this->getRlsClause()
        );
        $paramsProduct = $this->getRlsParams();
        $paramsProduct[':id_producto'] = $productId;
        $stmtProduct = $this->pdo->prepare($sqlProduct);
        $stmtProduct->execute($paramsProduct);
        $product = $stmtProduct->fetch(\PDO::FETCH_ASSOC);
        if ($product === false || $product === null) {
            return null;
        }

        $productName = (string)($product['nombre'] ?? '');

        $sqlDetalle = sprintf(
            'SELECT id_detalle, id_licitacion, pvu, unidades
             FROM %s
             WHERE %s AND id_producto = :id_producto',
            self::TABLE_DETALLE,
            $this->getRlsClause()
        );
        $paramsDetalle = $this->getRlsParams();
        $paramsDetalle[':id_producto'] = $productId;
        $stmtDetalle = $this->pdo->prepare($sqlDetalle);
        $stmtDetalle->execute($paramsDetalle);
        $detRows = $stmtDetalle->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $licitacionIds = [];
        $detalleIds = [];
        foreach ($detRows as $row) {
            if (isset($row['id_licitacion']) && $row['id_licitacion'] !== null) {
                $licitacionIds[(int)$row['id_licitacion']] = (int)$row['id_licitacion'];
            }
            if (isset($row['id_detalle']) && $row['id_detalle'] !== null) {
                $detalleIds[(int)$row['id_detalle']] = (int)$row['id_detalle'];
            }
        }
        $licitacionIds = array_values($licitacionIds);
        $detalleIds = array_values($detalleIds);

        $licitacionFechas = [];
        if ($licitacionIds !== []) {
            $paramsLic = $this->getRlsParams();
            $licPlaceholders = [];
            foreach ($licitacionIds as $idx => $idLic) {
                $ph = ':lic_' . $idx;
                $paramsLic[$ph] = $idLic;
                $licPlaceholders[] = $ph;
            }

            $sqlLic = sprintf(
                'SELECT id_licitacion, fecha_adjudicacion
                 FROM %s
                 WHERE %s AND id_licitacion IN (%s)',
                self::TABLE_LICITACIONES,
                $this->getRlsClause(),
                implode(', ', $licPlaceholders)
            );
            $stmtLic = $this->pdo->prepare($sqlLic);
            $stmtLic->execute($paramsLic);
            $rowsLic = $stmtLic->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rowsLic as $row) {
                if (!isset($row['id_licitacion'])) {
                    continue;
                }
                $normDate = $this->normalizeDateString($row['fecha_adjudicacion'] ?? null);
                if ($normDate !== null) {
                    $licitacionFechas[(int)$row['id_licitacion']] = $normDate;
                }
            }
        }

        $sqlRef = sprintf(
            'SELECT pvu, pcu, unidades, fecha_presupuesto
             FROM %s
             WHERE %s AND id_producto = :id_producto',
            self::TABLE_PRECIOS_REFERENCIA,
            $this->getRlsClause()
        );
        $paramsRef = $this->getRlsParams();
        $paramsRef[':id_producto'] = $productId;
        $stmtRef = $this->pdo->prepare($sqlRef);
        $stmtRef->execute($paramsRef);
        $refRows = $stmtRef->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $unidadesVendidasByDate = [];
        foreach ($refRows as $row) {
            if (!$this->isPcuNull($row['pcu'] ?? null)) {
                continue;
            }
            $date = $this->normalizeDateString($row['fecha_presupuesto'] ?? null);
            if ($date === null) {
                continue;
            }
            $qty = is_numeric($row['unidades'] ?? null) ? (float)$row['unidades'] : 0.0;
            $unidadesVendidasByDate[$date] = ($unidadesVendidasByDate[$date] ?? 0.0) + $qty;
        }

        $histPvuRows = [];
        foreach ($detRows as $row) {
            if (!is_numeric($row['pvu'] ?? null) || !isset($row['id_licitacion'])) {
                continue;
            }
            $idLic = (int)$row['id_licitacion'];
            $date = $licitacionFechas[$idLic] ?? null;
            if ($date === null) {
                continue;
            }
            $histPvuRows[] = [
                'time' => $date,
                'value' => (float)$row['pvu'],
            ];
        }
        foreach ($refRows as $row) {
            if (!is_numeric($row['pvu'] ?? null)) {
                continue;
            }
            $date = $this->normalizeDateString($row['fecha_presupuesto'] ?? null);
            if ($date === null) {
                continue;
            }
            $histPvuRows[] = [
                'time' => $date,
                'value' => (float)$row['pvu'],
            ];
        }

        usort(
            $histPvuRows,
            static fn (array $a, array $b): int => strcmp((string)$a['time'], (string)$b['time'])
        );
        $pvuByDate = [];
        foreach ($histPvuRows as $row) {
            $pvuByDate[(string)$row['time']] = (float)$row['value'];
        }
        ksort($pvuByDate);

        $priceHistory = [];
        foreach ($pvuByDate as $date => $value) {
            $priceHistory[] = [
                'time' => $date,
                'value' => round($value, 2),
                'unidades' => round((float)($unidadesVendidasByDate[$date] ?? 0.0), 2),
            ];
        }

        $histPcuRows = [];
        foreach ($refRows as $row) {
            if (!is_numeric($row['pcu'] ?? null)) {
                continue;
            }
            $date = $this->normalizeDateString($row['fecha_presupuesto'] ?? null);
            if ($date === null) {
                continue;
            }
            $histPcuRows[] = [
                'time' => $date,
                'value' => (float)$row['pcu'],
            ];
        }

        $realRows = [];
        if ($detalleIds !== []) {
            $paramsReal = $this->getRlsParams();
            $realPlaceholders = [];
            foreach ($detalleIds as $idx => $idDet) {
                $ph = ':det_' . $idx;
                $paramsReal[$ph] = $idDet;
                $realPlaceholders[] = $ph;
            }

            $sqlReal = sprintf(
                'SELECT id_detalle, id_entrega, proveedor, pcu
                 FROM %s
                 WHERE %s AND id_detalle IN (%s)',
                self::TABLE_REAL,
                $this->getRlsClause(),
                implode(', ', $realPlaceholders)
            );
            $stmtReal = $this->pdo->prepare($sqlReal);
            $stmtReal->execute($paramsReal);
            $realRows = $stmtReal->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $entregaIds = [];
            foreach ($realRows as $row) {
                if (isset($row['id_entrega']) && $row['id_entrega'] !== null) {
                    $entregaIds[(int)$row['id_entrega']] = (int)$row['id_entrega'];
                }
            }
            $entregaIds = array_values($entregaIds);

            $entregaFechas = [];
            if ($entregaIds !== []) {
                $paramsEnt = $this->getRlsParams();
                $entPlaceholders = [];
                foreach ($entregaIds as $idx => $idEnt) {
                    $ph = ':ent_' . $idx;
                    $paramsEnt[$ph] = $idEnt;
                    $entPlaceholders[] = $ph;
                }

                $sqlEnt = sprintf(
                    'SELECT id_entrega, fecha_entrega
                     FROM %s
                     WHERE %s AND id_entrega IN (%s)',
                    self::TABLE_ENTREGAS,
                    $this->getRlsClause(),
                    implode(', ', $entPlaceholders)
                );
                $stmtEnt = $this->pdo->prepare($sqlEnt);
                $stmtEnt->execute($paramsEnt);
                $rowsEnt = $stmtEnt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($rowsEnt as $row) {
                    if (!isset($row['id_entrega'])) {
                        continue;
                    }
                    $date = $this->normalizeDateString($row['fecha_entrega'] ?? null);
                    if ($date !== null) {
                        $entregaFechas[(int)$row['id_entrega']] = $date;
                    }
                }
            }

            foreach ($realRows as $row) {
                if (!is_numeric($row['pcu'] ?? null) || !isset($row['id_entrega'])) {
                    continue;
                }
                $idEnt = (int)$row['id_entrega'];
                $date = $entregaFechas[$idEnt] ?? null;
                if ($date === null) {
                    continue;
                }
                $histPcuRows[] = [
                    'time' => $date,
                    'value' => (float)$row['pcu'],
                ];
            }
        }

        usort(
            $histPcuRows,
            static fn (array $a, array $b): int => strcmp((string)$a['time'], (string)$b['time'])
        );
        $pcuByDate = [];
        foreach ($histPcuRows as $row) {
            $pcuByDate[(string)$row['time']] = (float)$row['value'];
        }
        ksort($pcuByDate);

        $priceHistoryPcu = [];
        foreach ($pcuByDate as $date => $value) {
            $priceHistoryPcu[] = [
                'time' => $date,
                'value' => round($value, 2),
            ];
        }

        $totalLicitado = 0.0;
        foreach ($detRows as $row) {
            if (!is_numeric($row['pvu'] ?? null) || !is_numeric($row['unidades'] ?? null)) {
                continue;
            }
            $totalLicitado += (float)$row['pvu'] * (float)$row['unidades'];
        }

        $volumeMetrics = [
            'total_licitado' => round($totalLicitado, 2),
            'cantidad_oferentes_promedio' => round((float)count($licitacionIds), 2),
        ];

        $competitorAgg = [];
        foreach ($realRows as $row) {
            if (!is_numeric($row['pcu'] ?? null)) {
                continue;
            }
            $proveedorRaw = isset($row['proveedor']) ? trim((string)$row['proveedor']) : '';
            $empresa = $proveedorRaw !== '' ? $proveedorRaw : 'N/A';

            if (!isset($competitorAgg[$empresa])) {
                $competitorAgg[$empresa] = [
                    'sum' => 0.0,
                    'count' => 0,
                ];
            }
            $competitorAgg[$empresa]['sum'] += (float)$row['pcu'];
            $competitorAgg[$empresa]['count']++;
        }

        $competitorAnalysis = [];
        foreach ($competitorAgg as $empresa => $agg) {
            $count = (int)$agg['count'];
            $avg = $count > 0 ? ((float)$agg['sum'] / $count) : 0.0;
            $competitorAnalysis[] = [
                'empresa' => $empresa,
                'precio_medio' => round($avg, 2),
                'cantidad_adjudicaciones' => $count,
            ];
        }
        usort(
            $competitorAnalysis,
            static function (array $a, array $b): int {
                $cmp = ((int)$b['cantidad_adjudicaciones']) <=> ((int)$a['cantidad_adjudicaciones']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return strcmp((string)$a['empresa'], (string)$b['empresa']);
            }
        );
        $competitorAnalysis = array_slice($competitorAnalysis, 0, 3);

        $forecast = null;
        if (count($priceHistory) >= 2) {
            $values = array_map(
                static fn (array $point): float => (float)$point['value'],
                $priceHistory
            );
            $window = min(5, count($values));
            $slice = array_slice($values, -$window);
            if ($slice !== []) {
                $forecast = round(array_sum($slice) / count($slice), 2);
            }
        }

        $pvuValues = [];
        foreach ($refRows as $row) {
            if (is_numeric($row['pvu'] ?? null)) {
                $pvuValues[] = (float)$row['pvu'];
            }
        }
        $precioReferenciaMedio = $pvuValues !== []
            ? round(array_sum($pvuValues) / count($pvuValues), 2)
            : null;

        return [
            'product_id' => $productId,
            'product_name' => $productName,
            'price_history' => $priceHistory,
            'price_history_pcu' => $priceHistoryPcu,
            'volume_metrics' => $volumeMetrics,
            'competitor_analysis' => $competitorAnalysis,
            'forecast' => $forecast,
            'precio_referencia_medio' => $precioReferenciaMedio,
        ];
    }

    /**
     * Price deviation check para un material concreto.
     *
     * @return array<string, mixed>
     */
    public function getPriceDeviationCheck(string $materialName, float $currentPrice): array
    {
        $orgParams = $this->getRlsParams();

        $sqlProd = sprintf(
            'SELECT id FROM %s WHERE %s AND nombre LIKE :nombre',
            self::TABLE_PRODUCTOS,
            $this->getRlsClause()
        );
        $stmtProd = $this->pdo->prepare($sqlProd);
        $stmtProd->execute([
            ':organization_id' => $orgParams[':organization_id'],
            ':nombre' => '%' . $materialName . '%',
        ]);
        $productIds = array_map(
            static fn (array $r): int => (int)$r['id'],
            $stmtProd->fetchAll(\PDO::FETCH_ASSOC) ?: []
        );

        if ($productIds === []) {
            return [
                'is_deviated' => true,
                'deviation_percentage' => 0.0,
                'historical_avg' => 0.0,
                'recommendation' => 'No hay histórico para este material. Revisar precio manualmente.',
            ];
        }

        $oneYearAgo = (new \DateTimeImmutable('now'))->sub(new \DateInterval('P365D'))->format('Y-m-d');
        $values = [];

        // PVU desde precios_referencia del último año
        $placeholders = [];
        $paramsRef = $this->getRlsParams();
        foreach ($productIds as $idx => $pid) {
            $ph = ':pid_' . $idx;
            $placeholders[] = $ph;
            $paramsRef[$ph] = $pid;
        }
        $sqlRef = sprintf(
            'SELECT pvu, fecha_presupuesto
             FROM %s
             WHERE %s AND id_producto IN (%s)',
            self::TABLE_PRECIOS_REFERENCIA,
            $this->getRlsClause(),
            implode(', ', $placeholders)
        );
        $stmtRef = $this->pdo->prepare($sqlRef);
        $stmtRef->execute($paramsRef);
        foreach ($stmtRef->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $pvu = $r['pvu'] ?? null;
            if ($pvu === null) {
                continue;
            }
            $fecha = $this->normalizeDateString($r['fecha_presupuesto'] ?? null);
            if ($fecha !== null && $fecha >= $oneYearAgo) {
                $values[] = (float)$pvu;
            }
        }

        // PVU desde detalle (todas, sin filtro por fecha exacto)
        $placeholdersDet = [];
        $paramsDet = $this->getRlsParams();
        foreach ($productIds as $idx => $pid) {
            $ph = ':prod_' . $idx;
            $placeholdersDet[] = $ph;
            $paramsDet[$ph] = $pid;
        }
        $sqlDet = sprintf(
            'SELECT pvu
             FROM %s
             WHERE %s AND id_producto IN (%s) AND activo = 1',
            self::TABLE_DETALLE,
            $this->getRlsClause(),
            implode(', ', $placeholdersDet)
        );
        $stmtDet = $this->pdo->prepare($sqlDet);
        $stmtDet->execute($paramsDet);
        foreach ($stmtDet->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $pvu = $r['pvu'] ?? null;
            if ($pvu === null) {
                continue;
            }
            $values[] = (float)$pvu;
        }

        $historicalAvg = $values !== [] ? array_sum($values) / count($values) : 0.0;
        if ($historicalAvg <= 0.0) {
            return [
                'is_deviated' => true,
                'deviation_percentage' => 0.0,
                'historical_avg' => 0.0,
                'recommendation' => 'Sin histórico reciente. Verificar precio con el mercado.',
            ];
        }

        $deviationPct = (($currentPrice - $historicalAvg) / $historicalAvg) * 100.0;
        $threshold = 10.0;
        $isDeviated = abs($deviationPct) > $threshold;

        if ($isDeviated && $deviationPct > 0) {
            $recommendation = sprintf(
                'Precio %.1f%% por encima de la media del último año (€%.2f). Revisar si el coste actual está justificado.',
                $deviationPct,
                $historicalAvg
            );
        } elseif ($isDeviated && $deviationPct < 0) {
            $recommendation = sprintf(
                'Precio %.1f%% por debajo de la media del último año (€%.2f). Confirmar que el proveedor y la calidad son correctos.',
                abs($deviationPct),
                $historicalAvg
            );
        } else {
            $recommendation = sprintf(
                'Precio alineado con la media histórica (€%.2f).',
                $historicalAvg
            );
        }

        return [
            'is_deviated' => $isDeviated,
            'deviation_percentage' => round($deviationPct, 2),
            'historical_avg' => round($historicalAvg, 2),
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Devuelve mapa id_estado -> nombre_estado.
     *
     * @return array<int|string, string>
     */
    private function getEstadosIdMap(): array
    {
        $sql = sprintf(
            'SELECT id_estado, nombre_estado FROM %s ORDER BY id_estado',
            self::TABLE_ESTADOS
        );
        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $id = $r['id_estado'];
            $map[$id] = (string)($r['nombre_estado'] ?? '');
        }

        return $map;
    }

    /**
     * KPIs vacíos (valores neutrales).
     *
     * @return array<string, mixed>
     */
    private function emptyKpis(): array
    {
        return [
            'timeline' => [],
            'total_oportunidades_uds' => 0,
            'total_oportunidades_euros' => 0.0,
            'total_ofertado_uds' => 0,
            'total_ofertado_euros' => 0.0,
            'ratio_ofertado_oportunidades_uds' => 0.0,
            'ratio_ofertado_oportunidades_euros' => 0.0,
            'ratio_adjudicadas_terminadas_ofertado' => 0.0,
            'margen_medio_ponderado_presupuestado' => null,
            'margen_medio_ponderado_real' => null,
            'pct_descartadas_uds' => null,
            'pct_descartadas_euros' => null,
            'ratio_adjudicacion' => 0.0,
        ];
    }

    /**
     * Margen medio ponderado presupuestado (detalle).
     *
     * @param array<int, int> $idLicitaciones
     */
    private function computeMargenPonderadoPresupuestado(array $idLicitaciones): ?float
    {
        if ($idLicitaciones === []) {
            return null;
        }

        $placeholders = [];
        $params = $this->getRlsParams();
        foreach ($idLicitaciones as $idx => $id) {
            $ph = ':lic_' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $id;
        }

        $sql = sprintf(
            'SELECT unidades, pvu, pcu
             FROM %s
             WHERE %s AND activo = 1 AND id_licitacion IN (%s)',
            self::TABLE_DETALLE,
            $this->getRlsClause(),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if ($rows === []) {
            return null;
        }

        $ventaTotal = 0.0;
        $beneficioTotal = 0.0;

        foreach ($rows as $r) {
            $ud = (float)($r['unidades'] ?? 0.0);
            $pvu = (float)($r['pvu'] ?? 0.0);
            $pcu = (float)($r['pcu'] ?? 0.0);
            $venta = $ud * $pvu;
            $coste = $ud * $pcu;
            $ventaTotal += $venta;
            $beneficioTotal += ($venta - $coste);
        }

        if ($ventaTotal <= 0.0) {
            return null;
        }

        return ($beneficioTotal / $ventaTotal) * 100.0;
    }

    /**
     * Margen medio ponderado real (real + detalle).
     *
     * @param array<int, int> $idLicitaciones
     */
    private function computeMargenPonderadoReal(array $idLicitaciones): ?float
    {
        if ($idLicitaciones === []) {
            return null;
        }

        $placeholders = [];
        $paramsReal = $this->getRlsParams();
        foreach ($idLicitaciones as $idx => $id) {
            $ph = ':lic_' . $idx;
            $placeholders[] = $ph;
            $paramsReal[$ph] = $id;
        }

        $sqlReal = sprintf(
            'SELECT id_detalle, cantidad, pcu
             FROM %s
             WHERE %s AND id_licitacion IN (%s)',
            self::TABLE_REAL,
            $this->getRlsClause(),
            implode(', ', $placeholders)
        );
        $stmtReal = $this->pdo->prepare($sqlReal);
        $stmtReal->execute($paramsReal);
        $realRows = $stmtReal->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if ($realRows === []) {
            return null;
        }

        $detalleIds = [];
        foreach ($realRows as $r) {
            if ($r['id_detalle'] !== null) {
                $detalleIds[(int)$r['id_detalle']] = (int)$r['id_detalle'];
            }
        }
        $detalleIds = array_values($detalleIds);
        if ($detalleIds === []) {
            return null;
        }

        $placeholdersDet = [];
        $paramsDet = $this->getRlsParams();
        foreach ($detalleIds as $idx => $did) {
            $ph = ':det_' . $idx;
            $placeholdersDet[] = $ph;
            $paramsDet[$ph] = $did;
        }
        $sqlDet = sprintf(
            'SELECT id_detalle, pvu
             FROM %s
             WHERE %s AND id_detalle IN (%s)',
            self::TABLE_DETALLE,
            $this->getRlsClause(),
            implode(', ', $placeholdersDet)
        );
        $stmtDet = $this->pdo->prepare($sqlDet);
        $stmtDet->execute($paramsDet);
        $pvuMap = [];
        foreach ($stmtDet->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $id = (int)$r['id_detalle'];
            $pvuMap[$id] = (float)($r['pvu'] ?? 0.0);
        }

        $ventaTotal = 0.0;
        $beneficioTotal = 0.0;
        foreach ($realRows as $r) {
            $cantidad = (float)($r['cantidad'] ?? 0.0);
            $pcu = (float)($r['pcu'] ?? 0.0);
            $idDet = $r['id_detalle'] ?? null;
            if ($idDet === null) {
                continue;
            }
            $pvu = $pvuMap[(int)$idDet] ?? 0.0;
            $venta = $cantidad * $pvu;
            $coste = $cantidad * $pcu;
            $ventaTotal += $venta;
            $beneficioTotal += ($venta - $coste);
        }

        if ($ventaTotal <= 0.0) {
            return null;
        }

        return ($beneficioTotal / $ventaTotal) * 100.0;
    }

    /**
     * Normaliza una fecha a YYYY-MM-DD o devuelve null.
     *
     * @param mixed $value
     */
    private function normalizeDateString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = (string)$value;
        if (strpos($s, 'T') !== false) {
            $s = explode('T', $s)[0];
        } else {
            $s = substr($s, 0, 10);
        }
        return strlen($s) >= 10 ? $s : null;
    }

    /**
     * Deduplica puntos temporales por fecha, quedándose con el último valor por día.
     *
     * @param array<int, array<string, float|string>> $points
     * @return array<int, array<string, float|string>>
     */
    private function dedupAndSortPoints(array $points): array
    {
        if ($points === []) {
            return [];
        }
        usort(
            $points,
            static fn (array $a, array $b): int => strcmp((string)$a['time'], (string)$b['time'])
        );
        $byDate = [];
        foreach ($points as $p) {
            $byDate[$p['time']] = $p;
        }
        ksort($byDate);
        return array_values($byDate);
    }

    /**
     * @param mixed $pcu
     */
    private function isPcuNull($pcu): bool
    {
        if ($pcu === null) {
            return true;
        }
        if (is_string($pcu)) {
            $trim = trim($pcu);
            if ($trim === '' || strtolower($trim) === 'null') {
                return true;
            }
        }
        if (is_numeric($pcu)) {
            return (float)$pcu === 0.0;
        }
        return false;
    }
}

