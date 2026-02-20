from datetime import date
from decimal import Decimal
from enum import Enum, IntEnum
from typing import Any, Dict, List, Literal, Optional
from uuid import UUID

from pydantic import BaseModel, Field, model_validator

# Valores permitidos para país de licitación
PaisLicitacion = Literal["España", "Portugal"]


class TipoProcedimiento(str, Enum):
    """
    Tipo de procedimiento (Acuerdos Marco / SDA / jerarquía padre-hijo).
    ORDINARIO, ACUERDO_MARCO, SDA = pueden ser padres.
    CONTRATO_BASADO = contrato derivado de un AM/SDA (tiene id_licitacion_padre).
    """

    ORDINARIO = "ORDINARIO"
    ACUERDO_MARCO = "ACUERDO_MARCO"
    SDA = "SDA"
    CONTRATO_BASADO = "CONTRATO_BASADO"


# ----- Estados de licitación (tbl_estados) -----
# IDs según base de datos actual. Extensible si se añaden más estados.
class EstadoLicitacion(IntEnum):
    """IDs de estados en tbl_estados. Máquina de estados para flujo de negocio."""

    DESCARTADA = 2
    EN_ANALISIS = 3
    PRESENTADA = 4
    ADJUDICADA = 5
    NO_ADJUDICADA = 6  # Perdida
    TERMINADA = 7
    # Solo estos 6 estados deben existir en tbl_estados (2–7). No hay estado "Ejecución".


# Estados a partir de los cuales no se pueden editar campos económicos ni partidas
ESTADOS_BLOQUEO_EDICION = {EstadoLicitacion.PRESENTADA, EstadoLicitacion.ADJUDICADA, EstadoLicitacion.NO_ADJUDICADA, EstadoLicitacion.TERMINADA}

# Estados que permiten imputar entregas (solo adjudicada; desde adjudicada solo se pasa a finalizada)
ESTADOS_PERMITEN_ENTREGAS = {EstadoLicitacion.ADJUDICADA}


# ----- Auth (Supabase Auth + profiles) -----


class UserLogin(BaseModel):
    """
    Credenciales para login directo contra Supabase Auth.
    El frontend normalmente usa signInWithPassword() y pasa el JWT al backend.
    Este modelo se mantiene por compatibilidad o para un endpoint de verificación.
    """

    email: str = Field(..., description="Correo electrónico del usuario.")
    password: str = Field(..., description="Contraseña (solo si el backend hace login; normalmente el frontend lo hace).")


class UserResponse(BaseModel):
    """
    Respuesta de autenticación con datos del perfil y organización.
    Usado en /auth/me y /auth/login (verificación de sesión).
    """

    id: str = Field(..., description="UUID del usuario (auth.users.id).")
    email: str = Field(..., description="Correo electrónico del usuario.")
    organization_id: UUID = Field(..., description="UUID de la organización del usuario.")
    role: str = Field(..., description="Rol: admin, admin_planta, admin_licitaciones, member_planta, member_licitaciones.")
    full_name: Optional[str] = Field(None, description="Nombre completo.")
    nombre: Optional[str] = Field(None, description="Alias de full_name para compatibilidad.")


class CurrentUser(BaseModel):
    """
    Usuario autenticado inyectado por get_current_user.
    Usado internamente en dependencias y routers.
    """

    user_id: str = Field(..., description="UUID del usuario (auth.users.id).")
    email: str = Field(..., description="Correo electrónico.")
    org_id: UUID = Field(..., description="UUID de la organización.")
    role: str = Field(default="member_licitaciones", description="Rol en la organización.")


class TimelineItem(BaseModel):
    """Una licitación para el gráfico timeline (barra desde adjudicación a finalización)."""
    id_licitacion: int
    nombre: str
    fecha_adjudicacion: Optional[str] = None
    fecha_finalizacion: Optional[str] = None
    estado_nombre: Optional[str] = None
    pres_maximo: Optional[Decimal] = None


