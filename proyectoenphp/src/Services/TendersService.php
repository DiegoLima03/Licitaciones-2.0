<?php

declare(strict_types=1);

require_once __DIR__ . '/../Repositories/TendersRepository.php';
require_once __DIR__ . '/../../config/database.php';

final class NotFoundException extends \RuntimeException
{
}

final class ConflictException extends \RuntimeException
{
}

final class TendersService
{
    private TendersRepository $tendersRepository;
    private \PDO $pdo;

    // Estados de licitación (equivalente a EstadoLicitacion en Python)
    private const ESTADO_DESCARTADA = 2;
    private const ESTADO_EN_ANALISIS = 3;
    private const ESTADO_PRESENTADA = 4;
    private const ESTADO_ADJUDICADA = 5;
    private const ESTADO_NO_ADJUDICADA = 6;
    private const ESTADO_TERMINADA = 7;

    /**
     * Estados a partir de los cuales no se pueden editar campos económicos ni partidas.
     *
     * @var int[]
     */
    private const ESTADOS_BLOQUEO_EDICION = [
        self::ESTADO_PRESENTADA,
        self::ESTADO_ADJUDICADA,
        self::ESTADO_NO_ADJUDICADA,
        self::ESTADO_TERMINADA,
    ];

    /**
     * @param TendersRepository $tendersRepository Repositorio de licitaciones inyectado (DI).
     */
    public function __construct(TendersRepository $tendersRepository)
    {
        $this->tendersRepository = $tendersRepository;
        $this->pdo = Database::getConnection();
    }

    /**
     * Lista licitaciones con filtros opcionales.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTenders(
        ?int $estadoId = null,
        ?string $nombre = null,
        ?string $pais = null
    ): array {
        return $this->tendersRepository->listTenders($estadoId, $nombre, $pais);
    }

    /**
     * Detalle de licitación con partidas. Lanza NotFoundException si no existe.
     *
     * @return array<string, mixed>
     */
    public function getTender(int $tenderId): array
    {
        $out = $this->tendersRepository->getTenderWithDetails($tenderId);
        if ($out === null || $out === []) {
            throw new NotFoundException('Licitación no encontrada.');
        }

        return $out;
    }

