from typing import Any, Dict, List, Literal, Optional

from pydantic import BaseModel, Field

# Valores permitidos para país de licitación
PaisLicitacion = Literal["España", "Portugal"]


# ----- Auth -----


class UserLogin(BaseModel):
    """Credenciales de acceso para el endpoint de autenticación."""

    email: str = Field(..., description="Correo electrónico del usuario.")
    password: str = Field(..., description="Contraseña en texto plano.")


class UserResponse(BaseModel):
    """
    Representación simplificada del usuario autenticado.

    Se adapta al esquema de la tabla `tbl_usuarios`. Se utilizan campos opcionales
    para ser tolerantes a ligeras variaciones en la base de datos.
    """

    id: Optional[int] = Field(None, description="Identificador del usuario.")
    email: str = Field(..., description="Correo electrónico del usuario.")
    rol: Optional[str] = Field(None, description="Rol del usuario.")
    nombre: Optional[str] = Field(None, description="Nombre para mostrar.")


class TimelineItem(BaseModel):
    """Una licitación para el gráfico timeline (barra desde adjudicación a finalización)."""
    id_licitacion: int
    nombre: str
    fecha_adjudicacion: Optional[str] = None
    fecha_finalizacion: Optional[str] = None
    estado_nombre: Optional[str] = None
    pres_maximo: Optional[float] = None


class KPIDashboard(BaseModel):
    """
    KPIs del dashboard: oportunidades, ofertado, ratios y timeline.
    Todas las fórmulas documentadas en los campos _help para tooltips.
    """

    # Timeline: licitaciones con fecha adjudicación y finalización para el gráfico
    timeline: List[TimelineItem] = Field(default_factory=list)

    # Total oportunidades = todas las licitaciones registradas
    total_oportunidades_uds: int = 0
    total_oportunidades_euros: float = 0.0

    # Total ofertado = solo Adjudicada, No Adjudicada, Presentada, Terminada
    total_ofertado_uds: int = 0
    total_ofertado_euros: float = 0.0

    # Ratio ofertado/oportunidades (uds y €)
    ratio_ofertado_oportunidades_uds: float = 0.0
    ratio_ofertado_oportunidades_euros: float = 0.0

    # Ratio (Adjudicadas+Terminadas) / Total ofertado
    ratio_adjudicadas_terminadas_ofertado: float = 0.0

    # Margen medio ponderado (adjudicadas + terminadas): presupuestado y real
    margen_medio_ponderado_presupuestado: Optional[float] = None
    margen_medio_ponderado_real: Optional[float] = None

    # % descartadas = descartadas / (total - análisis - valoración)
    pct_descartadas_uds: Optional[float] = None
    pct_descartadas_euros: Optional[float] = None

    # Ratio adjudicación = (Adjudicadas+Terminadas) / (Adjudicadas+No Adjudicadas+Terminadas)
    ratio_adjudicacion: float = 0.0


# ----- Licitaciones (tbl_licitaciones) -----


class TenderCreate(BaseModel):
    """Payload para crear una licitación."""

    nombre: str = Field(..., description="Nombre del proyecto.")
    pais: PaisLicitacion = Field(..., description="País de la licitación: España o Portugal.")
    numero_expediente: Optional[str] = Field(None, description="Nº expediente.")
    pres_maximo: Optional[float] = Field(0.0, description="Presupuesto máximo (€).")
    descripcion: Optional[str] = Field(None, description="Notas / descripción.")
    id_estado: int = Field(..., description="ID del estado.")
    tipo_de_licitacion: Optional[int] = Field(None, description="ID tipo de licitación.")
    fecha_presentacion: Optional[str] = Field(None, description="Fecha presentación (YYYY-MM-DD).")
    fecha_adjudicacion: Optional[str] = Field(None, description="Fecha adjudicación (YYYY-MM-DD).")
    fecha_finalizacion: Optional[str] = Field(None, description="Fecha finalización (YYYY-MM-DD).")


