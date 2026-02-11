"""
Búsqueda de productos (tbl_productos) para selectores/combobox del frontend.
"""

from typing import List

from fastapi import APIRouter, HTTPException, Query, status

from backend.config import supabase_client
from backend.models import ProductoSearchResult


router = APIRouter(prefix="/productos", tags=["productos"])


@router.get("/search", response_model=List[ProductoSearchResult])
def search_productos(
    q: str = Query(..., min_length=1, description="Texto para buscar por nombre o referencia."),
    limit: int = Query(30, ge=1, le=100, description="Máximo de resultados."),
) -> List[ProductoSearchResult]:
    """
    Búsqueda asíncrona de productos por nombre (y referencia si existe).
    Devuelve id y nombre para poblar combobox/selectores.

    GET /productos/search?q=Planta
    """
    try:
        # Buscar por nombre; si tbl_productos tiene 'referencia', se puede ampliar con .or_()
        response = (
            supabase_client.table("tbl_productos")
            .select("id, nombre")
            .ilike("nombre", f"%{q}%")
            .limit(limit)
            .order("nombre")
            .execute()
        )
        rows = response.data or []
        return [
            ProductoSearchResult(
                id=int(r["id"]),
                nombre=r.get("nombre") or "",
            )
            for r in rows
        ]
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error buscando productos: {e!s}",
        ) from e
