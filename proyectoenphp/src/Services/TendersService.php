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
     * Licitaciones AM/SDA adjudicadas para usar como padre al crear licitaciones derivadas.
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

        $tipoProcedimiento = isset($payload['tipo_procedimiento']) && $payload['tipo_procedimiento'] !== ''
            ? mb_strtoupper(trim((string)$payload['tipo_procedimiento']), 'UTF-8')
            : 'ORDINARIO';

        $tiposPermitidos = ['ORDINARIO', 'ACUERDO_MARCO', 'SDA', 'CONTRATO_BASADO', 'ESPECIFICO_SDA'];
        if (!in_array($tipoProcedimiento, $tiposPermitidos, true)) {
            throw new \InvalidArgumentException('Tipo de procedimiento no valido.');
        }

        $idLicitacionPadre = $payload['id_licitacion_padre'] ?? null;
        $hasParent = $idLicitacionPadre !== null && (string)$idLicitacionPadre !== '';
        $tiposDerivados = ['CONTRATO_BASADO', 'ESPECIFICO_SDA'];
        $requierePadre = in_array($tipoProcedimiento, $tiposDerivados, true);

        if ($requierePadre && !$hasParent) {
            $msg = $tipoProcedimiento === 'ESPECIFICO_SDA'
                ? 'Debes indicar una licitacion padre SDA para especifico SDA.'
                : 'Debes indicar una licitacion padre AM para contrato basado.';
            throw new \InvalidArgumentException($msg);
        }

        if (!$requierePadre && $hasParent) {
            throw new \InvalidArgumentException(
                'Solo los procedimientos derivados (contrato basado/especifico SDA) admiten licitacion padre.'
            );
        }

        if ($hasParent) {
            if (!is_numeric((string)$idLicitacionPadre) || (int)$idLicitacionPadre <= 0) {
                throw new \InvalidArgumentException('El id_licitacion_padre es invalido.');
            }

            $idLicitacionPadre = (int)$idLicitacionPadre;
            $padre = $this->tendersRepository->getById($idLicitacionPadre);
            if ($padre === null) {
                throw new \InvalidArgumentException('La licitacion padre indicada no existe.');
            }

            $tipoPadre = mb_strtoupper(trim((string)($padre['tipo_procedimiento'] ?? '')), 'UTF-8');
            $estadoPadre = (int)($padre['id_estado'] ?? 0);
            if ($estadoPadre !== self::ESTADO_ADJUDICADA) {
                throw new \InvalidArgumentException('La licitacion padre debe estar en estado Adjudicada.');
            }

            if ($tipoProcedimiento === 'CONTRATO_BASADO' && $tipoPadre !== 'ACUERDO_MARCO') {
                throw new \InvalidArgumentException(
                    'Contrato basado solo puede colgar de un Acuerdo Marco adjudicado.'
                );
            }
            if ($tipoProcedimiento === 'ESPECIFICO_SDA' && $tipoPadre !== 'SDA') {
                throw new \InvalidArgumentException(
                    'Especifico SDA solo puede colgar de un SDA adjudicado.'
                );
            }
        } else {
            $idLicitacionPadre = null;
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
            'id_licitacion_padre' => $idLicitacionPadre,
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

        $transicionesPermitidas = [];
        if ($idEstadoActual === 1 || $idEstadoActual === self::ESTADO_EN_ANALISIS) {
            $transicionesPermitidas = [
                self::ESTADO_PRESENTADA => 'Presentada',
                self::ESTADO_DESCARTADA => 'Descartada',
            ];
        } elseif ($idEstadoActual === self::ESTADO_PRESENTADA) {
            $transicionesPermitidas = [
                self::ESTADO_ADJUDICADA => 'Adjudicada',
                self::ESTADO_NO_ADJUDICADA => 'No adjudicada',
            ];
        } elseif ($idEstadoActual === self::ESTADO_ADJUDICADA) {
            $transicionesPermitidas = [
                self::ESTADO_TERMINADA => 'Terminada',
            ];
        }

        if (!array_key_exists($nuevoId, $transicionesPermitidas)) {
            throw new \InvalidArgumentException('TransiciÃ³n de estado no permitida desde el estado actual.');
        }

        $updateData = ['id_estado' => $nuevoId];
        $lotesConfig = $this->normalizeLotesConfig($licitacion['lotes_config'] ?? null);
        $lostLotes = $this->extractLostLotes($lotesConfig);
        $allLotesLost = $lotesConfig !== [] && count($lostLotes) === count($lotesConfig);
        $motivoPerdida = isset($payload['motivo_perdida']) ? trim((string)$payload['motivo_perdida']) : '';
        $competidorGanador = isset($payload['competidor_ganador']) ? trim((string)$payload['competidor_ganador']) : '';
        $importePerdida = $this->parsePositiveAmount($payload['importe_perdida'] ?? null);

        if ($nuevoId === self::ESTADO_ADJUDICADA && isset($payload['importe_adjudicacion'])) {
            $importeAdjudicacion = (float)$payload['importe_adjudicacion'];
            $updateData['pres_maximo'] = $importeAdjudicacion;
        }

        if (!empty($payload['fecha_adjudicacion'])) {
            $updateData['fecha_adjudicacion'] = (string)$payload['fecha_adjudicacion'];
        }

        if ($nuevoId === self::ESTADO_PRESENTADA) {
            $detallePresentacion = $this->tendersRepository->getTenderWithDetails($tenderId);
            $partidasPresentacion = is_array($detallePresentacion['partidas'] ?? null)
                ? $detallePresentacion['partidas']
                : [];

            $tienePartidasActivas = false;
            foreach ($partidasPresentacion as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
                if ($activo) {
                    $tienePartidasActivas = true;
                    break;
                }
            }

            if (!$tienePartidasActivas) {
                throw new \InvalidArgumentException(
                    'No puedes pasar a Presentada sin al menos una linea presupuestada.'
                );
            }

            if (empty($licitacion['fecha_presentacion'])) {
                $updateData['fecha_presentacion'] = (new \DateTimeImmutable('today'))->format('Y-m-d');
            }
        }

        if ($nuevoId === self::ESTADO_DESCARTADA && !empty($payload['motivo_descarte'])) {
            $desc = (string)($licitacion['descripcion'] ?? '');
            $updateData['descripcion'] = trim(
                $desc . "\n[MOTIVO DESCARTE]: " . (string)$payload['motivo_descarte']
            );
        }

        if ($nuevoId === self::ESTADO_NO_ADJUDICADA) {
            if ($competidorGanador === '' || $importePerdida === null) {
                throw new \InvalidArgumentException(
                    'Para marcar la licitacion como perdida debes indicar ganador e importe.'
                );
            }

            if ($lotesConfig !== []) {
                $updateData['lotes_config'] = $this->markAllLotesAsLost($lotesConfig);
            }

            $updateData['descripcion'] = $this->appendLossDescription(
                (string)($licitacion['descripcion'] ?? ''),
                'PERDIDA TOTAL',
                $motivoPerdida,
                $competidorGanador,
                $importePerdida,
                array_map(
                    static fn (array $item): string => (string)$item['nombre'],
                    $lotesConfig
                )
            );
        }

        // Validación diferida ERP: solo al pasar a ADJUDICADA.
        if ($nuevoId === self::ESTADO_ADJUDICADA) {
            if ($allLotesLost) {
                throw new \InvalidArgumentException(
                    'No puedes marcar Adjudicada si todos los lotes estan perdidos. Usa Marcar como Perdida.'
                );
            }

            $detalle = $this->tendersRepository->getTenderWithDetails($tenderId);
            $partidas = $detalle['partidas'] ?? [];

            $partidasActivas = [];
            foreach ($partidas as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $activo = array_key_exists('activo', $p) ? (bool)$p['activo'] : true;
                if ($activo) {
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
                    '[TenderService] Adjudicacion rechazada: partidas sin id_producto (ERP Belneo) en lineas activas. id_detalle='
                    . json_encode($idsDetalle)
                );

                throw new \InvalidArgumentException(
                    'Para adjudicar, todas las lineas de presupuesto activas deben tener un producto de Belneo (id_producto). '
                    . 'Partidas con solo nombre libre no son validas. Corrija las partidas activas y vuelva a intentar.'
                );
            }

            if ($lostLotes !== []) {
                if ($competidorGanador === '' || $importePerdida === null) {
                    throw new \InvalidArgumentException(
                        'Para adjudicar con lotes perdidos debes indicar ganador e importe.'
                    );
                }

                $updateData['descripcion'] = $this->appendLossDescription(
                    (string)($licitacion['descripcion'] ?? ''),
                    'LOTES PERDIDOS EN ADJUDICACION',
                    $motivoPerdida,
                    $competidorGanador,
                    $importePerdida,
                    $lostLotes
                );
            }
        }

        if ($nuevoId === self::ESTADO_TERMINADA) {
            $this->assertCanFinalizeTender($licitacion, $tenderId);
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
     * @param array<string, mixed> $licitacion
     */
    private function assertCanFinalizeTender(array $licitacion, int $tenderId): void
    {
        $detalle = $this->tendersRepository->getTenderWithDetails($tenderId);
        $partidas = is_array($detalle['partidas'] ?? null)
            ? $detalle['partidas']
            : [];
        $lotesConfig = $this->normalizeLotesConfig(
            $licitacion['lotes_config'] ?? ($detalle['lotes_config'] ?? null)
        );
        $filtrarPorLotesGanados = $lotesConfig !== [];

        /** @var array<string, bool> $lotesGanadosSet */
        $lotesGanadosSet = [];
        if ($filtrarPorLotesGanados) {
            foreach ($lotesConfig as $item) {
                $nombre = isset($item['nombre']) ? trim((string)$item['nombre']) : '';
                if ($nombre === '' || empty($item['ganado'])) {
                    continue;
                }
                $lotesGanadosSet[mb_strtolower($nombre, 'UTF-8')] = true;
            }
        }

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

            $lote = isset($p['lote']) ? trim((string)$p['lote']) : '';
            if ($lote === '') {
                $lote = 'General';
            }
            if ($filtrarPorLotesGanados) {
                $loteKey = mb_strtolower($lote, 'UTF-8');
                if (!isset($lotesGanadosSet[$loteKey])) {
                    continue;
                }
            }

            $unidades = isset($p['unidades']) ? (float)$p['unidades'] : 0.0;
            if ($unidades <= 0.0) {
                continue;
            }

            if (!isset($presupuestadoPorDetalle[$idDetalle])) {
                $presupuestadoPorDetalle[$idDetalle] = 0.0;
            }
            $presupuestadoPorDetalle[$idDetalle] += $unidades;
        }

        $lineas = $this->listExecutionLinesForFinalization(
            $tenderId,
            array_keys($presupuestadoPorDetalle)
        );
        if ($lineas === []) {
            throw new \InvalidArgumentException(
                'No puedes finalizar la licitacion sin lineas de entrega registradas.'
            );
        }

        /** @var array<int, float> $entregadoPorDetalle */
        $entregadoPorDetalle = [];
        $lineasConEstadoPendiente = 0;
        $lineasSinCobro = 0;
        $allowedEstados = [
            'ENTREGADO' => true,
            'FACTURADO' => true,
        ];

        foreach ($lineas as $lin) {
            if (!is_array($lin)) {
                continue;
            }

            $idDetalle = isset($lin['id_detalle']) ? (int)$lin['id_detalle'] : 0;
            if ($idDetalle <= 0) {
                continue;
            }

            $cantidad = isset($lin['cantidad']) ? (float)$lin['cantidad'] : 0.0;
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

        $qtyEpsilon = 0.0001;
        foreach ($presupuestadoPorDetalle as $idDetalle => $presupuestado) {
            $entregado = (float)($entregadoPorDetalle[$idDetalle] ?? 0.0);
            if (($presupuestado - $entregado) > $qtyEpsilon) {
                throw new \InvalidArgumentException(
                    'No puedes finalizar la licitacion: quedan lineas pendientes de entrega.'
                );
            }
        }

        if ($lineasConEstadoPendiente > 0 || $lineasSinCobro > 0) {
            throw new \InvalidArgumentException(
                'No puedes finalizar la licitacion: todas las lineas deben estar entregadas/facturadas y cobradas.'
            );
        }
    }

    /**
     * @param array<int, int> $idDetalles
     * @return array<int, array<string, mixed>>
     */
    private function listExecutionLinesForFinalization(int $tenderId, array $idDetalles): array
    {
        $sql = 'SELECT id_detalle, cantidad, estado, cobrado
                FROM tbl_licitaciones_real
                WHERE id_licitacion = :id_licitacion
                  AND id_tipo_gasto IS NULL
                  AND id_detalle IS NOT NULL';
        $params = [
            ':id_licitacion' => $tenderId,
        ];

        $idDetalles = array_values(array_unique(array_filter(
            $idDetalles,
            static fn (int $id): bool => $id > 0
        )));
        if ($idDetalles !== []) {
            $placeholders = [];
            foreach ($idDetalles as $idx => $idDetalle) {
                $ph = ':id_det_' . $idx;
                $placeholders[] = $ph;
                $params[$ph] = $idDetalle;
            }
            $sql .= ' AND id_detalle IN (' . implode(', ', $placeholders) . ')';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll() ?: [];
        return $rows;
    }

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
        if (is_string($lotes) && trim($lotes) !== '') {
            try {
                $decoded = json_decode($lotes, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $lotes = $decoded;
                }
            } catch (\Throwable) {
                return [];
            }
        }

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

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function extractLostLotes(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $nombre = isset($item['nombre']) ? trim((string)$item['nombre']) : '';
            if ($nombre === '' || !empty($item['ganado'])) {
                continue;
            }
            $out[] = $nombre;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function markAllLotesAsLost(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $nombre = isset($item['nombre']) ? trim((string)$item['nombre']) : '';
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
    private function parsePositiveAmount($raw): ?float
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

        $amount = (float)$normalized;
        return $amount > 0.0 ? $amount : null;
    }

    /**
     * @param array<int, string> $lostLotes
     */
    private function appendLossDescription(
        string $currentDescription,
        string $tag,
        string $motivo,
        string $competidorGanador,
        float $importeGanador,
        array $lostLotes = []
    ): string {
        $parts = [];
        if ($lostLotes !== []) {
            $parts[] = 'Lotes: ' . implode(', ', $lostLotes);
        }
        if ($motivo !== '') {
            $parts[] = 'Motivo: ' . $motivo;
        }
        $parts[] = 'Ganador: ' . $competidorGanador;
        $parts[] = 'Importe ganador: ' . number_format($importeGanador, 2, ',', '.') . ' EUR';

        $block = '[' . $tag . ']: ' . implode(' | ', $parts);
        $currentDescription = trim($currentDescription);

        return $currentDescription === ''
            ? $block
            : rtrim($currentDescription) . "\n" . $block;
    }
}

