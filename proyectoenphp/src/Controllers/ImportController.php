<?php

declare(strict_types=1);

require_once __DIR__ . '/../Services/ImportService.php';

final class ImportController
{
    private string $organizationId;

    public function __construct(string $organizationId)
    {
        $this->organizationId = $organizationId;
    }

    /**
     * POST /import/upload
     * Endpoint legado. Mantiene compatibilidad y redirige internamente
     * a los endpoints explícitos /import/excel/{licitacion_id} o /import/precios-referencia.
     */
    public function upload(): void
    {
        $type = isset($_POST['type']) ? (string)$_POST['type'] : 'tender';
        if ($type === 'reference_prices') {
            $this->importPreciosReferencia();
            return;
        }

        $licitacionId = isset($_POST['licitacion_id']) && is_numeric($_POST['licitacion_id'])
            ? (int)$_POST['licitacion_id']
            : 0;
        if ($licitacionId <= 0) {
            $this->jsonResponse(400, ['error' => 'licitacion_id es obligatorio y debe ser numérico.']);
            return;
        }

        $this->importExcel($licitacionId);
    }

    /**
     * POST /import/excel/{licitacion_id}
     *
     * Nota: en esta iteración PHP se soporta importación CSV.
     * Si se sube XLS/XLSX se devuelve un error explícito.
     */
    public function importExcel(int $licitacionId): void
    {
        if ($licitacionId <= 0) {
            $this->jsonResponse(400, ['error' => 'licitacion_id debe ser un entero positivo.']);
            return;
        }

        [$tmpPath, $ext] = $this->resolveUploadedFile();
        if ($tmpPath === null) {
            return;
        }

        if ($ext !== 'csv') {
            $this->jsonResponse(400, [
                'error' => 'Actualmente la importación en PHP soporta archivos CSV. Convierte el Excel a CSV e inténtalo de nuevo.',
            ]);
            return;
        }

        $tipoId = isset($_GET['tipo_id']) && is_numeric($_GET['tipo_id'])
            ? (int)$_GET['tipo_id']
            : 1;

        $service = new ImportService($this->organizationId);
        try {
            $result = $service->importTenderCsv($licitacionId, $tmpPath, $tipoId);
            $this->jsonResponse(201, [
                'message' => sprintf('Se han importado correctamente %d partidas.', (int)$result['rows_imported']),
                'licitacion_id' => $licitacionId,
                'rows_imported' => (int)$result['rows_imported'],
            ]);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Error durante la importación de partidas.', $e);
        }
    }

    /**
     * POST /import/precios-referencia
     */
    public function importPreciosReferencia(): void
    {
        [$tmpPath, $ext] = $this->resolveUploadedFile();
        if ($tmpPath === null) {
            return;
        }

        if ($ext !== 'csv') {
            $this->jsonResponse(400, [
                'error' => 'Actualmente la importación en PHP soporta archivos CSV. Convierte el Excel a CSV e inténtalo de nuevo.',
            ]);
            return;
        }

        $service = new ImportService($this->organizationId);
        try {
            $result = $service->importReferencePricesCsv($tmpPath);
            $rowsImported = (int)($result['rows_imported'] ?? 0);
            $rowsSkipped = (int)($result['rows_skipped'] ?? 0);

            $this->jsonResponse(201, [
                'message' => sprintf(
                    'Se han importado %d líneas de precios de referencia.%s',
                    $rowsImported,
                    $rowsSkipped > 0 ? " Se omitieron {$rowsSkipped} líneas." : ''
                ),
                'rows_imported' => $rowsImported,
                'rows_skipped' => $rowsSkipped,
                'skipped_details' => [],
            ]);
        } catch (\Throwable $e) {
            $this->jsonError(500, 'Error durante la importación de precios de referencia.', $e);
        }
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function resolveUploadedFile(): array
    {
        if (!isset($_FILES['file'])) {
            $this->jsonResponse(400, ['error' => 'No se ha subido ningún archivo.']);
            return [null, ''];
        }

        $file = $_FILES['file'];
        if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
            $this->jsonResponse(400, ['error' => 'Error en la subida del archivo.']);
            return [null, ''];
        }

        $tmpPath = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
        $originalName = isset($file['name']) ? (string)$file['name'] : '';
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            $this->jsonResponse(400, ['error' => 'Archivo de subida no válido.']);
            return [null, ''];
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return [$tmpPath, $ext];
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
     * Envía una respuesta JSON de error incluyendo detalles.
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