    /**
     * Licitaciones AM/SDA adjudicadas para usar como padre al crear CONTRATO_BASADO.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getParentTenders(): array
    {
        return $this->tendersRepository->getParentTenders();
    }

    /**
     * Crea una licitación en estado EN_ANALISIS.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createTender(array $payload): array
    {
        $fechaPresentacion = $payload['fecha_presentacion'] ?? null;
        $fechaAdjudicacion = $payload['fecha_adjudicacion'] ?? null;

        if (is_string($fechaPresentacion) && $fechaPresentacion !== '' &&
            is_string($fechaAdjudicacion) && $fechaAdjudicacion !== '' &&
            $fechaPresentacion > $fechaAdjudicacion
        ) {
            throw new \InvalidArgumentException(
                'La fecha de presentación debe ser anterior o igual a la fecha de adjudicación.'
            );
        }

        $idLicitacionPadre = $payload['id_licitacion_padre'] ?? null;
        if ($idLicitacionPadre !== null && $idLicitacionPadre !== '') {
            $tipoProcedimiento = 'CONTRATO_BASADO';
        } else {
            $tipoProcedimiento = isset($payload['tipo_procedimiento']) && $payload['tipo_procedimiento'] !== ''
                ? (string)$payload['tipo_procedimiento']
                : 'ORDINARIO';
        }

        $row = [
            'nombre' => (string)($payload['nombre'] ?? ''),
            'pais' => (string)($payload['pais'] ?? ''),
            'numero_expediente' => isset($payload['numero_expediente'])
                ? (string)$payload['numero_expediente']
                : '',
            'pres_maximo' => isset($payload['pres_maximo'])
                ? (float)$payload['pres_maximo']
                : 0.0,
            'descripcion' => isset($payload['descripcion'])
                ? (string)$payload['descripcion']
                : '',
            'enlace_gober' => $this->mutateGoberUrl(
                isset($payload['enlace_gober']) ? (string)$payload['enlace_gober'] : null
            ),
            'enlace_sharepoint' => isset($payload['enlace_sharepoint'])
                ? (trim((string)$payload['enlace_sharepoint']) ?: null)
                : null,
            'id_estado' => self::ESTADO_EN_ANALISIS,
            'id_tipolicitacion' => $payload['id_tipolicitacion'] ?? null,
            'fecha_presentacion' => $fechaPresentacion ?: null,
            'fecha_adjudicacion' => $fechaAdjudicacion ?: null,
            'fecha_finalizacion' => $payload['fecha_finalizacion'] ?? null,
            'tipo_procedimiento' => $tipoProcedimiento,
            'id_licitacion_padre' => $idLicitacionPadre !== null ? (int)$idLicitacionPadre : null,
        ];

        return $this->tendersRepository->create($row);
    }

    /**
     * Actualiza una licitación con reglas de negocio sobre estados y campos bloqueados.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateTender(int $tenderId, array $payload): array
    {
        // Asegura existencia
        $this->getTender($tenderId);

        $updateData = $payload;
        if ($updateData === []) {
            return $this->getTender($tenderId);
        }

        if (array_key_exists('enlace_gober', $updateData)) {
            $updateData['enlace_gober'] = $this->mutateGoberUrl(
                $updateData['enlace_gober'] !== null ? (string)$updateData['enlace_gober'] : null
            );
        }

        if (array_key_exists('lotes_config', $updateData)) {
            $updateData['lotes_config'] = $this->normalizeLotesConfig($updateData['lotes_config']);
        }

        if ($this->isEditionBlocked($tenderId)) {
            $camposBloqueados = [
                'pres_maximo',
                'descuento_global',
                'id_estado',
                'fecha_presentacion',
                'fecha_adjudicacion',
                'fecha_finalizacion',
                'pais',
            ];

            $camposPermitidosCuandoBloqueado = [
                'descripcion',
                'nombre',
                'numero_expediente',
                'id_tipolicitacion',
                'enlace_gober',
                'enlace_sharepoint',
                'lotes_config',
                'tipo_procedimiento',
                'id_licitacion_padre',
            ];

            $bloqueados = array_values(
                array_intersect(array_keys($updateData), $camposBloqueados)
            );

            if ($bloqueados !== []) {
                sort($bloqueados);
                throw new \InvalidArgumentException(
                    'No se pueden modificar campos económicos ni fechas cuando la licitación está '
                    . 'presentada o posterior. Use change-status para cambiar estado. '
                    . 'Campos bloqueados enviados: ' . implode(', ', $bloqueados)
                );
            }

            $updateData = array_filter(
                $updateData,
                static fn (string $key) => in_array($key, $camposPermitidosCuandoBloqueado, true),
                ARRAY_FILTER_USE_KEY
            );
        }

        return $this->tendersRepository->update($tenderId, $updateData);
    }

    /**
     * Elimina una licitación. Lanza NotFoundException si no existe.
     */
    public function deleteTender(int $tenderId): void
    {
        $existing = $this->tendersRepository->getById($tenderId);
        if ($existing === null) {
            throw new NotFoundException('Licitación no encontrada.');
        }

        $this->tendersRepository->delete($tenderId);
    }

