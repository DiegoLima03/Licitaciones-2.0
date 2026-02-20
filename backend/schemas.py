"""
Esquemas Pydantic para la API.
Reexporta desde backend.models para mantener un único lugar de definición.
"""

from backend.models import (
    DeliveryCreate,
    DeliveryHeaderCreate,
    DeliveryItem,
    DeliveryLineCreate,
    KPIDashboard,
    PartidaCreate,
    PartidaUpdate,
    ProductSearchItem,
    ScheduledDelivery,
    ScheduledDeliveryCreate,
    TenderAttachment,
    TenderAttachmentCreate,
    TenderCreate,
    TenderUpdate,
    TipoProcedimiento,
    UserLogin,
    UserResponse,
)

# Alias para documentación/consumo en endpoints de entregas
DeliverySchema = DeliveryCreate

__all__ = [
    "DeliveryCreate",
    "DeliveryHeaderCreate",
    "DeliveryItem",
    "DeliveryLineCreate",
    "DeliverySchema",
    "KPIDashboard",
    "PartidaCreate",
    "PartidaUpdate",
    "ProductSearchItem",
    "ScheduledDelivery",
    "ScheduledDeliveryCreate",
    "TenderAttachment",
    "TenderAttachmentCreate",
    "TenderCreate",
    "TenderUpdate",
    "TipoProcedimiento",
    "UserLogin",
    "UserResponse",
]
