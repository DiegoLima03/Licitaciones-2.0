"""
Listado de tipos de gasto desde tbl_tipos_gasto.
Usado en albaranes (línea Gasto extraordinario) y en gastos de proyecto.
"""

from typing import List

from fastapi import APIRouter, HTTPException, status

from backend.config import supabase_client


router = APIRouter(prefix="/tipos-gasto", tags=["tipos-gasto"])


@router.get("", response_model=List[dict])
def list_tipos_gasto() -> List[dict]:
    """
    Lista todos los tipos de gasto desde tbl_tipos_gasto.

    GET /tipos-gasto
    """
    try:
        response = (
            supabase_client.table("tbl_tipos_gasto")
            .select("id, codigo, nombre")
            .order("id")
            .execute()
        )
        return response.data or []
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error listando tipos de gasto: {e!s}",
        ) from e
