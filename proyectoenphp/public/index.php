<?php

declare(strict_types=1);

// Front Controller básico para la API PHP.
// Todas las peticiones entran por este archivo.

// Autoload/controladores simples (sin Composer, usamos require_once directos).
require_once __DIR__ . '/../src/Controllers/TendersController.php';
require_once __DIR__ . '/../src/Controllers/ProductsController.php';
require_once __DIR__ . '/../src/Controllers/DeliveriesController.php';
require_once __DIR__ . '/../src/Controllers/CatalogsController.php';
require_once __DIR__ . '/../src/Controllers/ExpensesController.php';
require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Controllers/AnalyticsController.php';
require_once __DIR__ . '/../src/Controllers/SearchController.php';
require_once __DIR__ . '/../src/Controllers/ImportController.php';
require_once __DIR__ . '/../src/Controllers/PermissionsController.php';
require_once __DIR__ . '/../src/Controllers/ReferencePricesController.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';

/**
 * Envía respuesta JSON estándar.
 *
 * @param mixed $data
 */
function sendJsonResponse(int $statusCode, $data): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    if ($data === null || $statusCode === 204) {
        return;
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    // Solo nos quedamos con la parte de la ruta (sin query string).
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';

    // ---------------------------
    // Rutas SSR (no /api)
    // ---------------------------
    if ($method === 'POST' && preg_match('#^/licitaciones/(\d+)/presupuesto$#', $path, $m)) {
        $tenderId = (int)$m[1];
        $controller = new TendersController();
        $controller->updateBudget($tenderId);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/licitaciones/(\d+)/ejecucion$#', $path, $m)) {
        $tenderId = (int)$m[1];
        $controller = new TendersController();
        $controller->updateExecution($tenderId);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/licitaciones/(\d+)/estado$#', $path, $m)) {
        $tenderId = (int)$m[1];
        $controller = new TendersController();
        $controller->updateStatus($tenderId);
        exit;
    }

    // Normalizamos: quitamos un posible sufijo de slash.
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }

    // Buscar el segmento /api en cualquier parte de la ruta (soporta subdirectorios).
    $apiPos = strpos($path, '/api');
    if ($apiPos === false) {
        throw new RuntimeException('Ruta no encontrada.', 404);
    }

    // Quitamos el prefijo hasta /api para facilitar el routing.
    $apiPath = substr($path, $apiPos + 4) ?: '/';

    // ---------------------------
    // AUTH (público: solo /auth/login)
    // ---------------------------
    if ($apiPath === '/auth/login' && $method === 'POST') {
        $controller = new AuthController();
        $controller->login();
        exit;
    }

    // Para el resto de rutas, requerimos autenticación.
    $tokenPayload = AuthMiddleware::authenticate();
    $orgId = (string)($tokenPayload['organization_id'] ?? $tokenPayload['org_id'] ?? '');
    $userId = (string)($tokenPayload['user_id'] ?? $tokenPayload['sub'] ?? '');
    $userRole = (string)($tokenPayload['role'] ?? 'member_licitaciones');

    // ---------------------------
    // AUTH (protegido)
    // ---------------------------
    if ($apiPath === '/auth/me' && $method === 'GET') {
        $controller = new AuthController();
        $controller->me($tokenPayload);
        exit;
    }

    if ($apiPath === '/auth/me/password' && $method === 'PATCH') {
        $controller = new AuthController();
        $controller->updateMyPassword($tokenPayload);
        exit;
    }

    if ($apiPath === '/auth/users' && $method === 'GET') {
        $controller = new AuthController();
        $controller->listUsers($orgId, $userRole);
        exit;
    }

    if ($apiPath === '/auth/users' && $method === 'POST') {
        $controller = new AuthController();
        $controller->createUser($orgId, $userRole);
        exit;
    }

    if (preg_match('#^/auth/users/([^/]+)/password$#', $apiPath, $m) && $method === 'PATCH') {
        $targetUserId = urldecode($m[1]);
        $controller = new AuthController();
        $controller->updateUserPassword($orgId, $userRole, $targetUserId);
        exit;
    }

    if (preg_match('#^/auth/users/([^/]+)$#', $apiPath, $m)) {
        $targetUserId = urldecode($m[1]);
        $controller = new AuthController();
        if ($method === 'PATCH') {
            $controller->updateUserRole($orgId, $userRole, $targetUserId);
        } elseif ($method === 'DELETE') {
            $controller->destroyUser($orgId, $userRole, $userId, $targetUserId);
        } else {
            throw new RuntimeException('Ruta no encontrada.', 404);
        }
        exit;
    }

    // ---------------------------
    // ANALYTICS
    // ---------------------------
    if ($apiPath === '/analytics/kpis' && $method === 'GET') {
        $controller = new AnalyticsController($orgId);
        $controller->getKpis();
        exit;
    }

    if (preg_match('#^/analytics/material-trends/(.+)$#', $apiPath, $m) && $method === 'GET') {
        $materialName = urldecode($m[1]);
        $controller = new AnalyticsController($orgId);
        $controller->getMaterialTrends($materialName);
        exit;
    }

    if ($apiPath === '/analytics/risk-adjusted-pipeline' && $method === 'GET') {
        $controller = new AnalyticsController($orgId);
        $controller->getRiskAdjustedPipeline();
        exit;
    }

    if ($apiPath === '/analytics/sweet-spots' && $method === 'GET') {
        $controller = new AnalyticsController($orgId);
        $controller->getSweetSpots();
        exit;
    }

    if ($apiPath === '/analytics/price-deviation-check' && $method === 'GET') {
        $controller = new AnalyticsController($orgId);
        $controller->getPriceDeviationCheck();
        exit;
    }

    if (preg_match('#^/analytics/product/(\d+)$#', $apiPath, $m) && $method === 'GET') {
        $productId = (int)$m[1];
        $controller = new AnalyticsController($orgId);
        $controller->getProductAnalytics($productId);
        exit;
    }

    // ---------------------------
    // TENDERS (licitaciones)
    // ---------------------------
    if ($apiPath === '/tenders' && $method === 'GET') {
        $controller = new TendersController();
        $controller->index();
        exit;
    }

    if ($apiPath === '/tenders/parents' && $method === 'GET') {
        $controller = new TendersController();
        $controller->parents();
        exit;
    }

    if (preg_match('#^/tenders/(\d+)$#', $apiPath, $m)) {
        $tenderId = (int)$m[1];
        $controller = new TendersController();
        if ($method === 'GET') {
            $controller->show($tenderId);
        } elseif ($method === 'PUT') {
            $controller->update($tenderId);
        } elseif ($method === 'DELETE') {
            $controller->destroy($tenderId);
        } else {
            throw new RuntimeException('Ruta no encontrada.', 404);
        }
        exit;
    }

    if ($apiPath === '/tenders' && $method === 'POST') {
        $controller = new TendersController();
        $controller->store();
        exit;
    }

    if (preg_match('#^/tenders/(\d+)/change-status$#', $apiPath, $m) && $method === 'POST') {
        $tenderId = (int)$m[1];
        $controller = new TendersController();
        $controller->changeStatus($tenderId);
        exit;
    }

    if (preg_match('#^/tenders/(\d+)/partidas$#', $apiPath, $m)) {
        $tenderId = (int)$m[1];
        $controller = new TendersController();
        if ($method === 'POST') {
            $controller->addPartida($tenderId);
        } else {
            throw new RuntimeException('Ruta no encontrada.', 404);
        }
        exit;
    }

    if (preg_match('#^/tenders/(\d+)/partidas/(\d+)$#', $apiPath, $m)) {
        $tenderId = (int)$m[1];
        $detalleId = (int)$m[2];
        $controller = new TendersController();
        if ($method === 'PUT') {
            $controller->updatePartida($tenderId, $detalleId);
        } elseif ($method === 'DELETE') {
            $controller->deletePartida($tenderId, $detalleId);
        } else {
            throw new RuntimeException('Ruta no encontrada.', 404);
        }
        exit;
    }

    // ---------------------------
    // PRODUCTS (productos)
    // ---------------------------
    if ($apiPath === '/productos/search' && $method === 'GET') {
        $controller = new ProductsController($orgId);
        $controller->index();
        exit;
    }

    // ---------------------------
    // PRECIOS REFERENCIA
    // ---------------------------
    if ($apiPath === '/precios-referencia' && $method === 'GET') {
        $controller = new ReferencePricesController($orgId);
        $controller->index();
        exit;
    }

    if ($apiPath === '/precios-referencia' && $method === 'POST') {
        $controller = new ReferencePricesController($orgId);
        $controller->store();
        exit;
    }

    // ---------------------------
    // DELIVERIES (entregas)
    // ---------------------------
    if ($apiPath === '/deliveries' && $method === 'GET') {
        $controller = new DeliveriesController($orgId);
        $controller->index();
        exit;
    }

    if ($apiPath === '/deliveries' && $method === 'POST') {
        $controller = new DeliveriesController($orgId);
        $controller->store();
        exit;
    }

    if (preg_match('#^/deliveries/lines/(\d+)$#', $apiPath, $m) && $method === 'PATCH') {
        $idReal = (int)$m[1];
        $controller = new DeliveriesController($orgId);
        $controller->updateLine($idReal);
        exit;
    }

    if (preg_match('#^/deliveries/(\d+)$#', $apiPath, $m) && $method === 'DELETE') {
        $deliveryId = (int)$m[1];
        $controller = new DeliveriesController($orgId);
        $controller->destroy($deliveryId);
        exit;
    }

    // ---------------------------
    // CATÁLOGOS (estados, tipos, tipos-gasto)
    // ---------------------------
    if ($apiPath === '/estados' && $method === 'GET') {
        $controller = new CatalogsController($orgId);
        $controller->getEstados();
        exit;
    }

    if ($apiPath === '/tipos' && $method === 'GET') {
        $controller = new CatalogsController($orgId);
        $controller->getTipos();
        exit;
    }

    if ($apiPath === '/tipos-gasto' && $method === 'GET') {
        $controller = new CatalogsController($orgId);
        $controller->getTiposGasto();
        exit;
    }

    // ---------------------------
    // EXPENSES (gastos extraordinarios)
    // ---------------------------
    if ($apiPath === '/expenses/tipos' && $method === 'GET') {
        $controller = new ExpensesController($orgId, $userId, $userRole);
        $controller->listExpenseTypes();
        exit;
    }

    if ($apiPath === '/expenses' && $method === 'POST') {
        $controller = new ExpensesController($orgId, $userId, $userRole);
        $controller->store();
        exit;
    }

    if (preg_match('#^/expenses/licitacion/(\d+)$#', $apiPath, $m) && $method === 'GET') {
        $licitacionId = (int)$m[1];
        $controller = new ExpensesController($orgId, $userId, $userRole);
        $controller->listByLicitacion($licitacionId);
        exit;
    }

    if (preg_match('#^/expenses/([0-9a-fA-F-]+)/status$#', $apiPath, $m) && $method === 'PATCH') {
        $expenseId = $m[1];
        $controller = new ExpensesController($orgId, $userId, $userRole);
        $controller->updateStatus($expenseId);
        exit;
    }

    if (preg_match('#^/expenses/([0-9a-fA-F-]+)$#', $apiPath, $m) && $method === 'DELETE') {
        $expenseId = $m[1];
        $controller = new ExpensesController($orgId, $userId, $userRole);
        $controller->destroy($expenseId);
        exit;
    }

    // ---------------------------
    // SEARCH (histórico y precios de referencia)
    // ---------------------------
    if (($apiPath === '/search' || $apiPath === '/search/products') && $method === 'GET') {
        $controller = new SearchController($orgId);
        $controller->searchProducts();
        exit;
    }

    if (preg_match('#^/reference-prices/(\d+)$#', $apiPath, $m) && $method === 'GET') {
        $productId = (int)$m[1];
        $controller = new SearchController($orgId);
        $controller->getReferencePrice($productId);
        exit;
    }

    // ---------------------------
    // IMPORT
    // ---------------------------
    if ($apiPath === '/import/precios-referencia' && $method === 'POST') {
        $controller = new ImportController($orgId);
        $controller->importPreciosReferencia();
        exit;
    }

    if (preg_match('#^/import/excel/(\d+)$#', $apiPath, $m) && $method === 'POST') {
        $licitacionId = (int)$m[1];
        $controller = new ImportController($orgId);
        $controller->importExcel($licitacionId);
        exit;
    }

    if ($apiPath === '/import/upload' && $method === 'POST') {
        $controller = new ImportController($orgId);
        $controller->upload();
        exit;
    }

    // ---------------------------
    // PERMISSIONS
    // ---------------------------
    if ($apiPath === '/permissions/role-matrix' && $method === 'GET') {
        $controller = new PermissionsController($orgId);
        $controller->getRoleMatrix();
        exit;
    }

    if ($apiPath === '/permissions/role-matrix' && $method === 'PUT') {
        $controller = new PermissionsController($orgId);
        $controller->updateRoleMatrix();
        exit;
    }

    if (preg_match('#^/permissions/role/(.+)$#', $apiPath, $m) && $method === 'GET') {
        $role = urldecode($m[1]);
        $controller = new PermissionsController($orgId);
        $controller->getPermissionsForRole($role);
        exit;
    }

    // Si hemos llegado aquí, la ruta no coincide con nada conocido.
    throw new RuntimeException('Ruta no encontrada.', 404);
} catch (Throwable $e) {
    $code = $e->getCode();
    $status = ($code === 404) ? 404 : 500;
    $message = $status === 404 ? 'Not Found' : 'Internal Server Error';

    sendJsonResponse($status, [
        'error' => $message,
        'details' => $e->getMessage(),
    ]);
}