class TenderUpdate(BaseModel):
    """Payload para actualizar una licitación (campos opcionales)."""

    nombre: Optional[str] = None
    pais: Optional[PaisLicitacion] = None
    numero_expediente: Optional[str] = None
    pres_maximo: Optional[float] = None
    descripcion: Optional[str] = None
    id_estado: Optional[int] = None
    tipo_de_licitacion: Optional[int] = None
    fecha_presentacion: Optional[str] = None
    fecha_adjudicacion: Optional[str] = None
    fecha_finalizacion: Optional[str] = None
    descuento_global: Optional[float] = None


# ----- Productos (tbl_productos) -----


class ProductoSearchResult(BaseModel):
    """Resultado de búsqueda de productos para combobox/selectores."""

    id: int = Field(..., description="ID del producto.")
    nombre: str = Field(..., description="Nombre del producto.")


# ----- Partidas (tbl_licitaciones_detalle) -----


class PartidaCreate(BaseModel):
    """Payload para añadir una partida manual a tbl_licitaciones_detalle."""

    lote: Optional[str] = Field("General", description="Lote / zona.")
    id_producto: int = Field(..., description="ID del producto en tbl_productos.")
    unidades: Optional[float] = Field(1.0, ge=0, description="Unidades.")
    pvu: Optional[float] = Field(0.0, ge=0, description="Precio venta unitario (€).")
    pcu: Optional[float] = Field(0.0, ge=0, description="Precio coste unitario (€).")
    pmaxu: Optional[float] = Field(0.0, ge=0, description="Precio máximo unitario (€).")
    activo: Optional[bool] = Field(True, description="Partida activa en el presupuesto.")


class PartidaUpdate(BaseModel):
    """Payload para actualizar una partida (campos opcionales)."""

    lote: Optional[str] = None
    id_producto: Optional[int] = None
    unidades: Optional[float] = Field(None, ge=0)
    pvu: Optional[float] = Field(None, ge=0)
    pcu: Optional[float] = Field(None, ge=0)
    pmaxu: Optional[float] = Field(None, ge=0)
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

    id_producto: int = Field(..., description="ID del producto en tbl_productos.")
    id_detalle: Optional[int] = Field(None, description="ID partida presupuesto (tbl_licitaciones_detalle). Null = gasto extraordinario.")
    proveedor: Optional[str] = Field("", description="Proveedor de la línea.")
    cantidad: float = Field(0.0, ge=0, description="Cantidad.")
    coste_unit: float = Field(0.0, ge=0, description="Coste unitario (€).")


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


# ----- Precios de referencia (tbl_precios_referencia) -----


class PrecioReferenciaCreate(BaseModel):
    """Payload para crear una línea de precio de referencia (sin licitación)."""

    id_producto: int = Field(..., description="ID del producto en tbl_productos.")
    pvu: Optional[float] = Field(None, description="Precio venta unitario.")
    pcu: Optional[float] = Field(None, description="Precio coste unitario.")
    unidades: Optional[float] = Field(None, description="Unidades.")
    proveedor: Optional[str] = Field(None, description="Proveedor.")
    notas: Optional[str] = Field(None, description="Notas.")
    fecha_presupuesto: Optional[str] = Field(None, description="Fecha del presupuesto/vigencia (YYYY-MM-DD). Para importación masiva.")


class PrecioReferencia(BaseModel):
    """Una línea de tbl_precios_referencia."""

    id: str = Field(..., description="UUID.")
    id_producto: int = Field(..., description="ID del producto en tbl_productos.")
    product_nombre: Optional[str] = Field(None, description="Nombre del producto (desde join).")
    pvu: Optional[float] = Field(None, description="Precio venta unitario.")
    pcu: Optional[float] = Field(None, description="Precio coste unitario.")
    unidades: Optional[float] = Field(None, description="Unidades.")
    proveedor: Optional[str] = Field(None, description="Proveedor.")
    notas: Optional[str] = Field(None, description="Notas.")
    fecha_presupuesto: Optional[str] = Field(None, description="Fecha del presupuesto/vigencia (YYYY-MM-DD).")


# ----- Buscador (productos en tbl_licitaciones_detalle + tbl_precios_referencia) -----


