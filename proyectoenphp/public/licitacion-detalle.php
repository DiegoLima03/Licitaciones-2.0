<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Repositories/TendersRepository.php';
require_once __DIR__ . '/../src/Repositories/DeliveriesRepository.php';
require_once __DIR__ . '/../src/Repositories/CatalogsRepository.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

/** @var array<string, mixed> $user */
$user = $_SESSION['user'];
$email = (string)($user['email'] ?? '');
$fullName = (string)($user['full_name'] ?? '');
$role = (string)($user['role'] ?? '');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$licitacion = null;
$loadError = null;
$entregas = [];
$tiposGasto = [];
$tiposLicitacion = [];
$selfUrl = (string)($_SERVER['PHP_SELF'] ?? 'licitacion-detalle.php');
$openMapProductsModal = false;
$openLossStateModal = false;
/** @var array<int, array<string,mixed>> $pendingPartidasSinProducto */
$pendingPartidasSinProducto = [];
$postedMotivoPerdida = trim((string)($_POST['motivo_perdida'] ?? ''));
$postedCompetidorGanador = trim((string)($_POST['competidor_ganador'] ?? ''));
$postedImportePerdida = trim((string)($_POST['importe_perdida'] ?? ''));
$isCreateDerivedSubmit = isset($_POST['form_tipo']) && (string)$_POST['form_tipo'] === 'crear_contrato_derivado';
/** @var array<int, string> $postedLotesPerdidos */
$postedLotesPerdidos = isset($_POST['lotes_perdidos']) && is_array($_POST['lotes_perdidos'])
    ? array_values(array_map(static fn ($v): string => trim((string)$v), $_POST['lotes_perdidos']))
    : [];
/** @var array<int, string> $postedGanadoresPorLote */
$postedGanadoresPorLote = isset($_POST['competidor_ganador_lote']) && is_array($_POST['competidor_ganador_lote'])
    ? array_values(array_map(static fn ($v): string => trim((string)$v), $_POST['competidor_ganador_lote']))
    : [];
/** @var array<int, string> $postedImportesPorLote */
$postedImportesPorLote = isset($_POST['importe_perdida_lote']) && is_array($_POST['importe_perdida_lote'])
    ? array_values(array_map(static fn ($v): string => trim((string)$v), $_POST['importe_perdida_lote']))
    : [];
/** @var array<string, array{ganador:string, importe_raw:string}> $postedLossByLoteMap */
$postedLossByLoteMap = [];
foreach ($postedLotesPerdidos as $idxPostedLote => $postedLoteNombre) {
    if ($postedLoteNombre === '') {
        continue;
    }
    $keyPostedLote = mb_strtolower($postedLoteNombre, 'UTF-8');
    $postedLossByLoteMap[$keyPostedLote] = [
        'ganador' => $postedGanadoresPorLote[$idxPostedLote] ?? '',
        'importe_raw' => $postedImportesPorLote[$idxPostedLote] ?? '',
    ];
}
/** @var array<int, string> $estadosLineaEntrega */
$estadosLineaEntrega = ['EN ESPERA', 'ENTREGADO', 'FACTURADO'];
$estadoBloqueoPresupuestoDesde = 4; // Desde "Presentada" el presupuesto queda bloqueado.
$requestedWith = mb_strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
$acceptHeader = mb_strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$isAjaxRequest = $requestedWith === 'xmlhttprequest'
    || strpos($acceptHeader, 'application/json') !== false;
$scriptBasePath = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
$scriptBasePath = $scriptBasePath === '/' ? '' : rtrim($scriptBasePath, '/');
$productosSearchUrl = $scriptBasePath . '/productos-search.php';

/**
 * @param array<string, mixed> $payload
 */
function jsonResponseAndExit(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @param array<int, int> $ids
 * @return array<int, int> mapa [id => id]
 */
function fetchExistingProductIds(\PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids, static fn (int $v): bool => $v > 0)));
    if ($ids === []) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($ids as $idx => $idValue) {
        $ph = ':pid_' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $idValue;
    }

    $sql = sprintf(
        'SELECT id FROM tbl_productos WHERE id IN (%s)',
        implode(', ', $placeholders)
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    /** @var array<int, int> $out */
    $out = [];
    while ($row = $stmt->fetch()) {
        if (isset($row['id'])) {
            $id = (int)$row['id'];
            $out[$id] = $id;
        }
    }

    return $out;
}

function normalizeCountryDisplay(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = strtr($value, [
        "\xC3\x83\xC2\xA1" => "\xC3\xA1",
        "\xC3\x83\xC2\xA9" => "\xC3\xA9",
        "\xC3\x83\xC2\xAD" => "\xC3\xAD",
        "\xC3\x83\xC2\xB3" => "\xC3\xB3",
        "\xC3\x83\xC2\xBA" => "\xC3\xBA",
        "\xC3\x83\xC2\xB1" => "\xC3\xB1",
    ]);

    $lower = mb_strtolower($value, 'UTF-8');
    if ($lower === 'espana' || $lower === ('espa' . "\xC3\xB1" . 'a')) {
        return 'Espa' . "\xC3\xB1" . 'a';
    }
    if ($lower === 'portugal') {
        return 'Portugal';
    }

    return $value;
}

function normalizeCountryKeyForCreate(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = strtr($value, [
        'á' => 'a',
        'à' => 'a',
        'ä' => 'a',
        'â' => 'a',
        'ã' => 'a',
        'é' => 'e',
        'è' => 'e',
        'ë' => 'e',
        'ê' => 'e',
        'í' => 'i',
        'ì' => 'i',
        'ï' => 'i',
        'î' => 'i',
        'ó' => 'o',
        'ò' => 'o',
        'ö' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ú' => 'u',
        'ù' => 'u',
        'ü' => 'u',
        'û' => 'u',
        'ñ' => 'n',
    ]);

    return preg_replace('/\s+/', ' ', trim((string)$value)) ?? '';
}

function canonicalCountryLabelForCreate(string $value): string
{
    $fixed = normalizeCountryDisplay($value);
    $key = normalizeCountryKeyForCreate($fixed);
    if ($key === 'espana') {
        return 'Espa' . "\xC3\xB1" . 'a';
    }
    if ($key === 'portugal') {
        return 'Portugal';
    }

    return trim($fixed);
}

function isValidDateYmdForCreate(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

function spainLabelForCreate(): string
{
    return 'Espa' . "\xC3\xB1" . 'a';
}

function isValidHttpUrlForCreate(string $value): bool
{
    if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    return $scheme === 'http' || $scheme === 'https';
}

/**
 * Crea (si no existe) un producto de catalogo a partir de texto libre y devuelve su id.
 */
function ensureCatalogProductIdForFreeText(\PDO $pdo, string $freeText): int
{
    $nombre = trim($freeText);
    if ($nombre === '') {
        throw new \InvalidArgumentException('El texto libre del producto esta vacio.');
    }

    $sqlFind = 'SELECT id FROM tbl_productos WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(:nombre)) LIMIT 1';
    $stmtFind = $pdo->prepare($sqlFind);
    $stmtFind->execute([
        ':nombre' => $nombre,
    ]);
    $existing = $stmtFind->fetchColumn();
    if ($existing !== false && $existing !== null) {
        return (int)$existing;
    }

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        // Reintento dentro de transaccion para minimizar duplicados en concurrencia.
        $stmtFind->execute([
            ':nombre' => $nombre,
        ]);
        $existingTx = $stmtFind->fetchColumn();
        if ($existingTx !== false && $existingTx !== null) {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return (int)$existingTx;
        }

        $nextId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM tbl_productos')->fetchColumn();
        $nextIdErp = (int)$pdo->query('SELECT COALESCE(MAX(id_erp), 0) + 1 AS next_id_erp FROM tbl_productos')->fetchColumn();

        $sqlInsert = 'INSERT INTO tbl_productos
            (id, id_erp, id_grupo_articulo, id_proveedor, paquete, nombre)
            VALUES
            (:id, :id_erp, 0, 0, 0, :nombre)';
        $stmtInsert = $pdo->prepare($sqlInsert);
        $stmtInsert->execute([
            ':id' => $nextId,
            ':id_erp' => $nextIdErp,
            ':nombre' => $nombre,
        ]);

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        return $nextId;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Fallback: si otro proceso lo insertÃƒÆ’Ã‚Â³ justo antes, recuperar el id y continuar.
        $stmtFind->execute([
            ':nombre' => $nombre,
        ]);
        $existingAfter = $stmtFind->fetchColumn();
        if ($existingAfter !== false && $existingAfter !== null) {
            return (int)$existingAfter;
        }

        throw $e;
    }
}

/**
 * Decodifica lotes_config a lista normalizada [{nombre, ganado}].
 *
 * @param mixed $raw
 * @return array<int, array{nombre:string, ganado:bool}>
 */
function decodeLotesConfig($raw): array
{
    $decoded = null;
    if (is_array($raw)) {
        $decoded = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        try {
            $tmp = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($tmp)) {
                $decoded = $tmp;
            }
        } catch (\Throwable $e) {
            $decoded = null;
        }
    }

    if (!is_array($decoded)) {
        return [];
    }

    /** @var array<string, array{nombre:string, ganado:bool}> $items */
    $items = [];
    foreach ($decoded as $item) {
        $name = '';
        $ganado = true;
        if (is_array($item)) {
            $name = trim((string)($item['nombre'] ?? ''));
            $ganado = !array_key_exists('ganado', $item) || (bool)$item['ganado'];
        } elseif (is_string($item) || is_numeric($item)) {
            $name = trim((string)$item);
            $ganado = true;
        }
        if ($name === '') {
            continue;
        }
        $key = mb_strtolower($name, 'UTF-8');
        if (isset($items[$key])) {
            // Si hay duplicados, preferimos "ganado=true" si alguno lo marca.
            $items[$key]['ganado'] = $items[$key]['ganado'] || $ganado;
            continue;
        }
        $items[$key] = [
            'nombre' => $name,
            'ganado' => $ganado,
        ];
    }

    return array_values($items);
}

/**
 * Devuelve los nombres de lote configurados en lotes_config.
 *
 * @param mixed $raw
 * @return array<int, string>
 */
function extractConfiguredLotes($raw): array
{
    $items = decodeLotesConfig($raw);
    $names = [];
    foreach ($items as $item) {
        $names[] = $item['nombre'];
    }
    return $names;
}

/**
 * Devuelve set de lotes ganados (clave lower-case => nombre original).
 *
 * @param mixed $raw
 * @return array<string, string>
 */
function extractWonLotesSet($raw): array
{
    $items = decodeLotesConfig($raw);
    $out = [];
    foreach ($items as $item) {
        if (!$item['ganado']) {
            continue;
        }
        $out[mb_strtolower($item['nombre'], 'UTF-8')] = $item['nombre'];
    }
    return $out;
}

/**
 * @param array<int, array{nombre:string, ganado:bool}> $items
 * @return array<int, string>
 */
function extractLostLotesFromItems(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        $nombre = trim((string)($item['nombre'] ?? ''));
        if ($nombre === '' || !empty($item['ganado'])) {
            continue;
        }
        $out[] = $nombre;
    }
    return $out;
}

/**
 * @param array<int, array{nombre:string, ganado:bool}> $items
 * @return array<int, array{nombre:string, ganado:bool}>
 */
function markAllDecodedLotesAsLost(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        $nombre = trim((string)($item['nombre'] ?? ''));
        if ($nombre === '') {
            continue;
        }
        $out[] = [
            'nombre' => $nombre,
            'ganado' => false,
        ];
    }
    return $out;
}

/**
 * @param mixed $raw
 */
function parseNullableAmount($raw): ?float
{
    if ($raw === null) {
        return null;
    }
    $txt = trim((string)$raw);
    if ($txt === '') {
        return null;
    }
    $normalized = str_replace(',', '.', $txt);
    if (!is_numeric($normalized)) {
        return null;
    }
    return (float)$normalized;
}

function formatEuroNote(float $amount): string
{
    return number_format($amount, 2, ',', '.') . ' EUR';
}

/**
 * @param array<int, string> $expectedLotes
 * @param array<string, array{ganador:string, importe_raw:string}> $postedByLoteMap
 * @return array{
 *   ok:bool,
 *   details:array<int, array{lote:string, ganador:string, importe:float}>,
 *   missing_lotes:array<int, string>
 * }
 */
function collectPerLoteLossDetails(array $expectedLotes, array $postedByLoteMap): array
{
    $details = [];
    $missingLotes = [];

    foreach ($expectedLotes as $expectedLoteName) {
        $expectedLoteName = trim((string)$expectedLoteName);
        if ($expectedLoteName === '') {
            continue;
        }

        $keyExpectedLote = mb_strtolower($expectedLoteName, 'UTF-8');
        $postedLotData = $postedByLoteMap[$keyExpectedLote] ?? ['ganador' => '', 'importe_raw' => ''];
        $ganador = trim((string)($postedLotData['ganador'] ?? ''));
        $importe = parseNullableAmount($postedLotData['importe_raw'] ?? null);

        if ($ganador === '' || $importe === null || $importe <= 0.0) {
            $missingLotes[] = $expectedLoteName;
            continue;
        }

        $details[] = [
            'lote' => $expectedLoteName,
            'ganador' => $ganador,
            'importe' => $importe,
        ];
    }

    return [
        'ok' => $missingLotes === [],
        'details' => $details,
        'missing_lotes' => $missingLotes,
    ];
}

/**
 * @param array<int, array{lote:string, ganador:string, importe:float}> $lotDetails
 */
function appendLossDescription(
    string $currentDescription,
    string $tag,
    string $motivo,
    array $lotDetails
): string {
    $parts = [];
    if ($motivo !== '') {
        $parts[] = 'Motivo: ' . $motivo;
    }
    if ($lotDetails !== []) {
        $lotNotes = array_map(
            static fn (array $detail): string => $detail['lote'] . ' -> '
                . $detail['ganador'] . ' (' . formatEuroNote((float)$detail['importe']) . ')',
            $lotDetails
        );
        $parts[] = 'Detalle lotes: ' . implode(', ', $lotNotes);
    }

    $block = '[' . $tag . ']: ' . implode(' | ', $parts);
    $currentDescription = trim($currentDescription);

    return $currentDescription === ''
        ? $block
        : rtrim($currentDescription) . "\n" . $block;
}

/**
 * @param array<string, mixed> $licitacionDetalle
 * @param array<int, array<string, mixed>> $entregas
 * @return array{ok:bool,error:string}
 */
function validateFinalizationRequirements(array $licitacionDetalle, array $entregas): array
{
    $partidas = is_array($licitacionDetalle['partidas'] ?? null)
        ? $licitacionDetalle['partidas']
        : [];
    $lotesConfigItems = decodeLotesConfig($licitacionDetalle['lotes_config'] ?? null);
    $filtrarPorLotesGanados = $lotesConfigItems !== [];
    $lotesGanadosSet = $filtrarPorLotesGanados
        ? extractWonLotesSet($lotesConfigItems)
        : [];

    /** @var array<int, float> $presupuestadoPorDetalle */
    $presupuestadoPorDetalle = [];
    foreach ($partidas as $p) {
        if (!is_array($p)) {
            continue;
        }

        $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
        if (!$activo) {
            continue;
        }

        $idDetalle = isset($p['id_detalle']) ? (int)$p['id_detalle'] : 0;
        if ($idDetalle <= 0) {
            continue;
        }

        $lote = trim((string)($p['lote'] ?? ''));
        if ($lote === '') {
            $lote = 'General';
        }
        if ($filtrarPorLotesGanados) {
            $loteKey = mb_strtolower($lote, 'UTF-8');
            if (!isset($lotesGanadosSet[$loteKey])) {
                continue;
            }
        }

        $unidades = (float)($p['unidades'] ?? 0.0);
        if ($unidades <= 0.0) {
            continue;
        }

        if (!isset($presupuestadoPorDetalle[$idDetalle])) {
            $presupuestadoPorDetalle[$idDetalle] = 0.0;
        }
        $presupuestadoPorDetalle[$idDetalle] += $unidades;
    }

    $allowedEstados = [
        'ENTREGADO' => true,
        'FACTURADO' => true,
    ];
    /** @var array<int, float> $entregadoPorDetalle */
    $entregadoPorDetalle = [];
    $lineasEvaluadas = 0;
    $lineasConEstadoPendiente = 0;
    $lineasSinCobro = 0;

    foreach ($entregas as $entrega) {
        if (!is_array($entrega)) {
            continue;
        }
        $lineas = is_array($entrega['lineas'] ?? null) ? $entrega['lineas'] : [];
        foreach ($lineas as $lin) {
            if (!is_array($lin)) {
                continue;
            }

            $idTipoGasto = $lin['id_tipo_gasto'] ?? null;
            if ($idTipoGasto !== null) {
                continue;
            }

            $idDetalle = isset($lin['id_detalle']) ? (int)$lin['id_detalle'] : 0;
            if ($idDetalle <= 0) {
                continue;
            }
            if ($presupuestadoPorDetalle !== [] && !isset($presupuestadoPorDetalle[$idDetalle])) {
                continue;
            }

            $lineasEvaluadas++;

            $cantidad = (float)($lin['cantidad'] ?? 0.0);
            if ($cantidad > 0.0) {
                if (!isset($entregadoPorDetalle[$idDetalle])) {
                    $entregadoPorDetalle[$idDetalle] = 0.0;
                }
                $entregadoPorDetalle[$idDetalle] += $cantidad;
            }

            $estadoLinea = mb_strtoupper(trim((string)($lin['estado'] ?? '')), 'UTF-8');
            if (!isset($allowedEstados[$estadoLinea])) {
                $lineasConEstadoPendiente++;
            }

            $cobradoRaw = $lin['cobrado'] ?? 0;
            $isCobrado = $cobradoRaw === true
                || $cobradoRaw === 1
                || $cobradoRaw === '1';
            if (!$isCobrado) {
                $lineasSinCobro++;
            }
        }
    }

    if ($lineasEvaluadas === 0) {
        return [
            'ok' => false,
            'error' => 'No puedes finalizar la licitacion sin lineas de entrega registradas.',
        ];
    }

    $qtyEpsilon = 0.0001;
    foreach ($presupuestadoPorDetalle as $idDetalle => $presupuestado) {
        $entregado = (float)($entregadoPorDetalle[$idDetalle] ?? 0.0);
        if (($presupuestado - $entregado) > $qtyEpsilon) {
            return [
                'ok' => false,
                'error' => 'No puedes finalizar la licitacion: quedan lineas pendientes de entrega.',
            ];
        }
    }

    if ($lineasConEstadoPendiente > 0 || $lineasSinCobro > 0) {
        return [
            'ok' => false,
            'error' => 'No puedes finalizar la licitacion: todas las lineas deben estar entregadas/facturadas y cobradas.',
        ];
    }

    return [
        'ok' => true,
        'error' => '',
    ];
}

