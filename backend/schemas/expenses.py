from datetime import date
from decimal import Decimal
from enum import Enum
from typing import Optional
from uuid import UUID

from pydantic import BaseModel, Field


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


