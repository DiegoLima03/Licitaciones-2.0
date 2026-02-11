"""
Listado de tipos de licitación desde tbl_tipolicitacion.
Usado por el frontend para el desplegable Tipo en Nueva Licitación.
"""

from typing import List

from fastapi import APIRouter, HTTPException, status

from backend.config import supabase_client


router = APIRouter(prefix="/tipos", tags=["tipos"])


@router.get("", response_model=List[dict])
def list_tipos() -> List[dict]:
    """
    Lista todos los tipos desde tbl_tipolicitacion (campo tipo).

    GET /tipos
    """
    try:
        response = (
            supabase_client.table("tbl_tipolicitacion")
            .select("id_tipolicitacion, tipo")
            .order("id_tipolicitacion")
            .execute()
        )
        return response.data or []
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error listando tipos: {e!s}",
        ) from e
