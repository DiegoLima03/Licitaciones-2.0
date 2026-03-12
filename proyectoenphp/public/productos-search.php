<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repositories/ProductsRepository.php';

header('Content-Type: application/json; charset=utf-8');

try {
    /** @var array<string, mixed>|null $user */
    $user = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    if ($user === null) {
        http_response_code(401);
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '' || mb_strlen($q, 'UTF-8') < 2) {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // limit=0 => sin limite (devolver todas las coincidencias para el autocompletado).
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
    if ($limit < 0) {
        $limit = 0;
    } elseif ($limit > 2000) {
        $limit = 2000;
    }

    $repo = new ProductsRepository();
    // En este autocompletado del detalle de licitacion buscamos solo por nombre.
    $results = $repo->searchProductos($q, $limit, false, true);

    $rows = [];
    foreach ($results as $row) {
        $id = isset($row['id']) ? (int)$row['id'] : 0;
        if ($id <= 0) {
            continue;
        }

        $rows[] = [
            'id_producto' => $id,
            'nombre' => (string)($row['nombre'] ?? ''),
            'nombre_proveedor' => isset($row['nombre_proveedor']) ? (string)$row['nombre_proveedor'] : null,
            'referencia' => isset($row['referencia']) ? (string)$row['referencia'] : null,
            'codigo_barras' => isset($row['codigo_barras']) ? (string)$row['codigo_barras'] : null,
        ];
    }

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error buscando productos',
        'details' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
