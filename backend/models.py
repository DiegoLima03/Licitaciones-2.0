from typing import Any, Dict, List, Optional

from pydantic import BaseModel, Field


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

