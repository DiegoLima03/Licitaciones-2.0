<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/AnalyticsRepository.php';

final class AnalyticsController
{
    private AnalyticsRepository $repository;

    public function __construct()
    {
        $this->repository = new AnalyticsRepository();
    }

    /**
     * GET /analytics/kpis
     */
    public function getKpis(): void
    {
        $desde = isset($_GET['fecha_adjudicacion_desde']) ? (string)$_GET['fecha_adjudicacion_desde'] : null;
        $hasta = isset($_GET['fecha_adjudicacion_hasta']) ? (string)$_GET['fecha_adjudicacion_hasta'] : null;

        try {
            $data = $this->repository->getKpis($desde ?: null, $hasta ?: null);
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error obteniendo KPIs.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * GET /analytics/material-trends/{material_name}
     */
    public function getMaterialTrends(string $materialName): void
    {
        try {
            $data = $this->repository->getMaterialTrends($materialName);
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error obteniendo tendencia de material.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * GET /analytics/risk-adjusted-pipeline
     */
    public function getRiskAdjustedPipeline(): void
    {
        try {
            $idEstadoEnAnalisis = $this->repository->getEstadoIdByName('EN ANÁLISIS');
            if ($idEstadoEnAnalisis === null) {
                $this->jsonResponse(200, [[
                    'category' => 'Comparativa',
                    'pipeline_bruto' => 0.0,
                    'pipeline_ajustado' => 0.0,
                ]]);
                return;
            }

            $data = $this->repository->getRiskAdjustedPipeline($idEstadoEnAnalisis);
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error obteniendo risk-adjusted pipeline.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * GET /analytics/sweet-spots
     */
    public function getSweetSpots(): void
    {
        try {
            $idsEstadosCerrados = $this->repository->getEstadoIdsByNames([
                'Adjudicada',
                'No Adjudicada',
                'Terminada',
            ]);
            $data = $this->repository->getSweetSpots($idsEstadosCerrados);
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error obteniendo sweet spots.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * GET /analytics/product/{product_id}
     */
    public function getProductAnalytics(int $productId): void
    {
        if ($productId <= 0) {
            $this->jsonResponse(400, ['error' => 'product_id debe ser un entero positivo.']);
            return;
        }

        try {
            $data = $this->repository->getProductAnalytics($productId);
            if ($data === null) {
                $this->jsonResponse(404, ['error' => 'Producto no encontrado.']);
                return;
            }
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error obteniendo analíticas de producto.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * GET /analytics/price-deviation-check
     */
    public function getPriceDeviationCheck(): void
    {
        $materialName = isset($_GET['material_name']) ? (string)$_GET['material_name'] : '';
        $currentPrice = isset($_GET['current_price']) ? (float)$_GET['current_price'] : 0.0;

        if ($materialName === '') {
            $this->jsonResponse(400, ['error' => 'material_name es obligatorio.']);
            return;
        }
        if ($currentPrice < 0) {
            $this->jsonResponse(400, ['error' => 'current_price debe ser >= 0.']);
            return;
        }

        try {
            $data = $this->repository->getPriceDeviationCheck($materialName, $currentPrice);
            $this->jsonResponse(200, $data);
        } catch (\PDOException $e) {
            $this->jsonError(500, 'Error en comprobación de desviación.', $e);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Unexpected error.', $e);
        }
    }

    /**
     * Envía una respuesta JSON estándar.
     *
     * @param mixed $data
     */
    private function jsonResponse(int $statusCode, $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        if ($data === null || $statusCode === 204) {
            return;
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Envía una respuesta JSON de error.
     */
    private function jsonError(int $statusCode, string $message, \Throwable $e): void
    {
        $payload = [
            'error' => $message,
            'details' => $e->getMessage(),
        ];

        $this->jsonResponse($statusCode, $payload);
    }
}

