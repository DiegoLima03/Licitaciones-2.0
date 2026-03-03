<?php

declare(strict_types=1);

final class Database
{
    private static ?\PDO $connection = null;

    private function __construct()
    {
    }

    private function __clone(): void
    {
    }

    public static function getConnection(): \PDO
    {
        if (self::$connection instanceof \PDO) {
            return self::$connection;
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3308';
        $dbName = getenv('DB_NAME') ?: 'licitaciones';
        $user = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';

        // Si el host viene con puerto (ej. 127.0.0.1:3308), separar
        if (str_contains($host, ':')) {
            [$host, $port] = array_merge(explode(':', $host, 2), ['3308']);
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbName);

        self::$connection = new \PDO(
            $dsn,
            $user,
            $password,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return self::$connection;
    }
}

