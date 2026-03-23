<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repositories/AnalyticsRepository.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $idProducto = isset($_GET['id_producto']) ? (int)$_GET['id_producto'] : 0;
    if ($idProducto <= 0) {
        echo json_encode(['error' => 'id_producto requerido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $repo = new AnalyticsRepository();
    $result = $repo->getWeightedHistoricalPrice($idProducto);

    if ($result === null) {
        echo json_encode([
            'has_data'     => false,
            'weighted_avg' => null,
            'sample_count' => 0,
            'message'      => 'Sin datos históricos para este producto.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'has_data'     => true,
        'weighted_avg' => $result['weighted_avg'],
        'sample_count' => $result['sample_count'],
        'sources'      => $result['sources'],
        'oldest_days'  => $result['oldest_days'],
        'newest_days'  => $result['newest_days'],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'Error consultando precio histórico',
        'details' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
