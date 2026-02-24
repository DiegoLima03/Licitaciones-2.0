from datetime import date
from decimal import Decimal
from typing import Any, Dict, List, Optional
from uuid import UUID

from pydantic import BaseModel, Field


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