class KPIDashboard(BaseModel):
    """
    KPIs del dashboard: oportunidades, ofertado, ratios y timeline.
    Todas las fórmulas documentadas en los campos _help para tooltips.
    """

    # Timeline: licitaciones con fecha adjudicación y finalización para el gráfico
    timeline: List[TimelineItem] = Field(default_factory=list)

    # Total oportunidades = todas las licitaciones registradas
    total_oportunidades_uds: int = 0
    total_oportunidades_euros: Decimal = Decimal("0")

    # Total ofertado = solo Adjudicada, No Adjudicada, Presentada, Terminada
    total_ofertado_uds: int = 0
    total_ofertado_euros: Decimal = Decimal("0")

    # Ratio ofertado/oportunidades (uds y €)
    ratio_ofertado_oportunidades_uds: Decimal = Decimal("0")
    ratio_ofertado_oportunidades_euros: Decimal = Decimal("0")

    # Ratio (Adjudicadas+Terminadas) / Total ofertado
    ratio_adjudicadas_terminadas_ofertado: Decimal = Decimal("0")

    # Margen medio ponderado (adjudicadas + terminadas): presupuestado y real
    margen_medio_ponderado_presupuestado: Optional[Decimal] = None
    margen_medio_ponderado_real: Optional[Decimal] = None

    # % descartadas = descartadas / (total - en análisis)
    pct_descartadas_uds: Optional[Decimal] = None
    pct_descartadas_euros: Optional[Decimal] = None

    # Ratio adjudicación = (Adjudicadas+Terminadas) / (Adjudicadas+No Adjudicadas+Terminadas)
    ratio_adjudicacion: Decimal = Decimal("0")


# ----- Licitaciones (tbl_licitaciones) -----


class TenderCreate(BaseModel):
    """Payload para crear una licitación. Estado inicial fijo: EN ANÁLISIS."""

    nombre: str = Field(..., description="Nombre del proyecto.")
    pais: PaisLicitacion = Field(..., description="País de la licitación: España o Portugal.")
    numero_expediente: Optional[str] = Field(None, description="Nº expediente.")
    pres_maximo: Optional[Decimal] = Field(Decimal("0"), description="Presupuesto máximo (€).")
    descripcion: Optional[str] = Field(None, description="Notas / descripción.")
    enlace_gober: Optional[str] = Field(None, description="URL de la licitación en Gober (plataforma de scraping).")
    enlace_sharepoint: Optional[str] = Field(None, description="URL de SharePoint con la documentación e información de la licitación.")
    id_tipolicitacion: Optional[int] = Field(None, description="ID tipo de licitación (FK tbl_tipolicitacion).")
    fecha_presentacion: Optional[str] = Field(None, description="Fecha presentación (YYYY-MM-DD).")
    fecha_adjudicacion: Optional[str] = Field(None, description="Fecha adjudicación (YYYY-MM-DD).")
    fecha_finalizacion: Optional[str] = Field(None, description="Fecha finalización (YYYY-MM-DD).")
    tipo_procedimiento: Optional[TipoProcedimiento] = Field(
        default=TipoProcedimiento.ORDINARIO,
        description="Tipo: ORDINARIO, ACUERDO_MARCO, SDA, CONTRATO_BASADO.",
    )
    id_licitacion_padre: Optional[int] = Field(
        None,
        description="ID de la licitación padre (AM/SDA) cuando tipo es CONTRATO_BASADO.",
    )


class TenderUpdate(BaseModel):
    """Payload para actualizar una licitación (campos opcionales)."""

    nombre: Optional[str] = None
    pais: Optional[PaisLicitacion] = None
    numero_expediente: Optional[str] = None
    pres_maximo: Optional[Decimal] = None
    descripcion: Optional[str] = None
    enlace_gober: Optional[str] = None
    enlace_sharepoint: Optional[str] = None
    id_estado: Optional[int] = None
    id_tipolicitacion: Optional[int] = None
    fecha_presentacion: Optional[str] = None
    fecha_adjudicacion: Optional[str] = None
    fecha_finalizacion: Optional[str] = None
    descuento_global: Optional[Decimal] = None
    lotes_config: Optional[List[Dict[str, Any]]] = None  # [{"nombre":"Lote 1","ganado":false}, ...]
    tipo_procedimiento: Optional[TipoProcedimiento] = None
    id_licitacion_padre: Optional[int] = None
    is_delivered: Optional[bool] = None
    is_invoiced: Optional[bool] = None
    is_collected: Optional[bool] = None
    coste_presupuestado: Optional[Decimal] = Field(None, ge=0)
    coste_real: Optional[Decimal] = Field(None, ge=0)
    gastos_extraordinarios: Optional[Decimal] = Field(None, ge=0)