    /**
     * Cambia el estado de una licitación respetando la máquina de estados.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function changeTenderStatus(int $tenderId, array $payload): array
    {
        $licitacion = $this->tendersRepository->getById($tenderId);
        if ($licitacion === null) {
            throw new NotFoundException('Licitación no encontrada.');
        }

        $idEstadoActual = (int)($licitacion['id_estado'] ?? 0);
        $nuevoId = (int)($payload['nuevo_estado_id'] ?? 0);

        if ($idEstadoActual === $nuevoId) {
            return array_merge($licitacion, ['message' => 'El estado ya era el solicitado.']);
        }

        $estadosValidos = [
            self::ESTADO_DESCARTADA,
            self::ESTADO_EN_ANALISIS,
            self::ESTADO_PRESENTADA,
            self::ESTADO_ADJUDICADA,
            self::ESTADO_NO_ADJUDICADA,
            self::ESTADO_TERMINADA,
        ];

        if (!in_array($nuevoId, $estadosValidos, true)) {
            throw new \InvalidArgumentException(sprintf('Estado %d no válido.', $nuevoId));
        }

        $updateData = ['id_estado' => $nuevoId];

        if ($nuevoId === self::ESTADO_ADJUDICADA && isset($payload['importe_adjudicacion'])) {
            $importeAdjudicacion = (float)$payload['importe_adjudicacion'];
            $updateData['pres_maximo'] = $importeAdjudicacion;
        }

        if (!empty($payload['fecha_adjudicacion'])) {
            $updateData['fecha_adjudicacion'] = (string)$payload['fecha_adjudicacion'];
        }

        if ($nuevoId === self::ESTADO_PRESENTADA && empty($licitacion['fecha_presentacion'])) {
            $updateData['fecha_presentacion'] = (new \DateTimeImmutable('today'))->format('Y-m-d');
        }

        if ($nuevoId === self::ESTADO_DESCARTADA && !empty($payload['motivo_descarte'])) {
            $desc = (string)($licitacion['descripcion'] ?? '');
            $updateData['descripcion'] = trim(
                $desc . "\n[MOTIVO DESCARTE]: " . (string)$payload['motivo_descarte']
            );
        }

        if (
            $nuevoId === self::ESTADO_NO_ADJUDICADA
            && (!empty($payload['motivo_perdida']) || !empty($payload['competidor_ganador']))
        ) {
            $desc = (string)($licitacion['descripcion'] ?? '');
            $partes = [];
            if (!empty($payload['motivo_perdida'])) {
                $partes[] = 'Motivo: ' . (string)$payload['motivo_perdida'];
            }
            if (!empty($payload['competidor_ganador'])) {
                $partes[] = 'Ganador: ' . (string)$payload['competidor_ganador'];
            }
            $updateData['descripcion'] = trim(
                $desc . "\n[PERDIDA]: " . implode(' | ', $partes)
            );
        }

        // Validación diferida ERP: solo al pasar a ADJUDICADA.
        if ($nuevoId === self::ESTADO_ADJUDICADA) {
            $detalle = $this->tendersRepository->getTenderWithDetails($tenderId);
            $partidas = $detalle['partidas'] ?? [];

            $lotesConfig = $licitacion['lotes_config'] ?? [];
            $lotesGanados = [];
            if (is_array($lotesConfig)) {
                foreach ($lotesConfig as $l) {
                    if (is_array($l) && !empty($l['ganado']) && isset($l['nombre'])) {
                        $lotesGanados[] = $l['nombre'];
                    }
                }
            }
            $tieneLotes = !empty($lotesConfig);

            $partidasActivas = [];
            foreach ($partidas as $p) {
                if (!is_array($p)) {
                    continue;
                }
                if (!empty($p['activo']) && (!$tieneLotes || in_array($p['lote'] ?? null, $lotesGanados, true))) {
                    $partidasActivas[] = $p;
                }
            }

            $sinProducto = array_filter(
                $partidasActivas,
                static fn (array $p): bool => !isset($p['id_producto']) || $p['id_producto'] === null
            );

            if ($sinProducto !== []) {
                $idsDetalle = [];
                foreach ($sinProducto as $p) {
                    if (isset($p['id_detalle'])) {
                        $idsDetalle[] = $p['id_detalle'];
                    }
                }
                error_log(
                    '[TenderService] Adjudicación rechazada: partidas sin id_producto (ERP Belneo) en lotes ganados. id_detalle='
                    . json_encode($idsDetalle)
                );

                throw new \InvalidArgumentException(
                    'Para adjudicar, todas las líneas de presupuesto de los lotes ganados deben tener un producto de Belneo (id_producto). '
                    . 'Partidas con solo nombre libre no son válidas. Corrija las partidas de los lotes ganados y vuelva a intentar.'
                );
            }
        }

        $result = $this->tendersRepository->updateTenderWithStateCheck(
            $tenderId,
            $updateData,
            $idEstadoActual
        );

        if ($result === null) {
            throw new ConflictException(
                'Conflicto de concurrencia: el estado de la licitación cambió. Recarga y vuelve a intentar.'
            );
        }

        return array_merge($result, ['message' => 'Estado actualizado correctamente.']);
    }

    /**
     * Añade una partida a la licitación con validaciones de estado.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function addPartida(int $tenderId, array $payload): array
    {
        if ($this->tendersRepository->getById($tenderId) === null) {
            throw new NotFoundException('Licitación no encontrada.');
        }

        if ($this->isEditionBlocked($tenderId)) {
            throw new \InvalidArgumentException(
                'No se pueden modificar partidas cuando la licitación ya está presentada o posterior.'
            );
        }

        $row = [
            'lote' => isset($payload['lote']) && $payload['lote'] !== ''
                ? (string)$payload['lote']
                : 'General',
            'id_producto' => $payload['id_producto'] ?? null,
            'nombre_producto_libre' => ($payload['id_producto'] ?? null) === null
                ? ($payload['nombre_producto_libre'] ?? null)
                : null,
            'unidades' => isset($payload['unidades'])
                ? (float)$payload['unidades']
                : 1.0,
            'pvu' => isset($payload['pvu']) ? (float)$payload['pvu'] : 0.0,
            'pcu' => isset($payload['pcu']) ? (float)$payload['pcu'] : 0.0,
            'pmaxu' => isset($payload['pmaxu']) ? (float)$payload['pmaxu'] : 0.0,
            'activo' => array_key_exists('activo', $payload) ? (bool)$payload['activo'] : true,
        ];

        return $this->tendersRepository->addPartida($tenderId, $row);
    }

    /**
     * Actualiza una partida con reglas de edición bloqueada.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updatePartida(int $tenderId, int $detalleId, array $payload): array
    {
        if ($this->tendersRepository->getById($tenderId) === null) {
            throw new NotFoundException('Licitación no encontrada.');
        }

        $updateData = $payload;

        if ($this->isEditionBlocked($tenderId)) {
            $allowedWhenBlocked = ['id_producto', 'nombre_producto_libre'];
            $keys = array_keys($updateData);
            foreach ($keys as $key) {
                if (!in_array($key, $allowedWhenBlocked, true)) {
                    throw new \InvalidArgumentException(
                        'No se pueden modificar partidas cuando la licitación ya está presentada o posterior.'
                    );
                }
            }
        }

        if ($updateData === []) {
            $partida = $this->tendersRepository->getPartida($tenderId, $detalleId);
            if ($partida === null) {
                throw new NotFoundException('Partida no encontrada.');
            }
            return $partida;
        }

        try {
            return $this->tendersRepository->updatePartida($tenderId, $detalleId, $updateData);
        } catch (\InvalidArgumentException) {
            throw new NotFoundException('Partida no encontrada.');
        }
    }

    /**
     * Elimina una partida con reglas de edición bloqueada.
     */
    public function deletePartida(int $tenderId, int $detalleId): void
    {
        if ($this->tendersRepository->getById($tenderId) === null) {
            throw new NotFoundException('Licitación no encontrada.');
        }

        if ($this->isEditionBlocked($tenderId)) {
            throw new \InvalidArgumentException(
                'No se pueden modificar partidas cuando la licitación ya está presentada o posterior.'
            );
        }

        try {
            $this->tendersRepository->deletePartida($tenderId, $detalleId);
        } catch (\InvalidArgumentException) {
            throw new NotFoundException('Partida no encontrada.');
        }
    }

