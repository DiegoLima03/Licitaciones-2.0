<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseRepository.php';

final class DisponibleRepository extends BaseRepository
{
    private const TABLE = 'tbl_disponible';

    /**
     * Devuelve todos los productos disponibles con filtros opcionales.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(
        ?string $zona = null,
        ?bool $disponible = null,
        ?string $buscar = null
    ): array {
        $where = ['1 = 1'];
        $params = [];

        if ($zona !== null && $zona !== '') {
            $where[] = 'LOWER(zona) = :zona';
            $params[':zona'] = mb_strtolower(trim($zona), 'UTF-8');
        }

        if ($disponible !== null) {
            $where[] = 'disponible = :disponible';
            $params[':disponible'] = $disponible ? 1 : 0;
        }

        if ($buscar !== null && $buscar !== '') {
            $term = '%' . $this->escapeLike(mb_strtolower(trim($buscar), 'UTF-8')) . '%';
            $where[] = "(
                LOWER(COALESCE(descripcion_rach, '')) LIKE :buscar1 ESCAPE '\\\\'
                OR LOWER(COALESCE(descripcion, ''))   LIKE :buscar2 ESCAPE '\\\\'
                OR LOWER(COALESCE(codigo_rach, ''))   LIKE :buscar3 ESCAPE '\\\\'
                OR LOWER(COALESCE(nombre_productor, '')) LIKE :buscar4 ESCAPE '\\\\'
                OR LOWER(COALESCE(nombre_floriday, '')) LIKE :buscar5 ESCAPE '\\\\'
            )";
            $params[':buscar1'] = $term;
            $params[':buscar2'] = $term;
            $params[':buscar3'] = $term;
            $params[':buscar4'] = $term;
            $params[':buscar5'] = $term;
        }

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY disponible DESC, nombre_productor ASC, descripcion_rach ASC',
            self::TABLE,
            implode(' AND ', $where)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Obtiene un producto por ID.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        $sql = sprintf('SELECT * FROM %s WHERE id = :id', self::TABLE);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Crea un nuevo producto disponible.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $payload = $this->sanitizePayload($data);
        if ($payload === []) {
            throw new \InvalidArgumentException('No hay datos para insertar.');
        }

        $columns      = array_keys($payload);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::TABLE,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $params = [];
        foreach ($payload as $col => $val) {
            $params[':' . $col] = $val;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Actualiza un producto existente.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $payload = $this->sanitizePayload($data);
        if ($payload === []) {
            return;
        }

        $setClauses = [];
        $params     = [':id' => $id];
        foreach ($payload as $col => $val) {
            $setClauses[]    = sprintf('%s = :%s', $col, $col);
            $params[':' . $col] = $val;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE id = :id',
            self::TABLE,
            implode(', ', $setClauses)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Elimina un producto por ID.
     */
    public function delete(int $id): void
    {
        $sql  = sprintf('DELETE FROM %s WHERE id = :id', self::TABLE);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
    }

    /**
     * Devuelve las zonas únicas existentes.
     *
     * @return array<int, string>
     */
    public function getZonas(): array
    {
        $sql  = sprintf(
            "SELECT DISTINCT zona FROM %s WHERE zona IS NOT NULL AND zona <> '' ORDER BY zona",
            self::TABLE
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'zona');
    }

    /**
     * Devuelve los productos disponibles (disponible = 1) para la vista de cliente.
     * Si $zonas está vacío, devuelve todas las zonas.
     *
     * @param  array<int, string> $zonas  Array de zonas permitidas, vacío = todas.
     * @return array<int, array<string, mixed>>
     */
    public function listForCliente(array $zonas): array
    {
        $where  = ['disponible = 1'];
        $params = [];

        if (!empty($zonas)) {
            $placeholders = [];
            foreach ($zonas as $i => $zona) {
                $key            = ':zona' . $i;
                $placeholders[] = $key;
                $params[$key]   = mb_strtoupper(trim($zona), 'UTF-8');
            }
            $where[] = 'UPPER(COALESCE(zona, \'\')) IN (' . implode(', ', $placeholders) . ')';
        }

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY nombre_productor ASC, descripcion_rach ASC',
            self::TABLE,
            implode(' AND ', $where)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /** Columnas permitidas para INSERT / UPDATE (whitelist). */
    private const ALLOWED_COLUMNS = [
        'foto', 'foto1', 'campanya_precios_espec', 'producto_precio_espec',
        'codigo', 'codigo_rach', 'descripcion_rach', 'ean',
        'id_articulo_agricultor', 'passaporte_fito', 'floricode',
        'nombre_floriday', 'clasificacion', 'calidad', 'descripcion',
        'precio_coste_productor', 'descuento_productor', 'precio_coste_final',
        'tarifa_mayorista', 'precio_x_unid', 'precio_x_unid_diplad_m7',
        'precio_x_unid_almeria', 'precio_t5_directo', 'precio_t5_almeria',
        'precio_t10', 'precio_t15', 'precio_dipladen_t25', 'precio_t25',
        'formato', 'tamanyo_aprox',
        'observaciones', 'clasificacion_compra_facil', 'color', 'caracteristicas',
        'cantidades_minimas', 'unids_x_piso', 'unids_x_cc', 'porcentaje_ocupacion',
        'zona', 'disponible',
        'pedido_x_unid', 'pedido_x_piso', 'pedido_x_cc',
        'cod_productor', 'cod_productor_opc2', 'cod_productor_opc3',
        'nombre_productor', 'unids_disponibles', 'fecha_sem_produccion',
        'ultimo_cambio', 'pasado_a_freshportal',
        'total_unids_x_linea', 'incremento_precio_x_unid',
    ];

    /**
     * Filtra el array de datos a solo columnas permitidas.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $data): array
    {
        $allowed = array_flip(self::ALLOWED_COLUMNS);
        return array_intersect_key($data, $allowed);
    }

    private function escapeLike(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\%', '_' => '\_']);
    }
}
