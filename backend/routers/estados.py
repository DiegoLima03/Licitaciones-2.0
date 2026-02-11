"""
Listado de estados desde tbl_estados.
Usado por el frontend para el desplegable de estados en el listado de licitaciones.
"""

from typing import List

from fastapi import APIRouter, HTTPException, status

from backend.config import supabase_client


router = APIRouter(prefix="/estados", tags=["estados"])


@router.get("", response_model=List[dict])
def list_estados() -> List[dict]:
    """
    Lista todos los estados desde tbl_estados.

    GET /estados
    """
    try:
        response = (
            supabase_client.table("tbl_estados")
            .select("id_estado, nombre_estado")
            .order("id_estado")
            .execute()
        )
        return response.data or []
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error listando estados: {e!s}",
        ) from e