class TenderStatusChange(BaseModel):
    """
    Payload para cambio de estado (POST /tenders/{id}/change-status).
    Campos obligatorios según el nuevo estado.
    """

    nuevo_estado_id: int = Field(..., description="ID del nuevo estado en tbl_estados.")
    motivo_descarte: Optional[str] = Field(None, description="Obligatorio si nuevo_estado == DESCARTADA.")
    motivo_perdida: Optional[str] = Field(None, description="Obligatorio si nuevo_estado == NO_ADJUDICADA/Perdida.")
    competidor_ganador: Optional[str] = Field(None, description="Empresa ganadora si nuevo_estado == Perdida.")
    importe_adjudicacion: Optional[Decimal] = Field(None, ge=0, description="Obligatorio si nuevo_estado == ADJUDICADA.")
    fecha_adjudicacion: Optional[date] = Field(None, description="Fecha de adjudicación (YYYY-MM-DD).")

    @model_validator(mode="after")
    def validar_campos_por_estado(self) -> "TenderStatusChange":
        try:
            e = EstadoLicitacion(self.nuevo_estado_id)
        except ValueError:
            return self  # ID no reconocido, la validación de transición lo rechazará
        if e == EstadoLicitacion.DESCARTADA and not (self.motivo_descarte and str(self.motivo_descarte).strip()):
            raise ValueError("motivo_descarte es obligatorio al pasar a DESCARTADA.")
        if e == EstadoLicitacion.NO_ADJUDICADA:
            if not (self.motivo_perdida and str(self.motivo_perdida).strip()):
                raise ValueError("motivo_perdida es obligatorio al pasar a PERDIDA.")
            if not (self.competidor_ganador and str(self.competidor_ganador).strip()):
                raise ValueError("competidor_ganador es obligatorio al pasar a PERDIDA.")
        if e == EstadoLicitacion.ADJUDICADA and (self.importe_adjudicacion is None or self.importe_adjudicacion <= Decimal("0")):
            raise ValueError("importe_adjudicacion es obligatorio y debe ser > 0 al pasar a ADJUDICADA.")
        return self


# ----- Productos (tbl_productos) -----


class ProductoSearchResult(BaseModel):
    """Resultado de búsqueda de productos para combobox/selectores."""

    id: int = Field(..., description="ID del producto.")
    nombre: str = Field(..., description="Nombre del producto.")
    nombre_proveedor: Optional[str] = Field(None, description="Nombre del proveedor del producto.")


# ----- Partidas (tbl_licitaciones_detalle) -----


class PartidaCreate(BaseModel):
    """Payload para añadir una partida manual a tbl_licitaciones_detalle. id_producto opcional (ERP Belneo); si NULL, usar nombre_producto_libre."""

    lote: Optional[str] = Field("General", description="Lote / zona.")
    id_producto: Optional[int] = Field(None, description="ID del producto en tbl_productos (opcional; si NULL, usar nombre_producto_libre).")
    nombre_producto_libre: Optional[str] = Field(None, description="Nombre libre del producto cuando no se vincula al ERP.")
    unidades: Optional[float] = Field(1.0, ge=0, description="Unidades.")
    pvu: Optional[Decimal] = Field(Decimal("0"), ge=0, description="Precio venta unitario (€).")
    pcu: Optional[Decimal] = Field(Decimal("0"), ge=0, description="Precio coste unitario (€).")
    pmaxu: Optional[Decimal] = Field(Decimal("0"), ge=0, description="Precio máximo unitario (€).")
    activo: Optional[bool] = Field(True, description="Partida activa en el presupuesto.")


class PartidaUpdate(BaseModel):
    """Payload para actualizar una partida (campos opcionales)."""

    lote: Optional[str] = None
    id_producto: Optional[int] = None
    nombre_producto_libre: Optional[str] = None
    unidades: Optional[float] = Field(None, ge=0)
    pvu: Optional[Decimal] = Field(None, ge=0)
    pcu: Optional[Decimal] = Field(None, ge=0)
    pmaxu: Optional[Decimal] = Field(None, ge=0)
    activo: Optional[bool] = None


# ----- Entregas (tbl_entregas + tbl_licitaciones_real) -----


