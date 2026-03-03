<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    if ($q === '' || mb_strlen($q) < 2) {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @var array<string, mixed> $user */
    $user = $_SESSION['user'] ?? [];
    $organizationId = (string)($user['organization_id'] ?? '');

    $pdo = Database::getConnection();

    $sql = "
        SELECT
            id            AS id_producto,
            nombre,
            nombre_proveedor,
            referencia,
            codigo_barras
        FROM tbl_productos
        WHERE organization_id = :org
          AND (
                nombre LIKE :q
             OR referencia LIKE :q
             OR codigo_barras LIKE :q
          )
        ORDER BY nombre
        LIMIT 20
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':org' => $organizationId,
        ':q'   => '%' . $q . '%',
    ]);

    $rows = $stmt->fetchAll() ?: [];

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error buscando productos',
        'details' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

