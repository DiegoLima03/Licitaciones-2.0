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


class EstadoLicitacion(IntEnum):
  """IDs de estados en tbl_estados. Máquina de estados para flujo de negocio."""

  DESCARTADA = 2
  EN_ANALISIS = 3
  PRESENTADA = 4
  ADJUDICADA = 5
  NO_ADJUDICADA = 6  # Perdida
  TERMINADA = 7


# Estados a partir de los cuales no se pueden editar campos económicos ni partidas
ESTADOS_BLOQUEO_EDICION = {
  EstadoLicitacion.PRESENTADA,
  EstadoLicitacion.ADJUDICADA,
  EstadoLicitacion.NO_ADJUDICADA,
  EstadoLicitacion.TERMINADA,
}

# Estados que permiten imputar entregas (solo adjudicada; desde adjudicada solo se pasa a finalizada)
ESTADOS_PERMITEN_ENTREGAS = {EstadoLicitacion.ADJUDICADA}


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


