from decimal import Decimal
from typing import List, Optional

from pydantic import BaseModel, Field


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

  timeline: List[TimelineItem] = Field(default_factory=list)
  total_oportunidades_uds: int = 0
  total_oportunidades_euros: Decimal = Decimal("0")
  total_ofertado_uds: int = 0
  total_ofertado_euros: Decimal = Decimal("0")
  ratio_ofertado_oportunidades_uds: Decimal = Decimal("0")
  ratio_ofertado_oportunidades_euros: Decimal = Decimal("0")
  ratio_adjudicadas_terminadas_ofertado: Decimal = Decimal("0")
  margen_medio_ponderado_presupuestado: Optional[Decimal] = None
  margen_medio_ponderado_real: Optional[Decimal] = None
  pct_descartadas_uds: Optional[Decimal] = None
  pct_descartadas_euros: Optional[Decimal] = None
  ratio_adjudicacion: Decimal = Decimal("0")


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