class DeliveryHeaderCreate(BaseModel):
    """Cabecera de una entrega (tbl_entregas)."""

    fecha: str = Field(..., description="Fecha del documento (YYYY-MM-DD).")
    codigo_albaran: str = Field(..., description="Nº albarán / referencia.")
    observaciones: Optional[str] = Field(None, description="Notas globales.")
    cliente: Optional[str] = Field(None, description="Cliente (si la tabla lo soporta).")


class DeliveryLineCreate(BaseModel):
    """Una línea de detalle de entrega."""

    id_producto: Optional[int] = Field(None, description="ID del producto (null si es gasto extraordinario con id_tipo_gasto).")
    id_detalle: Optional[int] = Field(None, description="ID partida presupuesto (tbl_licitaciones_detalle). Null = gasto extraordinario.")
    id_tipo_gasto: Optional[int] = Field(None, description="ID tipo de gasto (tbl_tipos_gasto). Si no null, línea es gasto extraordinario.")
    proveedor: Optional[str] = Field("", description="Proveedor de la línea.")
    cantidad: float = Field(0.0, ge=0, description="Cantidad.")
    coste_unit: Decimal = Field(Decimal("0"), ge=0, description="Coste unitario (€).")


# Alias para validación de items en POST /deliveries
DeliveryItem = DeliveryLineCreate


class DeliveryCreate(BaseModel):
    """Payload para crear una entrega (cabecera + líneas)."""

    id_licitacion: int = Field(..., description="ID de la licitación.")
    cabecera: DeliveryHeaderCreate = Field(..., description="Datos de cabecera.")
    lineas: List[DeliveryLineCreate] = Field(default_factory=list, description="Líneas del documento.")


class DeliveryLineUpdate(BaseModel):
    """Payload para actualizar estado/cobrado de una línea (tbl_licitaciones_real)."""

    estado: Optional[str] = Field(None, description="Estado de la línea (ej. EN ESPERA, ENTREGADO).")
    cobrado: Optional[bool] = Field(None, description="Si la línea está cobrada.")


# ----- Adjuntos a licitación (tender_attachments) -----


class TenderAttachmentCreate(BaseModel):
    """Payload para subir un adjunto a una licitación (pliegos, facturas)."""

    tender_id: int = Field(..., description="ID de la licitación.")
    file_path: str = Field(..., description="Ruta del archivo almacenado.")
    file_type: Optional[str] = Field(None, description="Tipo MIME o extensión (ej. application/pdf).")


class TenderAttachment(BaseModel):
    """Registro de adjunto (tender_attachments)."""

    id: UUID = Field(..., description="UUID del adjunto.")
    tender_id: int = Field(..., description="ID de la licitación.")
    file_path: str = Field(..., description="Ruta del archivo.")
    file_type: Optional[str] = Field(None, description="Tipo de archivo.")
    uploaded_at: str = Field(..., description="Fecha de subida (ISO).")


# ----- Hitos de entrega programados (scheduled_deliveries) -----


class ScheduledDeliveryCreate(BaseModel):
    """Payload para crear un hito de entrega programado."""

    tender_id: int = Field(..., description="ID de la licitación.")
    delivery_date: date = Field(..., description="Fecha prevista de entrega (YYYY-MM-DD).")
    status: Optional[str] = Field(None, description="Estado del hito (ej. PENDIENTE, ENTREGADO).")
    description: Optional[str] = Field(None, description="Descripción del hito.")
    items_json: Optional[Dict[str, Any]] = Field(None, description="Detalle de partidas en JSON.")


class ScheduledDelivery(BaseModel):
    """Registro de hito de entrega (scheduled_deliveries)."""

    id: UUID = Field(..., description="UUID del hito.")
    tender_id: int = Field(..., description="ID de la licitación.")
    delivery_date: date = Field(..., description="Fecha prevista.")
    status: Optional[str] = Field(None, description="Estado.")
    description: Optional[str] = Field(None, description="Descripción.")
    items_json: Optional[Dict[str, Any]] = Field(None, description="Detalle partidas.")


# ----- Gastos de proyecto (tbl_gastos_proyecto) -----


class TipoGasto(str, Enum):
    """Tipos de gasto extraordinario permitidos."""

    COMBUSTIBLE = "COMBUSTIBLE"
    HOTEL = "HOTEL"
    ALOJAMIENTO = "ALOJAMIENTO"
    TRANSPORTE = "TRANSPORTE"
    DIETAS = "DIETAS"
    SUMINISTROS = "SUMINISTROS"
    OTROS = "OTROS"


