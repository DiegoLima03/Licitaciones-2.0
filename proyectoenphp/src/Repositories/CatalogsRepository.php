<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseRepository.php';

final class CatalogsRepository extends BaseRepository
{
    private const TABLE_ESTADOS = 'tbl_estados';
    private const TABLE_TIPOS = 'tbl_tipolicitacion';
    private const TABLE_TIPOS_GASTO = 'tbl_tipos_gasto';

    /**
     * Lista todos los estados desde tbl_estados.
     * Nota: tabla de catálogo global.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEstados(): array
    {
        $sql = sprintf(
            'SELECT id_estado, nombre_estado FROM %s ORDER BY id_estado',
            self::TABLE_ESTADOS
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll() ?: [];

        return $rows;
    }

    /**
     * Lista todos los tipos desde tbl_tipolicitacion.
     * Nota: tabla de catálogo global.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTipos(): array
    {
        $sql = sprintf(
            'SELECT id_tipolicitacion, tipo FROM %s ORDER BY id_tipolicitacion',
            self::TABLE_TIPOS
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll() ?: [];

        return $rows;
    }

    /**
     * Lista todos los tipos de gasto desde tbl_tipos_gasto.
     * Nota: se trata como catálogo global.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTiposGasto(): array
    {
        $sql = sprintf(
            'SELECT id, codigo, nombre FROM %s ORDER BY id',
            self::TABLE_TIPOS_GASTO
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll() ?: [];

        return $rows;
    }
}

