<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repositories/SearchRepository.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '' || mb_strlen($q, 'UTF-8') < 2) {
        echo json_encode([
            'albaranes_venta' => [],
            'albaranes_compra' => [],
            'licitaciones' => [],
            'referencia' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
    $limit = max(1, min(500, $limit));

    $repo = new SearchRepository();
    $results = $repo->searchHistorico($q, $limit);

    echo json_encode($results, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error en la búsqueda',
        'details' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