class EstadoGasto(str, Enum):
    """Estados del flujo de aprobación de gastos."""

    PENDIENTE = "PENDIENTE"
    APROBADO = "APROBADO"
    RECHAZADO = "RECHAZADO"


class ProjectExpenseCreate(BaseModel):
    """Payload para crear un gasto extraordinario. Sin ID ni estado (siempre PENDIENTE inicial)."""

    id_licitacion: int = Field(..., description="ID de la licitación.")
    tipo_gasto: TipoGasto = Field(..., description="Tipo de gasto (COMBUSTIBLE, HOTEL, DIETAS, OTROS).")
    importe: Decimal = Field(..., gt=0, description="Importe en euros (obligatorio > 0).")
    fecha: date = Field(..., description="Fecha del ticket (YYYY-MM-DD).")
    descripcion: str = Field(default="", description="Motivo del gasto.")
    url_comprobante: str = Field(..., min_length=1, description="URL al comprobante subido (obligatorio para auditoría).")


class ProjectExpense(BaseModel):
    """Esquema de respuesta completo de un gasto de proyecto."""

    id: UUID = Field(..., description="UUID del gasto.")
    id_licitacion: int = Field(..., description="ID de la licitación.")
    id_usuario: UUID = Field(..., description="UUID del usuario que reportó el gasto.")
    organization_id: UUID = Field(..., description="UUID de la organización.")
    tipo_gasto: str = Field(..., description="Tipo de gasto.")
    importe: Decimal = Field(..., description="Importe en euros.")
    fecha: date = Field(..., description="Fecha del ticket.")
    descripcion: str = Field(..., description="Motivo del gasto.")
    url_comprobante: str = Field(..., description="URL al comprobante.")
    estado: str = Field(..., description="PENDIENTE | APROBADO | RECHAZADO.")
    created_at: str = Field(..., description="Timestamp de creación ISO.")


class ProjectExpenseUpdate(BaseModel):
    """Payload para actualizar un gasto (aprobar/rechazar o corregir importe)."""

    estado: Optional[EstadoGasto] = Field(None, description="Nuevo estado (APROBADO, RECHAZADO).")
    importe: Optional[Decimal] = Field(None, gt=0, description="Corregir importe si aplica.")


# ----- Precios de referencia (tbl_precios_referencia) -----


class PrecioReferenciaCreate(BaseModel):
    """Payload para crear una línea de precio de referencia (sin licitación)."""

    id_producto: int = Field(..., description="ID del producto en tbl_productos.")
    pvu: Optional[Decimal] = Field(None, description="Precio venta unitario.")
    pcu: Optional[Decimal] = Field(None, description="Precio coste unitario.")
    unidades: Optional[float] = Field(None, description="Unidades.")
    proveedor: Optional[str] = Field(None, description="Proveedor.")
    notas: Optional[str] = Field(None, description="Notas.")
    fecha_presupuesto: Optional[str] = Field(None, description="Fecha del presupuesto/vigencia (YYYY-MM-DD). Para importación masiva.")


class PrecioReferencia(BaseModel):
    """Una línea de tbl_precios_referencia."""

    id: str = Field(..., description="UUID.")
    id_producto: int = Field(..., description="ID del producto en tbl_productos.")
    product_nombre: Optional[str] = Field(None, description="Nombre del producto (desde join).")
    pvu: Optional[Decimal] = Field(None, description="Precio venta unitario.")
    pcu: Optional[Decimal] = Field(None, description="Precio coste unitario.")
    unidades: Optional[float] = Field(None, description="Unidades.")
    proveedor: Optional[str] = Field(None, description="Proveedor.")
    notas: Optional[str] = Field(None, description="Notas.")
    fecha_presupuesto: Optional[str] = Field(None, description="Fecha del presupuesto/vigencia (YYYY-MM-DD).")


# ----- Buscador (productos en tbl_licitaciones_detalle + tbl_precios_referencia) -----


