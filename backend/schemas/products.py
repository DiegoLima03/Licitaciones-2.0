from decimal import Decimal
from typing import Optional

from pydantic import BaseModel, Field


class ProductoSearchResult(BaseModel):
  """Resultado de búsqueda de productos para combobox/selectores."""

  id: int = Field(..., description="ID del producto.")
  nombre: str = Field(..., description="Nombre del producto.")
  nombre_proveedor: Optional[str] = Field(None, description="Nombre del proveedor del producto.")


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


