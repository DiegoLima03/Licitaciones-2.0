"""
Fachada de esquemas Pydantic.

Reexporta modelos desde ``backend.schemas.*`` para mantener compatibilidad
con código que todavía importa desde ``backend.schemas``.
"""

from backend.schemas.auth import CurrentUser, UserLogin, UserResponse
from backend.schemas.tenders import (
  PaisLicitacion,
  TipoProcedimiento,
  EstadoLicitacion,
  ESTADOS_BLOQUEO_EDICION,
  ESTADOS_PERMITEN_ENTREGAS,
  TenderCreate,
  TenderUpdate,
  TenderStatusChange,
  PartidaCreate,
  PartidaUpdate,
)
from backend.schemas.products import (
  ProductoSearchResult,
  ProductSearchItem,
  PrecioReferenciaCreate,
  PrecioReferencia,
)
from backend.schemas.deliveries import (
  DeliveryHeaderCreate,
  DeliveryLineCreate,
  DeliveryItem,
  DeliveryCreate,
  DeliveryLineUpdate,
)
from backend.schemas.expenses import (
  TipoGasto,
  EstadoGasto,
  ProjectExpenseCreate,
  ProjectExpense,
  ProjectExpenseUpdate,
)
from backend.schemas.analytics import (
  TimelineItem,
  KPIDashboard,
  MaterialTrendPoint,
  MaterialTrendResponse,
  RiskPipelineItem,
  SweetSpotItem,
  PriceDeviationResult,
  PriceHistoryPoint,
  VolumeMetrics,
  CompetitorItem,
    ProductAnalytics,
)
from backend.schemas.permissions import RolePermissionsMatrix

__all__ = [
  # Auth
  "UserLogin",
  "UserResponse",
  "CurrentUser",
  # Tenders
  "PaisLicitacion",
  "TipoProcedimiento",
  "EstadoLicitacion",
  "ESTADOS_BLOQUEO_EDICION",
  "ESTADOS_PERMITEN_ENTREGAS",
  "TenderCreate",
  "TenderUpdate",
  "TenderStatusChange",
  "PartidaCreate",
  "PartidaUpdate",
  # Products
  "ProductoSearchResult",
  "ProductSearchItem",
  "PrecioReferenciaCreate",
  "PrecioReferencia",
  # Deliveries
  "DeliveryHeaderCreate",
  "DeliveryLineCreate",
  "DeliveryItem",
  "DeliveryCreate",
  "DeliveryLineUpdate",
  # Expenses
  "TipoGasto",
  "EstadoGasto",
  "ProjectExpenseCreate",
  "ProjectExpense",
  "ProjectExpenseUpdate",
  # Analytics
  "TimelineItem",
  "KPIDashboard",
  "MaterialTrendPoint",
  "MaterialTrendResponse",
  "RiskPipelineItem",
  "SweetSpotItem",
  "PriceDeviationResult",
  "PriceHistoryPoint",
  "VolumeMetrics",
  "CompetitorItem",
    "ProductAnalytics",
    # Permissions
    "RolePermissionsMatrix",
]