class ProductSearchItem(BaseModel):
    """Un resultado de búsqueda por producto con datos de la licitación asociada."""

    id_producto: Optional[int] = Field(None, description="ID del producto en tbl_productos (para ficha analíticas).")
    producto: str = Field(..., description="Nombre del producto.")
    pvu: Optional[Decimal] = Field(None, description="Precio venta unitario.")
    pcu: Optional[Decimal] = Field(None, description="Precio coste unitario.")
    unidades: Optional[float] = Field(None, description="Unidades previstas.")
    licitacion_nombre: Optional[str] = Field(None, description="Nombre del expediente/licitación.")
    numero_expediente: Optional[str] = Field(None, description="Nº expediente.")
    proveedor: Optional[str] = Field(None, description="Proveedor asociado (desde tbl_licitaciones_real).")


# ----- Analytics (respuestas para endpoints de analítica) -----


class MaterialTrendPoint(BaseModel):
    """Punto temporal para gráfico de evolución de precios (Lightweight Charts)."""
    time: str = Field(..., description="Fecha en formato YYYY-MM-DD.")
    value: Decimal = Field(..., description="Precio en esa fecha (PVU o PCU).")


class MaterialTrendResponse(BaseModel):
    """Tendencia de precios: PVU (referencia + licitaciones detalle) y PCU (referencia + licitaciones real)."""
    pvu: List[MaterialTrendPoint] = Field(default_factory=list, description="Precio venta unitario (referencia + detalle).")
    pcu: List[MaterialTrendPoint] = Field(default_factory=list, description="Precio coste unitario (referencia + real).")


class RiskPipelineItem(BaseModel):
    """Pipeline bruto y ajustado por riesgo por categoría."""
    category: str = Field(..., description="Categoría (ej. tipo de obra/cliente).")
    pipeline_bruto: Decimal = Field(..., description="Suma de presupuestos máximos.")
    pipeline_ajustado: Decimal = Field(..., description="Pipeline ajustado por win rate.")


class SweetSpotItem(BaseModel):
    """Licitación cerrada para análisis de sweet spots."""
    id: str = Field(..., description="Identificador de la licitación.")
    presupuesto: Decimal = Field(..., description="Presupuesto máximo.")
    estado: str = Field(..., description="Adjudicada o Perdida.")
    cliente: str = Field(..., description="Nombre del expediente/cliente.")


class PriceDeviationResult(BaseModel):
    """Resultado de comprobación de desviación de precio vs histórico."""
    is_deviated: bool = Field(..., description="True si el precio se desvía significativamente.")
    deviation_percentage: Decimal = Field(..., description="Porcentaje de desviación vs media histórica.")
    historical_avg: Decimal = Field(..., description="Media del precio en el último año.")
    recommendation: str = Field(..., description="Recomendación para el usuario.")


# ----- Product Analytics (ficha técnica por producto) -----


class PriceHistoryPoint(BaseModel):
    """Punto de la serie temporal de precios adjudicados (eje X tiempo, eje Y precio)."""
    time: str = Field(..., description="Fecha ISO YYYY-MM-DD.")
    value: Decimal = Field(..., description="Precio de adjudicación (PVU).")
    unidades: Optional[float] = Field(None, description="Unidades (suma ese día) para tooltip.")


class VolumeMetrics(BaseModel):
    """Métricas de volumen para el producto."""
    total_licitado: Decimal = Field(..., description="Importe total licitado (PVU * unidades).")
    cantidad_oferentes_promedio: float = Field(..., description="Promedio de oferentes/licitaciones.")


class CompetitorItem(BaseModel):
    """Top empresa/proveedor con precios para este producto."""
    empresa: str = Field(..., description="Nombre del proveedor/empresa.")
    precio_medio: Decimal = Field(..., description="Precio medio adjudicado.")
    cantidad_adjudicaciones: int = Field(..., description="Número de adjudicaciones.")


class ProductAnalytics(BaseModel):
    """Respuesta de GET /analytics/product/{id}: analíticas avanzadas por producto."""
    product_id: int = Field(..., description="ID del producto en tbl_productos.")
    product_name: str = Field(..., description="Nombre del producto.")
    price_history: List[PriceHistoryPoint] = Field(default_factory=list, description="Historial PVU (precio venta).")
    price_history_pcu: List[PriceHistoryPoint] = Field(default_factory=list, description="Historial PCU (precio coste).")
    volume_metrics: VolumeMetrics = Field(...)
    competitor_analysis: List[CompetitorItem] = Field(default_factory=list)
    forecast: Optional[Decimal] = Field(None, description="Proyección MA del próximo precio.")
    precio_referencia_medio: Optional[Decimal] = Field(None, description="Media de precios de referencia.")

