<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

abstract class BaseRepository
{
    protected \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * Devuelve la cláusula RLS que debe incluirse en todas las consultas.
     */
    protected function getRlsClause(): string
    {
        return '1 = 1';
    }

    /**
     * Devuelve los parámetros asociados a la cláusula RLS.
     *
     * @return array<string, string>
     */
    protected function getRlsParams(): array
    {
        return [];
    }
}

