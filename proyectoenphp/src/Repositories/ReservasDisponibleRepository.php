<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseRepository.php';

final class ReservasDisponibleRepository extends BaseRepository
{
    private const TABLE_RESERVAS = 'tbl_reservas_disponible';
    private const TABLE_CONFIG   = 'tbl_cliente_config';

    // ── Configuración de cliente ────────────────────────────────────────────

    /**
     * Devuelve la configuración de zona/tarifa asignada a un usuario.
     *
     * @return array<string, mixed>|null
     */
    public function getClienteConfig(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE_CONFIG . ' WHERE user_id = :uid LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Crea o actualiza la configuración de un cliente.
     */
    public function upsertClienteConfig(
        string $userId,
        string $zonas,
        string $columnaPrecio,
        bool $puedeReservar = true,
        ?string $notas = null
    ): void {
        $sql = 'INSERT INTO ' . self::TABLE_CONFIG . '
                    (user_id, zonas, columna_precio, puede_reservar, notas)
                VALUES (:uid, :zonas, :cp, :pr, :notas)
                ON DUPLICATE KEY UPDATE
                    zonas          = VALUES(zonas),
                    columna_precio = VALUES(columna_precio),
                    puede_reservar = VALUES(puede_reservar),
                    notas          = VALUES(notas),
                    updated_at     = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':uid'   => $userId,
            ':zonas' => $zonas,
            ':cp'    => $columnaPrecio,
            ':pr'    => $puedeReservar ? 1 : 0,
            ':notas' => $notas,
        ]);
    }

    /**
     * Lista todos los clientes configurados (para la vista de admin en usuarios.php).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listClienteConfigs(): array
    {
        $sql = 'SELECT cc.*, p.full_name, p.email, p.role
                FROM ' . self::TABLE_CONFIG . ' cc
                LEFT JOIN profiles p ON p.id = cc.user_id
                ORDER BY p.full_name ASC, p.email ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ── Reservas ────────────────────────────────────────────────────────────

    /**
     * Devuelve las reservas activas de un cliente con info del producto.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReservasByUser(string $userId): array
    {
        $sql = 'SELECT r.*,
                       d.descripcion_rach, d.nombre_floriday,
                       d.formato, d.nombre_productor
                FROM ' . self::TABLE_RESERVAS . ' r
                LEFT JOIN tbl_disponible d ON d.id = r.disponible_id
                WHERE r.user_id = :uid AND r.unids > 0
                ORDER BY d.nombre_productor ASC, d.descripcion_rach ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Crea o actualiza la reserva de un cliente para un producto.
     */
    public function upsertReserva(int $disponibleId, string $userId, int $unids): void
    {
        $sql = 'INSERT INTO ' . self::TABLE_RESERVAS . '
                    (disponible_id, user_id, unids)
                VALUES (:did, :uid, :unids)
                ON DUPLICATE KEY UPDATE
                    unids      = VALUES(unids),
                    updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':did' => $disponibleId, ':uid' => $userId, ':unids' => $unids]);
    }

    /**
     * Elimina la reserva de un cliente para un producto.
     */
    public function deleteReserva(int $disponibleId, string $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . self::TABLE_RESERVAS . '
             WHERE disponible_id = :did AND user_id = :uid'
        );
        $stmt->execute([':did' => $disponibleId, ':uid' => $userId]);
    }

    /**
     * Devuelve el total de unidades reservadas por todos los clientes,
     * agrupado por disponible_id. Para uso en la vista de admin.
     *
     * @return array<int, int>  [disponible_id => total_reservado]
     */
    public function getTotalesByDisponible(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT disponible_id, SUM(unids) AS total
             FROM ' . self::TABLE_RESERVAS . '
             WHERE unids > 0
             GROUP BY disponible_id'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['disponible_id']] = (int)$row['total'];
        }
        return $result;
    }

    /**
     * Devuelve todas las reservas con detalle de producto y cliente.
     * Para la página de pedidos del admin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllReservasConDetalle(): array
    {
        $sql = 'SELECT
                    r.id, r.disponible_id, r.user_id, r.unids,
                    r.created_at, r.updated_at,
                    d.descripcion_rach, d.nombre_floriday, d.formato,
                    d.nombre_productor, d.zona,
                    d.precio_x_unid, d.precio_x_unid_diplad_m7,
                    d.precio_x_unid_almeria, d.precio_t5_directo, d.precio_t5_almeria,
                    p.full_name, p.email, p.role,
                    cc.columna_precio, cc.zonas AS zonas_cliente
                FROM ' . self::TABLE_RESERVAS . ' r
                LEFT JOIN tbl_disponible d  ON d.id    = r.disponible_id
                LEFT JOIN profiles p         ON p.id    = r.user_id
                LEFT JOIN ' . self::TABLE_CONFIG . ' cc ON cc.user_id = r.user_id
                WHERE r.unids > 0
                ORDER BY
                    COALESCE(p.full_name, p.email) ASC,
                    d.nombre_productor ASC,
                    d.descripcion_rach ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Devuelve el detalle de quién ha reservado un producto. Para uso en admin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDetalleByDisponible(int $disponibleId): array
    {
        $sql = 'SELECT r.*, p.full_name, p.email
                FROM ' . self::TABLE_RESERVAS . ' r
                LEFT JOIN profiles p ON p.id = r.user_id
                WHERE r.disponible_id = :did AND r.unids > 0
                ORDER BY p.full_name ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':did' => $disponibleId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
