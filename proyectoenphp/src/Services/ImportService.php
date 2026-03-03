<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/TendersRepository.php';
require_once __DIR__ . '/../../config/database.php';

final class ImportService
{
    private \PDO $pdo;
    private string $organizationId;
    private TendersRepository $tendersRepository;

    public function __construct(string $organizationId)
    {
        $this->pdo = Database::getConnection();
        $this->organizationId = $organizationId;
        $this->tendersRepository = new TendersRepository($organizationId);
    }

    /**
     * Importa partidas de licitación desde un CSV.
     *
     * Formato esperado (cabecera CSV):
     * lote,producto,unidades,pvu,pcu,pmaxu
     *
     * @return array<string, mixed>
     */
    public function importTenderCsv(int $tenderId, string $csvPath, int $tipoId = 1): array
    {
        if (!is_readable($csvPath)) {
            throw new \RuntimeException('No se puede leer el archivo CSV.');
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el archivo CSV.');
        }

        $header = fgetcsv($handle, 0, ',');
        if ($header === false) {
            fclose($handle);
            throw new \RuntimeException('El archivo CSV está vacío o no tiene cabecera.');
        }

        $header = array_map('trim', $header);
        $required = ['lote', 'producto', 'pvu', 'pcu', 'pmaxu'];
        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                fclose($handle);
                throw new \RuntimeException(
                    sprintf("Falta la columna requerida '%s' en el CSV.", $col)
                );
            }
        }

        $indexes = array_flip($header);

        $rowsImported = 0;
        $rowsSkipped = 0;

        try {
            $this->pdo->beginTransaction();

            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                if ($data === [null] || $data === false) {
                    continue;
                }

                $producto = trim((string)($data[$indexes['producto']] ?? ''));
                if ($producto === '') {
                    $rowsSkipped++;
                    continue;
                }

                $lote = trim((string)($data[$indexes['lote']] ?? ''));
                if ($lote === '') {
                    $lote = 'General';
                }

                $unidades = null;
                if ($tipoId !== 2) {
                    $unStr = trim((string)($data[$indexes['unidades']] ?? ''));
                    $unidades = $unStr !== '' ? (float)$unStr : null;
                }

                $pvuStr = trim((string)($data[$indexes['pvu']] ?? '0'));
                $pcuStr = trim((string)($data[$indexes['pcu']] ?? '0'));
                $pmaxStr = trim((string)($data[$indexes['pmaxu']] ?? '0'));

                $pvu = (float)$pvuStr;
                $pcu = (float)$pcuStr;
                $pmax = (float)$pmaxStr;

                $row = [
                    'lote' => $lote,
                    'producto' => $producto,
                    'unidades' => $unidades,
                    'pvu' => $pvu,
                    'pcu' => $pcu,
                    'pmaxu' => $pmax,
                    'activo' => true,
                ];

                $this->tendersRepository->addPartida($tenderId, $row);
                $rowsImported++;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            fclose($handle);
            throw $e;
        }

        fclose($handle);

        return [
            'licitacion_id' => $tenderId,
            'rows_imported' => $rowsImported,
            'rows_skipped' => $rowsSkipped,
        ];
    }

    /**
     * Importa precios de referencia desde un CSV.
     *
     * Formato esperado (cabecera CSV):
     * ref_articulo,articulo,cantidad,precio,fecha,albaran
     *
     * @return array<string, mixed>
     */
    public function importReferencePricesCsv(string $csvPath): array
    {
        if (!is_readable($csvPath)) {
            throw new \RuntimeException('No se puede leer el archivo CSV.');
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el archivo CSV.');
        }

        $header = fgetcsv($handle, 0, ',');
        if ($header === false) {
            fclose($handle);
            throw new \RuntimeException('El archivo CSV está vacío o no tiene cabecera.');
        }

        $header = array_map('trim', $header);
        $required = ['ref_articulo', 'articulo', 'cantidad', 'precio', 'fecha', 'albaran'];
        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                fclose($handle);
                throw new \RuntimeException(
                    sprintf("Falta la columna requerida '%s' en el CSV.", $col)
                );
            }
        }

        $indexes = array_flip($header);

        // Mapas de productos por referencia y nombre
        [$refMap, $nomMap] = $this->buildProductoMaps();

        $rowsImported = 0;
        $rowsSkipped = 0;

        try {
            $this->pdo->beginTransaction();

            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                if ($data === [null] || $data === false) {
                    continue;
                }

                $ref = trim((string)($data[$indexes['ref_articulo']] ?? ''));
                $art = trim((string)($data[$indexes['articulo']] ?? ''));
                $precioStr = trim((string)($data[$indexes['precio']] ?? '0'));
                $cantidadStr = trim((string)($data[$indexes['cantidad']] ?? '0'));
                $fechaStr = trim((string)($data[$indexes['fecha']] ?? ''));
                $albaranStr = trim((string)($data[$indexes['albaran']] ?? ''));

                $precio = (float)$precioStr;
                if ($precio <= 0.0) {
                    $rowsSkipped++;
                    continue;
                }

                $cantidad = $cantidadStr !== '' ? (float)$cantidadStr : null;

                $idProducto = null;
                if ($ref !== '' && isset($refMap[$ref])) {
                    $idProducto = $refMap[$ref];
                } elseif ($art !== '') {
                    $key = $art;
                    $idProducto = $nomMap[$key] ?? $nomMap[mb_strtolower($key)] ?? null;
                }

                if ($idProducto === null) {
                    $rowsSkipped++;
                    continue;
                }

                // Obtener nombre del producto (opcional, para rellenar "producto" en la tabla)
                $productNombre = $this->getProductNameById($idProducto);

                $sql = 'INSERT INTO tbl_precios_referencia
                        (id_producto, producto, organization_id, pvu, pcu, unidades, proveedor, notas, fecha_presupuesto)
                        VALUES
                        (:id_producto, :producto, :organization_id, :pvu, :pcu, :unidades, :proveedor, :notas, :fecha_presupuesto)';

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    ':id_producto' => $idProducto,
                    ':producto' => $productNombre ?? '',
                    ':organization_id' => $this->organizationId,
                    ':pvu' => null,
                    ':pcu' => $precio,
                    ':unidades' => $cantidad,
                    ':proveedor' => $albaranStr !== '' ? $albaranStr : null,
                    ':notas' => null,
                    ':fecha_presupuesto' => $fechaStr !== '' ? $fechaStr : null,
                ]);

                $rowsImported++;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            fclose($handle);
            throw $e;
        }

        fclose($handle);

        return [
            'rows_imported' => $rowsImported,
            'rows_skipped' => $rowsSkipped,
        ];
    }

    /**
     * Construye mapas referencia->id_producto y nombre->id_producto.
     *
     * @return array{0: array<string,int>, 1: array<string,int>}
     */
    private function buildProductoMaps(): array
    {
        $sql = 'SELECT id, nombre, referencia
                FROM tbl_productos
                WHERE ' . $this->getRlsClause();

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->getRlsParams());
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $refMap = [];
        $nomMap = [];

        foreach ($rows as $r) {
            $pid = isset($r['id']) ? (int)$r['id'] : null;
            if ($pid === null) {
                continue;
            }

            $ref = trim((string)($r['referencia'] ?? ''));
            if ($ref !== '') {
                $refMap[$ref] = $pid;
            }

            $nom = trim((string)($r['nombre'] ?? ''));
            if ($nom !== '' && mb_strtolower($nom) !== 'null') {
                $nomMap[$nom] = $pid;
                $nomMap[mb_strtolower($nom)] = $pid;
            }
        }

        return [$refMap, $nomMap];
    }

    /**
     * Obtiene el nombre de producto por ID (mismo organization_id).
     */
    private function getProductNameById(int $productId): ?string
    {
        $sql = 'SELECT nombre
                FROM tbl_productos
                WHERE ' . $this->getRlsClause() . ' AND id = :id
                LIMIT 1';

        $params = $this->getRlsParams();
        $params[':id'] = $productId;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row === null) {
            return null;
        }

        $nombre = trim((string)($row['nombre'] ?? ''));
        return $nombre !== '' ? $nombre : null;
    }

    private function getRlsClause(): string
    {
        return '1 = 1';
    }

    /**
     * @return array<string, string>
     */
    private function getRlsParams(): array
    {
        return [];
    }
}

