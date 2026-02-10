"""
Buscador histórico por producto.
Migrado desde src/views/search.py.
"""

from typing import List

from fastapi import APIRouter, HTTPException, Query, status

from backend.config import supabase_client
from backend.models import ProductSearchItem


router = APIRouter(prefix="/search", tags=["search"])


@router.get("/products", response_model=List[ProductSearchItem])
def search_products(
    q: str = Query(..., min_length=1, description="Texto de búsqueda en producto."),
) -> List[ProductSearchItem]:
    """
    Busca en tbl_licitaciones_detalle por producto (ilike)
    y devuelve cada resultado con nombre y numero_expediente de la licitación.

    GET /search/products?q=Planta
    """
    try:
        response = (
            supabase_client.table("tbl_licitaciones_detalle")
            .select("*, tbl_licitaciones(nombre, numero_expediente)")
            .ilike("producto", f"%{q}%")
            .execute()
        )
        data = response.data or []
        results: List[ProductSearchItem] = []
        for item in data:
            lic = item.get("tbl_licitaciones") or {}
            results.append(
                ProductSearchItem(
                    producto=item.get("producto") or "",
                    pvu=item.get("pvu"),
                    pcu=item.get("pcu"),
                    unidades=item.get("unidades"),
                    licitacion_nombre=lic.get("nombre"),
                    numero_expediente=lic.get("numero_expediente"),
                )
            )
        return results
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error en búsqueda: {e!s}",
        ) from e