try {
    if ($id <= 0) {
        throw new \InvalidArgumentException('Id de licitacion no valido.');
    }

    $repo = new TendersRepository();
    $deliveriesRepo = new DeliveriesRepository();
    $catalogsRepo = new CatalogsRepository();

    try {
        $tiposLicitacion = $catalogsRepo->getTipos();
    } catch (\Throwable $e) {
        $tiposLicitacion = [];
    }

    // Cargar tipos de gasto para gastos extraordinarios
    try {
        $pdoTmp = Database::getConnection();
        $stmtTipos = $pdoTmp->query('SELECT id, codigo, nombre FROM tbl_tipos_gasto ORDER BY id');
        $tiposGasto = $stmtTipos->fetchAll() ?: [];
    } catch (\Throwable $e) {
        $tiposGasto = [];
    }

    // Si viene un POST, puede ser cambio de estado o nueva partida de presupuesto.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // 0) Crear contrato derivado desde AM/SDA (actua como carpeta).
        if (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'crear_contrato_derivado') {
            $licitacionActual = $repo->getById($id);
            if ($licitacionActual === null) {
                $loadError = 'Licitacion no encontrada.';
            } else {
                $tipoPadre = mb_strtoupper(trim((string)($licitacionActual['tipo_procedimiento'] ?? '')), 'UTF-8');
                if ($tipoPadre !== 'ACUERDO_MARCO' && $tipoPadre !== 'SDA') {
                    $loadError = 'Solo puedes crear contratos derivados dentro de un Acuerdo Marco o SDA.';
                } else {
                    $nombre = trim((string)($_POST['nuevo_nombre'] ?? ''));
                    if ($nombre === '') {
                        $loadError = 'El nombre del proyecto derivado es obligatorio.';
                    }

                    $pais = canonicalCountryLabelForCreate((string)($_POST['nuevo_pais'] ?? ''));
                    $paisKey = normalizeCountryKeyForCreate($pais);
                    if ($loadError === null && !in_array($paisKey, ['espana', 'portugal'], true)) {
                        $loadError = 'Selecciona un pais valido (' . spainLabelForCreate() . ' o Portugal).';
                    }

                    $numeroExpediente = trim((string)($_POST['nuevo_numero_expediente'] ?? ''));
                    if ($loadError === null && $numeroExpediente === '') {
                        $loadError = 'El nro de expediente es obligatorio.';
                    }

                    $enlaceGober = trim((string)($_POST['nuevo_enlace_gober'] ?? ''));
                    $enlaceSharepoint = trim((string)($_POST['nuevo_enlace_sharepoint'] ?? ''));
                    if ($loadError === null && $enlaceGober !== '' && !isValidHttpUrlForCreate($enlaceGober)) {
                        $loadError = 'El enlace Gober debe ser una URL valida (http/https).';
                    }
                    if ($loadError === null && $enlaceSharepoint !== '' && !isValidHttpUrlForCreate($enlaceSharepoint)) {
                        $loadError = 'El enlace SharePoint debe ser una URL valida (http/https).';
                    }

                    $presMaximoRaw = trim((string)($_POST['nuevo_pres_maximo'] ?? ''));
                    if ($loadError === null && $presMaximoRaw === '') {
                        $loadError = 'El presupuesto maximo es obligatorio.';
                    }
                    $presMaximoNorm = str_replace(',', '.', $presMaximoRaw);
                    if ($loadError === null && !is_numeric($presMaximoNorm)) {
                        $loadError = 'El presupuesto maximo debe ser numerico.';
                    }
                    $presMaximo = (float)$presMaximoNorm;
                    if ($loadError === null && $presMaximo < 0) {
                        $loadError = 'El presupuesto maximo no puede ser negativo.';
                    }

                    $fechaPresentacion = trim((string)($_POST['nuevo_fecha_presentacion'] ?? ''));
                    $fechaAdjudicacion = trim((string)($_POST['nuevo_fecha_adjudicacion'] ?? ''));
                    $fechaFinalizacion = trim((string)($_POST['nuevo_fecha_finalizacion'] ?? ''));
                    if ($loadError === null && !isValidDateYmdForCreate($fechaPresentacion)) {
                        $loadError = 'La fecha de presentacion es obligatoria y debe tener formato YYYY-MM-DD.';
                    }
                    if ($loadError === null && !isValidDateYmdForCreate($fechaAdjudicacion)) {
                        $loadError = 'La fecha de adjudicacion es obligatoria y debe tener formato YYYY-MM-DD.';
                    }
                    if ($loadError === null && !isValidDateYmdForCreate($fechaFinalizacion)) {
                        $loadError = 'La fecha de finalizacion es obligatoria y debe tener formato YYYY-MM-DD.';
                    }
                    if ($loadError === null && $fechaPresentacion > $fechaAdjudicacion) {
                        $loadError = 'La fecha de presentacion debe ser anterior o igual a la fecha de adjudicacion.';
                    }
                    if ($loadError === null) {
                        $hoy = (new \DateTimeImmutable('today'))->format('Y-m-d');
                        if ($fechaPresentacion > $hoy && $enlaceGober === '') {
                            $loadError = 'El enlace Gober es obligatorio cuando la fecha de presentacion es futura.';
                        }
                    }

                    $idTipoRaw = trim((string)($_POST['nuevo_id_tipolicitacion'] ?? ''));
                    if ($loadError === null && ($idTipoRaw === '' || !ctype_digit($idTipoRaw) || (int)$idTipoRaw <= 0)) {
                        $loadError = 'Debes seleccionar un tipo de licitacion.';
                    }
                    $idTipo = (int)$idTipoRaw;
                    if ($loadError === null && $tiposLicitacion !== []) {
                        $tipoExiste = false;
                        foreach ($tiposLicitacion as $tipoItem) {
                            if (!is_array($tipoItem)) {
                                continue;
                            }
                            if ((int)($tipoItem['id_tipolicitacion'] ?? 0) === $idTipo) {
                                $tipoExiste = true;
                                break;
                            }
                        }
                        if (!$tipoExiste) {
                            $loadError = 'El tipo de licitacion seleccionado no existe.';
                        }
                    }

                    if ($loadError === null) {
                        $descripcion = trim((string)($_POST['nuevo_descripcion'] ?? ''));
                        $tipoDerivado = $tipoPadre === 'SDA' ? 'ESPECIFICO_SDA' : 'CONTRATO_BASADO';
                        $repo->create([
                            'nombre' => $nombre,
                            'pais' => $pais,
                            'numero_expediente' => $numeroExpediente,
                            'enlace_gober' => $enlaceGober !== '' ? $enlaceGober : null,
                            'enlace_sharepoint' => $enlaceSharepoint !== '' ? $enlaceSharepoint : null,
                            'pres_maximo' => $presMaximo,
                            'fecha_presentacion' => $fechaPresentacion,
                            'fecha_adjudicacion' => $fechaAdjudicacion,
                            'fecha_finalizacion' => $fechaFinalizacion,
                            'tipo_procedimiento' => $tipoDerivado,
                            'id_tipolicitacion' => $idTipo,
                            'id_licitacion_padre' => $id,
                            'id_estado' => 3,
                            'descripcion' => $descripcion !== '' ? $descripcion : null,
                            'lotes_config' => null,
                        ]);
                        header('Location: ' . $selfUrl . '?id=' . $id . '&tab=contratos-derivados&derived_created=1');
                        exit;
                    }
                }
            }
        // 1) Nuevo albaran
        } elseif (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'nuevo_albaran') {
            $fecha = trim((string)($_POST['fecha'] ?? ''));
            $codigoAlbaran = trim((string)($_POST['codigo_albaran'] ?? ''));
            $cliente = trim((string)($_POST['cliente'] ?? ''));
            $observaciones = trim((string)($_POST['observaciones'] ?? ''));
            /** @var array<int, array<string,mixed>>|mixed $lineasPresuRaw */
            $lineasPresuRaw = $_POST['lineas_presu'] ?? [];
            /** @var array<int, array<string,mixed>>|mixed $lineasExtRaw */
            $lineasExtRaw = $_POST['lineas_ext'] ?? [];
            $lineas = [];

            if ($fecha === '' || $codigoAlbaran === '') {
                $loadError = 'La fecha y el codigo de albaran son obligatorios.';
            } elseif (!is_array($lineasPresuRaw) || !is_array($lineasExtRaw)) {
                $loadError = 'Formato de lineas de albaran invalido.';
            } else {
                // Lineas presupuestadas
                foreach ($lineasPresuRaw as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $idDet = isset($row['id_detalle']) ? (int)$row['id_detalle'] : 0;
                    $cantidad = isset($row['cantidad']) ? (float)str_replace(',', '.', (string)$row['cantidad']) : 0.0;
                    $coste = isset($row['coste_unit']) ? (float)str_replace(',', '.', (string)$row['coste_unit']) : 0.0;
                    $proveedor = trim((string)($row['proveedor'] ?? ''));

                    if ($idDet <= 0 || ($cantidad <= 0 && $coste <= 0)) {
                        continue;
                    }

                    // Buscar partida para obtener id_producto si existe
                    $partida = $repo->getPartida($id, $idDet);
                    $idProducto = null;
                    if ($partida !== null && isset($partida['id_producto'])) {
                        $idProducto = $partida['id_producto'] !== null ? (int)$partida['id_producto'] : null;
                    }

                    $lineas[] = [
                        'id_producto' => $idProducto,
                        'id_detalle' => $idDet,
                        'id_tipo_gasto' => null,
                        'proveedor' => $proveedor,
                        'cantidad' => $cantidad,
                        'coste_unit' => $coste,
                    ];
                }

                // Lineas de gasto extraordinario
                $observacionesOtros = [];
                foreach ($lineasExtRaw as $idx => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $idTipoGasto = isset($row['id_tipo_gasto']) ? (int)$row['id_tipo_gasto'] : 0;
                    $costeExt = isset($row['coste_unit']) ? (float)str_replace(',', '.', (string)$row['coste_unit']) : 0.0;
                    $textoLibre = trim((string)($row['tipo_gasto_libre'] ?? ''));

                    if ($idTipoGasto <= 0 || $costeExt <= 0) {
                        continue;
                    }

                    $lineas[] = [
                        'id_producto' => null,
                        'id_detalle' => null,
                        'id_tipo_gasto' => $idTipoGasto,
                        'proveedor' => null,
                        'cantidad' => 0,
                        'coste_unit' => $costeExt,
                    ];

                    // Si el tipo de gasto se llama "Otros", anadimos la nota al campo observaciones
                    if ($textoLibre !== '') {
                        $nombreTipo = '';
                        foreach ($tiposGasto as $tg) {
                            if ((int)($tg['id'] ?? 0) === $idTipoGasto) {
                                $nombreTipo = (string)($tg['nombre'] ?? '');
                                break;
                            }
                        }
                        $esOtros = mb_strtolower(trim($nombreTipo)) === 'otros';
                        if ($esOtros) {
                            $observacionesOtros[] = 'Linea extra (' . ($idx + 1) . ', Otros): ' . $textoLibre;
                        }
                    }
                }

                if ($lineas === []) {
                    $loadError = 'Debes indicar al menos una linea valida de albaran.';
                } else {
                    try {
                        $observacionesFinal = trim($observaciones);
                        if ($observacionesOtros !== []) {
                            $extra = implode("\n", $observacionesOtros);
                            $observacionesFinal = $observacionesFinal !== '' ? $observacionesFinal . "\n" . $extra : $extra;
                        }

                        $payload = [
                            'id_licitacion' => $id,
                            'cabecera' => [
                                'fecha' => $fecha,
                                'codigo_albaran' => $codigoAlbaran,
                                'observaciones' => $observacionesFinal,
                                'cliente' => $cliente !== '' ? $cliente : null,
                            ],
                            'lineas' => $lineas,
                        ];
                        $deliveriesRepo->createDelivery($payload);
                        header('Location: ' . $selfUrl . '?id=' . $id . '&tab=ejecucion');
                        exit;
                    } catch (\Throwable $e) {
                        $loadError = 'Error al registrar el albaran: ' . $e->getMessage();
                    }
                }
            }
        // 2) Vincular partidas sin producto de catalogo
        } elseif (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'vincular_productos') {
            $detalleIdsRaw = $_POST['detalle_ids'] ?? [];
            $idProductoMapRaw = $_POST['id_producto_map'] ?? [];

            if (!is_array($detalleIdsRaw) || !is_array($idProductoMapRaw)) {
                $loadError = 'Formato invalido para vincular productos.';
                $openMapProductsModal = true;
            } else {
                /** @var array<int, int> $detalleIds */
                $detalleIds = [];
                foreach ($detalleIdsRaw as $dRaw) {
                    $detalleId = (int)$dRaw;
                    if ($detalleId > 0) {
                        $detalleIds[$detalleId] = $detalleId;
                    }
                }

                if ($detalleIds === []) {
                    $loadError = 'No hay partidas para vincular.';
                    $openMapProductsModal = true;
                } else {
                    /** @var array<int, int> $requestedProductIds */
                    $requestedProductIds = [];
                    foreach ($detalleIds as $detalleId) {
                        $pid = isset($idProductoMapRaw[(string)$detalleId]) ? (int)$idProductoMapRaw[(string)$detalleId] : 0;
                        if ($pid > 0) {
                            $requestedProductIds[$pid] = $pid;
                        }
                    }

                    $pdoProducts = Database::getConnection();
                    $validProductIds = fetchExistingProductIds(
                        $pdoProducts,
                        array_values($requestedProductIds)
                    );

                    /** @var array<int, int> $missingPartidas */
                    $missingPartidas = [];
                    foreach ($detalleIds as $detalleId) {
                        $pid = isset($idProductoMapRaw[(string)$detalleId]) ? (int)$idProductoMapRaw[(string)$detalleId] : 0;
                        if ($pid <= 0 || !isset($validProductIds[$pid])) {
                            $missingPartidas[$detalleId] = $detalleId;
                        }
                    }

                    if ($missingPartidas !== []) {
                        $loadError = 'Debes seleccionar un producto valido para cada partida pendiente.';
                        $openMapProductsModal = true;
                    } else {
                        foreach ($detalleIds as $detalleId) {
                            $pid = (int)$idProductoMapRaw[(string)$detalleId];
                            $partidaActual = $repo->getPartida($id, $detalleId);
                            if ($partidaActual === null) {
                                continue;
                            }
                            $repo->updatePartida($id, $detalleId, [
                                'id_producto' => $pid,
                                'nombre_producto_libre' => null,
                            ]);
                        }

                        header('Location: ' . $selfUrl . '?id=' . $id . '&mapped=1');
                        exit;
                    }
                }
            }
        // 3) Cambio de estado de una linea de entrega
        } elseif (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'actualizar_estado_linea') {
            $idRealLinea = isset($_POST['id_real']) ? (int)$_POST['id_real'] : 0;
            $estadoLineaRaw = trim((string)($_POST['estado_linea'] ?? ''));
            $estadoLinea = mb_strtoupper($estadoLineaRaw);

            if ($idRealLinea <= 0) {
                $loadError = 'Linea de entrega invalida.';
                if ($isAjaxRequest) {
                    jsonResponseAndExit([
                        'ok' => false,
                        'message' => $loadError,
                    ], 422);
                }
            } elseif (!in_array($estadoLinea, $estadosLineaEntrega, true)) {
                $loadError = 'Estado de linea invalido.';
                if ($isAjaxRequest) {
                    jsonResponseAndExit([
                        'ok' => false,
                        'message' => $loadError,
                    ], 422);
                }
            } else {
                try {
                    $deliveriesRepo->updateDeliveryLine($idRealLinea, [
                        'estado' => $estadoLinea,
                    ]);
                    if ($isAjaxRequest) {
                        jsonResponseAndExit([
                            'ok' => true,
                            'message' => 'Estado actualizado.',
                            'id_real' => $idRealLinea,
                            'estado' => $estadoLinea,
                        ]);
                    }
                    header('Location: ' . $selfUrl . '?id=' . $id . '&tab=ejecucion');
                    exit;
                } catch (\Throwable $e) {
                    $loadError = 'Error actualizando el estado de la linea: ' . $e->getMessage();
                    if ($isAjaxRequest) {
                        jsonResponseAndExit([
                            'ok' => false,
                            'message' => $loadError,
                        ], 500);
                    }
                }
            }
        // 4) Cambio de cobrado de una linea de entrega
        } elseif (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'actualizar_cobrado_linea') {
            $idRealLinea = isset($_POST['id_real']) ? (int)$_POST['id_real'] : 0;
            $cobradoLineaRaw = (string)($_POST['cobrado_linea'] ?? '');
            $cobradoLinea = $cobradoLineaRaw === '1' ? 1 : 0;

            if ($idRealLinea <= 0) {
                $loadError = 'Linea de entrega invalida.';
                if ($isAjaxRequest) {
                    jsonResponseAndExit([
                        'ok' => false,
                        'message' => $loadError,
                    ], 422);
                }
            } elseif ($cobradoLineaRaw !== '0' && $cobradoLineaRaw !== '1') {
                $loadError = 'Valor de cobrado invalido.';
                if ($isAjaxRequest) {
                    jsonResponseAndExit([
                        'ok' => false,
                        'message' => $loadError,
                    ], 422);
                }
            } else {
                try {
                    $deliveriesRepo->updateDeliveryLine($idRealLinea, [
                        'cobrado' => $cobradoLinea,
                    ]);
                    if ($isAjaxRequest) {
                        jsonResponseAndExit([
                            'ok' => true,
                            'message' => 'Cobrado actualizado.',
                            'id_real' => $idRealLinea,
                            'cobrado' => $cobradoLinea,
                        ]);
                    }
                    header('Location: ' . $selfUrl . '?id=' . $id . '&tab=ejecucion');
                    exit;
                } catch (\Throwable $e) {
                    $loadError = 'Error actualizando el cobrado de la linea: ' . $e->getMessage();
                    if ($isAjaxRequest) {
                        jsonResponseAndExit([
                            'ok' => false,
                            'message' => $loadError,
                        ], 500);
                    }
                }
            }
        // 5) Configurar lotes (crear estructura de lotes para la licitacion)
        } elseif (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'guardar_lotes_config') {
            $licitacionActual = $repo->getById($id);
            if ($licitacionActual === null) {
                $loadError = 'Licitacion no encontrada.';
            } else {
                $tipoProcCfg = mb_strtoupper(trim((string)($licitacionActual['tipo_procedimiento'] ?? '')), 'UTF-8');
                $isContratoDerivadoCfg = !empty($licitacionActual['id_licitacion_padre'])
                    || $tipoProcCfg === 'CONTRATO_BASADO'
                    || $tipoProcCfg === 'ESPECIFICO_SDA';
                $estadoActualCfg = (int)($licitacionActual['id_estado'] ?? 0);
                if ($isContratoDerivadoCfg) {
                    $loadError = 'En contratos basados/especificos no se usan lotes.';
                } elseif ($estadoActualCfg !== 3) {
                    $loadError = 'Solo puedes generar lotes en estado En analisis.';
                }
                $numLotes = isset($_POST['num_lotes']) ? (int)$_POST['num_lotes'] : 0;
                if ($loadError !== null) {
                    // Mensaje definido arriba.
                } elseif ($numLotes < 1 || $numLotes > 20) {
                    $loadError = 'Introduce un numero de lotes entre 1 y 20.';
                } else {
                    $cfgActual = decodeLotesConfig($licitacionActual['lotes_config'] ?? null);
                    $teniaLotesAntes = $cfgActual !== [];

                    $cfgNueva = [];
                    for ($i = 1; $i <= $numLotes; $i++) {
                        $cfgNueva[] = [
                            'nombre' => 'Lote ' . $i,
                            'ganado' => true,
                        ];
                    }

                    $repo->update($id, [
                        'lotes_config' => json_encode($cfgNueva, JSON_UNESCAPED_UNICODE),
                    ]);

                    // Primera configuracion: mover partidas sueltas al primer lote.
                    if (!$teniaLotesAntes && $cfgNueva !== []) {
                        $detalle = $repo->getTenderWithDetails($id);
                        $partidasCfg = is_array($detalle['partidas'] ?? null) ? $detalle['partidas'] : [];
                        $primerLote = $cfgNueva[0]['nombre'];
                        foreach ($partidasCfg as $pCfg) {
                            if (!is_array($pCfg)) {
                                continue;
                            }
                            $idDetalleCfg = isset($pCfg['id_detalle']) ? (int)$pCfg['id_detalle'] : 0;
                            if ($idDetalleCfg <= 0) {
                                continue;
                            }
                            $loteRaw = mb_strtolower(trim((string)($pCfg['lote'] ?? '')), 'UTF-8');
                            if ($loteRaw === '' || $loteRaw === 'general') {
                                $repo->updatePartida($id, $idDetalleCfg, ['lote' => $primerLote]);
                            }
                        }
                    }

                    header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto');
                    exit;
                }
            }
        // 6) Marcar lote como ganado/perdido (permitido desde Presentada)
        } elseif (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'toggle_lote_ganado') {
            $licitacionActual = $repo->getById($id);
            if ($licitacionActual === null) {
                $loadError = 'Licitacion no encontrada.';
            } else {
                $tipoProcCfg = mb_strtoupper(trim((string)($licitacionActual['tipo_procedimiento'] ?? '')), 'UTF-8');
                $isContratoDerivadoCfg = !empty($licitacionActual['id_licitacion_padre'])
                    || $tipoProcCfg === 'CONTRATO_BASADO'
                    || $tipoProcCfg === 'ESPECIFICO_SDA';
                if ($isContratoDerivadoCfg) {
                    $loadError = 'En contratos basados/especificos no se usan lotes.';
                }
                $estadoActual = (int)($licitacionActual['id_estado'] ?? 0);
                if ($loadError !== null) {
                    // Mensaje definido arriba.
                } elseif ($estadoActual !== 4) {
                    $loadError = 'Solo puedes marcar lotes ganados/perdidos en estado Presentada.';
                } else {
                    $nombreLote = trim((string)($_POST['lote_nombre'] ?? ''));
                    $ganadoNuevo = isset($_POST['ganado']) && (string)$_POST['ganado'] === '1';
                    $cfgActual = decodeLotesConfig($licitacionActual['lotes_config'] ?? null);
                    if ($cfgActual === []) {
                        $loadError = 'Esta licitacion no tiene lotes configurados.';
                    } elseif ($nombreLote === '') {
                        $loadError = 'Lote invalido.';
                    } else {
                        $actualizado = false;
                        foreach ($cfgActual as &$itemCfg) {
                            if (mb_strtolower($itemCfg['nombre'], 'UTF-8') !== mb_strtolower($nombreLote, 'UTF-8')) {
                                continue;
                            }
                            $itemCfg['ganado'] = $ganadoNuevo;
                            $actualizado = true;
                            break;
                        }
                        unset($itemCfg);

                        if (!$actualizado) {
                            $loadError = 'No se encontro el lote indicado.';
                        } else {
                            $repo->update($id, [
                                'lotes_config' => json_encode($cfgActual, JSON_UNESCAPED_UNICODE),
                            ]);
                            header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto');
                            exit;
                        }
                    }
                }
            }
        // 7) Acciones de la tabla interactiva de presupuesto (editar/anadir/eliminar en sitio)
        } elseif (isset($_POST['form_tipo']) && $_POST['form_tipo'] === 'budget_table_action') {
            $licitacionActual = $repo->getById($id);
            if ($licitacionActual === null) {
                $loadError = 'Licitacion no encontrada.';
            } else {
                $estadoLicitacionActual = (int)($licitacionActual['id_estado'] ?? 0);
                if ($estadoLicitacionActual >= $estadoBloqueoPresupuestoDesde) {
                    header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto&budget_locked=1');
                    exit;
                }

                $idTipoLicitacionActual = isset($licitacionActual['id_tipolicitacion'])
                    ? (int)$licitacionActual['id_tipolicitacion']
                    : 0;
                $showPmaxuActual = in_array($idTipoLicitacionActual, [1, 2, 4, 5], true);
                $showUnidadesActual = !in_array($idTipoLicitacionActual, [2, 4], true);
                $isTipoDescuentoActual = in_array($idTipoLicitacionActual, [2, 5], true);
                $lotesConfiguradosActual = extractConfiguredLotes($licitacionActual['lotes_config'] ?? null);
                $usaLotesActual = count($lotesConfiguradosActual) > 0;

                $parseDecimal = static function ($raw): float {
                    $txt = trim((string)$raw);
                    if ($txt === '') {
                        return 0.0;
                    }
                    return (float)str_replace(',', '.', $txt);
                };
                $parseDiscountPct = static function ($raw) use ($parseDecimal): float {
                    $pct = $parseDecimal($raw);
                    if (!is_finite($pct) || $pct < 0) {
                        return 0.0;
                    }
                    return $pct;
                };
                $calcPvuFromPmaxu = static function (float $pmaxu, float $discountPct): float {
                    if ($pmaxu <= 0.0) {
                        return 0.0;
                    }
                    $factor = 1.0 - ($discountPct / 100.0);
                    if ($factor < 0.0) {
                        $factor = 0.0;
                    }
                    return round($pmaxu * $factor, 2);
                };

                $descuentoGlobalActual = isset($licitacionActual['descuento_global'])
                    ? (float)$licitacionActual['descuento_global']
                    : 0.0;
                if ($isTipoDescuentoActual) {
                    $descuentoGlobalActual = $parseDiscountPct($_POST['descuento_global'] ?? $descuentoGlobalActual);
                    $descuentoGlobalActual = round($descuentoGlobalActual, 2);
                    $descuentoActualDb = isset($licitacionActual['descuento_global'])
                        ? round((float)$licitacionActual['descuento_global'], 2)
                        : null;
                    if ($descuentoActualDb === null || abs($descuentoActualDb - $descuentoGlobalActual) > 0.0001) {
                        $repo->update($id, ['descuento_global' => $descuentoGlobalActual]);
                    }
                }

                // Eliminar fila
                $eliminarId = isset($_POST['eliminar_id']) ? (int)$_POST['eliminar_id'] : 0;
                if ($eliminarId > 0) {
                    try {
                        $repo->deletePartida($id, $eliminarId);
                    } catch (\Throwable $e) {
                        $loadError = 'No se pudo eliminar la linea: ' . $e->getMessage();
                    }
                    if ($loadError === null) {
                        header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto');
                        exit;
                    }
                }

                /** @var array<int|string, mixed> $lineasRaw */
                $lineasRaw = isset($_POST['lineas']) && is_array($_POST['lineas']) ? $_POST['lineas'] : [];
                /** @var array<int|string, mixed> $lineasNuevasRaw */
                $lineasNuevasRaw = isset($_POST['lineas_nuevas']) && is_array($_POST['lineas_nuevas']) ? $_POST['lineas_nuevas'] : [];
                $pdoProducts = Database::getConnection();

                // Guardar una fila existente
                $guardarId = isset($_POST['guardar_id']) ? (int)$_POST['guardar_id'] : 0;
                if ($guardarId > 0) {
                    $linea = isset($lineasRaw[$guardarId]) && is_array($lineasRaw[$guardarId])
                        ? $lineasRaw[$guardarId]
                        : null;

                    if ($linea === null) {
                        $loadError = 'No se encontro la linea a guardar.';
                    } else {
                        $nombreLinea = trim((string)($linea['nombre_partida'] ?? ''));
                        $idProductoLinea = isset($linea['id_producto']) ? (int)$linea['id_producto'] : 0;
                        $loteLinea = trim((string)($linea['lote'] ?? ''));
                        if (!$usaLotesActual) {
                            $loteLinea = 'General';
                        } elseif ($loteLinea === '') {
                            $loteLinea = $lotesConfiguradosActual[0] ?? 'Lote 1';
                        }

                        $unidades = $parseDecimal($linea['unidades'] ?? '');
                        $pmaxu = $parseDecimal($linea['pmaxu'] ?? '');
                        $pvu = $parseDecimal($linea['pvu'] ?? '');
                        $pcu = $parseDecimal($linea['pcu'] ?? '');
                        if (!$showUnidadesActual) {
                            $unidades = 0.0;
                        }
                        if (!$showPmaxuActual) {
                            $pmaxu = 0.0;
                        }
                        if ($isTipoDescuentoActual) {
                            $pvu = $calcPvuFromPmaxu($pmaxu, $descuentoGlobalActual);
                        }

                        $payload = [
                            'lote' => $loteLinea !== '' ? $loteLinea : 'General',
                            'unidades' => $unidades,
                            'pmaxu' => $pmaxu,
                            'pvu' => $pvu,
                            'pcu' => $pcu,
                        ];

                        if ($idProductoLinea > 0) {
                            $validProductIds = fetchExistingProductIds($pdoProducts, [$idProductoLinea]);
                            if (!isset($validProductIds[$idProductoLinea])) {
                                $loadError = 'El producto seleccionado no existe en el catalogo.';
                            } else {
                                $payload['id_producto'] = $idProductoLinea;
                                $payload['nombre_producto_libre'] = null;
                            }
                        } else {
                            $payload['id_producto'] = null;
                            $payload['nombre_producto_libre'] = $nombreLinea !== '' ? $nombreLinea : null;
                        }

                        if ($loadError === null) {
                            $repo->updatePartida($id, $guardarId, $payload);
                            header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto');
                            exit;
                        }
                    }
                }

                // Guardar toda la tabla
                if ($loadError === null && isset($_POST['guardar_todo']) && $_POST['guardar_todo'] !== '') {
                    foreach ($lineasRaw as $idDetalleRaw => $lineaRaw) {
                        if (!is_array($lineaRaw)) {
                            continue;
                        }
                        $idDetalle = (int)$idDetalleRaw;
                        if ($idDetalle <= 0) {
                            continue;
                        }

                        $nombreLinea = trim((string)($lineaRaw['nombre_partida'] ?? ''));
                        $idProductoLinea = isset($lineaRaw['id_producto']) ? (int)$lineaRaw['id_producto'] : 0;
                        $loteLinea = trim((string)($lineaRaw['lote'] ?? ''));
                        if (!$usaLotesActual) {
                            $loteLinea = 'General';
                        } elseif ($loteLinea === '') {
                            $loteLinea = $lotesConfiguradosActual[0] ?? 'Lote 1';
                        }

                        $unidades = $parseDecimal($lineaRaw['unidades'] ?? '');
                        $pmaxu = $parseDecimal($lineaRaw['pmaxu'] ?? '');
                        $pvu = $parseDecimal($lineaRaw['pvu'] ?? '');
                        $pcu = $parseDecimal($lineaRaw['pcu'] ?? '');
                        if (!$showUnidadesActual) {
                            $unidades = 0.0;
                        }
                        if (!$showPmaxuActual) {
                            $pmaxu = 0.0;
                        }
                        if ($isTipoDescuentoActual) {
                            $pvu = $calcPvuFromPmaxu($pmaxu, $descuentoGlobalActual);
                        }

                        $payload = [
                            'lote' => $loteLinea !== '' ? $loteLinea : 'General',
                            'unidades' => $unidades,
                            'pmaxu' => $pmaxu,
                            'pvu' => $pvu,
                            'pcu' => $pcu,
                        ];

                        if ($idProductoLinea > 0) {
                            $validProductIds = fetchExistingProductIds($pdoProducts, [$idProductoLinea]);
                            if (!isset($validProductIds[$idProductoLinea])) {
                                $loadError = 'Hay una linea con producto invalido. Revisa los datos.';
                                break;
                            }
                            $payload['id_producto'] = $idProductoLinea;
                            $payload['nombre_producto_libre'] = null;
                        } else {
                            $payload['id_producto'] = null;
                            $payload['nombre_producto_libre'] = $nombreLinea !== '' ? $nombreLinea : null;
                        }

                        $repo->updatePartida($id, $idDetalle, $payload);
                    }

                    if ($loadError === null) {
                        foreach ($lineasNuevasRaw as $lineaNuevaRaw) {
                            if (!is_array($lineaNuevaRaw)) {
                                continue;
                            }

                            $nombrePartidaNueva = trim((string)($lineaNuevaRaw['nombre_partida'] ?? ''));
                            $idProductoNuevo = isset($lineaNuevaRaw['id_producto']) ? (int)$lineaNuevaRaw['id_producto'] : 0;
                            $loteNuevo = trim((string)($lineaNuevaRaw['lote'] ?? ''));
                            if (!$usaLotesActual) {
                                $loteNuevo = 'General';
                            } elseif ($loteNuevo === '') {
                                $loteNuevo = $lotesConfiguradosActual[0] ?? 'Lote 1';
                            }

                            $unidadesNueva = $parseDecimal($lineaNuevaRaw['unidades'] ?? '');
                            $pmaxuNueva = $parseDecimal($lineaNuevaRaw['pmaxu'] ?? '');
                            $pvuNueva = $parseDecimal($lineaNuevaRaw['pvu'] ?? '');
                            $pcuNueva = $parseDecimal($lineaNuevaRaw['pcu'] ?? '');
                            if (!$showUnidadesActual) {
                                $unidadesNueva = 0.0;
                            }
                            if (!$showPmaxuActual) {
                                $pmaxuNueva = 0.0;
                            }
                            if ($isTipoDescuentoActual) {
                                $pvuNueva = $calcPvuFromPmaxu($pmaxuNueva, $descuentoGlobalActual);
                            }

                            $hasIdentityNueva = $nombrePartidaNueva !== '' || $idProductoNuevo > 0;

                            if (!$hasIdentityNueva) {
                                continue;
                            }

                            $payloadNueva = [
                                'lote' => $loteNuevo !== '' ? $loteNuevo : 'General',
                                'unidades' => $unidadesNueva,
                                'pmaxu' => $pmaxuNueva,
                                'pvu' => $pvuNueva,
                                'pcu' => $pcuNueva,
                                'activo' => 1,
                            ];

                            if ($idProductoNuevo > 0) {
                                $validProductIds = fetchExistingProductIds($pdoProducts, [$idProductoNuevo]);
                                if (!isset($validProductIds[$idProductoNuevo])) {
                                    $loadError = 'Hay una nueva linea con producto invalido. Revisa los datos.';
                                    break;
                                }
                                $payloadNueva['id_producto'] = $idProductoNuevo;
                                $payloadNueva['nombre_producto_libre'] = null;
                            } else {
                                $payloadNueva['id_producto'] = null;
                                $payloadNueva['nombre_producto_libre'] = $nombrePartidaNueva;
                            }

                            $repo->addPartida($id, $payloadNueva);
                        }
                    }

                    if ($loadError === null) {
                        header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto');
                        exit;
                    }
                }

            }
        // 6) Cambio de estado de licitacion desde el popup
        } elseif (isset($_POST['estado']) && $_POST['estado'] !== '') {
            $estadoRaw = $_POST['estado'];
            if (is_string($estadoRaw) || is_numeric($estadoRaw)) {
                $estadoId = (int)$estadoRaw;
                if ($estadoId > 0) {
                    // Obtener estado actual respetando RLS
                    $actual = $repo->getById($id);
                    if ($actual === null) {
                        $loadError = 'Licitacion no encontrada.';
                    } else {
                        $estadoActual = (int)($actual['id_estado'] ?? 0);
                        $tipoProcEstado = mb_strtoupper(trim((string)($actual['tipo_procedimiento'] ?? '')), 'UTF-8');
                        $isContratoDerivadoEstado = !empty($actual['id_licitacion_padre'])
                            || $tipoProcEstado === 'CONTRATO_BASADO'
                            || $tipoProcEstado === 'ESPECIFICO_SDA';
                        $lotesEstadoItems = $isContratoDerivadoEstado
                            ? []
                            : decodeLotesConfig($actual['lotes_config'] ?? null);
                        $lotesPerdidosEstado = extractLostLotesFromItems($lotesEstadoItems);
                        $motivoPerdidaEstado = $postedMotivoPerdida;

                        // Mismo flujo de estados que en el proyecto React original.
                        $transiciones = [];
                        if ($estadoActual === 1 || $estadoActual === 3) {
                            $transiciones = [
                                4 => 'Presentada',
                                2 => 'Descartar',
                            ];
                        } elseif ($estadoActual === 4) {
                            $transiciones = [
                                5 => 'Adjudicada',
                                6 => 'Marcar como Perdida',
                            ];
                        } elseif ($estadoActual === 5) {
                            $transiciones = [
                                7 => 'Finalizada',
                            ];
                        }

                        if (!array_key_exists($estadoId, $transiciones)) {
                            $loadError = 'Transicion de estado no permitida desde el estado actual.';
                        } else {
                            if ($estadoId === 4) {
                                $detallePresentacion = $repo->getTenderWithDetails($id);
                                $partidasPresentacion = is_array($detallePresentacion['partidas'] ?? null)
                                    ? $detallePresentacion['partidas']
                                    : [];

                                $tienePartidasActivas = false;
                                foreach ($partidasPresentacion as $pp) {
                                    if (!is_array($pp)) {
                                        continue;
                                    }
                                    $activo = array_key_exists('activo', $pp) ? (bool)$pp['activo'] : true;
                                    if ($activo) {
                                        $tienePartidasActivas = true;
                                        break;
                                    }
                                }

                                if (!$tienePartidasActivas) {
                                    $loadError = 'No puedes pasar a Presentada sin al menos una linea presupuestada.';
                                } else {
                                    $repo->update($id, ['id_estado' => $estadoId]);
                                    header('Location: ' . $selfUrl . '?id=' . $id);
                                    exit;
                                }
                            } elseif ($estadoId === 5) {
                                $detalle = $repo->getTenderWithDetails($id);
                                $partidasAdjudicacion = is_array($detalle['partidas'] ?? null)
                                    ? $detalle['partidas']
                                    : [];

                                /** @var array<int, array<string,mixed>> $partidasRelevantesAdjudicacion */
                                $partidasRelevantesAdjudicacion = [];
                                foreach ($partidasAdjudicacion as $p) {
                                    if (!is_array($p)) {
                                        continue;
                                    }
                                    $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
                                    if (!$activo) {
                                        continue;
                                    }
                                    $partidasRelevantesAdjudicacion[] = $p;
                                }

                                /** @var array<int, int> $idsProductoAdjudicacion */
                                $idsProductoAdjudicacion = [];
                                foreach ($partidasRelevantesAdjudicacion as $p) {
                                    if (!is_array($p)) {
                                        continue;
                                    }
                                    $idProdPartida = isset($p['id_producto']) ? (int)$p['id_producto'] : 0;
                                    if ($idProdPartida > 0) {
                                        $idsProductoAdjudicacion[$idProdPartida] = $idProdPartida;
                                    }
                                }

                                /** @var array<int, int> $validProductIdsAdjudicacion */
                                $validProductIdsAdjudicacion = [];
                                if ($idsProductoAdjudicacion !== []) {
                                    $pdoProducts = Database::getConnection();
                                    $validProductIdsAdjudicacion = fetchExistingProductIds(
                                        $pdoProducts,
                                        array_values($idsProductoAdjudicacion)
                                    );
                                }

                                /** @var array<int, array<string,mixed>> $sinProducto */
                                $sinProducto = [];
                                /** @var array<string, string> $nombresSinProducto */
                                $nombresSinProducto = [];
                                foreach ($partidasRelevantesAdjudicacion as $p) {
                                    if (!is_array($p)) {
                                        continue;
                                    }
                                    $idProdPartida = isset($p['id_producto']) ? (int)$p['id_producto'] : 0;
                                    $hasValidProduct = $idProdPartida > 0
                                        && isset($validProductIdsAdjudicacion[$idProdPartida]);
                                    if (!$hasValidProduct) {
                                        $sinProducto[] = $p;
                                        $nombrePendiente = trim((string)($p['nombre_producto_libre'] ?? ($p['product_nombre'] ?? '')));
                                        if ($nombrePendiente !== '') {
                                            $nombresSinProducto[mb_strtolower($nombrePendiente)] = $nombrePendiente;
                                        }
                                    }
                                }

                                if ($loadError !== null) {
                                    // Error ya informado arriba.
                                } elseif ($sinProducto !== []) {
                                    $pendingPartidasSinProducto = $sinProducto;
                                    $openMapProductsModal = true;
                                    $ejemplos = array_slice(array_values($nombresSinProducto), 0, 3);
                                    $detallePendientes = $ejemplos !== []
                                        ? ' Pendientes: "' . implode('", "', $ejemplos) . '".'
                                        : '';
                                    $loadError = 'No se puede adjudicar: hay lineas activas sin producto de catalogo. '
                                        . 'Vincula cada linea con un producto existente y vuelve a intentar.'
                                        . $detallePendientes;
                                } elseif (
                                    $lotesEstadoItems !== []
                                    && count($lotesPerdidosEstado) === count($lotesEstadoItems)
                                ) {
                                    $loadError = 'No puedes marcar Adjudicada si todos los lotes estan perdidos. Usa Marcar como Perdida.';
                                } else {
                                    $updateEstadoData = ['id_estado' => $estadoId];
                                    if ($lotesPerdidosEstado !== []) {
                                        $lossDetailsResult = collectPerLoteLossDetails(
                                            $lotesPerdidosEstado,
                                            $postedLossByLoteMap
                                        );
                                        if (!$lossDetailsResult['ok']) {
                                            $missingLotesTxt = implode(', ', $lossDetailsResult['missing_lotes']);
                                            $loadError = 'Para adjudicar con lotes perdidos debes indicar ganador e importe en cada lote. Faltan: ' . $missingLotesTxt . '.';
                                            $openLossStateModal = true;
                                        } else {
                                            $updateEstadoData['descripcion'] = appendLossDescription(
                                                (string)($actual['descripcion'] ?? ''),
                                                'LOTES PERDIDOS EN ADJUDICACION',
                                                $motivoPerdidaEstado,
                                                $lossDetailsResult['details']
                                            );
                                        }
                                    }

                                    if ($loadError === null) {
                                        $repo->update($id, $updateEstadoData);
                                        header('Location: ' . $selfUrl . '?id=' . $id);
                                        exit;
                                    }
                                }
                            } elseif ($estadoId === 6) {
                                /** @var array<int, string> $lotesPerdidaTotal */
                                $lotesPerdidaTotal = [];
                                foreach ($lotesEstadoItems as $loteEstadoItem) {
                                    $nombreLoteEstado = trim((string)($loteEstadoItem['nombre'] ?? ''));
                                    if ($nombreLoteEstado === '') {
                                        continue;
                                    }
                                    $lotesPerdidaTotal[] = $nombreLoteEstado;
                                }
                                if ($lotesPerdidaTotal === []) {
                                    $lotesPerdidaTotal[] = 'General';
                                }

                                $lossDetailsResult = collectPerLoteLossDetails(
                                    $lotesPerdidaTotal,
                                    $postedLossByLoteMap
                                );

                                if (!$lossDetailsResult['ok']) {
                                    $missingLotesTxt = implode(', ', $lossDetailsResult['missing_lotes']);
                                    $loadError = 'Para marcar la licitacion como perdida debes indicar ganador e importe en cada lote. Faltan: ' . $missingLotesTxt . '.';
                                    $openLossStateModal = true;
                                } else {
                                    $updateEstadoData = [
                                        'id_estado' => $estadoId,
                                        'descripcion' => appendLossDescription(
                                            (string)($actual['descripcion'] ?? ''),
                                            'PERDIDA TOTAL',
                                            $motivoPerdidaEstado,
                                            $lossDetailsResult['details']
                                        ),
                                    ];

                                    if ($lotesEstadoItems !== []) {
                                        $updateEstadoData['lotes_config'] = json_encode(
                                            markAllDecodedLotesAsLost($lotesEstadoItems),
                                            JSON_UNESCAPED_UNICODE
                                        );
                                    }

                                    $repo->update($id, $updateEstadoData);
                                    header('Location: ' . $selfUrl . '?id=' . $id);
                                    exit;
                                }
                            } elseif ($estadoId === 7) {
                                $detalleFinalizacion = $repo->getTenderWithDetails($id);
                                if ($detalleFinalizacion === null) {
                                    $loadError = 'Licitacion no encontrada.';
                                } else {
                                    $entregasFinalizacion = $deliveriesRepo->listDeliveries($id);
                                    $finalizationCheck = validateFinalizationRequirements(
                                        $detalleFinalizacion,
                                        $entregasFinalizacion
                                    );

                                    if (!$finalizationCheck['ok']) {
                                        $loadError = $finalizationCheck['error'];
                                    } else {
                                        $repo->update($id, ['id_estado' => $estadoId]);
                                        header('Location: ' . $selfUrl . '?id=' . $id);
                                        exit;
                                    }
                                }
                            } else {
                                $repo->update($id, ['id_estado' => $estadoId]);
                                header('Location: ' . $selfUrl . '?id=' . $id);
                                exit;
                            }
                        }
                    }
                } else {
                    $loadError = 'Parametro de estado invalido.';
                }
            } else {
                $loadError = 'Parametro de estado invalido.';
            }
        } else {
            // 6) Alta rapida de partida de presupuesto
            $licitacionActual = $repo->getById($id);
            $estadoLicitacionActual = (int)($licitacionActual['id_estado'] ?? 0);
            if ($estadoLicitacionActual >= $estadoBloqueoPresupuestoDesde) {
                header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto&budget_locked=1');
                exit;
            }

            $nombrePartida = trim((string)($_POST['nombre_partida'] ?? ''));
            $idProductoPost = isset($_POST['id_producto']) ? (int)$_POST['id_producto'] : 0;
            $lote = trim((string)($_POST['lote'] ?? ''));
            $idTipoLicitacionActual = isset($licitacionActual['id_tipolicitacion'])
                ? (int)$licitacionActual['id_tipolicitacion']
                : 0;
            $showPmaxuActual = in_array($idTipoLicitacionActual, [1, 2, 4, 5], true);
            $showUnidadesActual = !in_array($idTipoLicitacionActual, [2, 4], true);
            $isTipoDescuentoActual = in_array($idTipoLicitacionActual, [2, 5], true);
            $lotesConfiguradosActual = extractConfiguredLotes($licitacionActual['lotes_config'] ?? null);
            $usaLotesActual = count($lotesConfiguradosActual) > 0;
            if (!$usaLotesActual) {
                $lote = 'General';
            } elseif ($lote === '') {
                $lote = $lotesConfiguradosActual[0] ?? 'Lote 1';
            }
            $unidadesRaw = (string)($_POST['unidades'] ?? '');
            $pmaxuRaw = (string)($_POST['pmaxu'] ?? '');
            $pvuRaw = (string)($_POST['pvu'] ?? '');
            $pcuRaw = (string)($_POST['pcu'] ?? '');

            $unidades = $unidadesRaw !== '' ? (float)str_replace(',', '.', $unidadesRaw) : 0.0;
            $pmaxu = $pmaxuRaw !== '' ? (float)str_replace(',', '.', $pmaxuRaw) : 0.0;
            $pvu = $pvuRaw !== '' ? (float)str_replace(',', '.', $pvuRaw) : 0.0;
            $pcu = $pcuRaw !== '' ? (float)str_replace(',', '.', $pcuRaw) : 0.0;
            if (!$showUnidadesActual) {
                $unidades = 0.0;
            }
            if (!$showPmaxuActual) {
                $pmaxu = 0.0;
            }
            if ($isTipoDescuentoActual) {
                $descuentoGlobalActual = isset($licitacionActual['descuento_global'])
                    ? (float)$licitacionActual['descuento_global']
                    : 0.0;
                if (isset($_POST['descuento_global'])) {
                    $descuentoGlobalActual = (float)str_replace(',', '.', (string)$_POST['descuento_global']);
                    if (!is_finite($descuentoGlobalActual) || $descuentoGlobalActual < 0.0) {
                        $descuentoGlobalActual = 0.0;
                    }
                    $descuentoGlobalActual = round($descuentoGlobalActual, 2);
                }
                $factorDescuento = 1.0 - ($descuentoGlobalActual / 100.0);
                if ($factorDescuento < 0.0) {
                    $factorDescuento = 0.0;
                }
                $pvu = $pmaxu > 0.0 ? round($pmaxu * $factorDescuento, 2) : 0.0;
            }

            $hasNumericData = ($showUnidadesActual && $unidades > 0)
                || ($showPmaxuActual && $pmaxu > 0)
                || $pvu > 0
                || $pcu > 0;

            if ($nombrePartida !== '' && $hasNumericData) {
                $payload = [
                    'lote' => $lote !== '' ? $lote : 'General',
                    'unidades' => $unidades,
                    'pvu' => $pvu,
                    'pcu' => $pcu,
                    'pmaxu' => $pmaxu,
                    'activo' => 1,
                ];

                if ($idProductoPost > 0) {
                    $pdoProducts = Database::getConnection();
                    $validProductIds = fetchExistingProductIds($pdoProducts, [$idProductoPost]);
                    if (!isset($validProductIds[$idProductoPost])) {
                        $loadError = 'El producto seleccionado no existe en el catalogo.';
                    } else {
                        $payload['id_producto'] = $idProductoPost;
                        $payload['nombre_producto_libre'] = null;
                    }
                } else {
                    $payload['nombre_producto_libre'] = $nombrePartida;
                }

                if ($loadError === null) {
                    $repo->addPartida($id, $payload);
                    // Redirigir para evitar re-envio del formulario al refrescar.
                    header('Location: ' . $selfUrl . '?id=' . $id . '&tab=presupuesto');
                    exit;
                }
            }
        }
    }

    $licitacion = $repo->getTenderWithDetails($id);
    if ($licitacion === null) {
        $loadError = 'Licitacion no encontrada.';
    } else {
        // Cargar entregas (albaranes) para pestana de Ejecucion / Remaining
        $entregas = $deliveriesRepo->listDeliveries($id);
    }
} catch (\Throwable $e) {
    $loadError = $e->getMessage();
}

