<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseRepository.php';

final class DeliveriesRepository extends BaseRepository
{
    private const TABLE_ENTREGAS = 'tbl_entregas';
    private const TABLE_LICITACIONES = 'tbl_licitaciones';
    private const TABLE_LICITACIONES_DETALLE = 'tbl_licitaciones_detalle';
    private const TABLE_LICITACIONES_REAL = 'tbl_licitaciones_real';
    private const TABLE_PRODUCTOS = 'tbl_productos';
    private const TABLE_TIPOS_GASTO = 'tbl_tipos_gasto';

    // Estados permitidos para imputar entregas (equivalente a ESTADOS_PERMITEN_ENTREGAS)
    private const ESTADO_ADJUDICADA = 5;
    private const ESTADOS_PERMITEN_ENTREGAS = [self::ESTADO_ADJUDICADA];
    private const QTY_EPSILON = 0.0001;

    public function __construct(string $organizationId)
    {
        parent::__construct($organizationId);
    }

    /**
     * Lista entregas. Si se pasa licitacionId, filtra por esa licitación e incluye líneas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDeliveries(?int $licitacionId = null): array
    {
        $where = $this->getRlsClause();
        $params = $this->getRlsParams();

        if ($licitacionId !== null) {
            $where .= ' AND id_licitacion = :id_licitacion';
            $params[':id_licitacion'] = $licitacionId;
        }

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY fecha_entrega DESC',
            self::TABLE_ENTREGAS,
            $where
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array<string, mixed>> $entregas */
        $entregas = $stmt->fetchAll() ?: [];

        $result = [];

        foreach ($entregas as $entrega) {
            $idEntrega = $entrega['id_entrega'] ?? null;
            if ($idEntrega === null) {
                continue;
            }

            $rlsLines = str_replace(
                'organization_id',
                'lr.organization_id',
                $this->getRlsClause()
            );

            $whereLines = $rlsLines . ' AND lr.id_entrega = :id_entrega';
            $paramsLines = $this->getRlsParams();
            $paramsLines[':id_entrega'] = (int)$idEntrega;

            $sqlLines = sprintf(
                'SELECT lr.*, p.nombre AS producto_nombre, tg.nombre AS tipo_gasto_nombre
                 FROM %1$s lr
                 LEFT JOIN %2$s p
                   ON p.id = lr.id_producto
                 LEFT JOIN %3$s tg
                   ON tg.id = lr.id_tipo_gasto
                 WHERE %4$s
                 ORDER BY lr.id_real',
                self::TABLE_LICITACIONES_REAL,
                self::TABLE_PRODUCTOS,
                self::TABLE_TIPOS_GASTO,
                $whereLines
            );

            $stmtLines = $this->pdo->prepare($sqlLines);
            $stmtLines->execute($paramsLines);

            /** @var array<int, array<string, mixed>> $rawLineas */
            $rawLineas = $stmtLines->fetchAll() ?: [];

            $lineas = [];
            foreach ($rawLineas as $lin) {
                $productNombre = $lin['producto_nombre'] ?? null;
                $tipoGastoNombre = $lin['tipo_gasto_nombre'] ?? null;

                $base = $lin;
                unset($base['producto_nombre'], $base['tipo_gasto_nombre']);

                $lineas[] = array_merge(
                    $base,
                    [
                        'product_nombre' => $productNombre ?? $tipoGastoNombre,
                    ]
                );
            }

            $ent = $entrega;
            $ent['lineas'] = $lineas;
            $result[] = $ent;
        }

