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
    numero_expediente: Optional[str] = None
    pres_maximo: Optional[float] = None
    descripcion: Optional[str] = None
    id_estado: Optional[int] = None
    tipo_de_licitacion: Optional[int] = None
    fecha_presentacion: Optional[str] = None
    fecha_adjudicacion: Optional[str] = None
    fecha_finalizacion: Optional[str] = None
    descuento_global: Optional[float] = None


class PartidaCreate(BaseModel):
    """Payload para añadir una partida manual a tbl_licitaciones_detalle."""

    lote: Optional[str] = Field("General", description="Lote / zona.")
    producto: str = Field(..., description="Descripción del producto o partida.")
    unidades: Optional[float] = Field(1.0, ge=0, description="Unidades.")
    pvu: Optional[float] = Field(0.0, ge=0, description="Precio venta unitario (€).")
    pcu: Optional[float] = Field(0.0, ge=0, description="Precio coste unitario (€).")
    pmaxu: Optional[float] = Field(0.0, ge=0, description="Precio máximo unitario (€).")
    activo: Optional[bool] = Field(True, description="Partida activa en el presupuesto.")


# ----- Entregas (tbl_entregas + tbl_licitaciones_real) -----


class DeliveryHeaderCreate(BaseModel):
    """Cabecera de una entrega (tbl_entregas)."""

    fecha: str = Field(..., description="Fecha del documento (YYYY-MM-DD).")
    codigo_albaran: str = Field(..., description="Nº albarán / referencia.")
    observaciones: Optional[str] = Field(None, description="Notas globales.")
    cliente: Optional[str] = Field(None, description="Cliente (si la tabla lo soporta).")


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


# ----- Precios de referencia (tbl_precios_referencia) -----


class PrecioReferenciaCreate(BaseModel):
    """Payload para crear una línea de precio de referencia (sin licitación)."""

    producto: str = Field(..., description="Nombre del producto.")
    pvu: Optional[float] = Field(None, description="Precio venta unitario.")
    pcu: Optional[float] = Field(None, description="Precio coste unitario.")
    unidades: Optional[float] = Field(None, description="Unidades.")
    proveedor: Optional[str] = Field(None, description="Proveedor.")
    notas: Optional[str] = Field(None, description="Notas.")


class PrecioReferencia(BaseModel):
    """Una línea de tbl_precios_referencia."""

    id: str = Field(..., description="UUID.")
    producto: str = Field(..., description="Nombre del producto.")
    pvu: Optional[float] = Field(None, description="Precio venta unitario.")
    pcu: Optional[float] = Field(None, description="Precio coste unitario.")
    unidades: Optional[float] = Field(None, description="Unidades.")
    proveedor: Optional[str] = Field(None, description="Proveedor.")
    notas: Optional[str] = Field(None, description="Notas.")
    fecha_creacion: Optional[str] = Field(None, description="Fecha de creación (ISO).")
    creado_por: Optional[str] = Field(None, description="UUID del usuario creador.")


# ----- Buscador (productos en tbl_licitaciones_detalle + tbl_precios_referencia) -----


class ProductSearchItem(BaseModel):
    """Un resultado de búsqueda por producto con datos de la licitación asociada."""

    producto: str = Field(..., description="Nombre del producto.")
    pvu: Optional[float] = Field(None, description="Precio venta unitario.")
    pcu: Optional[float] = Field(None, description="Precio coste unitario.")
    unidades: Optional[float] = Field(None, description="Unidades previstas.")
    licitacion_nombre: Optional[str] = Field(None, description="Nombre del expediente/licitación.")
    numero_expediente: Optional[str] = Field(None, description="Nº expediente.")
    proveedor: Optional[str] = Field(None, description="Proveedor asociado (desde tbl_licitaciones_real).")

