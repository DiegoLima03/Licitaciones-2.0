<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Comprueba si una columna existe en la tabla indicada.
 */
function tableColumnExists(\PDO $pdo, string $table, string $column): bool
{
    $sql = '
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    return $stmt->fetchColumn() !== false;
}

try {
    /** @var array<string, mixed>|null $user */
    $user = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    if ($user === null) {
        http_response_code(401);
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 30) {
        $limit = 30;
    }

    $pdo = Database::getConnection();

    $hasNombreProveedor = tableColumnExists($pdo, 'tbl_productos', 'nombre_proveedor');
    $hasReferencia = tableColumnExists($pdo, 'tbl_productos', 'referencia');
    $hasCodigoBarras = tableColumnExists($pdo, 'tbl_productos', 'codigo_barras');

    $selectCols = [
        'id AS id_producto',
        'nombre',
        $hasNombreProveedor ? 'nombre_proveedor' : 'NULL AS nombre_proveedor',
        $hasReferencia ? 'referencia' : 'NULL AS referencia',
        $hasCodigoBarras ? 'codigo_barras' : 'NULL AS codigo_barras',
    ];

    $likeValue = '%' . $q . '%';
    $searchCols = ['nombre LIKE :q_nombre'];
    $params = [
        ':q_nombre' => $likeValue,
        ':q_prefijo' => $q . '%',
    ];

    if ($hasReferencia) {
        $searchCols[] = 'referencia LIKE :q_referencia';
        $params[':q_referencia'] = $likeValue;
    }
    if ($hasCodigoBarras) {
        $searchCols[] = 'codigo_barras LIKE :q_codigo_barras';
        $params[':q_codigo_barras'] = $likeValue;
    }

    $sql = sprintf(
        'SELECT %s
         FROM tbl_productos
         WHERE (%s)
         ORDER BY
           CASE WHEN nombre LIKE :q_prefijo THEN 0 ELSE 1 END,
           nombre
         LIMIT %d',
        implode(', ', $selectCols),
        implode(' OR ', $searchCols),
        $limit
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error buscando productos',
        'details' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

