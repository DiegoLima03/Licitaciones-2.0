<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseRepository.php';

final class TendersRepository extends BaseRepository
{
    private const TABLE_LICITACIONES = 'tbl_licitaciones';
    private const TABLE_DETALLE = 'tbl_licitaciones_detalle';
    private const PK_LICITACION = 'id_licitacion';
    private const PK_DETALLE = 'id_detalle';

    private const ESTADO_EN_ANALISIS = 3;
    private const ESTADO_ADJUDICADA = 5;

    public function __construct(string $organizationId)
    {
        parent::__construct($organizationId);
    }

    /**
     * Obtiene una licitación por ID.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int $tenderId): ?array
    {
        return $this->getTenderById($tenderId);
    }

    /**
     * Crea una licitación.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function create(array $row): array
    {
        $payload = $row;
        $payload['organization_id'] = $this->organizationId;

        $columns = array_keys($payload);
        $placeholders = array_map(
            static fn (string $col): string => ':' . $col,
            $columns
        );

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::TABLE_LICITACIONES,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $params = [];
        foreach ($payload as $col => $value) {
            $params[':' . $col] = $value;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $id = (int)$this->pdo->lastInsertId();
        $created = $this->getTenderById($id);
        if ($created === null) {
            throw new \RuntimeException('Error al recuperar la licitación recién creada.');
        }

        return $created;
    }

    /**
     * Actualiza una licitación.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $tenderId, array $data): array
    {
        $payload = $data;
        unset($payload['organization_id']);

        if ($payload === []) {
            $existing = $this->getTenderById($tenderId);
            if ($existing === null) {
                throw new \InvalidArgumentException('Licitación no encontrada.');
            }
            return $existing;
        }

        $setClauses = [];
        $params = $this->getRlsParams();
        foreach ($payload as $col => $value) {
            $placeholder = ':' . $col;
            $setClauses[] = sprintf('%s = %s', $col, $placeholder);
            $params[$placeholder] = $value;
        }

        $params[':tender_id'] = $tenderId;

        $sql = sprintf(
            'UPDATE %s
             SET %s
             WHERE %s AND %s = :tender_id',
            self::TABLE_LICITACIONES,
            implode(', ', $setClauses),
            $this->getRlsClause(),
            self::PK_LICITACION
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Licitación no encontrada.');
        }

        $updated = $this->getTenderById($tenderId);
        if ($updated === null) {
            throw new \RuntimeException('Error al recuperar la licitación actualizada.');
        }

        return $updated;
    }

    /**
     * Elimina una licitación.
     */
    public function delete(int $tenderId): void
    {
        $where = $this->getRlsClause() . ' AND ' . self::PK_LICITACION . ' = :tender_id';
        $params = $this->getRlsParams();
        $params[':tender_id'] = $tenderId;

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            self::TABLE_LICITACIONES,
            $where
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Licitación no encontrada.');
        }
    }

    /**
     * Lista licitaciones raíz y, excepcionalmente, derivados en análisis con
     * fecha de presentación en ≤5 días. Filtros opcionales; orden id_licitacion desc.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTenders(
        ?int $estadoId = null,
        ?string $nombre = null,
        ?string $pais = null
    ): array {
        $where = $this->getRlsClause() . ' AND id_licitacion_padre IS NULL';
        $params = $this->getRlsParams();

        if ($estadoId !== null) {
            $where .= ' AND id_estado = :estado_id';
            $params[':estado_id'] = $estadoId;
        }

        if ($nombre !== null && trim($nombre) !== '') {
            $where .= ' AND nombre LIKE :nombre';
            $params[':nombre'] = '%' . trim($nombre) . '%';
        }

        if ($pais !== null && trim($pais) !== '') {
            $where .= ' AND pais = :pais';
            $params[':pais'] = trim($pais);
        }

        $sqlRaiz = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY %s DESC',
            self::TABLE_LICITACIONES,
            $where,
            self::PK_LICITACION
        );

        $stmt = $this->pdo->prepare($sqlRaiz);
        $stmt->execute($params);
        /** @var array<int, array<string, mixed>> $raizList */
        $raizList = $stmt->fetchAll() ?: [];

        $derivadosUrgentes = [];

        if ($estadoId === null || $estadoId === self::ESTADO_EN_ANALISIS) {
            $today = new \DateTimeImmutable('today');
            $end = $today->modify('+5 days');

            $startIso = $today->format('Y-m-d');
            $endIso = $end->format('Y-m-d');

            $whereDeriv = $this->getRlsClause()
                . ' AND id_licitacion_padre IS NOT NULL'
                . ' AND id_estado = :estado_en_analisis'
                . ' AND fecha_presentacion >= :fecha_desde'
                . ' AND fecha_presentacion <= :fecha_hasta';

            $paramsDeriv = $this->getRlsParams();
            $paramsDeriv[':estado_en_analisis'] = self::ESTADO_EN_ANALISIS;
            $paramsDeriv[':fecha_desde'] = $startIso;
            $paramsDeriv[':fecha_hasta'] = $endIso;

            $sqlDeriv = sprintf(
                'SELECT * FROM %s WHERE %s ORDER BY %s DESC',
                self::TABLE_LICITACIONES,
                $whereDeriv,
                self::PK_LICITACION
            );

            $stmtDeriv = $this->pdo->prepare($sqlDeriv);
            $stmtDeriv->execute($paramsDeriv);
            /** @var array<int, array<string, mixed>> $derivadosUrgentes */
            $derivadosUrgentes = $stmtDeriv->fetchAll() ?: [];

            // Filtros adicionales por nombre/pais si aplican
            if ($nombre !== null && trim($nombre) !== '') {
                $n = mb_strtolower(trim($nombre));
                $derivadosUrgentes = array_values(array_filter(
                    $derivadosUrgentes,
                    static function (array $row) use ($n): bool {
                        $nombreRow = (string)($row['nombre'] ?? '');
                        return $nombreRow !== '' && str_contains(mb_strtolower($nombreRow), $n);
                    }
                ));
            }

            if ($pais !== null && trim($pais) !== '') {
                $p = trim($pais);
                $derivadosUrgentes = array_values(array_filter(
                    $derivadosUrgentes,
                    static function (array $row) use ($p): bool {
                        return ($row['pais'] ?? null) === $p;
                    }
                ));
            }

            // Restringir a fecha realmente en ventana (por si el campo es timestamp)
            $derivadosUrgentes = array_values(array_filter(
                $derivadosUrgentes,
                static function (array $row) use ($today, $end): bool {
                    $fp = $row['fecha_presentacion'] ?? null;
                    if ($fp === null || $fp === '') {
                        return false;
                    }

                    try {
                        $datePart = explode('T', (string)$fp)[0];
                        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $datePart);
                        if (!$d) {
                            return false;
                        }

                        return $d >= $today && $d <= $end;
                    } catch (\Throwable) {
                        return false;
                    }
                }
            ));
        }

        // Evitar duplicados por id y ordenar por id desc
        $seen = [];
        foreach ($raizList as $r) {
            if (isset($r[self::PK_LICITACION])) {
                $seen[(string)$r[self::PK_LICITACION]] = true;
            }
        }

        $extra = [];
        foreach ($derivadosUrgentes as $d) {
            $id = isset($d[self::PK_LICITACION]) ? (string)$d[self::PK_LICITACION] : null;
            if ($id !== null && !isset($seen[$id])) {
                $seen[$id] = true;
                $extra[] = $d;
            }
        }

        $merged = array_merge($raizList, $extra);

        usort(
            $merged,
            static function (array $a, array $b): int {
                $va = (int)($a[self::PK_LICITACION] ?? 0);
                $vb = (int)($b[self::PK_LICITACION] ?? 0);
                return $vb <=> $va;
            }
        );

        return $merged;
    }

    /**
     * Licitaciones hijo (CONTRATO_BASADO) cuyo id_licitacion_padre es el dado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getContratosDerivados(int $idLicitacionPadre): array
    {
        $where = $this->getRlsClause() . ' AND id_licitacion_padre = :id_licitacion_padre';
        $params = $this->getRlsParams();
        $params[':id_licitacion_padre'] = $idLicitacionPadre;

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY %s DESC',
            self::TABLE_LICITACIONES,
            $where,
            self::PK_LICITACION
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Obtiene una licitación por ID con sus partidas y contratos derivados.
     *
     * @return array<string, mixed>|null
     */
    public function getTenderWithDetails(int $tenderId): ?array
    {
        $licitacion = $this->getTenderById($tenderId);
        if ($licitacion === null) {
            return null;
        }

        // En esta consulta usamos alias "d" para tbl_licitaciones_detalle,
        // por lo que la cláusula RLS debe estar cualificada para evitar ambigüedad
        // con otras tablas (ej. tbl_productos).
        $where = $this->getRlsClause()
            . ' AND ' . self::PK_LICITACION . ' = :tender_id';
        $params = $this->getRlsParams();
        $params[':tender_id'] = $tenderId;

        $sql = sprintf(
            'SELECT d.*, p.nombre AS producto_nombre, p.nombre_proveedor AS producto_nombre_proveedor
             FROM %s d
             LEFT JOIN tbl_productos p
               ON p.id = d.id_producto
             WHERE %s
             ORDER BY d.lote, d.%s',
            self::TABLE_DETALLE,
            $where,
            self::PK_DETALLE
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } catch (\PDOException $e) {
            // Fallback para esquemas legacy sin id_producto o sin tabla/columnas de productos.
            $sqlFallback = sprintf(
                'SELECT d.*
                 FROM %s d
                 WHERE %s
                 ORDER BY d.lote, d.%s',
                self::TABLE_DETALLE,
                $where,
                self::PK_DETALLE
            );
            $stmt = $this->pdo->prepare($sqlFallback);
            $stmt->execute($params);
        }

        /** @var array<int, array<string, mixed>> $rawList */
        $rawList = $stmt->fetchAll() ?: [];

        $partidas = [];
        foreach ($rawList as $row) {
            $productNombre = $row['producto_nombre'] ?? null;
            if ($productNombre === null || $productNombre === '') {
                $productNombre = $row['nombre_producto_libre'] ?? null;
            }

            $nombreProveedor = (string)($row['producto_nombre_proveedor'] ?? '');
            $nombreProveedor = trim($nombreProveedor);
            $nombreProveedor = $nombreProveedor !== '' ? $nombreProveedor : null;

            $baseRow = $row;
            unset($baseRow['producto_nombre'], $baseRow['producto_nombre_proveedor']);

            $partidas[] = array_merge(
                $baseRow,
                [
                    'product_nombre' => $productNombre,
                    'nombre_proveedor' => $nombreProveedor,
                ]
            );
        }

        $out = $licitacion;
        $out['partidas'] = $partidas;

        $tipoProc = $licitacion['tipo_procedimiento'] ?? '';
        $tipoStr = is_string($tipoProc) ? mb_strtoupper($tipoProc) : '';

        if ($tipoStr === 'ACUERDO_MARCO' || $tipoStr === 'SDA') {
            $out['contratos_derivados'] = $this->getContratosDerivados($tenderId);
        } else {
            $out['contratos_derivados'] = [];
        }

        $idPadre = $licitacion['id_licitacion_padre'] ?? null;
        if ($idPadre !== null) {
            $padre = $this->getTenderById((int)$idPadre);
            if ($padre !== null) {
                $out['licitacion_padre'] = [
                    'id_licitacion' => $padre['id_licitacion'],
                    'nombre' => $padre['nombre'] ?? null,
                    'numero_expediente' => $padre['numero_expediente'] ?? null,
                ];
            } else {
                $out['licitacion_padre'] = null;
            }
        } else {
            $out['licitacion_padre'] = null;
        }

        return $out;
    }

    /**
     * Total de presupuesto de partidas activas.
     */
    public function getActiveBudgetTotal(int $tenderId): float
    {
        // Obtener tipo de licitación para decidir cómo sumar
        $whereTipo = $this->getRlsClause() . ' AND ' . self::PK_LICITACION . ' = :tender_id';
        $paramsTipo = $this->getRlsParams();
        $paramsTipo[':tender_id'] = $tenderId;

        $sqlTipo = sprintf(
            'SELECT id_tipolicitacion FROM %s WHERE %s LIMIT 1',
            self::TABLE_LICITACIONES,
            $whereTipo
        );

        $stmtTipo = $this->pdo->prepare($sqlTipo);
        $stmtTipo->execute($paramsTipo);
        $rowTipo = $stmtTipo->fetch() ?: null;

        $tipoId = null;
        if ($rowTipo !== null && array_key_exists('id_tipolicitacion', $rowTipo)) {
            $value = $rowTipo['id_tipolicitacion'];
            $tipoId = is_numeric($value) ? (int)$value : null;
        }

        $sinUnidades = $tipoId !== null && in_array($tipoId, [2, 4], true);

        $where = $this->getRlsClause()
            . ' AND ' . self::PK_LICITACION . ' = :tender_id'
            . ' AND activo = :activo';
        $params = $this->getRlsParams();
        $params[':tender_id'] = $tenderId;
        $params[':activo'] = 1;

        if ($sinUnidades) {
            $sql = sprintf(
                'SELECT pvu FROM %s WHERE %s',
                self::TABLE_DETALLE,
                $where
            );
        } else {
            $sql = sprintf(
                'SELECT unidades, pvu FROM %s WHERE %s',
                self::TABLE_DETALLE,
                $where
            );
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $total = 0.0;
        while ($row = $stmt->fetch()) {
            $p = (float)($row['pvu'] ?? 0);
            if ($sinUnidades) {
                $total += $p;
            } else {
                $u = (float)($row['unidades'] ?? 0);
                $total += $u * $p;
            }
        }

        return $total;
    }

    /**
     * Obtiene una partida por id_licitacion e id_detalle.
     *
     * @return array<string, mixed>|null
     */
    public function getPartida(int $tenderId, int $detalleId): ?array
    {
        $where = $this->getRlsClause()
            . ' AND ' . self::PK_LICITACION . ' = :tender_id'
            . ' AND ' . self::PK_DETALLE . ' = :detalle_id';
        $params = $this->getRlsParams();
        $params[':tender_id'] = $tenderId;
        $params[':detalle_id'] = $detalleId;

        $sql = sprintf(
            'SELECT d.*, p.nombre AS producto_nombre
             FROM %s d
             LEFT JOIN tbl_productos p
               ON p.id = d.id_producto
             WHERE %s
             LIMIT 1',
            self::TABLE_DETALLE,
            $where
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } catch (\PDOException $e) {
            $sqlFallback = sprintf(
                'SELECT d.*
                 FROM %s d
                 WHERE %s
                 LIMIT 1',
                self::TABLE_DETALLE,
                $where
            );
            $stmt = $this->pdo->prepare($sqlFallback);
            $stmt->execute($params);
        }
        $row = $stmt->fetch();

        if ($row === false || $row === null) {
            return null;
        }

        $productNombre = $row['producto_nombre'] ?? null;
        unset($row['producto_nombre']);

        $row['product_nombre'] = $productNombre;

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * Inserta una partida en tbl_licitaciones_detalle; organization_id se inyecta.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function addPartida(int $tenderId, array $row): array
    {
        $payload = $row;
        $payload['organization_id'] = $this->organizationId;
        $payload[self::PK_LICITACION] = $tenderId;

        $columns = array_keys($payload);
        $placeholders = array_map(
            static fn (string $col): string => ':' . $col,
            $columns
        );

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::TABLE_DETALLE,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $params = [];
        foreach ($payload as $col => $value) {
            $params[':' . $col] = $value;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $lastId = (int)$this->pdo->lastInsertId();
        $inserted = $this->getPartida($tenderId, $lastId);
        if ($inserted === null) {
            throw new \RuntimeException('Insert partida no devolvió datos.');
        }

        return $inserted;
    }

    /**
     * Actualiza una partida; solo si pertenece a la organización.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updatePartida(int $tenderId, int $detalleId, array $data): array
    {
        // No permitir modificar organization_id desde el payload
        $payload = $data;
        unset($payload['organization_id']);

        if ($payload === []) {
            $existing = $this->getPartida($tenderId, $detalleId);
            if ($existing === null) {
                throw new \InvalidArgumentException('Partida no encontrada.');
            }
            return $existing;
        }

        $setClauses = [];
        $params = $this->getRlsParams();
        foreach ($payload as $col => $value) {
            $placeholder = ':' . $col;
            $setClauses[] = sprintf('%s = %s', $col, $placeholder);
            $params[$placeholder] = $value;
        }

        $params[':tender_id'] = $tenderId;
        $params[':detalle_id'] = $detalleId;

        $sql = sprintf(
            'UPDATE %s
             SET %s
             WHERE %s AND %s = :tender_id AND %s = :detalle_id',
            self::TABLE_DETALLE,
            implode(', ', $setClauses),
            $this->getRlsClause(),
            self::PK_LICITACION,
            self::PK_DETALLE
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Partida no encontrada.');
        }

        $updated = $this->getPartida($tenderId, $detalleId);
        if ($updated === null) {
            throw new \RuntimeException('Error al recuperar la partida actualizada.');
        }

        return $updated;
    }

    /**
     * Elimina una partida; solo si pertenece a la organización.
     */
    public function deletePartida(int $tenderId, int $detalleId): void
    {
        $where = $this->getRlsClause()
            . ' AND ' . self::PK_LICITACION . ' = :tender_id'
            . ' AND ' . self::PK_DETALLE . ' = :detalle_id';
        $params = $this->getRlsParams();
        $params[':tender_id'] = $tenderId;
        $params[':detalle_id'] = $detalleId;

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            self::TABLE_DETALLE,
            $where
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Partida no encontrada.');
        }
    }

    /**
     * Licitaciones que pueden ser padre (AM o SDA) y están adjudicadas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getParentTenders(): array
    {
        $where = $this->getRlsClause()
            . ' AND tipo_procedimiento IN (:tipo_am, :tipo_sda)'
            . ' AND id_estado = :estado_adjudicada';

        $params = $this->getRlsParams();
        $params[':tipo_am'] = 'ACUERDO_MARCO';
        $params[':tipo_sda'] = 'SDA';
        $params[':estado_adjudicada'] = self::ESTADO_ADJUDICADA;

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY %s DESC',
            self::TABLE_LICITACIONES,
            $where,
            self::PK_LICITACION
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Actualiza la licitación solo si id_estado coincide (optimistic lock).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function updateTenderWithStateCheck(
        int $tenderId,
        array $data,
        int $expectedIdEstado
    ): ?array {
        // No permitir modificar organization_id desde el payload
        $payload = $data;
        unset($payload['organization_id']);

        if ($payload === []) {
            return $this->getTenderById($tenderId);
        }

        $setClauses = [];
        $params = $this->getRlsParams();

        foreach ($payload as $col => $value) {
            $placeholder = ':' . $col;
            $setClauses[] = sprintf('%s = %s', $col, $placeholder);
            $params[$placeholder] = $value;
        }

        $params[':tender_id'] = $tenderId;
        $params[':expected_id_estado'] = $expectedIdEstado;

        $sql = sprintf(
            'UPDATE %s
             SET %s
             WHERE %s AND %s = :tender_id AND id_estado = :expected_id_estado',
            self::TABLE_LICITACIONES,
            implode(', ', $setClauses),
            $this->getRlsClause(),
            self::PK_LICITACION
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->getTenderById($tenderId);
    }

    /**
     * Obtiene una licitación por ID respetando RLS.
     *
     * @return array<string, mixed>|null
     */
    private function getTenderById(int $tenderId): ?array
    {
        $where = $this->getRlsClause() . ' AND ' . self::PK_LICITACION . ' = :tender_id';
        $params = $this->getRlsParams();
        $params[':tender_id'] = $tenderId;

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s LIMIT 1',
            self::TABLE_LICITACIONES,
            $where
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        if ($row === false || $row === null) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $row;
    }
}

