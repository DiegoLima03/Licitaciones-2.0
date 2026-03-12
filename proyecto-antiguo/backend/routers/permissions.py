"""
Gestión de permisos por rol (qué secciones de la app puede ver cada rol).

Almacena la matriz role→feature→allowed en una tabla multi-tenant de Supabase.
Si no hay configuración guardada para la organización, usa una matriz por defecto.
"""

from typing import Any, Dict, List

from fastapi import APIRouter, Depends, HTTPException, status

from backend.config import supabase_client
from backend.deps import CurrentUserDep
from backend.schemas.auth import CurrentUser
from backend.schemas.permissions import RolePermissionsMatrix


router = APIRouter(prefix="/permissions", tags=["permissions"])


TABLE = "role_permissions"


FEATURE_KEYS = [
    "dashboard",
    "licitaciones",
    "buscador",
    "lineas",
    "analytics",
    "usuarios",
]

DEFAULT_MATRIX: Dict[str, Dict[str, bool]] = {
    "admin": {
        "dashboard": True,
        "licitaciones": True,
        "buscador": True,
        "lineas": True,
        "analytics": True,
        "usuarios": True,
    },
    "admin_licitaciones": {
        "dashboard": True,
        "licitaciones": True,
        "buscador": True,
        "lineas": True,
        "analytics": True,
        "usuarios": False,
    },
    "member_licitaciones": {
        "dashboard": True,
        "licitaciones": True,
        "buscador": True,
        "lineas": True,
        "analytics": False,
        "usuarios": False,
    },
    "admin_planta": {
        "dashboard": False,
        "licitaciones": True,
        "buscador": True,
        "lineas": True,
        "analytics": False,
        "usuarios": False,
    },
    "member_planta": {
        "dashboard": False,
        "licitaciones": True,
        "buscador": True,
        "lineas": False,
        "analytics": False,
        "usuarios": False,
    },
}


def _org_str(user: CurrentUser) -> str:
    return str(user.org_id)


def _merge_with_defaults(rows: List[Dict[str, Any]]) -> Dict[str, Dict[str, bool]]:
    """Fusiona la configuración guardada con la matriz por defecto (fallback)."""
    matrix: Dict[str, Dict[str, bool]] = {k: v.copy() for k, v in DEFAULT_MATRIX.items()}

    for row in rows:
        role = str(row.get("role") or "").strip().lower()
        feature = str(row.get("feature") or "").strip()
        allowed = bool(row.get("allowed"))
        if not role or feature not in FEATURE_KEYS:
            continue
        if role not in matrix:
            matrix[role] = {f: False for f in FEATURE_KEYS}
        matrix[role][feature] = allowed

    return matrix


def _is_missing_table_error(exc: Exception) -> bool:
    """Devuelve True si el error viene de que la tabla role_permissions no existe aún."""
    msg = str(exc)
    if "role_permissions" in msg and "Could not find the table" in msg:
        return True
    if "PGRST205" in msg:
        return True
    return False


@router.get("/role-matrix", response_model=RolePermissionsMatrix)
def get_role_matrix(current_user: CurrentUserDep) -> RolePermissionsMatrix:
    """
    Devuelve la matriz de permisos de la organización.

    Si no hay configuración guardada, se devuelve la matriz por defecto.
    GET /permissions/role-matrix
    """
    org_s = _org_str(current_user)
    try:
        res = (
            supabase_client.table(TABLE)
            .select("*")
            .eq("organization_id", org_s)
            .execute()
        )
    except Exception as e:
        # Si la tabla aún no existe, devolvemos la matriz por defecto (modo compatibilidad).
        if _is_missing_table_error(e):
            return RolePermissionsMatrix(matrix=DEFAULT_MATRIX)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error leyendo permisos: {e!s}",
        ) from e

    rows = list(res.data or [])
    if not rows:
        return RolePermissionsMatrix(matrix=DEFAULT_MATRIX)

    matrix = _merge_with_defaults(rows)
    return RolePermissionsMatrix(matrix=matrix)


@router.put("/role-matrix", response_model=RolePermissionsMatrix)
def update_role_matrix(
    payload: RolePermissionsMatrix, current_user: CurrentUserDep
) -> RolePermissionsMatrix:
    """
    Actualiza la matriz de permisos de la organización.

    Solo debería usarse por administradores (controlado en frontend).
    Implementación sencilla: borra la configuración anterior de la organización
    y vuelve a insertar todas las filas.

    PUT /permissions/role-matrix
    Body: { "matrix": { "admin_licitaciones": { "dashboard": true, ... }, ... } }
    """
    org_s = _org_str(current_user)

    # Normalizar payload: asegurar claves válidas
    rows_to_insert: List[Dict[str, Any]] = []
    for role, perms in (payload.matrix or {}).items():
        role_norm = str(role).strip().lower()
        if not role_norm:
            continue
        for feature, allowed in (perms or {}).items():
            if feature not in FEATURE_KEYS:
                continue
            rows_to_insert.append(
                {
                    "organization_id": org_s,
                    "role": role_norm,
                    "feature": feature,
                    "allowed": bool(allowed),
                }
            )

    try:
        # Borrar configuración anterior de esta organización
        supabase_client.table(TABLE).delete().eq("organization_id", org_s).execute()
        if rows_to_insert:
            supabase_client.table(TABLE).insert(rows_to_insert).execute()
    except Exception as e:
        if _is_missing_table_error(e):
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=(
                    "La tabla 'role_permissions' aún no existe en la base de datos. "
                    "Crea la tabla en Supabase (ver documentación) para poder guardar permisos personalizados."
                ),
            )
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error actualizando permisos: {e!s}",
        ) from e

    # Devolver matriz normalizada (merge con defaults)
    final_matrix = _merge_with_defaults(rows_to_insert)
    return RolePermissionsMatrix(matrix=final_matrix)