class ProductSearchItem(BaseModel):
    """Un resultado de búsqueda por producto con datos de la licitación asociada."""

    id_producto: Optional[int] = Field(None, description="ID del producto en tbl_productos (para ficha analíticas).")
    producto: str = Field(..., description="Nombre del producto.")
    pvu: Optional[float] = Field(None, description="Precio venta unitario.")
    pcu: Optional[float] = Field(None, description="Precio coste unitario.")
    unidades: Optional[float] = Field(None, description="Unidades previstas.")
    licitacion_nombre: Optional[str] = Field(None, description="Nombre del expediente/licitación.")
    numero_expediente: Optional[str] = Field(None, description="Nº expediente.")
    proveedor: Optional[str] = Field(None, description="Proveedor asociado (desde tbl_licitaciones_real).")


# ----- Analytics (respuestas para endpoints de analítica) -----


class MaterialTrendPoint(BaseModel):
    """Punto temporal para gráfico de evolución de precios (Lightweight Charts)."""
    time: str = Field(..., description="Fecha en formato YYYY-MM-DD.")
    value: float = Field(..., description="Precio en esa fecha (PVU o PCU).")


class MaterialTrendResponse(BaseModel):
    """Tendencia de precios: PVU (referencia + licitaciones detalle) y PCU (referencia + licitaciones real)."""
    pvu: List[MaterialTrendPoint] = Field(default_factory=list, description="Precio venta unitario (referencia + detalle).")
    pcu: List[MaterialTrendPoint] = Field(default_factory=list, description="Precio coste unitario (referencia + real).")


class RiskPipelineItem(BaseModel):
    """Pipeline bruto y ajustado por riesgo por categoría."""
    category: str = Field(..., description="Categoría (ej. tipo de obra/cliente).")
    pipeline_bruto: float = Field(..., description="Suma de presupuestos máximos.")
    pipeline_ajustado: float = Field(..., description="Pipeline ajustado por win rate.")


class SweetSpotItem(BaseModel):
    """Licitación cerrada para análisis de sweet spots."""
    id: str = Field(..., description="Identificador de la licitación.")
    presupuesto: float = Field(..., description="Presupuesto máximo.")
    estado: str = Field(..., description="Adjudicada o Perdida.")
    cliente: str = Field(..., description="Nombre del expediente/cliente.")


class PriceDeviationResult(BaseModel):
    """Resultado de comprobación de desviación de precio vs histórico."""
    is_deviated: bool = Field(..., description="True si el precio se desvía significativamente.")
    deviation_percentage: float = Field(..., description="Porcentaje de desviación vs media histórica.")
    historical_avg: float = Field(..., description="Media del precio en el último año.")
    recommendation: str = Field(..., description="Recomendación para el usuario.")


# ----- Product Analytics (ficha técnica por producto) -----


class PriceHistoryPoint(BaseModel):
    """Punto de la serie temporal de precios adjudicados (eje X tiempo, eje Y precio)."""
    time: str = Field(..., description="Fecha ISO YYYY-MM-DD.")
    value: float = Field(..., description="Precio de adjudicación (PVU).")


class VolumeMetrics(BaseModel):
    """Métricas de volumen para el producto."""
    total_licitado: float = Field(..., description="Importe total licitado (PVU * unidades).")
    cantidad_oferentes_promedio: float = Field(..., description="Promedio de oferentes/licitaciones.")


class CompetitorItem(BaseModel):
    """Top empresa/proveedor con precios para este producto."""
    empresa: str = Field(..., description="Nombre del proveedor/empresa.")
    precio_medio: float = Field(..., description="Precio medio adjudicado.")
    cantidad_adjudicaciones: int = Field(..., description="Número de adjudicaciones.")


class ProductAnalytics(BaseModel):
    """Respuesta de GET /analytics/product/{id}: analíticas avanzadas por producto."""
    product_id: int = Field(..., description="ID del producto en tbl_productos.")
    product_name: str = Field(..., description="Nombre del producto.")
    price_history: List[PriceHistoryPoint] = Field(default_factory=list)
    volume_metrics: VolumeMetrics = Field(...)
    competitor_analysis: List[CompetitorItem] = Field(default_factory=list)
    forecast: Optional[float] = Field(None, description="Proyección MA del próximo precio.")
    precio_referencia_medio: Optional[float] = Field(None, description="Media de precios de referencia.")

