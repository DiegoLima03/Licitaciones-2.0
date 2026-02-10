from typing import Any, Dict, List, Optional

from pydantic import BaseModel, Field


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


class KPIDashboard(BaseModel):
    """
    Estructura de respuesta para los KPIs generales del dashboard.

    La lógica de cálculo se mantiene en `src/logic/dashboard_analytics.py`;
    aquí solo se define una versión serializable a JSON.
    """

    total_count: int
    pipeline_monto: float
    adjudicado_monto: float
    win_rate: float
    total_monto_historico: float
    df_mensual: Dict[str, float]
    df_tipos: Dict[str, float]
    df_timeline: List[Dict[str, Any]]


# ----- Licitaciones (tbl_licitaciones) -----


class TenderCreate(BaseModel):
    """Payload para crear una licitación."""

    nombre: str = Field(..., description="Nombre del proyecto.")
    numero_expediente: Optional[str] = Field(None, description="Nº expediente.")
    pres_maximo: Optional[float] = Field(0.0, description="Presupuesto máximo (€).")
    descripcion: Optional[str] = Field(None, description="Notas / descripción.")
    id_estado: int = Field(..., description="ID del estado.")
    tipo_de_licitacion: Optional[int] = Field(None, description="ID tipo de licitación.")
    fecha_presentacion: Optional[str] = Field(None, description="Fecha presentación (YYYY-MM-DD).")
    fecha_adjudicacion: Optional[str] = Field(None, description="Fecha adjudicación (YYYY-MM-DD).")


class TenderUpdate(BaseModel):
    """Payload para actualizar una licitación (campos opcionales)."""

    nombre: Optional[str] = None
    numero_expediente: Optional[str] = None
    pres_maximo: Optional[float] = None
    descripcion: Optional[str] = None
    id_estado: Optional[int] = None
    tipo_de_licitacion: Optional[int] = None
    fecha_presentacion: Optional[str] = None
    fecha_adjudicacion: Optional[str] = None
    fecha_finalizacion: Optional[str] = None
    descuento_global: Optional[float] = None


# ----- Entregas (tbl_entregas + tbl_licitaciones_real) -----


class DeliveryHeaderCreate(BaseModel):
    """Cabecera de una entrega."""

    fecha: str = Field(..., description="Fecha del documento (YYYY-MM-DD).")
    codigo_albaran: str = Field(..., description="Nº albarán / referencia.")
    observaciones: Optional[str] = Field(None, description="Notas globales.")


class DeliveryLineCreate(BaseModel):
    """Una línea de detalle de entrega."""

    concepto_partida: str = Field(..., description="Concepto / partida (o 'Lote - Producto').")
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


# ----- Buscador (productos en tbl_licitaciones_detalle) -----


class ProductSearchItem(BaseModel):
    """Un resultado de búsqueda por producto con datos de la licitación asociada."""

    producto: str = Field(..., description="Nombre del producto.")
    pvu: Optional[float] = Field(None, description="Precio venta unitario.")
    pcu: Optional[float] = Field(None, description="Precio coste unitario.")
    unidades: Optional[float] = Field(None, description="Unidades previstas.")
    licitacion_nombre: Optional[str] = Field(None, description="Nombre del expediente/licitación.")
    numero_expediente: Optional[str] = Field(None, description="Nº expediente.")