    /**
     * True si la licitación está en un estado que bloquea edición económica.
     */
    private function isEditionBlocked(int $tenderId): bool
    {
        $lic = $this->tendersRepository->getById($tenderId);
        if ($lic === null) {
            return true;
        }

        $idEstado = (int)($lic['id_estado'] ?? 0);
        return in_array($idEstado, self::ESTADOS_BLOQUEO_EDICION, true);
    }

    /**
     * Mutador de URL de Gover: /tenders/ -> /public/.
     */
    private function mutateGoberUrl(?string $url): ?string
    {
        if ($url === null || $url === '' || strpos($url, '/tenders/') === false) {
            return $url;
        }

        return preg_replace('#/tenders/#', '/public/', $url, 1) ?? $url;
    }

    /**
     * Normaliza lotes_config a lista simple (nombre, ganado).
     *
     * @param mixed $lotes
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLotesConfig($lotes): array
    {
        if (!is_array($lotes) || $lotes === []) {
            return [];
        }

        $out = [];
        foreach ($lotes as $x) {
            if (!is_array($x)) {
                continue;
            }

            $nombre = isset($x['nombre']) ? trim((string)$x['nombre']) : '';
            $out[] = [
                'nombre' => $nombre !== '' ? $nombre : 'Lote',
                'ganado' => !empty($x['ganado']),
            ];
        }

        return $out;
    }
}