$mappedSuccess = isset($_GET['mapped']) && (string)$_GET['mapped'] === '1';
/** @var array<int, array<string,mixed>> $partidasSinProductoCatalogo */
$partidasSinProductoCatalogo = [];
if ($licitacion !== null) {
    $partidasTmp = is_array($licitacion['partidas'] ?? null) ? $licitacion['partidas'] : [];
    $cfgLotesTmp = decodeLotesConfig($licitacion['lotes_config'] ?? null);
    $tieneLotesConfigTmp = $cfgLotesTmp !== [];
    $lotesGanadosTmp = extractWonLotesSet($licitacion['lotes_config'] ?? null);
    $tipoProcTmp = mb_strtoupper(trim((string)($licitacion['tipo_procedimiento'] ?? '')), 'UTF-8');
    $isContratoDerivadoTmp = !empty($licitacion['id_licitacion_padre'])
        || $tipoProcTmp === 'CONTRATO_BASADO'
        || $tipoProcTmp === 'ESPECIFICO_SDA';
    if ($isContratoDerivadoTmp) {
        $tieneLotesConfigTmp = false;
        $lotesGanadosTmp = [];
    }
    /** @var array<int, int> $idsProductoPartidasActivas */
    $idsProductoPartidasActivas = [];
    foreach ($partidasTmp as $p) {
        if (!is_array($p)) {
            continue;
        }
        $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
        if (!$activo) {
            continue;
        }
        if ($tieneLotesConfigTmp) {
            $loteTmp = mb_strtolower(trim((string)($p['lote'] ?? '')), 'UTF-8');
            if ($loteTmp === '') {
                $loteTmp = 'general';
            }
            if (!isset($lotesGanadosTmp[$loteTmp])) {
                continue;
            }
        }
        $idProdPartida = isset($p['id_producto']) ? (int)$p['id_producto'] : 0;
        if ($idProdPartida > 0) {
            $idsProductoPartidasActivas[$idProdPartida] = $idProdPartida;
        }
    }

    /** @var array<int, int> $validProductIdsPartidasActivas */
    $validProductIdsPartidasActivas = [];
    if ($idsProductoPartidasActivas !== []) {
        $pdoProductsCheck = Database::getConnection();
        $validProductIdsPartidasActivas = fetchExistingProductIds(
            $pdoProductsCheck,
            array_values($idsProductoPartidasActivas)
        );
    }

    foreach ($partidasTmp as $p) {
        if (!is_array($p)) {
            continue;
        }
        $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
        if (!$activo) {
            continue;
        }
        if ($tieneLotesConfigTmp) {
            $loteTmp = mb_strtolower(trim((string)($p['lote'] ?? '')), 'UTF-8');
            if ($loteTmp === '') {
                $loteTmp = 'general';
            }
            if (!isset($lotesGanadosTmp[$loteTmp])) {
                continue;
            }
        }
        $idProdPartida = isset($p['id_producto']) ? (int)$p['id_producto'] : 0;
        $hasValidProduct = $idProdPartida > 0
            && isset($validProductIdsPartidasActivas[$idProdPartida]);
        if (!$hasValidProduct) {
            $partidasSinProductoCatalogo[] = $p;
        }
    }
}
if ($pendingPartidasSinProducto === []) {
    $pendingPartidasSinProducto = $partidasSinProductoCatalogo;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle licitacion</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: var(--vz-crema);
            color: var(--vz-negro);
        }
        .layout {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 220px;
            background: linear-gradient(180deg, #5c472f, var(--vz-marron1) 52%, var(--vz-negro));
            border-right: 1px solid rgba(229, 226, 220, 0.25);
            padding: 16px 14px;
            display: flex;
            flex-direction: column;
        }
        .sidebar-logo {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 18px;
        }
        .sidebar-logo span {
            color: #efe7bf;
        }
        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: auto;
        }
        .nav-link {
            display: block;
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 0.9rem;
            color: var(--vz-crema);
            text-decoration: none;
        }
        .nav-link:hover {
            background-color: rgba(229, 226, 220, 0.14);
        }
        .nav-link.active {
            background: var(--vz-crema);
            color: var(--vz-negro);
            font-weight: 600;
        }
        .sidebar-footer {
            margin-top: 24px;
            font-size: 0.75rem;
            color: rgba(229, 226, 220, 0.7);
        }
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            background: var(--vz-verde);
            border-bottom: 1px solid rgba(16, 24, 14, 0.24);
        }
        header h1 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--vz-crema);
        }
        .user-info {
            font-size: 0.85rem;
            text-align: right;
            color: var(--vz-crema);
        }
        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            background-color: rgba(229, 226, 220, 0.96);
            color: var(--vz-marron1);
            font-size: 0.75rem;
            margin-top: 2px;
        }
        main {
            width: 1100px;
            max-width: 1100px;
            margin: 32px auto;
            padding: 0 16px 32px;
        }
        .card {
            background-color: var(--vz-blanco);
            border-radius: 12px;
            padding: 18px 18px 20px;
            box-shadow: 0 2px 8px rgba(16, 24, 14, 0.08);
            border: 1px solid var(--vz-marron2);
            /* Mantener altura visual constante entre pestanas */
            min-height: 420px;
            display: flex;
            flex-direction: column;
            /* Asegurar mismo ancho en todas las pestanas */
            width: 100%;
            box-sizing: border-box;
        }
        .card h2 {
            margin: 0 0 8px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .detail-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 6px;
        }
        .detail-title {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .detail-head-right {
            margin-left: auto;
            min-width: 350px;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
        }
        .detail-status-row {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }
        .detail-status-label {
            font-size: 0.8rem;
            color: #6b7280;
            font-weight: 600;
        }
        .detail-change-btn {
            border: 1px solid #1f2937;
            border-radius: 9999px;
            background: #020617;
            color: #e5e7eb;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 6px 14px;
            cursor: pointer;
            transition: background-color 140ms ease, transform 140ms ease;
        }
        .detail-change-btn:hover {
            background: #0b1324;
            transform: translateY(-1px);
        }
        .detail-type-cards {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .detail-type-card {
            min-width: 190px;
            border: 1px solid rgba(133, 114, 94, 0.45);
            border-radius: 12px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(245, 242, 235, 0.98) 100%);
            padding: 8px 12px;
            box-shadow: 0 2px 8px rgba(16, 24, 14, 0.06);
        }
        .detail-type-label {
            display: block;
            margin: 0 0 2px;
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--vz-marron2);
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .detail-type-value {
            display: block;
            font-size: 1rem;
            font-weight: 600;
            color: var(--vz-negro);
            line-height: 1.2;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px 20px;
            margin-top: 8px;
            font-size: 0.9rem;
        }
        .meta-label {
            display: block;
            font-size: 0.75rem;
            color: var(--vz-marron2);
            margin-bottom: 2px;
        }
        .meta-value {
            color: var(--vz-negro);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            font-size: 0.85rem;
        }
        th, td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(133, 114, 94, 0.35);
            text-align: left;
        }
        th {
            font-weight: 600;
            color: var(--vz-marron2);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        tbody tr:hover {
            background-color: var(--vz-verde-suave);
        }
        .back-link {
            display: inline-block;
            margin-bottom: 12px;
            font-size: 0.85rem;
            color: #5a4b2f;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        /* Tabs estilo similar al frontend React (TabsList / TabsTrigger) */
        .tabs {
            margin-top: 20px;
        }
        .tabs-list {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 36px;
            border-radius: 9999px;
            background-color: var(--vz-crema);
            padding: 2px;
            font-size: 0.8rem;
            color: var(--vz-marron2);
        }
        .tab-trigger {
            border: none;
            background: transparent;
            padding: 4px 10px;
            border-radius: 9999px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--vz-marron1);
        }
        .tab-trigger:hover {
            color: var(--vz-negro);
        }
        .tab-trigger.active {
            background-color: var(--vz-blanco);
            color: var(--vz-negro);
            box-shadow: 0 0 0 1px rgba(133, 114, 94, 0.4);
        }
        .tab-content {
            margin-top: 16px;
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .lotes-config-panel {
            margin: 10px 0 14px;
            padding: 10px 12px;
            border: 1px solid rgba(133, 114, 94, 0.35);
            border-radius: 12px;
            background: #f8f6ef;
        }
        .lotes-config-empty {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 0.82rem;
            color: #6b5d47;
        }
        .lotes-config-form {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .lotes-config-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #7c6f58;
            font-weight: 700;
        }
        .lotes-config-form input {
            width: 72px;
            height: 32px;
            border: 1px solid var(--vz-marron2);
            border-radius: 8px;
            background: #fff;
            color: var(--vz-negro);
            padding: 0 8px;
            font-size: 0.82rem;
        }
        .lotes-config-form button {
            height: 32px;
            border: 1px solid var(--vz-verde);
            border-radius: 9999px;
            background: var(--vz-verde);
            color: var(--vz-crema);
            font-size: 0.78rem;
            font-weight: 600;
            padding: 0 12px;
            cursor: pointer;
        }
        .kpi-grid {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }
        .kpi-grid-main {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .kpi-grid-secondary {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        .kpi-card {
            border: 1px solid rgba(133, 114, 94, 0.45);
            border-radius: 12px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.99) 0%, rgba(245, 242, 235, 0.98) 100%);
            padding: 10px 12px;
            box-shadow: 0 2px 8px rgba(16, 24, 14, 0.05);
        }
        .kpi-label {
            display: block;
            margin: 0 0 4px;
            font-size: 0.7rem;
            font-weight: 700;
            color: #7c6f58;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .kpi-value {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            color: #111827;
            line-height: 1.2;
        }
        .kpi-value.is-positive {
            color: #14532d;
        }
        .kpi-value.is-warning {
            color: #7c2d12;
        }
        .kpi-value.is-negative {
            color: #9f1239;
        }
        .kpi-note {
            margin-top: 10px;
            border: 1px solid #bae6fd;
            background: #eff6ff;
            color: #1e3a8a;
            border-radius: 10px;
            padding: 9px 12px;
            font-size: 0.83rem;
        }
        .kpi-deviation {
            margin-top: 12px;
            border: 1px solid rgba(133, 114, 94, 0.45);
            border-radius: 12px;
            background: #fff;
            padding: 10px 12px;
        }
        .kpi-deviation-title {
            margin: 0 0 8px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #7c6f58;
        }
        .kpi-deviation-content {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }
        .kpi-deviation-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.83rem;
            color: #475569;
        }
        .kpi-deviation-item strong {
            color: #111827;
            font-size: 0.88rem;
        }
        .kpi-deviation-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            padding: 3px 10px;
            font-size: 0.83rem;
            font-weight: 700;
            border: 1px solid transparent;
        }
        .kpi-deviation-pill.is-over {
            background: #fee2e2;
            border-color: #fecaca;
            color: #991b1b;
        }
        .kpi-deviation-pill.is-under {
            background: #dcfce7;
            border-color: #bbf7d0;
            color: #166534;
        }
        .kpi-deviation-pill.is-neutral {
            background: #f1f5f9;
            border-color: #e2e8f0;
            color: #334155;
        }
        .derived-parent-hint {
            margin-top: 10px;
            padding: 10px 12px;
            border: 1px solid rgba(133, 114, 94, 0.35);
            border-radius: 12px;
            background: #f8f6ef;
            font-size: 0.82rem;
            color: #5f513f;
        }
        .derived-parent-hint a {
            color: #1f3b6f;
            text-decoration: underline;
            font-weight: 600;
        }
        .derived-tab-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }
        .derived-tab-intro {
            margin: 0;
            font-size: 0.85rem;
            color: #6b5d47;
        }
        .derived-create-panel {
            border: 1px solid rgba(133, 114, 94, 0.35);
            border-radius: 12px;
            background: #f8f6ef;
            padding: 12px;
            margin-bottom: 14px;
        }
        .derived-create-title {
            margin: 0 0 8px;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--vz-negro);
        }
        .derived-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }
        .derived-form-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .derived-form-field.full {
            grid-column: 1 / -1;
        }
        .derived-form-field label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #7c6f58;
        }
        .derived-form-field input,
        .derived-form-field select,
        .derived-form-field textarea {
            width: 100%;
            min-height: 34px;
            border: 1px solid #b9a891;
            border-radius: 9px;
            background: #fff;
            color: var(--vz-negro);
            padding: 6px 10px;
            font-size: 0.82rem;
            font-family: inherit;
        }
        .derived-form-field textarea {
            min-height: 74px;
            resize: vertical;
        }
        .derived-form-actions {
            margin-top: 12px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .derived-submit-btn {
            border: none;
            border-radius: 9999px;
            background: var(--vz-verde);
            color: var(--vz-crema);
            font-size: 0.8rem;
            font-weight: 700;
            padding: 8px 14px;
            cursor: pointer;
        }
        .derived-created-banner {
            margin-bottom: 10px;
            border: 1px solid rgba(22, 163, 74, 0.45);
            background: rgba(22, 163, 74, 0.14);
            color: #14532d;
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 0.82rem;
            font-weight: 600;
        }
        .derived-empty {
            margin: 0;
            font-size: 0.84rem;
            color: #7a6c54;
        }
        .derived-table-wrap {
            border: 1px solid rgba(133, 114, 94, 0.35);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }
        .derived-table {
            margin-top: 0;
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }
        .derived-table thead th {
            background: rgba(132, 124, 31, 0.9);
            color: #fdfaf2;
            border-bottom: 1px solid rgba(133, 114, 94, 0.35);
        }
        .derived-table td.is-right,
        .derived-table th.is-right {
            text-align: right;
        }
        .derived-table td a {
            color: #1f3b6f;
            text-decoration: none;
            font-weight: 600;
        }
        .derived-table td a:hover {
            text-decoration: underline;
        }
        @media (max-width: 980px) {
            .detail-head {
                flex-direction: column;
                gap: 12px;
            }
            .detail-head-right {
                width: 100%;
                min-width: 0;
                align-items: flex-start;
                margin-left: 0;
            }
            .detail-status-row {
                justify-content: flex-start;
            }
            .detail-type-cards {
                width: 100%;
                justify-content: flex-start;
            }
            .detail-type-card {
                min-width: 210px;
                flex: 1 1 210px;
            }
            .detail-title {
                font-size: 1.5rem;
            }
            .kpi-grid-main {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .kpi-grid-secondary {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link
        rel="stylesheet"
        href="assets/css/master-detail-theme.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/assets/css/master-detail-theme.css')); ?>"
    >
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-logo">
                Licitaciones
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="licitaciones.php" class="nav-link active">Licitaciones</a>
                <a href="buscador.php" class="nav-link">Buscador historico</a>
                <a href="analytics.php" class="nav-link">Analitica</a>
                <a href="disponible.php" class="nav-link">Disponible</a>
                <a href="disponible-cliente.php" class="nav-link">Vista Cliente</a>
                <a href="pedidos-disponible.php" class="nav-link">Pedidos</a>
                <a href="usuarios.php" class="nav-link">Usuarios</a>
            </nav>
            <div class="sidebar-footer">
                            </div>
        </aside>
        <div class="main">
            <header>
                <h1>Detalle de licitacion</h1>
                <div class="user-info">
                    <div><?php echo htmlspecialchars($fullName !== '' ? $fullName : $email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php if ($role !== ''): ?>
                        <div class="pill"><?php echo htmlspecialchars($role, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <div>
                        <a href="logout.php">Cerrar sesion</a>
                    </div>
                </div>
            </header>

            <main>
                <a href="licitaciones.php" class="back-link">&larr; Volver al listado</a>

                <div class="card">
                    <?php if ($licitacion === null): ?>
                        <?php if ($loadError !== null): ?>
                            <p style="color:#fecaca;font-size:0.9rem;">
                                Error cargando la licitacion: <?php echo htmlspecialchars($loadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </p>
                        <?php else: ?>
                            <p style="color:#9ca3af;font-size:0.9rem;">No se encontro la licitacion solicitada.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($loadError !== null): ?>
                            <div class="detail-error-banner">
                                <?php echo htmlspecialchars($loadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </div>
                        <?php endif; ?>
                        <?php
                        $estadoIdActual = (int)($licitacion['id_estado'] ?? 0);
                        $estadoNombres = [
                            1 => 'Borrador',
                            2 => 'Descartada',
                            3 => 'En analisis',
                            4 => 'Presentada',
                            5 => 'Adjudicada',
                            6 => 'No adjudicada',
                            7 => 'Terminada',
                        ];
                        $estadoActualLabel = $estadoNombres[$estadoIdActual] ?? 'Desconocido';

                        // Colores por estado (pill)
                        $estadoBg = 'rgba(71, 85, 105, 0.16)';
                        $estadoBorder = '#475569';
                        $estadoText = '#1f2937';
                        switch ($estadoIdActual) {
                            case 1: // Borrador
                                $estadoBg = 'rgba(148, 163, 184, 0.24)';
                                $estadoBorder = '#64748b';
                                $estadoText = '#1f2937';
                                break;
                            case 3: // En analisis
                                $estadoBg = 'rgba(59, 130, 246, 0.18)';
                                $estadoBorder = '#2563eb';
                                $estadoText = '#1e3a8a';
                                break;
                            case 4: // Presentada
                                $estadoBg = 'rgba(234, 179, 8, 0.24)';
                                $estadoBorder = '#b45309';
                                $estadoText = '#5f370e';
                                break;
                            case 5: // Adjudicada
                                $estadoBg = 'rgba(34, 197, 94, 0.22)';
                                $estadoBorder = '#15803d';
                                $estadoText = '#14532d';
                                break;
                            case 6: // No adjudicada
                            case 2: // Descartada
                                $estadoBg = 'rgba(239, 68, 68, 0.2)';
                                $estadoBorder = '#b91c1c';
                                $estadoText = '#7f1d1d';
                                break;
                            case 7: // Terminada
                                $estadoBg = 'rgba(6, 182, 212, 0.2)';
                                $estadoBorder = '#0e7490';
                                $estadoText = '#164e63';
                                break;
                        }

                        // Misma logica de flujo que en React:
                        $transicionesDisponibles = [];
                        if ($estadoIdActual === 1 || $estadoIdActual === 3) {
                            $transicionesDisponibles = [
                                4 => 'Presentada',
                                2 => 'Descartar',
                            ];
                        } elseif ($estadoIdActual === 4) {
                            $transicionesDisponibles = [
                                5 => 'Adjudicada',
                                6 => 'Marcar como Perdida',
                            ];
                        } elseif ($estadoIdActual === 5) {
                            $transicionesDisponibles = [
                                7 => 'Finalizada',
                            ];
                        }

                        $tipoProcedimiento = trim((string)($licitacion['tipo_procedimiento'] ?? 'ORDINARIO'));
                        if ($tipoProcedimiento === '') {
                            $tipoProcedimiento = 'ORDINARIO';
                        }
                        $tipoProcedimientoUpper = mb_strtoupper($tipoProcedimiento, 'UTF-8');
                        $isContratoDerivado = !empty($licitacion['id_licitacion_padre'])
                            || $tipoProcedimientoUpper === 'CONTRATO_BASADO'
                            || $tipoProcedimientoUpper === 'ESPECIFICO_SDA';
                        $estadoModalLotesItems = $isContratoDerivado
                            ? []
                            : decodeLotesConfig($licitacion['lotes_config'] ?? null);
                        $estadoModalLotesPerdidos = extractLostLotesFromItems($estadoModalLotesItems);
                        $estadoModalTodosLotesPerdidos = $estadoModalLotesItems !== []
                            && count($estadoModalLotesPerdidos) === count($estadoModalLotesItems);
                        $lossModalPostedStateId = isset($_POST['estado']) ? (int)$_POST['estado'] : 0;
                        $lossModalInitialTitle = 'Completar perdida';
                        $lossModalInitialIntro = 'Indica ganador e importe por cada lote afectado. El motivo es opcional.';
                        /** @var array<int, string> $lossModalInitialLotesList */
                        $lossModalInitialLotesList = [];
                        if ($lossModalPostedStateId === 6) {
                            $lossModalInitialTitle = 'Marcar licitacion como perdida';
                            $lossModalInitialIntro = 'Se marcaran todos los lotes como perdidos. Indica ganador e importe por lote. El motivo es opcional.';
                            if ($estadoModalLotesItems !== []) {
                                $lossModalInitialLotesList = array_values(array_map(
                                    static fn (array $item): string => (string)$item['nombre'],
                                    $estadoModalLotesItems
                                ));
                            }
                            if ($lossModalInitialLotesList === []) {
                                $lossModalInitialLotesList = ['General'];
                            }
                        } elseif ($lossModalPostedStateId === 5 && $estadoModalLotesPerdidos !== []) {
                            $lossModalInitialTitle = 'Completar adjudicacion parcial';
                            $lossModalInitialIntro = 'Hay lotes marcados como perdidos. Indica ganador e importe por lote. El motivo es opcional.';
                            $lossModalInitialLotesList = array_values($estadoModalLotesPerdidos);
                        }
                        /** @var array<string, array{ganador:string, importe:string}> $lossModalInitialValuesMap */
                        $lossModalInitialValuesMap = [];
                        foreach ($lossModalInitialLotesList as $lossModalLoteName) {
                            $keyLossModalLote = mb_strtolower($lossModalLoteName, 'UTF-8');
                            $postedLossLot = $postedLossByLoteMap[$keyLossModalLote] ?? ['ganador' => '', 'importe_raw' => ''];
                            $lossModalInitialValuesMap[$keyLossModalLote] = [
                                'ganador' => (string)($postedLossLot['ganador'] ?? ''),
                                'importe' => (string)($postedLossLot['importe_raw'] ?? ''),
                            ];
                        }

                        $idTipoLicitacion = isset($licitacion['id_tipolicitacion'])
                            ? (int)$licitacion['id_tipolicitacion']
                            : 0;
                        $tipoLicitacionFallbackById = [
                            1 => 'Unidades y Precio Maximo',
                            2 => 'Precio Unitario Max. (sin unidades, descuentos)',
                            3 => 'Unidades (sin precio unitario)',
                            4 => 'Precio Unitario Max. (sin unidades)',
                            5 => 'Unidades y Precio Maximo (Descuentos)',
                        ];
                        $tipoLicitacionNombre = '';
                        foreach ($tiposLicitacion as $tipoItem) {
                            if (!is_array($tipoItem)) {
                                continue;
                            }
                            $idTipoItem = isset($tipoItem['id_tipolicitacion'])
                                ? (int)$tipoItem['id_tipolicitacion']
                                : 0;
                            if ($idTipoItem === $idTipoLicitacion) {
                                $tipoLicitacionNombre = trim((string)($tipoItem['tipo'] ?? ''));
                                break;
                            }
                        }
                        $nombrePareceMojibake = preg_match('/[\x{00C2}\x{00C3}]/u', $tipoLicitacionNombre) === 1;
                        if ($idTipoLicitacion > 0 && ($tipoLicitacionNombre === '' || $nombrePareceMojibake) && isset($tipoLicitacionFallbackById[$idTipoLicitacion])) {
                            $tipoLicitacionNombre = $tipoLicitacionFallbackById[$idTipoLicitacion];
                        }
                        if ($tipoLicitacionNombre === '') {
                            if ($idTipoLicitacion > 0) {
                                $tipoLicitacionNombre = 'Tipo #' . $idTipoLicitacion;
                            } else {
                                $tipoLicitacionNombre = '-';
                            }
                        }
                        ?>
                        <div class="detail-head">
                            <h2 class="detail-title"><?php echo htmlspecialchars((string)($licitacion['nombre'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h2>
                            <div class="detail-head-right">
                                <div class="detail-status-row">
                                    <span class="detail-status-label">Estado:</span>
                                    <span
                                        style="display:inline-block;padding:3px 11px;border-radius:9999px;background-color:<?php echo $estadoBg; ?>;color:<?php echo $estadoText; ?>;font-size:0.9rem;font-weight:700;letter-spacing:0.01em;border:1px solid <?php echo $estadoBorder; ?>;"
                                    >
                                        <?php echo htmlspecialchars($estadoActualLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                    </span>
                                    <?php if ($transicionesDisponibles !== []): ?>
                                        <button
                                            type="button"
                                            id="btn-cambiar-estado"
                                            class="detail-change-btn"
                                        >
                                            Cambiar estado
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="detail-type-cards">
                                    <div class="detail-type-card">
                                        <span class="detail-type-label">Tipo licitacion</span>
                                        <span class="detail-type-value">
                                            <?php echo htmlspecialchars($tipoLicitacionNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                    <div class="detail-type-card">
                                        <span class="detail-type-label">Tipo procedimiento</span>
                                        <span class="detail-type-value">
                                            <?php echo htmlspecialchars($tipoProcedimiento, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($transicionesDisponibles !== []): ?>
                            <div
                                id="modal-cambiar-estado"
                                style="position:fixed;inset:0;background:rgba(15,23,42,0.72);display:none;align-items:center;justify-content:center;z-index:50;"
                            >
                                <div style="background:#020617;border-radius:12px;border:1px solid #1f2937;box-shadow:0 18px 35px rgba(15,23,42,0.9);max-width:420px;width:100%;padding:16px 18px;">
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
                                        <h3 style="margin:0;font-size:0.9rem;font-weight:600;color:#e5e7eb;">Cambiar estado</h3>
                                        <button
                                            type="button"
                                            id="modal-cambiar-estado-close"
                                            style="border:none;background:transparent;color:#9ca3af;font-size:0.9rem;cursor:pointer;"
                                        >&times;</button>
                                    </div>
                                    <p style="margin:0 0 10px;font-size:0.8rem;color:#9ca3af;">
                                        Selecciona el nuevo estado al que quieres mover la licitacion.
                                    </p>
                                    <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px;">
                                    <?php foreach ($transicionesDisponibles as $nuevoId => $label): ?>
                                            <?php
                                                $nuevoEstadoId = (int)$nuevoId;
                                                $requiresMapBeforeAdjudicar = $nuevoEstadoId === 5 && $partidasSinProductoCatalogo !== [];
                                                $requiresLossModal = false;
                                                $lossModalTitle = '';
                                                $lossModalIntro = '';
                                                /** @var array<int, string> $lossModalLotesList */
                                                $lossModalLotesList = [];
                                                if ($nuevoEstadoId === 6) {
                                                    $requiresLossModal = true;
                                                    $lossModalTitle = 'Marcar licitacion como perdida';
                                                    $lossModalIntro = 'Se marcaran todos los lotes como perdidos. Indica ganador e importe por lote. El motivo es opcional.';
                                                    if ($estadoModalLotesItems !== []) {
                                                        $lossModalLotesList = array_values(array_map(
                                                            static fn (array $item): string => (string)$item['nombre'],
                                                            $estadoModalLotesItems
                                                        ));
                                                    }
                                                    if ($lossModalLotesList === []) {
                                                        $lossModalLotesList = ['General'];
                                                    }
                                                } elseif ($nuevoEstadoId === 5 && $estadoModalLotesPerdidos !== []) {
                                                    $requiresLossModal = true;
                                                    $lossModalTitle = 'Completar adjudicacion parcial';
                                                    $lossModalIntro = 'Hay lotes marcados como perdidos. Indica ganador e importe por lote. El motivo es opcional.';
                                                    $lossModalLotesList = array_values($estadoModalLotesPerdidos);
                                                }
                                            ?>
                                            <?php if ($nuevoEstadoId === 5 && $estadoModalTodosLotesPerdidos): ?>
                                                <button
                                                    type="button"
                                                    disabled
                                                    style="width:100%;text-align:left;border:1px solid #d8d2c4;border-radius:8px;background:#f8f6ef;color:#9ca3af;font-size:0.8rem;font-weight:600;padding:6px 10px;cursor:not-allowed;opacity:0.8;"
                                                >
                                                    <?php echo htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> (marca algun lote como ganado)
                                                </button>
                                            <?php elseif ($requiresMapBeforeAdjudicar): ?>
                                                <button
                                                    type="button"
                                                    class="js-open-map-products-from-status"
                                                    style="width:100%;text-align:left;border:1px solid #7a2722;border-radius:8px;background:#fff6f5;color:#7a2722;font-size:0.8rem;font-weight:600;padding:6px 10px;cursor:pointer;"
                                                >
                                                    <?php echo htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> (vincular productos antes)
                                                </button>
                                            <?php elseif ($requiresLossModal): ?>
                                                <?php
                                                $lossTriggerStyle = $nuevoEstadoId === 6
                                                    ? 'width:100%;text-align:left;border:1px solid #b91c1c;border-radius:8px;background:#fef2f2;color:#7f1d1d;font-size:0.8rem;font-weight:600;padding:6px 10px;cursor:pointer;'
                                                    : 'width:100%;text-align:left;border:1px solid #1f2937;border-radius:8px;background:#020617;color:#e5e7eb;font-size:0.8rem;font-weight:500;padding:6px 10px;cursor:pointer;';
                                                ?>
                                                <button
                                                    type="button"
                                                    class="js-open-loss-status-modal"
                                                    data-estado="<?php echo $nuevoEstadoId; ?>"
                                                    data-title="<?php echo htmlspecialchars($lossModalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    data-intro="<?php echo htmlspecialchars($lossModalIntro, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    data-lotes-json="<?php echo htmlspecialchars(json_encode($lossModalLotesList, JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    style="<?php echo htmlspecialchars($lossTriggerStyle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                >
                                                    <?php echo htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                </button>
                                            <?php else: ?>
                                                <form
                                                    action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    method="POST"
                                                    style="margin:0;"
                                                >
                                                    <input type="hidden" name="estado" value="<?php echo $nuevoEstadoId; ?>">
                                                    <button
                                                        type="submit"
                                                        style="width:100%;text-align:left;border:1px solid #1f2937;border-radius:8px;background:#020617;color:#e5e7eb;font-size:0.8rem;font-weight:500;padding:6px 10px;cursor:pointer;"
                                                    >
                                                        <?php echo htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <button
                                        type="button"
                                        id="modal-cambiar-estado-cancel"
                                        style="border:1px solid #374151;border-radius:8px;background:#020617;color:#9ca3af;font-size:0.75rem;padding:4px 10px;cursor:pointer;"
                                    >
                                        Cancelar
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($transicionesDisponibles !== []): ?>
                            <div
                                id="modal-detalle-perdida-estado"
                                data-open="<?php echo $openLossStateModal ? '1' : '0'; ?>"
                                data-initial-state="<?php echo $lossModalPostedStateId; ?>"
                                data-initial-title="<?php echo htmlspecialchars($lossModalInitialTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                data-initial-intro="<?php echo htmlspecialchars($lossModalInitialIntro, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                data-initial-lotes-json="<?php echo htmlspecialchars(json_encode($lossModalInitialLotesList, JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                data-initial-values-json="<?php echo htmlspecialchars(json_encode($lossModalInitialValuesMap, JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                class="status-loss-modal"
                                style="display:none;"
                            >
                                <div class="status-loss-dialog">
                                    <form
                                        method="post"
                                        action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                        class="status-loss-form"
                                    >
                                        <input type="hidden" name="estado" id="status-loss-estado" value="<?php echo $lossModalPostedStateId > 0 ? $lossModalPostedStateId : ''; ?>">
                                        <div class="status-loss-head">
                                            <h3 id="status-loss-title"><?php echo htmlspecialchars($lossModalInitialTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h3>
                                            <button type="button" id="modal-detalle-perdida-close" class="status-loss-close">&times;</button>
                                        </div>
                                        <p id="status-loss-intro" class="status-loss-intro">
                                            <?php echo htmlspecialchars($lossModalInitialIntro, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        </p>
                                        <?php $hasInitialLossLotes = $lossModalInitialLotesList !== []; ?>
                                        <div id="status-loss-lotes-wrap" class="status-loss-lotes <?php echo $hasInitialLossLotes ? '' : 'is-hidden'; ?>">
                                            <strong>Lotes afectados:</strong>
                                            <span id="status-loss-lotes-text"><?php echo htmlspecialchars(implode(', ', $lossModalInitialLotesList), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                        </div>
                                        <div id="status-loss-lotes-rows" class="status-loss-lotes-rows"></div>
                                        <label class="status-loss-field">
                                            <span>Motivo de la perdida (opcional)</span>
                                            <textarea name="motivo_perdida" rows="3"><?php echo htmlspecialchars($postedMotivoPerdida, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                                        </label>
                                        <div class="status-loss-actions">
                                            <button type="button" id="modal-detalle-perdida-cancel" class="status-loss-cancel">Cancelar</button>
                                            <button type="submit" class="status-loss-submit">Confirmar cambio</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($pendingPartidasSinProducto !== []): ?>
                            <div
                                id="modal-vincular-productos"
                                data-open="<?php echo $openMapProductsModal ? '1' : '0'; ?>"
                                class="map-products-modal"
                                style="display:none;"
                            >
                                <div class="map-products-dialog">
                                    <div class="map-products-header">
                                        <h3>Vincular productos ERP</h3>
                                        <button type="button" id="modal-vincular-productos-close" class="map-products-close">&times;</button>
                                    </div>
                                    <p class="map-products-note">
                                        Para adjudicar, cada partida activa debe vincularse con un producto existente del catalogo.
                                    </p>
                                    <form method="post" action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                        <input type="hidden" name="form_tipo" value="vincular_productos">
                                        <div class="map-products-list">
                                            <?php foreach ($pendingPartidasSinProducto as $pp): ?>
                                                <?php
                                                $detalleIdMap = (int)($pp['id_detalle'] ?? 0);
                                                if ($detalleIdMap <= 0) {
                                                    continue;
                                                }
                                                $loteMap = trim((string)($pp['lote'] ?? ''));
                                                if ($loteMap === '') {
                                                    $loteMap = 'General';
                                                }
                                                $nombreLibreMap = trim((string)($pp['nombre_producto_libre'] ?? ''));
                                                $prefillMap = $nombreLibreMap !== '' ? $nombreLibreMap : '';
                                                ?>
                                                <div class="map-products-row">
                                                    <input type="hidden" name="detalle_ids[]" value="<?php echo $detalleIdMap; ?>">
                                                    <input type="hidden" name="id_producto_map[<?php echo $detalleIdMap; ?>]" id="map-id-<?php echo $detalleIdMap; ?>" value="">
                                                    <div class="map-products-row-top">
                                                        <span>Lote: <?php echo htmlspecialchars($loteMap, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                                        <span>Partida #<?php echo $detalleIdMap; ?></span>
                                                    </div>
                                                    <div class="map-products-row-text">
                                                        Texto libre: <?php echo htmlspecialchars($nombreLibreMap !== '' ? $nombreLibreMap : '(sin texto)', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                    </div>
                                                    <div class="map-products-input-wrap">
                                                        <input
                                                            type="text"
                                                            class="map-product-input"
                                                            data-target-id="map-id-<?php echo $detalleIdMap; ?>"
                                                            data-detail-id="<?php echo $detalleIdMap; ?>"
                                                            value="<?php echo htmlspecialchars($prefillMap, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                            placeholder="Buscar producto en ERP..."
                                                            autocomplete="off"
                                                        />
                                                        <div id="map-suggest-<?php echo $detalleIdMap; ?>" class="map-product-suggestions"></div>
                                                        <div id="map-status-<?php echo $detalleIdMap; ?>" class="ac-status map-ac-status"></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="map-products-actions">
                                            <button type="button" id="modal-vincular-productos-cancel" class="map-products-cancel">Cancelar</button>
                                            <button type="submit" class="map-products-save">Guardar vinculos</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="meta-grid">
                            <div>
                                <span class="meta-label">Nro expediente</span>
                                <span class="meta-value">
                                    <?php echo htmlspecialchars((string)($licitacion['numero_expediente'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                </span>
                            </div>
                            <div>
                                <span class="meta-label">Presupuesto maximo</span>
                                <span class="meta-value">
                                    <?php echo number_format((float)($licitacion['pres_maximo'] ?? 0), 0, ',', '.'); ?> EUR
                                </span>
                            </div>
                            <div>
                                <span class="meta-label">Fecha presentacion</span>
                                <span class="meta-value">
                                    <?php
                                    $fp = (string)($licitacion['fecha_presentacion'] ?? '');
                                    if ($fp !== '' && str_contains($fp, ' ')) {
                                        $fp = explode(' ', $fp)[0];
                                    }
                                    if ($fp !== '' && str_contains($fp, '-')) {
                                        $parts = explode('-', $fp);
                                        if (count($parts) === 3) {
                                            echo htmlspecialchars($parts[2] . '/' . $parts[1] . '/' . $parts[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                        } else {
                                            echo htmlspecialchars($fp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div>
                                <span class="meta-label">Pais</span>
                                <span class="meta-value">
                                    <?php
                                    $paisDisplay = normalizeCountryDisplay((string)($licitacion['pais'] ?? ''));
                                    echo htmlspecialchars($paisDisplay !== '' ? $paisDisplay : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                    ?>
                                </span>
                            </div>
                        </div>
                        <?php
                        $licitacionPadreVista = is_array($licitacion['licitacion_padre'] ?? null)
                            ? $licitacion['licitacion_padre']
                            : null;
                        ?>
                        <?php if ($isContratoDerivado && $licitacionPadreVista !== null): ?>
                            <div class="derived-parent-hint">
                                Contrato derivado de:
                                <a href="<?php echo htmlspecialchars('licitacion-detalle.php?id=' . (int)($licitacionPadreVista['id_licitacion'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                    <?php
                                    $padreExp = trim((string)($licitacionPadreVista['numero_expediente'] ?? ''));
                                    $padreNombre = trim((string)($licitacionPadreVista['nombre'] ?? ''));
                                    echo htmlspecialchars(
                                        ($padreExp !== '' ? $padreExp . ' - ' : '')
                                        . ($padreNombre !== '' ? $padreNombre : ('#' . (int)($licitacionPadreVista['id_licitacion'] ?? 0))),
                                        ENT_QUOTES | ENT_SUBSTITUTE,
                                        'UTF-8'
                                    );
                                    ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php
                        /** @var array<int, array<string,mixed>> $partidas */
                        $partidas = is_array($licitacion['partidas'] ?? null) ? $licitacion['partidas'] : [];
                        /** @var array<int, array<string,mixed>> $contratosDerivados */
                        $contratosDerivados = is_array($licitacion['contratos_derivados'] ?? null)
                            ? $licitacion['contratos_derivados']
                            : [];
                        $isAmSda = $tipoProcedimientoUpper === 'ACUERDO_MARCO' || $tipoProcedimientoUpper === 'SDA';
                        $idEstado = (int)($licitacion['id_estado'] ?? 0);
                        $idTipoLicitacionVista = isset($licitacion['id_tipolicitacion'])
                            ? (int)$licitacion['id_tipolicitacion']
                            : 0;
                        $showPmaxuPresupuesto = in_array($idTipoLicitacionVista, [1, 2, 4, 5], true);
                        $showUnidadesPresupuesto = !in_array($idTipoLicitacionVista, [2, 4], true);
                        $isTipoDescuentoPresupuesto = in_array($idTipoLicitacionVista, [2, 5], true);
                        $presupuestoBloqueado = $idEstado >= $estadoBloqueoPresupuestoDesde;
                        $descuentoGlobalPresupuesto = isset($licitacion['descuento_global'])
                            ? (float)$licitacion['descuento_global']
                            : 0.0;
                        if ($isTipoDescuentoPresupuesto && $descuentoGlobalPresupuesto <= 0.0) {
                            $sumPmaxu = 0.0;
                            $sumPvu = 0.0;
                            foreach ($partidas as $pDesc) {
                                if (!is_array($pDesc)) {
                                    continue;
                                }
                                $activaDesc = array_key_exists('activo', $pDesc) ? (bool)$pDesc['activo'] : true;
                                if (!$activaDesc) {
                                    continue;
                                }
                                $pmaxuDesc = (float)($pDesc['pmaxu'] ?? 0.0);
                                $pvuDesc = (float)($pDesc['pvu'] ?? 0.0);
                                if ($pmaxuDesc <= 0.0 || $pvuDesc <= 0.0) {
                                    continue;
                                }
                                $sumPmaxu += $pmaxuDesc;
                                $sumPvu += $pvuDesc;
                            }
                            if ($sumPmaxu > 0.0 && $sumPvu > 0.0) {
                                $factorDesc = max(0.0, min(1.0, $sumPvu / $sumPmaxu));
                                $descuentoGlobalPresupuesto = round((1.0 - $factorDesc) * 100.0, 2);
                            }
                        }
                        $lotesConfigItems = decodeLotesConfig($licitacion['lotes_config'] ?? null);
                        $lotesConfigurados = extractConfiguredLotes($licitacion['lotes_config'] ?? null);
                        if ($isContratoDerivado) {
                            $lotesConfigItems = [];
                            $lotesConfigurados = [];
                        }
                        $usaLotesPresupuesto = count($lotesConfigurados) > 0;
                        $puedeMarcarLotesGanados = $idEstado === 4;
                        /** @var array<string, bool> $lotesGanadosSet */
                        $lotesGanadosSet = [];
                        foreach ($lotesConfigItems as $loteCfgItem) {
                            if (empty($loteCfgItem['ganado'])) {
                                continue;
                            }
                            $lotesGanadosSet[mb_strtolower((string)$loteCfgItem['nombre'], 'UTF-8')] = true;
                        }
                        /** @var array<string, array{nombre:string, ganado:bool, form_id:string}> $lotesConfigUiMap */
                        $lotesConfigUiMap = [];
                        $loteToggleIndex = 0;
                        foreach ($lotesConfigItems as $loteCfgItem) {
                            $nombreLoteCfgItem = trim((string)($loteCfgItem['nombre'] ?? ''));
                            if ($nombreLoteCfgItem === '') {
                                continue;
                            }
                            $lotesConfigUiMap[mb_strtolower($nombreLoteCfgItem, 'UTF-8')] = [
                                'nombre' => $nombreLoteCfgItem,
                                'ganado' => !empty($loteCfgItem['ganado']),
                                'form_id' => 'toggle-lote-ganado-' . $loteToggleIndex,
                            ];
                            $loteToggleIndex++;
                        }
                        if (!$usaLotesPresupuesto) {
                            $lotesDetectados = [];
                            foreach ($partidas as $pLote) {
                                if (!is_array($pLote)) {
                                    continue;
                                }
                                $activoLote = array_key_exists('activo', $pLote) ? (bool)$pLote['activo'] : true;
                                if (!$activoLote) {
                                    continue;
                                }
                                $nombreLote = trim((string)($pLote['lote'] ?? ''));
                                if ($nombreLote === '') {
                                    $nombreLote = 'General';
                                }
                                $lotesDetectados[mb_strtolower($nombreLote)] = true;
                            }
                            $usaLotesPresupuesto = count($lotesDetectados) > 1;
                        }
                        $filtrarPorLotesGanados = $lotesConfigItems !== [] && $idEstado >= 5;
                        // A partir de ADJUDICADA (5) mostramos pestanas de ejecucion/remaining como en el frontend antiguo.
                        $showEjecucionRemaining = $idEstado >= 5;
                        $derivedCreated = isset($_GET['derived_created']) && (string)$_GET['derived_created'] === '1';

                        $spainLabelCreate = spainLabelForCreate();
                        $nuevoDerivadoPaisDefault = canonicalCountryLabelForCreate((string)($licitacion['pais'] ?? $spainLabelCreate));
                        if ($nuevoDerivadoPaisDefault !== $spainLabelCreate && $nuevoDerivadoPaisDefault !== 'Portugal') {
                            $nuevoDerivadoPaisDefault = $spainLabelCreate;
                        }
                        $nuevoDerivadoFormValues = [
                            'nombre' => $isCreateDerivedSubmit ? trim((string)($_POST['nuevo_nombre'] ?? '')) : '',
                            'pais' => $isCreateDerivedSubmit
                                ? canonicalCountryLabelForCreate((string)($_POST['nuevo_pais'] ?? ''))
                                : $nuevoDerivadoPaisDefault,
                            'numero_expediente' => $isCreateDerivedSubmit ? trim((string)($_POST['nuevo_numero_expediente'] ?? '')) : '',
                            'pres_maximo' => $isCreateDerivedSubmit ? trim((string)($_POST['nuevo_pres_maximo'] ?? '')) : '',
                            'fecha_presentacion' => $isCreateDerivedSubmit ? trim((string)($_POST['nuevo_fecha_presentacion'] ?? '')) : '',
                            'fecha_adjudicacion' => $isCreateDerivedSubmit ? trim((string)($_POST['nuevo_fecha_adjudicacion'] ?? '')) : '',
                            'fecha_finalizacion' => $isCreateDerivedSubmit ? trim((string)($_POST['nuevo_fecha_finalizacion'] ?? '')) : '',
                            'id_tipolicitacion' => $isCreateDerivedSubmit ? trim((string)($_POST['nuevo_id_tipolicitacion'] ?? '')) : '',
                            'enlace_gober' => $isCreateDerivedSubmit ? trim((string)($_POST['nuevo_enlace_gober'] ?? '')) : '',
                            'enlace_sharepoint' => $isCreateDerivedSubmit ? trim((string)($_POST['nuevo_enlace_sharepoint'] ?? '')) : '',
                            'descripcion' => $isCreateDerivedSubmit ? trim((string)($_POST['nuevo_descripcion'] ?? '')) : '',
                        ];
                        if ($nuevoDerivadoFormValues['pais'] !== $spainLabelCreate && $nuevoDerivadoFormValues['pais'] !== 'Portugal') {
                            $nuevoDerivadoFormValues['pais'] = $nuevoDerivadoPaisDefault;
                        }

                        // -------------------------
                        // Indicadores (replica del frontend antiguo)
                        // -------------------------
                        $formatEuroKpi = static function (float $value): string {
                            return number_format($value, 0, ',', '.') . ' EUR';
                        };
                        $formatPctKpi = static function (float $value): string {
                            return number_format($value, 1, ',', '.') . '%';
                        };

                        $presupuestoBaseKpi = (float)($licitacion['pres_maximo'] ?? 0.0);
                        $isTipo2Kpi = $idTipoLicitacionVista === 2;

                        /** @var array<int, array<string,mixed>> $partidasActivasKpi */
                        $partidasActivasKpi = [];
                        foreach ($partidas as $pKpi) {
                            if (!is_array($pKpi)) {
                                continue;
                            }
                            $activaKpi = array_key_exists('activo', $pKpi) ? (bool)$pKpi['activo'] : true;
                            if (!$activaKpi) {
                                continue;
                            }
                            $loteKpi = trim((string)($pKpi['lote'] ?? ''));
                            if ($loteKpi === '') {
                                $loteKpi = 'General';
                            }
                            if ($filtrarPorLotesGanados) {
                                $loteKeyKpi = mb_strtolower($loteKpi, 'UTF-8');
                                if (!isset($lotesGanadosSet[$loteKeyKpi])) {
                                    continue;
                                }
                            }
                            $partidasActivasKpi[] = $pKpi;
                        }

                        $ofertadoKpi = 0.0;
                        $costePrevistoKpi = 0.0;
                        if ($isTipo2Kpi) {
                            $totalPmaxuKpi = 0.0;
                            $totalPvuKpi = 0.0;
                            $totalPcuKpi = 0.0;
                            $numPartidasTipo2Kpi = count($partidasActivasKpi);
                            foreach ($partidasActivasKpi as $pTipo2Kpi) {
                                $totalPmaxuKpi += (float)($pTipo2Kpi['pmaxu'] ?? 0.0);
                                $totalPvuKpi += (float)($pTipo2Kpi['pvu'] ?? 0.0);
                                $totalPcuKpi += (float)($pTipo2Kpi['pcu'] ?? 0.0);
                            }
                            if ($presupuestoBaseKpi > 0.0) {
                                $factorKpi = $totalPmaxuKpi > 0.0
                                    ? max(0.0, min(1.0, $totalPvuKpi / $totalPmaxuKpi))
                                    : 1.0;
                                $ofertadoKpi = $presupuestoBaseKpi * $factorKpi;
                            } else {
                                $ofertadoKpi = $totalPvuKpi;
                            }
                            if ($numPartidasTipo2Kpi > 0) {
                                $mediaPvuKpi = $totalPvuKpi / $numPartidasTipo2Kpi;
                                $mediaCosteKpi = $totalPcuKpi / $numPartidasTipo2Kpi;
                                $udsTeoricasKpi = $mediaPvuKpi > 0.0 ? $ofertadoKpi / $mediaPvuKpi : 0.0;
                                $costePrevistoKpi = $udsTeoricasKpi * $mediaCosteKpi;
                            }
                        } else {
                            foreach ($partidasActivasKpi as $pKpi) {
                                $udsKpi = (float)($pKpi['unidades'] ?? 0.0);
                                $pvuKpi = (float)($pKpi['pvu'] ?? 0.0);
                                $pcuKpi = (float)($pKpi['pcu'] ?? 0.0);
                                $ofertadoKpi += $udsKpi * $pvuKpi;
                                $costePrevistoKpi += $udsKpi * $pcuKpi;
                            }
                        }
                        $beneficioPrevistoKpi = $ofertadoKpi - $costePrevistoKpi;

                        $costeRealEntregadoKpi = 0.0;
                        $gastosExtraEntregasKpi = 0.0;
                        $totalCobradoKpi = 0.0;
                        $totalPendienteKpi = 0.0;
                        foreach ($entregas as $entKpi) {
                            $lineasKpi = isset($entKpi['lineas']) && is_array($entKpi['lineas']) ? $entKpi['lineas'] : [];
                            foreach ($lineasKpi as $linKpi) {
                                $idDetKpi = $linKpi['id_detalle'] ?? null;
                                $idTipoGastoKpi = $linKpi['id_tipo_gasto'] ?? null;
                                $cantidadKpi = (float)($linKpi['cantidad'] ?? 0.0);
                                $pcuKpi = (float)($linKpi['pcu'] ?? 0.0);
                                $esGastoExtraKpi = $idDetKpi === null && $idTipoGastoKpi !== null;

                                if ($esGastoExtraKpi) {
                                    $gastosExtraEntregasKpi += $pcuKpi;
                                    continue;
                                }

                                $costeRealEntregadoKpi += $cantidadKpi * $pcuKpi;
                                $importeKpi = $cantidadKpi * $pcuKpi;
                                if ($importeKpi <= 0.0) {
                                    continue;
                                }
                                $cobradoRawKpi = $linKpi['cobrado'] ?? false;
                                $isCobradoKpi = $cobradoRawKpi === true
                                    || $cobradoRawKpi === 1
                                    || $cobradoRawKpi === '1';
                                if ($isCobradoKpi) {
                                    $totalCobradoKpi += $importeKpi;
                                } else {
                                    $totalPendienteKpi += $importeKpi;
                                }
                            }
                        }
                        $beneficioRealKpi = $ofertadoKpi - ($costeRealEntregadoKpi + $gastosExtraEntregasKpi);
                        $totalEntregadoImporteKpi = $totalCobradoKpi + $totalPendienteKpi;
                        $pctCobradoKpi = $totalEntregadoImporteKpi > 0.0
                            ? (int)round(($totalCobradoKpi / $totalEntregadoImporteKpi) * 100.0)
                            : 0;
                        $showExecutionKpis = $idEstado >= 5;

                        $costePresupuestadoRawKpi = $licitacion['coste_presupuestado'] ?? null;
                        $costeRealRawKpi = $licitacion['coste_real'] ?? null;
                        $costePresupuestadoKpi = parseNullableAmount($costePresupuestadoRawKpi);
                        $costeRealHistoricoKpi = parseNullableAmount($costeRealRawKpi);
                        $gastosExtraHistoricoKpi = parseNullableAmount($licitacion['gastos_extraordinarios'] ?? null);
                        $costeRealTotalHistoricoKpi = ($costeRealHistoricoKpi ?? 0.0) + ($gastosExtraHistoricoKpi ?? 0.0);
                        $hasPresuHistoricoKpi = $costePresupuestadoKpi !== null && $costePresupuestadoKpi > 0.0;
                        $hasRealHistoricoKpi = $costeRealTotalHistoricoKpi > 0.0;
                        $showCostDeviationKpi = ($costePresupuestadoRawKpi !== null || $costeRealRawKpi !== null)
                            && ($hasPresuHistoricoKpi || $hasRealHistoricoKpi);
                        $deviationPctKpi = null;
                        if ($hasPresuHistoricoKpi && $costeRealTotalHistoricoKpi > 0.0) {
                            $deviationPctKpi = (($costeRealTotalHistoricoKpi - (float)$costePresupuestadoKpi) / (float)$costePresupuestadoKpi) * 100.0;
                        }

                        // -------------------------
                        // Calculos para Remaining
                        // -------------------------
                        // Mapa id_detalle => partida
                        $idToPartida = [];
                        foreach ($partidas as $p) {
                            if (isset($p['id_detalle'])) {
                                $idToPartida[(int)$p['id_detalle']] = $p;
                            }
                        }

                        // itemsPresupuestoAgregado: por lote+descripcion
                        $itemsPresupuestoAgregado = [];
                        foreach ($partidas as $p) {
                            $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
                            if (!$activo) {
                                continue;
                            }
                            $lote = trim((string)($p['lote'] ?? ''));
                            if ($lote === '') {
                                $lote = 'General';
                            }
                            if ($filtrarPorLotesGanados) {
                                $loteKey = mb_strtolower($lote, 'UTF-8');
                                if (!isset($lotesGanadosSet[$loteKey])) {
                                    continue;
                                }
                            }
                            $descripcion = (string)($p['nombre_producto_libre'] ?? ($p['product_nombre'] ?? ''));
                            $unidades = (float)($p['unidades'] ?? 0);
                            $key = $lote . '|' . $descripcion;
                            if (!isset($itemsPresupuestoAgregado[$key])) {
                                $itemsPresupuestoAgregado[$key] = [
                                    'lote' => $lote,
                                    'descripcion' => $descripcion,
                                    'unidades' => 0.0,
                                ];
                            }
                            $itemsPresupuestoAgregado[$key]['unidades'] += $unidades;
                        }

                        // ejecutadoPorPartida: unidades reales entregadas por lote+descripcion
                        // ejecutadoPorDetalle: unidades reales entregadas por id_detalle
                        $ejecutadoPorPartida = [];
                        $ejecutadoPorDetalle = [];
                        foreach ($entregas as $ent) {
                            $lineas = isset($ent['lineas']) && is_array($ent['lineas']) ? $ent['lineas'] : [];
                            foreach ($lineas as $lin) {
                                $idDet = $lin['id_detalle'] ?? null;
                                $idTipoGasto = $lin['id_tipo_gasto'] ?? null;
                                if ($idDet === null || $idTipoGasto !== null) {
                                    // Solo lineas presupuestadas (no gastos extra)
                                    continue;
                                }
                                $idDet = (int)$idDet;
                                $cant = (float)($lin['cantidad'] ?? 0);

                                if (!isset($ejecutadoPorDetalle[$idDet])) {
                                    $ejecutadoPorDetalle[$idDet] = 0.0;
                                }
                                $ejecutadoPorDetalle[$idDet] += $cant;

                                if (!isset($idToPartida[$idDet])) {
                                    continue;
                                }
                                $partida = $idToPartida[$idDet];
                                $lote = trim((string)($partida['lote'] ?? ''));
                                if ($lote === '') {
                                    $lote = 'General';
                                }
                                if ($filtrarPorLotesGanados) {
                                    $loteKey = mb_strtolower($lote, 'UTF-8');
                                    if (!isset($lotesGanadosSet[$loteKey])) {
                                        continue;
                                    }
                                }
                                $descripcion = (string)($partida['nombre_producto_libre'] ?? ($partida['product_nombre'] ?? ''));
                                $key = $lote . '|' . $descripcion;
                                if (!isset($ejecutadoPorPartida[$key])) {
                                    $ejecutadoPorPartida[$key] = 0.0;
                                }
                                $ejecutadoPorPartida[$key] += $cant;
                            }
                        }
                        ?>

                        <section class="kpi-grid kpi-grid-main">
                            <article class="kpi-card">
                                <span class="kpi-label">Presupuesto Base</span>
                                <p class="kpi-value"><?php echo $formatEuroKpi($presupuestoBaseKpi); ?></p>
                            </article>
                            <article class="kpi-card">
                                <span class="kpi-label">Ofertado (Partidas activas)</span>
                                <p class="kpi-value is-positive"><?php echo $formatEuroKpi($ofertadoKpi); ?></p>
                            </article>
                            <article class="kpi-card">
                                <span class="kpi-label">Coste Estimado</span>
                                <p class="kpi-value is-warning"><?php echo $formatEuroKpi($costePrevistoKpi); ?></p>
                            </article>
                            <article class="kpi-card">
                                <span class="kpi-label">Beneficio Previsto</span>
                                <p class="kpi-value <?php echo $beneficioPrevistoKpi >= 0.0 ? 'is-positive' : 'is-negative'; ?>">
                                    <?php echo $formatEuroKpi($beneficioPrevistoKpi); ?>
                                </p>
                            </article>
                        </section>

                        <?php if ($showExecutionKpis): ?>
                            <section class="kpi-grid kpi-grid-secondary">
                                <article class="kpi-card">
                                    <span class="kpi-label">Coste real (entregado)</span>
                                    <p class="kpi-value is-warning"><?php echo $formatEuroKpi($costeRealEntregadoKpi); ?></p>
                                </article>
                                <article class="kpi-card">
                                    <span class="kpi-label">Gastos extraordinarios</span>
                                    <p class="kpi-value is-warning"><?php echo $formatEuroKpi($gastosExtraEntregasKpi); ?></p>
                                </article>
                                <article class="kpi-card">
                                    <span class="kpi-label">Beneficio real</span>
                                    <p class="kpi-value <?php echo $beneficioRealKpi >= 0.0 ? 'is-positive' : 'is-negative'; ?>">
                                        <?php echo $formatEuroKpi($beneficioRealKpi); ?>
                                    </p>
                                </article>
                            </section>

                            <section class="kpi-grid kpi-grid-secondary">
                                <article class="kpi-card">
                                    <span class="kpi-label">Importe cobrado</span>
                                    <p class="kpi-value is-positive"><?php echo $formatEuroKpi($totalCobradoKpi); ?></p>
                                </article>
                                <article class="kpi-card">
                                    <span class="kpi-label">Importe pendiente de cobro</span>
                                    <p class="kpi-value is-warning"><?php echo $formatEuroKpi($totalPendienteKpi); ?></p>
                                </article>
                                <article class="kpi-card">
                                    <span class="kpi-label">% cobrado sobre entregado</span>
                                    <p class="kpi-value"><?php echo $pctCobradoKpi; ?>%</p>
                                </article>
                            </section>
                        <?php endif; ?>

                        <?php if ($isAmSda): ?>
                            <div class="kpi-note">
                                Este es un Acuerdo Marco / SDA. La gestion de presupuesto, entregas y remaining se hace en cada contrato derivado.
                            </div>
                        <?php endif; ?>

                        <?php if ($showCostDeviationKpi): ?>
                            <?php
                            $desviacionClassKpi = 'is-neutral';
                            if ($deviationPctKpi !== null) {
                                if ($deviationPctKpi > 0.0) {
                                    $desviacionClassKpi = 'is-over';
                                } elseif ($deviationPctKpi < 0.0) {
                                    $desviacionClassKpi = 'is-under';
                                }
                            }
                            ?>
                            <section class="kpi-deviation">
                                <p class="kpi-deviation-title">Desviacion de coste</p>
                                <div class="kpi-deviation-content">
                                    <div class="kpi-deviation-item">
                                        <span>Presupuestado</span>
                                        <strong><?php echo $formatEuroKpi((float)($costePresupuestadoKpi ?? 0.0)); ?></strong>
                                    </div>
                                    <div class="kpi-deviation-item">
                                        <span>Real / historico</span>
                                        <strong><?php echo $formatEuroKpi($costeRealTotalHistoricoKpi); ?></strong>
                                        <?php if (($gastosExtraHistoricoKpi ?? 0.0) > 0.0): ?>
                                            <span>(+ <?php echo $formatEuroKpi((float)$gastosExtraHistoricoKpi); ?> extra)</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($deviationPctKpi !== null): ?>
                                        <div class="kpi-deviation-pill <?php echo $desviacionClassKpi; ?>">
                                            <?php echo ($deviationPctKpi > 0.0 ? '+' : '') . $formatPctKpi($deviationPctKpi); ?> desviacion
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                        <div class="tabs">
                            <div class="tabs-list">
                                <?php if ($isAmSda): ?>
                                    <button type="button" class="tab-trigger active" data-tab="contratos-derivados">
                                        Contratos derivados
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="tab-trigger active" data-tab="presupuesto">
                                        Presupuesto (Oferta)
                                    </button>
                                    <?php if ($showEjecucionRemaining): ?>
                                        <button type="button" class="tab-trigger" data-tab="ejecucion">
                                            Entregas (Real / Albaranes)
                                        </button>
                                        <button type="button" class="tab-trigger" data-tab="remaining">
                                            Remaining
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <?php if ($isAmSda): ?>
                                <div id="tab-contratos-derivados" class="tab-content active">
                                    <?php if ($derivedCreated): ?>
                                        <div class="derived-created-banner">
                                            Contrato derivado creado correctamente.
                                        </div>
                                    <?php endif; ?>
                                    <div class="derived-tab-toolbar">
                                        <p class="derived-tab-intro">
                                            Esta licitacion actua como carpeta. Gestiona aqui sus contratos derivados.
                                        </p>
                                    </div>
                                    <div class="derived-create-panel">
                                        <h3 class="derived-create-title">
                                            Nuevo <?php echo $tipoProcedimientoUpper === 'SDA' ? 'Especifico SDA' : 'Contrato Basado'; ?>
                                        </h3>
                                        <form method="post" action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id . '&tab=contratos-derivados', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                            <input type="hidden" name="form_tipo" value="crear_contrato_derivado">
                                            <div class="derived-form-grid">
                                                <div class="derived-form-field">
                                                    <label for="nuevo-nombre">Nombre del proyecto</label>
                                                    <input
                                                        id="nuevo-nombre"
                                                        name="nuevo_nombre"
                                                        type="text"
                                                        required
                                                        value="<?php echo htmlspecialchars((string)$nuevoDerivadoFormValues['nombre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    />
                                                </div>
                                                <div class="derived-form-field">
                                                    <label for="nuevo-pais">Pais</label>
                                                    <select id="nuevo-pais" name="nuevo_pais" required>
                                                        <option value="<?php echo htmlspecialchars($spainLabelCreate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" <?php echo (string)$nuevoDerivadoFormValues['pais'] === $spainLabelCreate ? 'selected' : ''; ?>><?php echo htmlspecialchars($spainLabelCreate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                                                        <option value="Portugal" <?php echo (string)$nuevoDerivadoFormValues['pais'] === 'Portugal' ? 'selected' : ''; ?>>Portugal</option>
                                                    </select>
                                                </div>
                                                <div class="derived-form-field">
                                                    <label for="nuevo-expediente">Nro expediente</label>
                                                    <input
                                                        id="nuevo-expediente"
                                                        name="nuevo_numero_expediente"
                                                        type="text"
                                                        required
                                                        value="<?php echo htmlspecialchars((string)$nuevoDerivadoFormValues['numero_expediente'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    />
                                                </div>
                                                <div class="derived-form-field">
                                                    <label for="nuevo-presupuesto">Presupuesto max. (EUR)</label>
                                                    <input
                                                        id="nuevo-presupuesto"
                                                        name="nuevo_pres_maximo"
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        required
                                                        value="<?php echo htmlspecialchars((string)$nuevoDerivadoFormValues['pres_maximo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    />
                                                </div>
                                                <div class="derived-form-field">
                                                    <label for="nuevo-fp">F. presentacion</label>
                                                    <input
                                                        id="nuevo-fp"
                                                        name="nuevo_fecha_presentacion"
                                                        type="date"
                                                        required
                                                        value="<?php echo htmlspecialchars((string)$nuevoDerivadoFormValues['fecha_presentacion'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    />
                                                </div>
                                                <div class="derived-form-field">
                                                    <label for="nuevo-fa">F. adjudicacion</label>
                                                    <input
                                                        id="nuevo-fa"
                                                        name="nuevo_fecha_adjudicacion"
                                                        type="date"
                                                        required
                                                        value="<?php echo htmlspecialchars((string)$nuevoDerivadoFormValues['fecha_adjudicacion'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    />
                                                </div>
                                                <div class="derived-form-field">
                                                    <label for="nuevo-ff">F. finalizacion</label>
                                                    <input
                                                        id="nuevo-ff"
                                                        name="nuevo_fecha_finalizacion"
                                                        type="date"
                                                        required
                                                        value="<?php echo htmlspecialchars((string)$nuevoDerivadoFormValues['fecha_finalizacion'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    />
                                                </div>
                                                <div class="derived-form-field">
                                                    <label for="nuevo-tipo">Tipo de licitacion</label>
                                                    <select id="nuevo-tipo" name="nuevo_id_tipolicitacion" required>
                                                        <option value="" <?php echo (string)$nuevoDerivadoFormValues['id_tipolicitacion'] === '' ? 'selected' : ''; ?> disabled>
                                                            <?php echo $tiposLicitacion === [] ? 'No hay tipos disponibles' : 'Selecciona un tipo'; ?>
                                                        </option>
                                                        <?php foreach ($tiposLicitacion as $tipoDerivadoItem): ?>
                                                            <?php
                                                            if (!is_array($tipoDerivadoItem)) {
                                                                continue;
                                                            }
                                                            $idTipoDerivadoItem = (int)($tipoDerivadoItem['id_tipolicitacion'] ?? 0);
                                                            if ($idTipoDerivadoItem <= 0) {
                                                                continue;
                                                            }
                                                            ?>
                                                            <option
                                                                value="<?php echo $idTipoDerivadoItem; ?>"
                                                                <?php echo (string)$nuevoDerivadoFormValues['id_tipolicitacion'] === (string)$idTipoDerivadoItem ? 'selected' : ''; ?>
                                                            >
                                                                <?php echo htmlspecialchars((string)($tipoDerivadoItem['tipo'] ?? ('Tipo #' . $idTipoDerivadoItem)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="derived-form-field full">
                                                    <label for="nuevo-gober">Enlace Gober (opcional)</label>
                                                    <input
                                                        id="nuevo-gober"
                                                        name="nuevo_enlace_gober"
                                                        type="url"
                                                        value="<?php echo htmlspecialchars((string)$nuevoDerivadoFormValues['enlace_gober'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    />
                                                </div>
                                                <div class="derived-form-field full">
                                                    <label for="nuevo-sharepoint">Enlace SharePoint (opcional)</label>
                                                    <input
                                                        id="nuevo-sharepoint"
                                                        name="nuevo_enlace_sharepoint"
                                                        type="url"
                                                        value="<?php echo htmlspecialchars((string)$nuevoDerivadoFormValues['enlace_sharepoint'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    />
                                                </div>
                                                <div class="derived-form-field full">
                                                    <label for="nuevo-descripcion">Notas / Descripcion</label>
                                                    <textarea id="nuevo-descripcion" name="nuevo_descripcion" rows="3"><?php echo htmlspecialchars((string)$nuevoDerivadoFormValues['descripcion'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
                                                </div>
                                            </div>
                                            <div class="derived-form-actions">
                                                <button type="submit" class="derived-submit-btn">
                                                    Crear <?php echo $tipoProcedimientoUpper === 'SDA' ? 'Especifico SDA' : 'Contrato Basado'; ?>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                    <?php if ($contratosDerivados === []): ?>
                                        <p class="derived-empty">
                                            Aun no hay contratos derivados para este expediente.
                                        </p>
                                    <?php else: ?>
                                        <div class="derived-table-wrap">
                                            <table class="derived-table">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Expediente</th>
                                                        <th>Nombre</th>
                                                        <th>Procedimiento</th>
                                                        <th>Estado</th>
                                                        <th class="is-right">Presupuesto (EUR)</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($contratosDerivados as $contratoDerivado): ?>
                                                        <?php
                                                        $idContratoDerivado = (int)($contratoDerivado['id_licitacion'] ?? 0);
                                                        if ($idContratoDerivado <= 0) {
                                                            continue;
                                                        }
                                                        $estadoDerivadoId = (int)($contratoDerivado['id_estado'] ?? 0);
                                                        $estadoDerivadoNombre = $estadoNombres[$estadoDerivadoId] ?? ('Estado ' . $estadoDerivadoId);
                                                        $procDerivado = trim((string)($contratoDerivado['tipo_procedimiento'] ?? 'CONTRATO_BASADO'));
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <a href="<?php echo htmlspecialchars('licitacion-detalle.php?id=' . $idContratoDerivado, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                                                    #<?php echo $idContratoDerivado; ?>
                                                                </a>
                                                            </td>
                                                            <td><?php echo htmlspecialchars((string)($contratoDerivado['numero_expediente'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                            <td>
                                                                <a href="<?php echo htmlspecialchars('licitacion-detalle.php?id=' . $idContratoDerivado, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                                                    <?php echo htmlspecialchars((string)($contratoDerivado['nombre'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                                </a>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($procDerivado, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                            <td><?php echo htmlspecialchars($estadoDerivadoNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                            <td class="is-right"><?php echo number_format((float)($contratoDerivado['pres_maximo'] ?? 0), 2, ',', '.'); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!$isAmSda): ?>
                            <div id="tab-presupuesto" class="tab-content active">
                                <?php if ($mappedSuccess): ?>
                                    <div class="mapped-success-banner">
                                        Productos vinculados correctamente.
                                    </div>
                                <?php endif; ?>
                                <?php if ($isContratoDerivado || ($lotesConfigItems === [] && $idEstado === 3)): ?>
                                    <div class="lotes-config-panel">
                                        <?php if ($isContratoDerivado): ?>
                                            <div class="lotes-config-empty">
                                                <span>En contratos basados o especificos no se usa configuracion de lotes.</span>
                                            </div>
                                        <?php else: ?>
                                            <div class="lotes-config-empty">
                                                <form
                                                    method="post"
                                                    action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id . '&tab=presupuesto', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    class="lotes-config-form"
                                                >
                                                    <input type="hidden" name="form_tipo" value="guardar_lotes_config">
                                                    <input type="hidden" name="num_lotes" value="2" class="js-generate-lotes-count">
                                                    <button
                                                        type="button"
                                                        class="js-generate-lotes-flow"
                                                        data-min="1"
                                                        data-max="20"
                                                    >
                                                        Generar lotes
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php
                                /** @var array<int, array<string,mixed>> $partidasActivas */
                                $partidasActivas = [];
                                foreach ($partidas as $pActiva) {
                                    if (!is_array($pActiva)) {
                                        continue;
                                    }
                                    $activa = array_key_exists('activo', $pActiva) ? (bool)$pActiva['activo'] : true;
                                    if (!$activa) {
                                        continue;
                                    }
                                    $partidasActivas[] = $pActiva;
                                }
                                $splitByLotesCards = !$isContratoDerivado && count($lotesConfigurados) > 0;
                                $showLoteColumnPresupuesto = $usaLotesPresupuesto && !$splitByLotesCards;
                                $budgetSaveLabel = $splitByLotesCards ? 'Guardar presupuesto' : 'Guardar toda la tabla';
                                /** @var array<string, string> $lotesConfiguradosMap */
                                $lotesConfiguradosMap = [];
                                /** @var array<string, array<int, array<string,mixed>>> $partidasActivasPorLote */
                                $partidasActivasPorLote = [];
                                /** @var array<int, array<string,mixed>> $partidasActivasOtrosLote */
                                $partidasActivasOtrosLote = [];
                                if ($splitByLotesCards) {
                                    foreach ($lotesConfigurados as $loteCfgNombre) {
                                        $keyLoteCfg = mb_strtolower($loteCfgNombre, 'UTF-8');
                                        $lotesConfiguradosMap[$keyLoteCfg] = $loteCfgNombre;
                                        $partidasActivasPorLote[$loteCfgNombre] = [];
                                    }
                                    foreach ($partidasActivas as $pActiva) {
                                        $lotePartidaTmp = trim((string)($pActiva['lote'] ?? ''));
                                        if ($lotePartidaTmp === '') {
                                            $lotePartidaTmp = 'General';
                                        }
                                        $keyLoteTmp = mb_strtolower($lotePartidaTmp, 'UTF-8');
                                        if (isset($lotesConfiguradosMap[$keyLoteTmp])) {
                                            $nombreCfg = $lotesConfiguradosMap[$keyLoteTmp];
                                            $partidasActivasPorLote[$nombreCfg][] = $pActiva;
                                        } else {
                                            $partidasActivasOtrosLote[] = $pActiva;
                                        }
                                    }
                                }
                                /** @var array<int, array{title:string, subtitle:string, default_lote:string, rows:array<int, array<string,mixed>>, is_otros:bool, toggle_lote:string}> $budgetCardGroups */
                                $budgetCardGroups = [];
                                if ($splitByLotesCards) {
                                    foreach ($lotesConfigurados as $loteCfgNombre) {
                                        $budgetCardGroups[] = [
                                            'title' => $loteCfgNombre,
                                            'subtitle' => 'Partidas de este lote',
                                            'default_lote' => $loteCfgNombre,
                                            'rows' => $partidasActivasPorLote[$loteCfgNombre] ?? [],
                                            'is_otros' => false,
                                            'toggle_lote' => $loteCfgNombre,
                                        ];
                                    }
                                    if ($partidasActivasOtrosLote !== []) {
                                        $budgetCardGroups[] = [
                                            'title' => 'Otros (sin lote configurado)',
                                            'subtitle' => 'Partidas con lote no configurado',
                                            'default_lote' => $lotesConfigurados[0] ?? 'General',
                                            'rows' => $partidasActivasOtrosLote,
                                            'is_otros' => true,
                                            'toggle_lote' => '',
                                        ];
                                    }
                                }
                                if ($budgetCardGroups === []) {
                                    $budgetCardGroups[] = [
                                        'title' => '',
                                        'subtitle' => '',
                                        'default_lote' => $lotesConfigurados[0] ?? 'General',
                                        'rows' => $partidasActivas,
                                        'is_otros' => false,
                                        'toggle_lote' => '',
                                    ];
                                }
                                $colsSoloLectura = 1
                                    + ($usaLotesPresupuesto ? 1 : 0)
                                    + ($showUnidadesPresupuesto ? 1 : 0)
                                    + ($showPmaxuPresupuesto ? 1 : 0)
                                    + 3;
                                $colsEditable = $colsSoloLectura + 1;
                                ?>
                                <?php if ($puedeMarcarLotesGanados): ?>
                                    <?php foreach ($lotesConfigUiMap as $loteCfgUi): ?>
                                        <form
                                            id="<?php echo htmlspecialchars((string)$loteCfgUi['form_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                            method="post"
                                            action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id . '&tab=presupuesto', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                            hidden
                                        >
                                            <input type="hidden" name="form_tipo" value="toggle_lote_ganado">
                                            <input type="hidden" name="lote_nombre" value="<?php echo htmlspecialchars((string)$loteCfgUi['nombre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                            <input type="hidden" name="ganado" value="<?php echo !empty($loteCfgUi['ganado']) ? '0' : '1'; ?>">
                                        </form>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if (!$presupuestoBloqueado): ?>
                                    <form
                                        method="post"
                                        action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id . '&tab=presupuesto', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                        class="budget-table-form"
                                        novalidate
                                    >
                                        <input type="hidden" name="form_tipo" value="budget_table_action">
                                        <?php if ($isTipoDescuentoPresupuesto): ?>
                                            <div
                                                class="budget-discount-toolbar"
                                                style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;padding:8px 10px;border:1px solid #d8d2c4;border-radius:10px;background:#f8f6ef;"
                                            >
                                                <span style="font-size:0.8rem;font-weight:600;color:#6b5d47;">Descuentos sobre precio maximo</span>
                                                <div style="display:flex;align-items:center;gap:8px;">
                                                    <span style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.04em;color:#7c6f58;">Descuento global</span>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        name="descuento_global"
                                                        id="descuento-global-input"
                                                        value="<?php echo htmlspecialchars((string)$descuentoGlobalPresupuesto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                        class="budget-input budget-input-right"
                                                        style="width:88px;"
                                                    />
                                                    <span style="font-size:0.8rem;color:#7c6f58;">%</span>
                                                    <button
                                                        type="button"
                                                        id="btn-aplicar-descuento-global"
                                                        class="btn-row-save"
                                                        style="padding:6px 10px;"
                                                    >
                                                        Aplicar a PVU
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <div class="<?php echo $splitByLotesCards ? 'budget-lote-cards' : ''; ?>">
                                            <?php $newRowIndex = 0; ?>
                                            <?php foreach ($budgetCardGroups as $budgetCard): ?>
                                                <?php
                                                $partidasActivasRender = is_array($budgetCard['rows'] ?? null) ? $budgetCard['rows'] : [];
                                                $defaultLoteCard = trim((string)($budgetCard['default_lote'] ?? 'General'));
                                                $toggleLoteCard = trim((string)($budgetCard['toggle_lote'] ?? ''));
                                                $loteToggleUi = null;
                                                if ($toggleLoteCard !== '') {
                                                    $toggleLoteKey = mb_strtolower($toggleLoteCard, 'UTF-8');
                                                    $loteToggleUi = $lotesConfigUiMap[$toggleLoteKey] ?? null;
                                                }
                                                if ($defaultLoteCard === '') {
                                                    $defaultLoteCard = $lotesConfigurados[0] ?? 'General';
                                                }
                                                ?>
                                                <?php if ($splitByLotesCards): ?>
                                                    <section class="budget-lote-card <?php echo !empty($budgetCard['is_otros']) ? 'is-otros' : ''; ?>">
                                                        <div class="budget-lote-card-head">
                                                            <h4 class="budget-lote-title"><?php echo htmlspecialchars((string)($budgetCard['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h4>
                                                            <div class="budget-lote-card-tools">
                                                                <?php if ($puedeMarcarLotesGanados && is_array($loteToggleUi)): ?>
                                                                    <?php $ganadoCard = !empty($loteToggleUi['ganado']); ?>
                                                                    <button
                                                                        type="submit"
                                                                        form="<?php echo htmlspecialchars((string)$loteToggleUi['form_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                        formnovalidate
                                                                        class="budget-lote-toggle <?php echo $ganadoCard ? 'is-ganado' : 'is-perdido'; ?>"
                                                                        aria-pressed="<?php echo $ganadoCard ? 'true' : 'false'; ?>"
                                                                        aria-label="<?php echo htmlspecialchars('Cambiar estado de ' . (string)$loteToggleUi['nombre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                        title="Cambiar estado de lote"
                                                                    >
                                                                        <span class="budget-lote-toggle-track" aria-hidden="true">
                                                                            <span class="budget-lote-toggle-thumb"></span>
                                                                        </span>
                                                                        <span class="budget-lote-toggle-label"><?php echo $ganadoCard ? 'Ganado' : 'Perdido'; ?></span>
                                                                    </button>
                                                                <?php endif; ?>
                                                                <span class="budget-lote-meta"><?php echo count($partidasActivasRender); ?> linea(s)</span>
                                                            </div>
                                                        </div>
                                                        <?php if ($partidasActivasRender === []): ?>
                                                            <p class="budget-lote-empty-note">No hay partidas activas en este bloque. Puedes anadir nuevas lineas aqui.</p>
                                                        <?php endif; ?>
                                                <?php endif; ?>
                                        <table
                                            class="budget-lines-table budget-lines-table-editable"
                                            data-show-unidades="<?php echo $showUnidadesPresupuesto ? '1' : '0'; ?>"
                                            data-show-pmaxu="<?php echo $showPmaxuPresupuesto ? '1' : '0'; ?>"
                                            data-tipo-descuento="<?php echo $isTipoDescuentoPresupuesto ? '1' : '0'; ?>"
                                        >
                                            <thead>
                                                <tr>
                                                    <th>Producto</th>
                                                    <?php if ($showLoteColumnPresupuesto): ?>
                                                        <th class="is-right">Lote</th>
                                                    <?php endif; ?>
                                                    <?php if ($showUnidadesPresupuesto): ?>
                                                        <th class="is-right">Uds.</th>
                                                    <?php endif; ?>
                                                    <?php if ($showPmaxuPresupuesto): ?>
                                                        <th class="is-right">PMAXU (EUR)</th>
                                                    <?php endif; ?>
                                                    <th class="is-right">PVU (EUR)</th>
                                                    <th class="is-right">PCU (EUR)</th>
                                                    <th class="is-right">Importe (EUR)</th>
                                                    <th class="is-right">Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($partidasActivasRender as $p): ?>
                                                    <?php
                                                    $detalleId = (int)($p['id_detalle'] ?? 0);
                                                    if ($detalleId <= 0) {
                                                        continue;
                                                    }
                                                    $nombreProd = (string)($p['product_nombre'] ?? ($p['nombre_producto_libre'] ?? ''));
                                                    $idProducto = isset($p['id_producto']) ? (int)$p['id_producto'] : 0;
                                                    $lotePartida = trim((string)($p['lote'] ?? ''));
                                                    if ($lotePartida === '') {
                                                        $lotePartida = 'General';
                                                    }
                                                    $uds = (float)($p['unidades'] ?? 0);
                                                    $pmaxu = (float)($p['pmaxu'] ?? 0);
                                                    $pvu = (float)($p['pvu'] ?? 0);
                                                    $pcu = (float)($p['pcu'] ?? 0);
                                                    $importe = $showUnidadesPresupuesto ? ($uds > 0 ? $uds * $pvu : $pvu) : $pvu;
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <input type="hidden" name="lineas[<?php echo $detalleId; ?>][id_producto]" value="<?php echo $idProducto > 0 ? $idProducto : ''; ?>" />
                                                            <input
                                                                type="text"
                                                                name="lineas[<?php echo $detalleId; ?>][nombre_partida]"
                                                                value="<?php echo htmlspecialchars($nombreProd, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                class="budget-input"
                                                                <?php echo $idProducto > 0 ? 'readonly' : ''; ?>
                                                            />
                                                            <?php if ($usaLotesPresupuesto && !$showLoteColumnPresupuesto): ?>
                                                                <input
                                                                    type="hidden"
                                                                    name="lineas[<?php echo $detalleId; ?>][lote]"
                                                                    value="<?php echo htmlspecialchars($lotePartida, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                />
                                                            <?php endif; ?>
                                                        </td>
                                                        <?php if ($showLoteColumnPresupuesto): ?>
                                                            <td class="budget-cell-num">
                                                                <?php if ($lotesConfigurados !== []): ?>
                                                                    <select name="lineas[<?php echo $detalleId; ?>][lote]" class="budget-input budget-input-right">
                                                                        <?php foreach ($lotesConfigurados as $loteConfigNombre): ?>
                                                                            <option
                                                                                value="<?php echo htmlspecialchars($loteConfigNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                                <?php echo $lotePartida === $loteConfigNombre ? 'selected' : ''; ?>
                                                                            >
                                                                                <?php echo htmlspecialchars($loteConfigNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                <?php else: ?>
                                                                    <input
                                                                        type="text"
                                                                        name="lineas[<?php echo $detalleId; ?>][lote]"
                                                                        value="<?php echo htmlspecialchars($lotePartida, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                        class="budget-input budget-input-right"
                                                                    />
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php if ($showUnidadesPresupuesto): ?>
                                                            <td class="budget-cell-num">
                                                                <input type="number" step="any" min="0" name="lineas[<?php echo $detalleId; ?>][unidades]" value="<?php echo $uds > 0 ? htmlspecialchars((string)$uds, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>" class="budget-input budget-input-right" />
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php if ($showPmaxuPresupuesto): ?>
                                                            <td class="budget-cell-num">
                                                                <input type="number" step="any" min="0" name="lineas[<?php echo $detalleId; ?>][pmaxu]" value="<?php echo $pmaxu > 0 ? htmlspecialchars((string)$pmaxu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>" class="budget-input budget-input-right" />
                                                            </td>
                                                        <?php endif; ?>
                                                        <td class="budget-cell-num">
                                                            <input
                                                                type="number"
                                                                step="any"
                                                                min="0"
                                                                name="lineas[<?php echo $detalleId; ?>][pvu]"
                                                                value="<?php echo $pvu > 0 ? htmlspecialchars((string)$pvu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>"
                                                                class="budget-input budget-input-right"
                                                                <?php echo $isTipoDescuentoPresupuesto ? 'readonly tabindex="-1" data-auto-pvu="1"' : ''; ?>
                                                            />
                                                        </td>
                                                        <td class="budget-cell-num">
                                                            <input type="number" step="any" min="0" name="lineas[<?php echo $detalleId; ?>][pcu]" value="<?php echo $pcu > 0 ? htmlspecialchars((string)$pcu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>" class="budget-input budget-input-right" />
                                                        </td>
                                                        <td class="is-right"><?php echo number_format($importe, 2, ',', '.'); ?></td>
                                                        <td class="budget-cell-actions">
                                                            <button
                                                                type="submit"
                                                                name="eliminar_id"
                                                                value="<?php echo $detalleId; ?>"
                                                                class="btn-row-trash"
                                                                title="Eliminar linea"
                                                                aria-label="Eliminar linea"
                                                                onclick="return window.confirm('Se eliminara esta linea de presupuesto.');"
                                                            >
                                                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                                    <path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 12h12l1-13H5l1 13Z" fill="currentColor" />
                                                                </svg>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr class="budget-new-row js-budget-new-row" data-new-index="<?php echo $newRowIndex; ?>">
                                                    <td class="budget-cell-concept">
                                                        <input type="hidden" name="lineas_nuevas[<?php echo $newRowIndex; ?>][id_producto]" class="js-budget-product-id" value="" />
                                                        <?php if ($usaLotesPresupuesto && !$showLoteColumnPresupuesto): ?>
                                                            <input
                                                                type="hidden"
                                                                name="lineas_nuevas[<?php echo $newRowIndex; ?>][lote]"
                                                                value="<?php echo htmlspecialchars($defaultLoteCard, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                data-default-value="<?php echo htmlspecialchars($defaultLoteCard, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                            />
                                                        <?php endif; ?>
                                                        <div class="budget-autocomplete-wrap">
                                                            <input
                                                                type="text"
                                                                name="lineas_nuevas[<?php echo $newRowIndex; ?>][nombre_partida]"
                                                                class="budget-input js-budget-concept-input"
                                                                placeholder="Anadir nuevo concepto..."
                                                                autocomplete="off"
                                                            />
                                                            <div class="budget-autocomplete-list js-budget-suggest-box"></div>
                                                            <div class="ac-status budget-ac-status js-budget-ac-status"></div>
                                                        </div>
                                                    </td>
                                                    <?php if ($showLoteColumnPresupuesto): ?>
                                                        <td class="budget-cell-num">
                                                            <?php if ($lotesConfigurados !== []): ?>
                                                                <select
                                                                    name="lineas_nuevas[<?php echo $newRowIndex; ?>][lote]"
                                                                    class="budget-input budget-input-right"
                                                                    data-default-value="<?php echo htmlspecialchars($defaultLoteCard, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                >
                                                                    <?php foreach ($lotesConfigurados as $loteConfigNombre): ?>
                                                                        <option
                                                                            value="<?php echo htmlspecialchars($loteConfigNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                            <?php echo $defaultLoteCard === $loteConfigNombre ? 'selected' : ''; ?>
                                                                        >
                                                                            <?php echo htmlspecialchars($loteConfigNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            <?php else: ?>
                                                                <input type="text" name="lineas_nuevas[<?php echo $newRowIndex; ?>][lote]" placeholder="Lote 1" class="budget-input budget-input-right" />
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endif; ?>
                                                    <?php if ($showUnidadesPresupuesto): ?>
                                                        <td class="budget-cell-num">
                                                            <input type="number" step="any" min="0" name="lineas_nuevas[<?php echo $newRowIndex; ?>][unidades]" placeholder="0" class="budget-input budget-input-right" />
                                                        </td>
                                                    <?php endif; ?>
                                                    <?php if ($showPmaxuPresupuesto): ?>
                                                        <td class="budget-cell-num">
                                                            <input type="number" step="any" min="0" name="lineas_nuevas[<?php echo $newRowIndex; ?>][pmaxu]" placeholder="0" class="budget-input budget-input-right" />
                                                        </td>
                                                    <?php endif; ?>
                                                    <td class="budget-cell-num">
                                                        <input
                                                            type="number"
                                                            step="any"
                                                            min="0"
                                                            name="lineas_nuevas[<?php echo $newRowIndex; ?>][pvu]"
                                                            placeholder="0"
                                                            class="budget-input budget-input-right"
                                                            <?php echo $isTipoDescuentoPresupuesto ? 'readonly tabindex="-1" data-auto-pvu="1"' : ''; ?>
                                                        />
                                                    </td>
                                                    <td class="budget-cell-num">
                                                        <input type="number" step="any" min="0" name="lineas_nuevas[<?php echo $newRowIndex; ?>][pcu]" placeholder="0" class="budget-input budget-input-right" />
                                                    </td>
                                                    <td class="is-right budget-new-importe">-</td>
                                                    <td class="budget-cell-actions">
                                                        <span class="budget-auto-add-hint">Se crea otra fila automaticamente</span>
                                                    </td>
                                                </tr>
                                                <?php $newRowIndex++; ?>
                                            </tbody>
                                        </table>
                                                <?php if ($splitByLotesCards): ?>
                                                    </section>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="budget-table-actions">
                                            <button
                                                type="submit"
                                                name="guardar_todo"
                                                value="1"
                                                class="btn-save-budget-table"
                                                aria-label="<?php echo htmlspecialchars($budgetSaveLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                            >
                                                <?php echo htmlspecialchars($budgetSaveLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                            </button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <?php if ($partidasActivas === []): ?>
                                        <p class="budget-empty">
                                            Esta licitacion aun no tiene partidas de presupuesto cargadas.
                                        </p>
                                    <?php else: ?>
                                        <div class="<?php echo $splitByLotesCards ? 'budget-lote-cards' : ''; ?>">
                                            <?php foreach ($budgetCardGroups as $budgetCard): ?>
                                                <?php
                                                $partidasActivasRender = is_array($budgetCard['rows'] ?? null) ? $budgetCard['rows'] : [];
                                                $toggleLoteCard = trim((string)($budgetCard['toggle_lote'] ?? ''));
                                                $loteToggleUi = null;
                                                if ($toggleLoteCard !== '') {
                                                    $toggleLoteKey = mb_strtolower($toggleLoteCard, 'UTF-8');
                                                    $loteToggleUi = $lotesConfigUiMap[$toggleLoteKey] ?? null;
                                                }
                                                ?>
                                                <?php if ($splitByLotesCards): ?>
                                                    <section class="budget-lote-card <?php echo !empty($budgetCard['is_otros']) ? 'is-otros' : ''; ?>">
                                                        <div class="budget-lote-card-head">
                                                            <h4 class="budget-lote-title"><?php echo htmlspecialchars((string)($budgetCard['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h4>
                                                            <div class="budget-lote-card-tools">
                                                                <?php if ($puedeMarcarLotesGanados && is_array($loteToggleUi)): ?>
                                                                    <?php $ganadoCard = !empty($loteToggleUi['ganado']); ?>
                                                                    <button
                                                                        type="submit"
                                                                        form="<?php echo htmlspecialchars((string)$loteToggleUi['form_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                        formnovalidate
                                                                        class="budget-lote-toggle <?php echo $ganadoCard ? 'is-ganado' : 'is-perdido'; ?>"
                                                                        aria-pressed="<?php echo $ganadoCard ? 'true' : 'false'; ?>"
                                                                        aria-label="<?php echo htmlspecialchars('Cambiar estado de ' . (string)$loteToggleUi['nombre'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                        title="Cambiar estado de lote"
                                                                    >
                                                                        <span class="budget-lote-toggle-track" aria-hidden="true">
                                                                            <span class="budget-lote-toggle-thumb"></span>
                                                                        </span>
                                                                        <span class="budget-lote-toggle-label"><?php echo $ganadoCard ? 'Ganado' : 'Perdido'; ?></span>
                                                                    </button>
                                                                <?php endif; ?>
                                                                <span class="budget-lote-meta"><?php echo count($partidasActivasRender); ?> linea(s)</span>
                                                            </div>
                                                        </div>
                                                <?php endif; ?>

                                                <?php if ($partidasActivasRender === []): ?>
                                                    <p class="budget-lote-empty-note">No hay partidas activas en este bloque.</p>
                                                <?php else: ?>
                                                    <table class="budget-lines-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Producto</th>
                                                                <?php if ($showLoteColumnPresupuesto): ?>
                                                                    <th class="is-right">Lote</th>
                                                                <?php endif; ?>
                                                                <?php if ($showUnidadesPresupuesto): ?>
                                                                    <th class="is-right">Uds.</th>
                                                                <?php endif; ?>
                                                                <?php if ($showPmaxuPresupuesto): ?>
                                                                    <th class="is-right">PMAXU (EUR)</th>
                                                                <?php endif; ?>
                                                                <th class="is-right">PVU (EUR)</th>
                                                                <th class="is-right">PCU (EUR)</th>
                                                                <th class="is-right">Importe (EUR)</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($partidasActivasRender as $p): ?>
                                                                <?php
                                                                $nombreProd = (string)($p['product_nombre'] ?? ($p['nombre_producto_libre'] ?? ''));
                                                                $lotePartida = trim((string)($p['lote'] ?? ''));
                                                                if ($lotePartida === '') {
                                                                    $lotePartida = 'General';
                                                                }
                                                                $uds = (float)($p['unidades'] ?? 0);
                                                                $pmaxu = (float)($p['pmaxu'] ?? 0);
                                                                $pvu = (float)($p['pvu'] ?? 0);
                                                                $pcu = (float)($p['pcu'] ?? 0);
                                                                $importe = $showUnidadesPresupuesto ? ($uds > 0 ? $uds * $pvu : $pvu) : $pvu;
                                                                ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($nombreProd, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                                    <?php if ($showLoteColumnPresupuesto): ?>
                                                                        <td class="is-right"><?php echo htmlspecialchars($lotePartida, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                                    <?php endif; ?>
                                                                    <?php if ($showUnidadesPresupuesto): ?>
                                                                        <td class="is-right"><?php echo $uds > 0 ? number_format($uds, 2, ',', '.') : '-'; ?></td>
                                                                    <?php endif; ?>
                                                                    <?php if ($showPmaxuPresupuesto): ?>
                                                                        <td class="is-right"><?php echo $pmaxu > 0 ? number_format($pmaxu, 2, ',', '.') : '-'; ?></td>
                                                                    <?php endif; ?>
                                                                    <td class="is-right"><?php echo number_format($pvu, 2, ',', '.'); ?></td>
                                                                    <td class="is-right"><?php echo number_format($pcu, 2, ',', '.'); ?></td>
                                                                    <td class="is-right"><?php echo number_format($importe, 2, ',', '.'); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                <?php endif; ?>

                                                <?php if ($splitByLotesCards): ?>
                                                    </section>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <?php if ($showEjecucionRemaining): ?>
                                <div id="tab-ejecucion" class="tab-content">
                                    <div class="deliveries-toolbar">
                                        <p class="deliveries-intro">
                                            Resumen de entregas y albaranes vinculados a esta licitacion.
                                        </p>
                                        <button
                                            type="button"
                                            id="btn-nuevo-albaran"
                                            class="btn-new-delivery"
                                        >
                                            + Registrar nuevo albaran
                                        </button>
                                    </div>
                                    <?php if (empty($entregas)): ?>
                                        <p class="deliveries-empty">
                                            No hay entregas registradas para esta licitacion.
                                        </p>
                                    <?php else: ?>
                                        <div class="deliveries-list">
                                            <?php foreach ($entregas as $ent): ?>
                                                <?php
                                                $codigoAlbaran = (string)($ent['codigo_albaran'] ?? '');
                                                $fechaEntrega = (string)($ent['fecha_entrega'] ?? '');
                                                $obs = (string)($ent['observaciones'] ?? '');
                                                $lineas = isset($ent['lineas']) && is_array($ent['lineas']) ? $ent['lineas'] : [];
                                                ?>
                                                <div class="delivery-card">
                                                    <div class="delivery-head">
                                                        <div>
                                                            <div class="delivery-code">
                                                                <?php echo htmlspecialchars($codigoAlbaran !== '' ? $codigoAlbaran : 'Sin codigo', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                            </div>
                                                            <div class="delivery-date">
                                                                Fecha: <?php echo htmlspecialchars($fechaEntrega, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                            </div>
                                                        </div>
                                                        <?php if ($obs !== ''): ?>
                                                            <div class="delivery-note">
                                                                <?php echo htmlspecialchars($obs, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="delivery-lines-wrap">
                                                        <table class="delivery-lines-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>Concepto</th>
                                                                    <th>Proveedor</th>
                                                                    <th class="is-right">Cantidad</th>
                                                                    <th class="is-right">Coste</th>
                                                                    <th class="is-center">Estado</th>
                                                                    <th class="is-center">Cobrado</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php if (empty($lineas)): ?>
                                                                    <tr>
                                                                        <td colspan="6" class="delivery-empty-row">
                                                                            Sin lineas
                                                                        </td>
                                                                    </tr>
                                                                <?php else: ?>
                                                                    <?php foreach ($lineas as $lin): ?>
                                                                        <?php
                                                                        $esGastoExtra = ($lin['id_detalle'] ?? null) === null && isset($lin['id_tipo_gasto']);
                                                                        $concepto = (string)($lin['product_nombre'] ?? '-');
                                                                        $proveedor = (string)($lin['proveedor'] ?? '-');
                                                                        $cantidad = (float)($lin['cantidad'] ?? 0);
                                                                        $pcu = (float)($lin['pcu'] ?? 0);
                                                                        $estadoLin = (string)($lin['estado'] ?? '');
                                                                        if ($estadoLin === '' || !in_array($estadoLin, $estadosLineaEntrega, true)) {
                                                                            $estadoLin = 'EN ESPERA';
                                                                        }
                                                                        $cobrado = (bool)($lin['cobrado'] ?? false);
                                                                        $cobradoValue = $cobrado ? 1 : 0;
                                                                        $idRealLinea = isset($lin['id_real']) ? (int)$lin['id_real'] : 0;
                                                                        $classCobrado = $cobrado ? 'linea-tag-cobrado-si' : 'linea-tag-cobrado-no';
                                                                        ?>
                                                                        <tr>
                                                                            <td><?php echo htmlspecialchars($concepto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                                            <td><?php echo htmlspecialchars($proveedor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                                            <td class="is-right"><?php echo number_format($cantidad, 2, ',', '.'); ?></td>
                                                                            <td class="is-right"><?php echo number_format($pcu, 2, ',', '.'); ?> EUR</td>
                                                                            <td class="is-center">
                                                                                <?php if ($esGastoExtra): ?>
                                                                                    <span class="linea-tag-extra">Gasto ext.</span>
                                                                                <?php elseif ($idRealLinea > 0): ?>
                                                                                    <form
                                                                                        method="post"
                                                                                        action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id . '&tab=ejecucion', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                                        class="delivery-state-form"
                                                                                    >
                                                                                        <input type="hidden" name="form_tipo" value="actualizar_estado_linea">
                                                                                        <input type="hidden" name="id_real" value="<?php echo $idRealLinea; ?>">
                                                                                        <select
                                                                                            name="estado_linea"
                                                                                            class="delivery-state-select"
                                                                                        >
                                                                                            <?php foreach ($estadosLineaEntrega as $estadoOpt): ?>
                                                                                                <option
                                                                                                    value="<?php echo htmlspecialchars($estadoOpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                                                    <?php echo $estadoLin === $estadoOpt ? 'selected' : ''; ?>
                                                                                                >
                                                                                                    <?php echo htmlspecialchars($estadoOpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                                                                </option>
                                                                                            <?php endforeach; ?>
                                                                                        </select>
                                                                                    </form>
                                                                                <?php else: ?>
                                                                                    <span class="linea-tag-estado"><?php echo htmlspecialchars($estadoLin !== '' ? $estadoLin : 'EN ESPERA', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                            <td class="is-center">
                                                                                <?php if ($esGastoExtra): ?>
                                                                                    <span class="linea-tag-extra">-</span>
                                                                                <?php elseif ($idRealLinea > 0): ?>
                                                                                    <form
                                                                                        method="post"
                                                                                        action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id . '&tab=ejecucion', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                                        class="delivery-cobrado-form"
                                                                                    >
                                                                                        <input type="hidden" name="form_tipo" value="actualizar_cobrado_linea">
                                                                                        <input type="hidden" name="id_real" value="<?php echo $idRealLinea; ?>">
                                                                                        <select
                                                                                            name="cobrado_linea"
                                                                                            class="delivery-cobrado-select"
                                                                                        >
                                                                                            <option value="0" <?php echo $cobradoValue === 0 ? 'selected' : ''; ?>>No</option>
                                                                                            <option value="1" <?php echo $cobradoValue === 1 ? 'selected' : ''; ?>>Si</option>
                                                                                        </select>
                                                                                    </form>
                                                                                <?php else: ?>
                                                                                    <span class="<?php echo $classCobrado; ?>">
                                                                                        <?php echo $cobrado ? 'Si' : 'No'; ?>
                                                                                    </span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Modal nuevo albaran -->
                                    <div
                                        id="modal-nuevo-albaran"
                                        style="position:fixed;inset:0;background:rgba(15,23,42,0.72);display:none;align-items:center;justify-content:center;z-index:60;"
                                    >
                                        <div class="albaran-modal-dialog" style="background:#020617;border-radius:12px;border:1px solid #1f2937;box-shadow:0 18px 35px rgba(15,23,42,0.9);max-width:720px;width:100%;padding:16px 18px;">
                                            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
                                                <h3 style="margin:0;font-size:0.9rem;font-weight:600;color:#e5e7eb;">Registrar nuevo albaran</h3>
                                                <button
                                                    type="button"
                                                    id="modal-nuevo-albaran-close"
                                                    style="border:none;background:transparent;color:#9ca3af;font-size:0.9rem;cursor:pointer;"
                                                >&times;</button>
                                            </div>
                                            <p style="margin:0 0 10px;font-size:0.8rem;color:#9ca3af;">
                                                Cabecera del albaran y lineas de entrega (concepto, proveedor, cantidad, coste).
                                            </p>
                                            <form
                                                method="post"
                                                action="<?php echo htmlspecialchars($selfUrl . '?id=' . $id . '&tab=ejecucion', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                id="form-nuevo-albaran"
                                            >
                                                <input type="hidden" name="form_tipo" value="nuevo_albaran">
                                                <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:10px;">
                                                    <div style="display:flex;flex-direction:column;gap:4px;">
                                                        <label style="font-size:0.75rem;font-weight:600;color:#9ca3af;">Fecha</label>
                                                        <input
                                                            type="date"
                                                            name="fecha"
                                                            value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                            style="height:32px;border-radius:8px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:4px 8px;font-size:0.8rem;"
                                                            required
                                                        />
                                                    </div>
                                                    <div style="display:flex;flex-direction:column;gap:4px;">
                                                        <label style="font-size:0.75rem;font-weight:600;color:#9ca3af;">Codigo albaran</label>
                                                        <input
                                                            type="text"
                                                            name="codigo_albaran"
                                                            placeholder="Ej. ALB-001"
                                                            style="height:32px;border-radius:8px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:4px 8px;font-size:0.8rem;"
                                                            required
                                                        />
                                                    </div>
                                                    <div style="display:flex;flex-direction:column;gap:4px;">
                                                        <label style="font-size:0.75rem;font-weight:600;color:#9ca3af;">Cliente (opcional)</label>
                                                        <input
                                                            type="text"
                                                            name="cliente"
                                                            style="height:32px;border-radius:8px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:4px 8px;font-size:0.8rem;"
                                                        />
                                                    </div>
                                                </div>
                                                <div style="margin-bottom:10px;display:flex;flex-direction:column;gap:4px;">
                                                    <label style="font-size:0.75rem;font-weight:600;color:#9ca3af;">Observaciones</label>
                                                    <textarea
                                                        name="observaciones"
                                                        rows="2"
                                                        placeholder="Opcional"
                                                        style="width:100%;border-radius:8px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;padding:6px 8px;font-size:0.8rem;resize:vertical;"
                                                    ></textarea>
                                                </div>

                                                <div style="margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
                                                    <span style="font-size:0.8rem;color:#9ca3af;">Lineas del albaran</span>
                                                    <div class="albaran-type-switch" style="display:inline-flex;border-radius:9999px;border:1px solid #1f2937;overflow:hidden;">
                                                        <button
                                                            type="button"
                                                            id="btn-albaran-tipo-presu"
                                                            class="albaran-tab-btn is-active"
                                                            style="border:none;background:#0f172a;color:#e5e7eb;font-size:0.75rem;font-weight:500;padding:4px 10px;cursor:pointer;"
                                                        >
                                                            Partidas
                                                        </button>
                                                        <button
                                                            type="button"
                                                            id="btn-albaran-tipo-ext"
                                                            class="albaran-tab-btn"
                                                            style="border:none;background:transparent;color:#9ca3af;font-size:0.75rem;font-weight:500;padding:4px 10px;cursor:pointer;"
                                                        >
                                                            Gastos extra
                                                        </button>
                                                    </div>
                                                </div>

                                                <div id="albaran-section-presu" style="border-radius:8px;border:1px solid #1f2937;overflow:hidden;">
                                                    <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                                                        <thead>
                                                            <tr style="border-bottom:1px solid #1f2937;font-size:0.7rem;text-transform:uppercase;color:#9ca3af;">
                                                                <th style="padding:4px 6px;text-align:left;">Partida</th>
                                                                <th style="padding:4px 6px;text-align:left;">Proveedor</th>
                                                                <th style="padding:4px 6px;text-align:right;">Cantidad</th>
                                                                <th style="padding:4px 6px;text-align:right;">Coste EUR</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php for ($i = 0; $i < 3; $i++): ?>
                                                                <tr style="border-bottom:1px solid #111827;">
                                                                    <td style="padding:4px 6px;min-width:200px;">
                                                                        <select
                                                                            name="lineas_presu[<?php echo $i; ?>][id_detalle]"
                                                                            class="js-albaran-partida-select"
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;"
                                                                        >
                                                                            <option value="">Selecciona partida...</option>
                                                                            <?php foreach ($partidas as $p): ?>
                                                                                <?php
                                                                                $idDet = (int)($p['id_detalle'] ?? 0);
                                                                                $nombreProd = (string)($p['product_nombre'] ?? ($p['nombre_producto_libre'] ?? ''));
                                                                                $lote = trim((string)($p['lote'] ?? ''));
                                                                                if ($lote === '') $lote = 'General';
                                                                                $udsPresu = (float)($p['unidades'] ?? 0.0);
                                                                                $udsEntregadas = (float)($ejecutadoPorDetalle[$idDet] ?? 0.0);
                                                                                $udsRestantes = $udsPresu > 0 ? max(0.0, $udsPresu - $udsEntregadas) : 0.0;
                                                                                $restanteTxt = $udsPresu > 0
                                                                                    ? number_format($udsRestantes, 2, ',', '.')
                                                                                    : '-';
                                                                                $baseLabel = $usaLotesPresupuesto
                                                                                    ? ($lote . ' - ' . $nombreProd)
                                                                                    : $nombreProd;
                                                                                $proveedorPartida = trim((string)($p['nombre_proveedor'] ?? ''));
                                                                                if ($proveedorPartida === '') {
                                                                                    $proveedorPartida = trim((string)($p['proveedor'] ?? ''));
                                                                                }
                                                                                if ($showUnidadesPresupuesto) {
                                                                                    $label = $baseLabel . ' (restante por entregar: ' . $restanteTxt . ')';
                                                                                } else {
                                                                                    $label = $baseLabel;
                                                                                }
                                                                                ?>
                                                                                <option
                                                                                    value="<?php echo $idDet; ?>"
                                                                                    data-proveedor="<?php echo htmlspecialchars($proveedorPartida, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                                                >
                                                                                    <?php echo htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </td>
                                                                    <td style="padding:4px 6px;min-width:150px;">
                                                                        <input
                                                                            type="text"
                                                                            name="lineas_presu[<?php echo $i; ?>][proveedor]"
                                                                            class="js-albaran-proveedor-input"
                                                                            placeholder="Proveedor"
                                                                            readonly
                                                                            aria-readonly="true"
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;"
                                                                        />
                                                                    </td>
                                                                    <td style="padding:4px 6px;text-align:right;width:80px;">
                                                                        <input
                                                                            type="number"
                                                                            step="any"
                                                                            min="0"
                                                                            name="lineas_presu[<?php echo $i; ?>][cantidad]"
                                                                            placeholder="0"
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;text-align:right;"
                                                                        />
                                                                    </td>
                                                                    <td style="padding:4px 6px;text-align:right;width:90px;">
                                                                        <input
                                                                            type="number"
                                                                            step="any"
                                                                            min="0"
                                                                            name="lineas_presu[<?php echo $i; ?>][coste_unit]"
                                                                            placeholder="0,00"
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;text-align:right;"
                                                                        />
                                                                    </td>
                                                                </tr>
                                                            <?php endfor; ?>
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <div id="albaran-section-ext" style="margin-top:12px;margin-bottom:6px;font-size:0.8rem;color:#9ca3af;display:none;">
                                                    <div style="border-radius:8px;border:1px solid #1f2937;overflow:hidden;">
                                                    <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                                                        <thead>
                                                            <tr style="border-bottom:1px solid #1f2937;font-size:0.7rem;text-transform:uppercase;color:#9ca3af;">
                                                                <th style="padding:4px 6px;text-align:left;">Tipo gasto</th>
                                                                <th style="padding:4px 6px;text-align:left;">Detalle (solo para "Otros")</th>
                                                                <th style="padding:4px 6px;text-align:right;">Coste EUR</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php for ($j = 0; $j < 2; $j++): ?>
                                                                <tr style="border-bottom:1px solid #111827;">
                                                                    <td style="padding:4px 6px;min-width:160px;">
                                                                        <select
                                                                            name="lineas_ext[<?php echo $j; ?>][id_tipo_gasto]"
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;"
                                                                        >
                                                                            <option value="">Tipo de gasto...</option>
                                                                            <?php foreach ($tiposGasto as $tg): ?>
                                                                                <?php
                                                                                $idG = (int)($tg['id'] ?? 0);
                                                                                $nombreG = (string)($tg['nombre'] ?? ($tg['codigo'] ?? ''));
                                                                                ?>
                                                                                <option value="<?php echo $idG; ?>">
                                                                                    <?php echo htmlspecialchars($nombreG, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </td>
                                                                    <td style="padding:4px 6px;min-width:200px;">
                                                                        <input
                                                                            type="text"
                                                                            name="lineas_ext[<?php echo $j; ?>][tipo_gasto_libre]"
                                                                            placeholder='Solo si el tipo es "Otros"'
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;"
                                                                        />
                                                                    </td>
                                                                    <td style="padding:4px 6px;text-align:right;width:90px;">
                                                                        <input
                                                                            type="number"
                                                                            step="any"
                                                                            min="0"
                                                                            name="lineas_ext[<?php echo $j; ?>][coste_unit]"
                                                                            placeholder="0,00"
                                                                            style="width:100%;height:30px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:0.75rem;padding:2px 6px;text-align:right;"
                                                                        />
                                                                    </td>
                                                                </tr>
                                                            <?php endfor; ?>
                                                        </tbody>
                                                    </table>
                                                    </div>
                                                </div>

                                                <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px;">
                                                    <button
                                                        type="button"
                                                        id="modal-nuevo-albaran-cancel"
                                                        class="albaran-cancel-btn"
                                                        style="border:1px solid #374151;border-radius:8px;background:#020617;color:#9ca3af;font-size:0.75rem;padding:4px 10px;cursor:pointer;"
                                                    >
                                                        Cancelar
                                                    </button>
                                                    <button
                                                        type="submit"
                                                        class="albaran-submit-btn"
                                                        style="border:none;border-radius:8px;background:linear-gradient(135deg,#10b981,#0ea5e9);color:#020617;font-size:0.8rem;font-weight:600;padding:6px 12px;cursor:pointer;"
                                                    >
                                                        Registrar albaran
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <div id="tab-remaining" class="tab-content">
                                    <p class="remaining-intro">
                                        Comparativa entre unidades presupuestadas y ejecutadas por partida.
                                    </p>
                                    <div class="remaining-card">
                                        <table class="remaining-table">
                                            <thead>
                                                <tr>
                                                    <th style="text-align:left;">Lote</th>
                                                    <th style="text-align:left;">Partida</th>
                                                    <th style="text-align:right;">Ud. Presu.</th>
                                                    <th style="text-align:right;">Ud. Real</th>
                                                    <th style="text-align:right;">Pendiente</th>
                                                    <th style="text-align:left;">Progreso</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($itemsPresupuestoAgregado as $key => $item): ?>
                                                    <?php
                                                    $ejecutado = (float)($ejecutadoPorPartida[$key] ?? 0.0);
                                                    $presu = (float)($item['unidades'] ?? 0.0);
                                                    $pendiente = max(0.0, $presu - $ejecutado);
                                                    $progreso = $presu > 0 ? max(0.0, min(100.0, ($ejecutado / $presu) * 100.0)) : 0.0;
                                                    $progresoRounded = (int)round($progreso);
                                                    $progresoVisual = $progresoRounded > 0 ? max(4, $progresoRounded) : 0;
                                                    $progresoClass = 'remaining-progress-fill is-low';
                                                    if ($progresoRounded >= 80) {
                                                        $progresoClass = 'remaining-progress-fill is-high';
                                                    } elseif ($progresoRounded >= 40) {
                                                        $progresoClass = 'remaining-progress-fill is-mid';
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td class="remaining-lote"><?php echo htmlspecialchars((string)$item['lote'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                        <td class="remaining-partida">
                                                            <?php echo htmlspecialchars((string)$item['descripcion'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                                        </td>
                                                        <td class="remaining-num">
                                                            <?php echo number_format($presu, 2, ',', '.'); ?>
                                                        </td>
                                                        <td class="remaining-num">
                                                            <?php echo number_format($ejecutado, 2, ',', '.'); ?>
                                                        </td>
                                                        <td class="remaining-num">
                                                            <?php echo number_format($pendiente, 2, ',', '.'); ?>
                                                        </td>
                                                        <td class="remaining-progress-cell">
                                                            <div class="remaining-progress-track">
                                                                <div
                                                                    class="<?php echo $progresoClass; ?>"
                                                                    style="width:<?php echo $progresoVisual; ?>%;"
                                                                ></div>
                                                            </div>
                                                            <div class="remaining-progress-value">
                                                                <?php echo $progresoRounded; ?> %
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
</body>
<script>
const PRODUCTOS_SEARCH_URL = <?php echo json_encode($productosSearchUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

// Tabs simples en cliente para navegar entre Presupuesto / Ejecucion / Remaining
document.addEventListener('DOMContentLoaded', function () {
    var triggers = Array.prototype.slice.call(document.querySelectorAll('.tab-trigger'));
    var contents = Array.prototype.slice.call(document.querySelectorAll('.tab-content'));
    function activarTab(tab) {
        if (!tab) return false;
        var btn = triggers.find(function (b) { return b.getAttribute('data-tab') === tab; });
        if (!btn) return false;
        triggers.forEach(function (b) { b.classList.remove('active'); });
        contents.forEach(function (c) { c.classList.remove('active'); });
        btn.classList.add('active');
        var target = document.getElementById('tab-' + tab);
        if (target) target.classList.add('active');
        return true;
    }
    triggers.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tab = btn.getAttribute('data-tab');
            if (!tab) return;
            activarTab(tab);
        });
    });

    // Mantener pestana segun query param (?tab=ejecucion|remaining|presupuesto)
    try {
        var params = new URLSearchParams(window.location.search || '');
        var tabFromUrl = params.get('tab');
        if (tabFromUrl) {
            activarTab(tabFromUrl);
        }
    } catch (e) {
        // Ignorar errores de parseo y mantener pestana por defecto.
    }
});

// Lotes: flujo en dos pasos (confirmar -> indicar cantidad)
document.addEventListener('DOMContentLoaded', function () {
    var buttons = Array.prototype.slice.call(document.querySelectorAll('.js-generate-lotes-flow'));
    if (buttons.length === 0) return;

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = btn.closest('form');
            if (!form) return;
            var countInput = form.querySelector('.js-generate-lotes-count');
            if (!countInput) return;

            var accepted = window.confirm('Quieres generar lotes para esta licitacion?');
            if (!accepted) return;

            var min = parseInt(btn.getAttribute('data-min') || '1', 10);
            var max = parseInt(btn.getAttribute('data-max') || '20', 10);
            if (!Number.isFinite(min)) min = 1;
            if (!Number.isFinite(max)) max = 20;
            if (max < min) {
                var tmp = min;
                min = max;
                max = tmp;
            }

            var current = String(countInput.value || '2').trim();
            if (current === '') current = '2';

            var answer = window.prompt('Cuantos lotes quieres generar?', current);
            if (answer === null) return;
            var cleaned = String(answer).trim();

            if (!/^\d+$/.test(cleaned)) {
                window.alert('Introduce un numero entero entre ' + min + ' y ' + max + '.');
                return;
            }

            var value = parseInt(cleaned, 10);
            if (!Number.isFinite(value) || value < min || value > max) {
                window.alert('Introduce un numero de lotes entre ' + min + ' y ' + max + '.');
                return;
            }

            countInput.value = String(value);
            form.submit();
        });
    });
});

// Presupuesto: mantener siempre una fila nueva vacia al final (sin boton "Anadir")
document.addEventListener('DOMContentLoaded', function () {
    var budgetForms = Array.prototype.slice.call(document.querySelectorAll('form.budget-table-form'));

    function isBudgetDecimalField(field) {
        if (!field || field.type !== 'number') return false;
        var name = String(field.getAttribute('name') || '');
        if (name === 'descuento_global') return true;
        return /\[(unidades|pmaxu|pvu|pcu)\]/.test(name);
    }

    function normalizeBudgetNumericFields(scope) {
        if (!scope || !scope.querySelectorAll) return;
        var numericFields = scope.querySelectorAll('input[type="number"]');
        numericFields.forEach(function (field) {
            if (isBudgetDecimalField(field)) {
                field.setAttribute('step', 'any');
            }
        });
    }

    budgetForms.forEach(function (form) {
        form.setAttribute('novalidate', 'novalidate');
        form.noValidate = true;
        normalizeBudgetNumericFields(form);
    });

    var tables = Array.prototype.slice.call(document.querySelectorAll('.budget-lines-table-editable'));
    if (tables.length === 0) return;

    var baseTable = tables[0];
    var isTipoDescuento = baseTable.getAttribute('data-tipo-descuento') === '1';
    var descuentoInput = document.getElementById('descuento-global-input');
    var btnAplicarDescuento = document.getElementById('btn-aplicar-descuento-global');

    function tableUsesUnidades(table) {
        return !!table && table.getAttribute('data-show-unidades') === '1';
    }

    function tableUsesPmaxu(table) {
        return !!table && table.getAttribute('data-show-pmaxu') === '1';
    }

    function parseNumber(v) {
        var txt = String(v == null ? '' : v).trim().replace(',', '.');
        if (txt === '') return 0;
        var n = parseFloat(txt);
        return Number.isFinite(n) ? n : 0;
    }

    function getDiscountPct() {
        if (!isTipoDescuento || !descuentoInput) return 0;
        var pct = parseNumber(descuentoInput.value);
        return pct >= 0 ? pct : 0;
    }

    function calcPvuFromDiscount(base) {
        if (!Number.isFinite(base) || base <= 0) return 0;
        var factor = 1 - getDiscountPct() / 100;
        if (factor < 0) factor = 0;
        return Math.round(base * factor * 100) / 100;
    }

    function recalcRowDiscountPvu(row) {
        if (!isTipoDescuento || !row) return;
        var rowTable = row.closest('.budget-lines-table-editable');
        if (!tableUsesPmaxu(rowTable)) return;
        var pmaxu = row.querySelector('input[name*=\"[pmaxu]\"]');
        var pvu = row.querySelector('input[name*=\"[pvu]\"]');
        if (!pmaxu || !pvu) return;
        var base = parseNumber(pmaxu.value);
        var calc = calcPvuFromDiscount(base);
        pvu.value = calc > 0 ? String(calc) : '';
    }

    function recalcAllDiscountRows() {
        if (!isTipoDescuento) return;
        tables.forEach(function (table) {
            var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
            rows.forEach(function (row) {
                recalcRowDiscountPvu(row);
                updateNewRowImporte(row);
            });
        });
    }

    function getNewRowsInTbody(tbody) {
        if (!tbody) return [];
        return Array.prototype.slice.call(tbody.querySelectorAll('.js-budget-new-row'));
    }

    function getAllNewRows() {
        var rows = [];
        tables.forEach(function (table) {
            var tbody = table.querySelector('tbody');
            rows = rows.concat(getNewRowsInTbody(tbody));
        });
        return rows;
    }

    function rowHasData(row) {
        if (!row) return false;
        var concept = row.querySelector('input[name*=\"[nombre_partida]\"]');
        var idProducto = row.querySelector('input[name*=\"[id_producto]\"]');
        var unidades = row.querySelector('input[name*=\"[unidades]\"]');
        var pmaxu = row.querySelector('input[name*=\"[pmaxu]\"]');
        var pvu = row.querySelector('input[name*=\"[pvu]\"]');
        var pcu = row.querySelector('input[name*=\"[pcu]\"]');

        var conceptTxt = concept ? String(concept.value || '').trim() : '';
        var productId = idProducto ? String(idProducto.value || '').trim() : '';
        var unidadesVal = unidades ? parseNumber(unidades.value) : 0;
        var pmaxuVal = pmaxu ? parseNumber(pmaxu.value) : 0;
        var pvuVal = pvu ? parseNumber(pvu.value) : 0;
        var pcuVal = pcu ? parseNumber(pcu.value) : 0;

        return conceptTxt !== ''
            || productId !== ''
            || unidadesVal > 0
            || pmaxuVal > 0
            || pvuVal > 0
            || pcuVal > 0;
    }

    function updateNewRowImporte(row) {
        if (!row) return;
        var importeCell = row.querySelector('.budget-new-importe');
        if (!importeCell) return;
        var rowTable = row.closest('.budget-lines-table-editable');
        var showUnidades = tableUsesUnidades(rowTable);
        var unidades = row.querySelector('input[name*=\"[unidades]\"]');
        var pvu = row.querySelector('input[name*=\"[pvu]\"]');
        var unidadesVal = unidades ? parseNumber(unidades.value) : 0;
        var pvuVal = pvu ? parseNumber(pvu.value) : 0;
        var importe = showUnidades ? (unidadesVal > 0 ? unidadesVal * pvuVal : pvuVal) : pvuVal;
        if (importe > 0) {
            importeCell.textContent = importe.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
            importeCell.textContent = '-';
        }
    }

    function bindNewRow(row) {
        if (!row || row.getAttribute('data-budget-bound') === '1') return;
        row.setAttribute('data-budget-bound', '1');
        normalizeBudgetNumericFields(row);
        var fields = row.querySelectorAll('input, select');
        fields.forEach(function (field) {
            field.addEventListener('input', function () {
                recalcRowDiscountPvu(row);
                updateNewRowImporte(row);
                ensureTrailingEmptyRows();
            });
            field.addEventListener('change', function () {
                recalcRowDiscountPvu(row);
                updateNewRowImporte(row);
                ensureTrailingEmptyRows();
            });
        });
    }

    function renumberRow(row, idx) {
        row.setAttribute('data-new-index', String(idx));
        var fields = row.querySelectorAll('input, select, textarea');
        fields.forEach(function (field) {
            var name = field.getAttribute('name');
            if (name) {
                field.setAttribute('name', name.replace(/lineas_nuevas\[\d+\]/, 'lineas_nuevas[' + idx + ']'));
            }
        });
    }

    function cloneAsNewRow(fromRow, idx) {
        var clone = fromRow.cloneNode(true);
        clone.setAttribute('data-budget-bound', '0');
        clone.setAttribute('data-budget-ac-bound', '0');
        clone.classList.add('js-budget-new-row');
        normalizeBudgetNumericFields(clone);
        renumberRow(clone, idx);

        var textInputs = clone.querySelectorAll('input[type=\"text\"], input[type=\"number\"], input[type=\"hidden\"]');
        textInputs.forEach(function (el) {
            if (el.type === 'hidden') {
                var hiddenDefault = el.getAttribute('data-default-value');
                el.value = hiddenDefault !== null ? hiddenDefault : '';
            } else {
                el.value = '';
            }
        });

        var selects = clone.querySelectorAll('select');
        selects.forEach(function (sel) {
            var defaultValue = sel.getAttribute('data-default-value');
            if (defaultValue !== null && defaultValue !== '') {
                sel.value = defaultValue;
                if (sel.value !== defaultValue) {
                    sel.selectedIndex = 0;
                }
            } else {
                sel.selectedIndex = 0;
            }
        });

        var suggest = clone.querySelector('.js-budget-suggest-box');
        if (suggest) {
            suggest.innerHTML = '';
        }
        var acStatus = clone.querySelector('.js-budget-ac-status');
        if (acStatus) {
            acStatus.textContent = '';
        }

        var hint = clone.querySelector('.budget-auto-add-hint');
        if (hint) {
            hint.textContent = 'Se crea otra fila automaticamente';
        }

        updateNewRowImporte(clone);
        return clone;
    }

    function ensureTrailingEmptyRowForTable(table) {
        if (!table) return;
        var tbody = table.querySelector('tbody');
        if (!tbody) return;

        var rows = getNewRowsInTbody(tbody);
        if (rows.length === 0) return;

        var activeEl = document.activeElement;
        var activeRow = activeEl && activeEl.closest ? activeEl.closest('.js-budget-new-row') : null;

        var dataRows = [];
        var emptyRows = [];
        rows.forEach(function (row) {
            if (rowHasData(row)) {
                dataRows.push(row);
            } else {
                emptyRows.push(row);
            }
        });

        var trailingEmpty = null;
        if (emptyRows.length > 0) {
            trailingEmpty = emptyRows[emptyRows.length - 1];
            if (activeRow && emptyRows.indexOf(activeRow) !== -1) {
                trailingEmpty = activeRow;
            }
        } else {
            var baseRow = dataRows.length > 0 ? dataRows[dataRows.length - 1] : rows[rows.length - 1];
            trailingEmpty = cloneAsNewRow(baseRow, getAllNewRows().length);
            tbody.appendChild(trailingEmpty);
        }

        rows.forEach(function (row) {
            if (row === trailingEmpty) return;
            if (!rowHasData(row) && row.parentNode === tbody) {
                tbody.removeChild(row);
            }
        });

        if (trailingEmpty && trailingEmpty.parentNode === tbody) {
            tbody.appendChild(trailingEmpty);
        }

        var normalizedRows = getNewRowsInTbody(tbody);
        normalizedRows.forEach(function (row, idx) {
            bindNewRow(row);
            recalcRowDiscountPvu(row);
            updateNewRowImporte(row);
        });

        if (normalizedRows.length === 0) {
            var fallback = cloneAsNewRow(rows[0], getAllNewRows().length);
            tbody.appendChild(fallback);
            bindNewRow(fallback);
            recalcRowDiscountPvu(fallback);
            updateNewRowImporte(fallback);
        }
    }

    function renumberAllNewRows() {
        var allRows = getAllNewRows();
        allRows.forEach(function (row, idx) {
            renumberRow(row, idx);
            bindNewRow(row);
            recalcRowDiscountPvu(row);
            updateNewRowImporte(row);
        });
    }

    function ensureTrailingEmptyRows() {
        tables.forEach(function (table) {
            ensureTrailingEmptyRowForTable(table);
        });
        renumberAllNewRows();
    }

    tables.forEach(function (table) {
        var tbody = table.querySelector('tbody');
        getNewRowsInTbody(tbody).forEach(function (row) {
            bindNewRow(row);
            recalcRowDiscountPvu(row);
            updateNewRowImporte(row);
        });
    });
    ensureTrailingEmptyRows();

    if (isTipoDescuento && descuentoInput) {
        descuentoInput.addEventListener('input', function () {
            recalcAllDiscountRows();
        });
        descuentoInput.addEventListener('change', function () {
            recalcAllDiscountRows();
        });
    }
    if (isTipoDescuento && btnAplicarDescuento) {
        btnAplicarDescuento.addEventListener('click', function (ev) {
            ev.preventDefault();
            recalcAllDiscountRows();
        });
    }
    recalcAllDiscountRows();
});

// Guardado asincrono para estado/cobrado de lineas de entrega
document.addEventListener('DOMContentLoaded', function () {
    var forms = Array.prototype.slice.call(
        document.querySelectorAll('.delivery-state-form, .delivery-cobrado-form')
    );
    if (forms.length === 0) return;

    function setSavingState(select, isSaving) {
        if (!select) return;
        if (isSaving) {
            select.classList.add('is-saving');
            select.setAttribute('aria-busy', 'true');
            select.disabled = true;
            return;
        }
        select.classList.remove('is-saving');
        select.removeAttribute('aria-busy');
        select.disabled = false;
    }

    function markSaved(select) {
        if (!select) return;
        select.classList.add('is-saved');
        window.setTimeout(function () {
            select.classList.remove('is-saved');
        }, 700);
    }

    function showInlineError(form, message) {
        if (!form) return;
        var old = form.querySelector('.delivery-inline-error');
        if (old && old.parentNode) {
            old.parentNode.removeChild(old);
        }
        var node = document.createElement('div');
        node.className = 'delivery-inline-error';
        node.textContent = message || 'No se pudo actualizar la linea.';
        form.appendChild(node);
        window.setTimeout(function () {
            if (node.parentNode) {
                node.parentNode.removeChild(node);
            }
        }, 3200);
    }

    function submitFormAjax(form) {
        var select = form.querySelector('select');
        if (!select) return;

        var prevValue = select.getAttribute('data-prev-value');
        if (prevValue === null) {
            prevValue = select.value;
            select.setAttribute('data-prev-value', prevValue);
        }

        var formData = new FormData(form);
        if (select.name && !formData.has(select.name)) {
            formData.set(select.name, select.value);
        }
        formData.append('ajax', '1');
        setSavingState(select, true);

        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (data) {
                    return {
                        ok: response.ok,
                        data: data
                    };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data || result.data.ok !== true) {
                    var errorMessage = (result.data && result.data.message)
                        ? String(result.data.message)
                        : 'No se pudo actualizar la linea.';
                    throw new Error(errorMessage);
                }
                select.setAttribute('data-prev-value', select.value);
                markSaved(select);
            })
            .catch(function (error) {
                if (prevValue !== null) {
                    select.value = prevValue;
                }
                showInlineError(form, error && error.message ? error.message : 'No se pudo actualizar la linea.');
            })
            .finally(function () {
                setSavingState(select, false);
            });
    }

    forms.forEach(function (form) {
        var select = form.querySelector('select');
        if (!select) return;

        select.setAttribute('data-prev-value', select.value);
        select.addEventListener('focus', function () {
            select.setAttribute('data-prev-value', select.value);
        });
        select.addEventListener('change', function () {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }
            var evt = document.createEvent('Event');
            evt.initEvent('submit', true, true);
            form.dispatchEvent(evt);
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitFormAjax(form);
        });
    });
});

// Modal "Cambiar estado"
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('btn-cambiar-estado');
    var modal = document.getElementById('modal-cambiar-estado');
    var btnClose = document.getElementById('modal-cambiar-estado-close');
    var btnCancel = document.getElementById('modal-cambiar-estado-cancel');

    if (!btn || !modal) return;

    function openModal() {
        modal.style.display = 'flex';
    }
    function closeModal() {
        modal.style.display = 'none';
    }

    btn.addEventListener('click', openModal);
    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCancel) btnCancel.addEventListener('click', closeModal);

    var closeByBackdropPress = false;
    modal.addEventListener('mousedown', function (e) {
        closeByBackdropPress = (e.target === modal);
    });
    modal.addEventListener('click', function (e) {
        if (closeByBackdropPress && e.target === modal) {
            closeModal();
        }
        closeByBackdropPress = false;
    });
});

// Modal "Detalle de perdida" para cambios de estado desde Presentada
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('modal-detalle-perdida-estado');
    if (!modal) return;

    var triggers = Array.prototype.slice.call(document.querySelectorAll('.js-open-loss-status-modal'));
    var btnClose = document.getElementById('modal-detalle-perdida-close');
    var btnCancel = document.getElementById('modal-detalle-perdida-cancel');
    var hiddenEstado = document.getElementById('status-loss-estado');
    var title = document.getElementById('status-loss-title');
    var intro = document.getElementById('status-loss-intro');
    var lotesWrap = document.getElementById('status-loss-lotes-wrap');
    var lotesText = document.getElementById('status-loss-lotes-text');
    var lotesRows = document.getElementById('status-loss-lotes-rows');

    function parseJsonData(raw, fallback) {
        if (!raw) return fallback;
        try {
            return JSON.parse(raw);
        } catch (e) {
            return fallback;
        }
    }

    function normalizeLotes(lotes) {
        if (!Array.isArray(lotes)) return [];
        var out = [];
        lotes.forEach(function (item) {
            var txt = String(item == null ? '' : item).trim();
            if (txt !== '') {
                out.push(txt);
            }
        });
        return out;
    }

    function createLossField(labelText, inputEl) {
        var label = document.createElement('label');
        label.className = 'status-loss-field';
        var span = document.createElement('span');
        span.textContent = labelText;
        label.appendChild(span);
        label.appendChild(inputEl);
        return label;
    }

    function renderLoteRows(lotes, valuesByKey) {
        if (!lotesRows) return;
        lotesRows.innerHTML = '';

        var normalizedLotes = normalizeLotes(lotes);
        if (normalizedLotes.length === 0) {
            normalizedLotes = ['General'];
        }

        normalizedLotes.forEach(function (loteName) {
            var row = document.createElement('div');
            row.className = 'status-loss-lote-row';

            var rowTitle = document.createElement('div');
            rowTitle.className = 'status-loss-lote-name';
            rowTitle.textContent = loteName;
            row.appendChild(rowTitle);

            var hiddenLote = document.createElement('input');
            hiddenLote.type = 'hidden';
            hiddenLote.name = 'lotes_perdidos[]';
            hiddenLote.value = loteName;
            row.appendChild(hiddenLote);

            var fieldsWrap = document.createElement('div');
            fieldsWrap.className = 'status-loss-lote-fields';
            var keyLote = loteName.toLowerCase();
            var lotValues = valuesByKey && valuesByKey[keyLote] ? valuesByKey[keyLote] : {};

            var ganadorInput = document.createElement('input');
            ganadorInput.type = 'text';
            ganadorInput.name = 'competidor_ganador_lote[]';
            ganadorInput.required = true;
            ganadorInput.value = lotValues.ganador ? String(lotValues.ganador) : '';

            var importeInput = document.createElement('input');
            importeInput.type = 'number';
            importeInput.name = 'importe_perdida_lote[]';
            importeInput.required = true;
            importeInput.min = '0.01';
            importeInput.step = '0.01';
            importeInput.value = lotValues.importe ? String(lotValues.importe) : '';

            fieldsWrap.appendChild(createLossField('Competidor ganador', ganadorInput));
            fieldsWrap.appendChild(createLossField('Importe ganador (EUR)', importeInput));
            row.appendChild(fieldsWrap);
            lotesRows.appendChild(row);
        });
    }

    function applyConfig(config) {
        if (!config) return;
        if (hiddenEstado) hiddenEstado.value = config.estado || '';
        if (title) title.textContent = config.title || 'Completar perdida';
        if (intro) intro.textContent = config.intro || 'Indica ganador e importe por cada lote afectado. El motivo es opcional.';
        var normalizedLotes = normalizeLotes(config.lotes || []);
        if (lotesWrap && lotesText) {
            lotesText.textContent = normalizedLotes.join(', ');
            if (normalizedLotes.length > 0) {
                lotesWrap.classList.remove('is-hidden');
            } else {
                lotesWrap.classList.add('is-hidden');
            }
        }
        renderLoteRows(normalizedLotes, config.values || {});
    }

    function openModal(config) {
        applyConfig(config);
        modal.style.display = 'flex';
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    triggers.forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            var statusModal = document.getElementById('modal-cambiar-estado');
            if (statusModal) {
                statusModal.style.display = 'none';
            }
            openModal({
                estado: trigger.getAttribute('data-estado') || '',
                title: trigger.getAttribute('data-title') || '',
                intro: trigger.getAttribute('data-intro') || '',
                lotes: parseJsonData(trigger.getAttribute('data-lotes-json'), []),
                values: {}
            });
        });
    });

    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCancel) btnCancel.addEventListener('click', closeModal);

    var closeByBackdropPress = false;
    modal.addEventListener('mousedown', function (e) {
        closeByBackdropPress = (e.target === modal);
    });
    modal.addEventListener('click', function (e) {
        if (closeByBackdropPress && e.target === modal) {
            closeModal();
        }
        closeByBackdropPress = false;
    });

    if (modal.getAttribute('data-open') === '1') {
        openModal({
            estado: modal.getAttribute('data-initial-state') || '',
            title: modal.getAttribute('data-initial-title') || '',
            intro: modal.getAttribute('data-initial-intro') || '',
            lotes: parseJsonData(modal.getAttribute('data-initial-lotes-json'), []),
            values: parseJsonData(modal.getAttribute('data-initial-values-json'), {})
        });
    }
});

// Modal "Nuevo albaran"
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('btn-nuevo-albaran');
    var modal = document.getElementById('modal-nuevo-albaran');
    var btnClose = document.getElementById('modal-nuevo-albaran-close');
    var btnCancel = document.getElementById('modal-nuevo-albaran-cancel');
    var btnTipoPresu = document.getElementById('btn-albaran-tipo-presu');
    var btnTipoExt = document.getElementById('btn-albaran-tipo-ext');
    var secPresu = document.getElementById('albaran-section-presu');
    var secExt = document.getElementById('albaran-section-ext');

    if (!btn || !modal) return;

    function limpiarEtiquetaGastosExtra() {
        if (!secExt) return;

        var textoObjetivo = 'gastos extraordinarios';
        var nodes = secExt.childNodes;
        for (var i = nodes.length - 1; i >= 0; i--) {
            var node = nodes[i];
            if (node.nodeType === 3) {
                var textoNode = (node.nodeValue || '').trim().toLowerCase();
                if (textoNode === textoObjetivo && node.parentNode) {
                    node.parentNode.removeChild(node);
                }
                continue;
            }

            if (node.nodeType !== 1) continue;
            var tagName = (node.tagName || '').toUpperCase();
            var textoElemento = (node.textContent || '').trim().toLowerCase();
            var esEtiquetaSimple = tagName === 'DIV' || tagName === 'SPAN' || tagName === 'P' || tagName === 'LABEL' || tagName === 'LEGEND';
            var contieneTabla = typeof node.querySelector === 'function' && node.querySelector('table') !== null;
            if (esEtiquetaSimple && !contieneTabla && textoElemento === textoObjetivo) {
                node.remove();
            }
        }

        var legends = secExt.querySelectorAll('legend');
        for (var j = legends.length - 1; j >= 0; j--) {
            var legend = legends[j];
            if ((legend.textContent || '').trim().toLowerCase() === textoObjetivo) {
                legend.remove();
            }
        }
    }

    function getSelectedProveedor(selectEl) {
        if (!selectEl || !selectEl.options || selectEl.selectedIndex < 0) return '';
        var selectedOpt = selectEl.options[selectEl.selectedIndex];
        if (!selectedOpt) return '';
        var proveedor = selectedOpt.getAttribute('data-proveedor');
        return typeof proveedor === 'string' ? proveedor.trim() : '';
    }

    function syncProveedorForRow(row) {
        if (!row) return;
        var partidaSelect = row.querySelector('.js-albaran-partida-select');
        var proveedorInput = row.querySelector('.js-albaran-proveedor-input');
        if (!partidaSelect || !proveedorInput) return;
        proveedorInput.value = getSelectedProveedor(partidaSelect);
    }

    function bindProveedorAutofill() {
        if (!secPresu) return;
        var rows = secPresu.querySelectorAll('tbody tr');
        rows.forEach(function (row) {
            var partidaSelect = row.querySelector('.js-albaran-partida-select');
            if (!partidaSelect) return;
            if (partidaSelect.getAttribute('data-prov-bound') !== '1') {
                partidaSelect.setAttribute('data-prov-bound', '1');
                partidaSelect.addEventListener('change', function () {
                    syncProveedorForRow(row);
                });
            }
            syncProveedorForRow(row);
        });
    }

    function openModal() {
        bindProveedorAutofill();
        modal.style.display = 'flex';
    }
    function closeModal() {
        modal.style.display = 'none';
    }
    function activarTipo(tipo) {
        if (!btnTipoPresu || !btnTipoExt || !secPresu || !secExt) return;
        if (tipo === 'presu') {
            secPresu.style.display = 'block';
            secExt.style.display = 'none';
            btnTipoPresu.classList.add('is-active');
            btnTipoExt.classList.remove('is-active');
        } else {
            secPresu.style.display = 'none';
            secExt.style.display = 'block';
            btnTipoExt.classList.add('is-active');
            btnTipoPresu.classList.remove('is-active');
        }
    }

    btn.addEventListener('click', openModal);
    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCancel) btnCancel.addEventListener('click', closeModal);
    if (btnTipoPresu) btnTipoPresu.addEventListener('click', function () { activarTipo('presu'); });
    if (btnTipoExt) btnTipoExt.addEventListener('click', function () { activarTipo('ext'); });

    // Tipo por defecto: partidas
    activarTipo('presu');
    limpiarEtiquetaGastosExtra();
    bindProveedorAutofill();

    var closeByBackdropPress = false;
    modal.addEventListener('mousedown', function (e) {
        closeByBackdropPress = (e.target === modal);
    });
    modal.addEventListener('click', function (e) {
        if (closeByBackdropPress && e.target === modal) {
            closeModal();
        }
        closeByBackdropPress = false;
    });
});

// Modal "Vincular productos ERP"
document.addEventListener('DOMContentLoaded', function () {
    var statusTriggers = Array.prototype.slice.call(document.querySelectorAll('.js-open-map-products-from-status'));
    var modal = document.getElementById('modal-vincular-productos');
    var btnClose = document.getElementById('modal-vincular-productos-close');
    var btnCancel = document.getElementById('modal-vincular-productos-cancel');

    if (!modal) return;

    function openModal() {
        modal.style.display = 'flex';
    }
    function closeModal() {
        modal.style.display = 'none';
    }

    statusTriggers.forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            var statusModal = document.getElementById('modal-cambiar-estado');
            if (statusModal) {
                statusModal.style.display = 'none';
            }
            openModal();
        });
    });
    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCancel) btnCancel.addEventListener('click', closeModal);
    var closeByBackdropPress = false;
    modal.addEventListener('mousedown', function (e) {
        closeByBackdropPress = (e.target === modal);
    });
    modal.addEventListener('click', function (e) {
        if (closeByBackdropPress && e.target === modal) {
            closeModal();
        }
        closeByBackdropPress = false;
    });

    if (modal.getAttribute('data-open') === '1') {
        openModal();
    }

    var inputs = Array.prototype.slice.call(modal.querySelectorAll('.map-product-input'));
    inputs.forEach(function (input) {
        var targetId = input.getAttribute('data-target-id');
        var detailId = input.getAttribute('data-detail-id');
        if (!targetId || !detailId) return;
        var hidden = document.getElementById(targetId);
        var box = document.getElementById('map-suggest-' + detailId);
        if (!hidden || !box) return;

        var timer = null;

        function clearBox() {
            box.innerHTML = '';
            box.style.display = 'none';
        }

        function renderSuggestions(items) {
            if (!Array.isArray(items) || items.length === 0) {
                clearBox();
                return;
            }
            box.innerHTML = '';
            items.forEach(function (it) {
                var row = document.createElement('button');
                row.type = 'button';
                row.className = 'map-product-option';
                var proveedor = typeof it.nombre_proveedor === 'string' ? it.nombre_proveedor.trim() : '';
                var label = (it.nombre || '') + (it.referencia ? ' (' + it.referencia + ')' : '');
                if (proveedor !== '') {
                    label += ' - Proveedor: ' + proveedor;
                }
                row.textContent = label;
                row.addEventListener('click', function () {
                    input.value = it.nombre || '';
                    hidden.value = String(it.id_producto || '');
                    clearBox();
                });
                box.appendChild(row);
            });
            box.style.display = 'block';
        }

        input.addEventListener('input', function () {
            hidden.value = '';
            var q = input.value.trim();
            if (timer) window.clearTimeout(timer);
            if (q.length < 2) {
                clearBox();
                return;
            }
            timer = window.setTimeout(function () {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', PRODUCTOS_SEARCH_URL + '?q=' + encodeURIComponent(q) + '&limit=0', true);
                xhr.onreadystatechange = function () {
                    if (xhr.readyState !== 4) return;
                    if (xhr.status === 200) {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            renderSuggestions(Array.isArray(data) ? data : []);
                        } catch (e) {
                            clearBox();
                        }
                    } else {
                        clearBox();
                    }
                };
                xhr.send();
            }, 220);
        });

        document.addEventListener('click', function (e) {
            if (!box.contains(e.target) && e.target !== input) {
                clearBox();
            }
        });
    });
});

// Autocompletado de productos en "Nuevo concepto" (Presupuesto)
document.addEventListener('DOMContentLoaded', function () {
    var tables = Array.prototype.slice.call(document.querySelectorAll('.budget-lines-table-editable'));
    if (tables.length === 0) return;

    function bindRowAutocomplete(row) {
        if (!row || row.getAttribute('data-budget-ac-bound') === '1') {
            return;
        }
        var input = row.querySelector('.js-budget-concept-input');
        var hiddenId = row.querySelector('.js-budget-product-id');
        var box = row.querySelector('.js-budget-suggest-box');
        if (!input || !hiddenId || !box) {
            return;
        }

        row.setAttribute('data-budget-ac-bound', '1');
        var timer = null;
        var requestId = 0;
        var suggestions = [];
        var activeIndex = -1;

        function clearBox() {
            box.innerHTML = '';
            box.style.display = 'none';
            suggestions = [];
            activeIndex = -1;
            row.classList.remove('is-suggest-open');
        }

        function renderInfoRow(texto) {
            box.innerHTML = '';
            suggestions = [];
            activeIndex = -1;
            var info = document.createElement('div');
            info.style.padding = '7px 8px';
            info.style.fontSize = '0.8rem';
            info.style.color = '#85725e';
            info.textContent = texto;
            box.appendChild(info);
            box.style.display = 'block';
            row.classList.add('is-suggest-open');
        }

        function updateActiveOption() {
            var rows = Array.prototype.slice.call(box.querySelectorAll('.product-suggest-option'));
            rows.forEach(function (optRow, idx) {
                if (idx === activeIndex) {
                    optRow.style.background = 'rgba(142,139,48,0.12)';
                } else {
                    optRow.style.background = '#fff';
                }
            });
        }

        function aplicarSeleccion(it) {
            input.value = typeof it.nombre === 'string' ? it.nombre : '';
            hiddenId.value = String(it.id_producto || '');
            clearBox();
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function renderSuggestions(items) {
            suggestions = Array.isArray(items) ? items : [];
            activeIndex = -1;
            if (suggestions.length === 0) {
                renderInfoRow('Sin coincidencias');
                return;
            }
            box.innerHTML = '';
            suggestions.forEach(function (it, idx) {
                var opt = document.createElement('button');
                opt.type = 'button';
                opt.className = 'product-suggest-option';
                opt.style.width = '100%';
                opt.style.border = 'none';
                opt.style.borderBottom = '1px solid rgba(133,114,94,0.25)';
                opt.style.background = '#fff';
                opt.style.textAlign = 'left';
                opt.style.color = '#10180e';
                opt.style.fontSize = '0.8rem';
                opt.style.padding = '7px 8px';
                opt.style.cursor = 'pointer';
                var proveedor = typeof it.nombre_proveedor === 'string' ? it.nombre_proveedor.trim() : '';
                var label = (it.nombre || '') + (it.referencia ? ' (' + it.referencia + ')' : '');
                if (proveedor !== '') {
                    label += ' - Proveedor: ' + proveedor;
                }
                opt.textContent = label;

                opt.addEventListener('mouseenter', function () {
                    activeIndex = idx;
                    updateActiveOption();
                });
                opt.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    aplicarSeleccion(it);
                });

                box.appendChild(opt);
            });
            updateActiveOption();
            box.style.display = 'block';
            row.classList.add('is-suggest-open');
        }

        function lanzarBusqueda(forceImmediate) {
            var q = input.value.trim();
            hiddenId.value = '';
            if (timer) window.clearTimeout(timer);
            if (q.length < 2) {
                clearBox();
                return;
            }

            var execute = function () {
                var currentRequestId = ++requestId;
                renderInfoRow('Buscando...');
                var xhr = new XMLHttpRequest();
                xhr.open('GET', PRODUCTOS_SEARCH_URL + '?q=' + encodeURIComponent(q) + '&limit=0', true);
                xhr.onreadystatechange = function () {
                    if (xhr.readyState !== 4) return;
                    if (currentRequestId !== requestId) return;
                    if (xhr.status === 200) {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            renderSuggestions(Array.isArray(data) ? data : []);
                        } catch (e) {
                            renderInfoRow('No se pudo procesar la busqueda');
                        }
                    } else {
                        renderInfoRow('No se pudo cargar sugerencias (HTTP ' + xhr.status + ')');
                    }
                };
                xhr.send();
            };

            if (forceImmediate) {
                execute();
                return;
            }
            timer = window.setTimeout(execute, 180);
        }

        input.addEventListener('input', function () {
            lanzarBusqueda(false);
        });

        input.addEventListener('focus', function () {
            if (input.value.trim().length > 0) {
                lanzarBusqueda(true);
            }
        });

        input.addEventListener('keydown', function (e) {
            if (box.style.display === 'none' || suggestions.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = activeIndex < suggestions.length - 1 ? activeIndex + 1 : 0;
                updateActiveOption();
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = activeIndex > 0 ? activeIndex - 1 : suggestions.length - 1;
                updateActiveOption();
                return;
            }
            if (e.key === 'Enter' && activeIndex >= 0 && suggestions[activeIndex]) {
                e.preventDefault();
                aplicarSeleccion(suggestions[activeIndex]);
                return;
            }
            if (e.key === 'Escape') {
                clearBox();
            }
        });

        document.addEventListener('click', function (e) {
            if (!box.contains(e.target) && e.target !== input) {
                clearBox();
            }
        });
    }

    tables.forEach(function (table) {
        var tbody = table.querySelector('tbody');
        if (!tbody) return;

        Array.prototype.slice.call(tbody.querySelectorAll('.js-budget-new-row')).forEach(bindRowAutocomplete);

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                Array.prototype.slice.call(mutation.addedNodes).forEach(function (node) {
                    if (!node || node.nodeType !== 1) return;
                    if (node.classList && node.classList.contains('js-budget-new-row')) {
                        bindRowAutocomplete(node);
                    }
                });
            });
        });
        observer.observe(tbody, { childList: true });
    });
});
</script>
</html>