        return $result;
    }

    /**
     * Crea una entrega (cabecera + líneas) de forma transaccional.
     * El payload sigue la estructura de DeliveryCreate del código original.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createDelivery(array $payload): array
    {
        $licitacionId = (int)($payload['id_licitacion'] ?? 0);

        // Verificar licitación existe y estado permitido
        $idEstado = $this->getLicitacionEstado($licitacionId);
        if ($idEstado === null) {
            throw new \InvalidArgumentException('Licitación no encontrada.', 404);
        }
        if (!in_array($idEstado, self::ESTADOS_PERMITEN_ENTREGAS, true)) {
            throw new \InvalidArgumentException(
                'No se pueden imputar entregas a una licitación no adjudicada. El estado debe ser ADJUDICADA o EJECUCIÓN.',
                400
            );
        }

        /** @var array<string, mixed> $cabecera */
        $cabecera = (array)($payload['cabecera'] ?? []);
        /** @var array<int, array<string, mixed>> $lineas */
        $lineas = isset($payload['lineas']) && is_array($payload['lineas'])
            ? $payload['lineas']
            : [];

        try {
            $this->pdo->beginTransaction();

            $newIdEntrega = $this->insertCabeceraEntrega($licitacionId, $cabecera);

            $lineasAInsertar = $this->buildLineasAInsertar($licitacionId, $newIdEntrega, $cabecera, $lineas);

            if ($lineasAInsertar === []) {
                $this->pdo->rollBack();
                throw new \InvalidArgumentException('El documento no tenía líneas válidas.', 400);
            }

            // Regla de negocio: impedir sobre-entregar cantidades frente al presupuesto.
            $this->validateNoOverDelivery($licitacionId, $lineasAInsertar);

            $this->insertLineasEntrega($lineasAInsertar);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $count = count($lineasAInsertar);

        return [
            'id_entrega' => $newIdEntrega,
            'message' => sprintf('Documento guardado con %d líneas.', $count),
            'lines_count' => $count,
        ];
    }

    /**
     * Actualiza estado/cobrado de una línea de entrega.
     *
     * @param array<string, mixed> $updates
     * @return array<string, mixed>
     */
    public function updateDeliveryLine(int $idReal, array $updates): array
    {
        // Filtrar solo campos permitidos
        $allowed = ['estado', 'cobrado'];
        $payload = [];
        foreach ($updates as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $payload[$key] = $value;
            }
        }

        if ($payload === []) {
            return [
                'id_real' => $idReal,
                'message' => 'Nada que actualizar.',
            ];
        }

        $set = [];
        $params = $this->getRlsParams();
        $params[':id_real'] = $idReal;

        foreach ($payload as $col => $value) {
            $placeholder = ':' . $col;
            $set[] = sprintf('%s = %s', $col, $placeholder);
            $params[$placeholder] = $value;
        }

        $where = $this->getRlsClause() . ' AND id_real = :id_real';

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            self::TABLE_LICITACIONES_REAL,
            implode(', ', $set),
            $where
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'id_real' => $idReal,
            'message' => 'Línea actualizada.',
        ];
    }

    /**
     * Elimina una entrega y sus líneas (cascade manual).
     */
    public function deleteDelivery(int $deliveryId): void
    {
        $params = $this->getRlsParams();
        $params[':id_entrega'] = $deliveryId;

        $whereReal = $this->getRlsClause() . ' AND id_entrega = :id_entrega';
        $sqlReal = sprintf(
            'DELETE FROM %s WHERE %s',
            self::TABLE_LICITACIONES_REAL,
            $whereReal
        );

        $stmtReal = $this->pdo->prepare($sqlReal);
        $stmtReal->execute($params);

        $whereEnt = $this->getRlsClause() . ' AND id_entrega = :id_entrega';
        $sqlEnt = sprintf(
            'DELETE FROM %s WHERE %s',
            self::TABLE_ENTREGAS,
            $whereEnt
        );

        $stmtEnt = $this->pdo->prepare($sqlEnt);
        $stmtEnt->execute($params);
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
        $row = $stmt->fetch();

        if ($row === false || $row === null) {
            return null;
        }

        return isset($row['id_estado']) ? (int)$row['id_estado'] : null;
    }

    /**
     * Inserta la cabecera de la entrega y devuelve el nuevo id_entrega.
     *
     * @param array<string, mixed> $cabecera
     */
    private function insertCabeceraEntrega(int $licitacionId, array $cabecera): int
    {
        $insert = [
            'id_licitacion' => $licitacionId,
            'organization_id' => $this->organizationId,
            'fecha_entrega' => (string)($cabecera['fecha'] ?? ''),
            'codigo_albaran' => (string)($cabecera['codigo_albaran'] ?? ''),
            'observaciones' => (string)($cabecera['observaciones'] ?? ''),
        ];

        if (isset($cabecera['cliente']) && $cabecera['cliente'] !== null && $cabecera['cliente'] !== '') {
            $insert['cliente'] = (string)$cabecera['cliente'];
        }

        $columns = array_keys($insert);
        $placeholders = array_map(
            static fn (string $col): string => ':' . $col,
            $columns
        );

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::TABLE_ENTREGAS,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $params = [];
        foreach ($insert as $col => $value) {
            $params[':' . $col] = $value;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Construye las líneas válidas a insertar en tbl_licitaciones_real.
     *
     * @param array<string, mixed>               $cabecera
     * @param array<int, array<string, mixed>>   $lineas
     * @return array<int, array<string, mixed>>
     */
    private function buildLineasAInsertar(
        int $licitacionId,
        int $idEntrega,
        array $cabecera,
        array $lineas
    ): array {
        $fecha = (string)($cabecera['fecha'] ?? '');

        $lineasAInsertar = [];

        foreach ($lineas as $line) {
            if (!is_array($line)) {
                continue;
            }

            $qty = isset($line['cantidad']) ? (float)$line['cantidad'] : 0.0;
            $cost = isset($line['coste_unit']) ? (float)$line['coste_unit'] : 0.0;

            if ($qty === 0.0 && $cost === 0.0) {
                continue;
            }

            $idTipoGasto = $line['id_tipo_gasto'] ?? null;
            $isExtraordinario = $idTipoGasto !== null;

            $idProducto = $line['id_producto'] ?? null;
            $idProducto = $idProducto !== null ? (int)$idProducto : null;

            if (!$isExtraordinario && $idProducto === null) {
                continue;
            }

            $idDetalle = $line['id_detalle'] ?? null;
            $idDetalle = $idDetalle !== null ? (int)$idDetalle : null;

            $provLinea = isset($line['proveedor']) ? trim((string)$line['proveedor']) : '';

            $row = [
                'id_licitacion' => $licitacionId,
                'organization_id' => $this->organizationId,
                'id_entrega' => $idEntrega,
                'id_detalle' => $idDetalle,
                'fecha_entrega' => $fecha,
                'cantidad' => $qty,
                'pcu' => $cost,
                'proveedor' => $provLinea,
                'estado' => 'EN ESPERA',
                'cobrado' => 0,
                // Siempre incluimos ambas columnas para mantener placeholders estables
                // al mezclar lineas presupuestadas y extraordinarias.
                'id_producto' => null,
                'id_tipo_gasto' => null,
            ];

            if ($isExtraordinario) {
                $row['id_tipo_gasto'] = (int)$idTipoGasto;
            } else {
                $row['id_producto'] = $idProducto;
            }

            $lineasAInsertar[] = $row;
        }

        return $lineasAInsertar;
    }

    /**
     * Valida que las lineas presupuestadas no exceden la cantidad pendiente por partida.
     *
     * @param array<int, array<string, mixed>> $lineasAInsertar
     */
    private function validateNoOverDelivery(int $licitacionId, array $lineasAInsertar): void
    {
        /** @var array<int, float> $nuevaCantidadPorDetalle */
        $nuevaCantidadPorDetalle = [];

        foreach ($lineasAInsertar as $row) {
            $idTipoGasto = $row['id_tipo_gasto'] ?? null;
            if ($idTipoGasto !== null) {
                // Gastos extraordinarios: no tienen limite de unidades presupuestadas.
                continue;
            }

            $cantidad = (float)($row['cantidad'] ?? 0.0);
            if ($cantidad <= 0.0) {
                continue;
            }

            $idDetalleRaw = $row['id_detalle'] ?? null;
            if ($idDetalleRaw === null) {
                throw new \InvalidArgumentException(
                    'No se puede registrar una linea con cantidad sin partida de presupuesto asociada.',
                    400
                );
            }

            $idDetalle = (int)$idDetalleRaw;
            if ($idDetalle <= 0) {
                throw new \InvalidArgumentException('Partida de presupuesto invalida en linea de albaran.', 400);
            }

            if (!isset($nuevaCantidadPorDetalle[$idDetalle])) {
                $nuevaCantidadPorDetalle[$idDetalle] = 0.0;
            }
            $nuevaCantidadPorDetalle[$idDetalle] += $cantidad;
        }

        if ($nuevaCantidadPorDetalle === []) {
            return;
        }

        $idDetalles = array_keys($nuevaCantidadPorDetalle);
        $presupuestadoPorDetalle = $this->getPresupuestadoByDetalle($licitacionId, $idDetalles);
        $entregadoPorDetalle = $this->getEntregadoByDetalle($licitacionId, $idDetalles);

        foreach ($nuevaCantidadPorDetalle as $idDetalle => $cantidadNueva) {
            if (!array_key_exists($idDetalle, $presupuestadoPorDetalle)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'La partida #%d no existe o no esta activa en el presupuesto de esta licitacion.',
                        $idDetalle
                    ),
                    400
                );
            }

            $presupuestado = (float)$presupuestadoPorDetalle[$idDetalle];
            $entregado = (float)($entregadoPorDetalle[$idDetalle] ?? 0.0);
            $pendiente = max(0.0, $presupuestado - $entregado);

            if (($cantidadNueva - $pendiente) > self::QTY_EPSILON) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'La partida #%d excede la cantidad pendiente. Pendiente: %s, intentado: %s.',
                        $idDetalle,
                        number_format($pendiente, 2, ',', '.'),
                        number_format($cantidadNueva, 2, ',', '.')
                    ),
                    400
                );
            }
        }
    }

    /**
     * @param array<int, int> $idDetalles
     * @return array<int, float> mapa [id_detalle => unidades_presupuestadas]
     */
    private function getPresupuestadoByDetalle(int $licitacionId, array $idDetalles): array
    {
        $idDetalles = array_values(array_unique(array_filter(
            $idDetalles,
            static fn (int $v): bool => $v > 0
        )));

        if ($idDetalles === []) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':id_licitacion' => $licitacionId,
            ':activo' => 1,
        ];

        foreach ($idDetalles as $idx => $idDetalle) {
            $ph = ':d_' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $idDetalle;
        }

        $sql = sprintf(
            'SELECT id_detalle, COALESCE(unidades, 0) AS unidades
             FROM %s
             WHERE id_licitacion = :id_licitacion
               AND activo = :activo
               AND id_detalle IN (%s)',
            self::TABLE_LICITACIONES_DETALLE,
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll() ?: [];

        /** @var array<int, float> $out */
        $out = [];
        foreach ($rows as $row) {
            $idDetalle = isset($row['id_detalle']) ? (int)$row['id_detalle'] : 0;
            if ($idDetalle <= 0) {
                continue;
            }
            $out[$idDetalle] = (float)($row['unidades'] ?? 0.0);
        }

        return $out;
    }

    /**
     * @param array<int, int> $idDetalles
     * @return array<int, float> mapa [id_detalle => cantidad_entregada]
     */
    private function getEntregadoByDetalle(int $licitacionId, array $idDetalles): array
    {
        $idDetalles = array_values(array_unique(array_filter(
            $idDetalles,
            static fn (int $v): bool => $v > 0
        )));

        if ($idDetalles === []) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':id_licitacion' => $licitacionId,
        ];

        foreach ($idDetalles as $idx => $idDetalle) {
            $ph = ':r_' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $idDetalle;
        }

        $sql = sprintf(
            'SELECT id_detalle, COALESCE(SUM(cantidad), 0) AS cantidad_total
             FROM %s
             WHERE id_licitacion = :id_licitacion
               AND id_detalle IN (%s)
             GROUP BY id_detalle',
            self::TABLE_LICITACIONES_REAL,
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll() ?: [];

        /** @var array<int, float> $out */
        $out = [];
        foreach ($rows as $row) {
            $idDetalle = isset($row['id_detalle']) ? (int)$row['id_detalle'] : 0;
            if ($idDetalle <= 0) {
                continue;
            }
            $out[$idDetalle] = (float)($row['cantidad_total'] ?? 0.0);
        }

        return $out;
    }

    /**
     * Inserta todas las líneas de entrega en tbl_licitaciones_real.
     *
     * @param array<int, array<string, mixed>> $lineasAInsertar
     */
    private function insertLineasEntrega(array $lineasAInsertar): void
    {
        if ($lineasAInsertar === []) {
            return;
        }

        // Unificamos columnas de todas las lineas para evitar desajustes de placeholders.
        $columns = [];
        $seen = [];
        foreach ($lineasAInsertar as $row) {
            foreach (array_keys($row) as $col) {
                if (isset($seen[$col])) {
                    continue;
                }
                $seen[$col] = true;
                $columns[] = $col;
            }
        }
        $placeholders = array_map(
            static fn (string $col): string => ':' . $col,
            $columns
        );

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::TABLE_LICITACIONES_REAL,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);

        foreach ($lineasAInsertar as $row) {
            $params = [];
            foreach ($columns as $col) {
                $params[':' . $col] = array_key_exists($col, $row) ? $row[$col] : null;
            }
            $stmt->execute($params);
        }
    }
}

