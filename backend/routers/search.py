"""
Buscador histórico.
Migrado desde src/views/search.py.
Busca en tbl_licitaciones_detalle (ILIKE en producto y, si existe, descripcion).
"""

from typing import List

from fastapi import APIRouter, HTTPException, Query, status

from backend.config import supabase_client
from backend.models import ProductSearchItem


router = APIRouter(prefix="/search", tags=["search"])


def _search_detalle(q: str) -> List[dict]:
    """
    Busca en tbl_licitaciones_detalle con ILIKE en producto (y descripcion si existe).
    Si la tabla no tiene columna descripcion, solo se filtra por producto.
    """
    base = supabase_client.table("tbl_licitaciones_detalle").select(
        "*, tbl_licitaciones(nombre, numero_expediente)"
    )
    try:
        response = base.or_(f"producto.ilike.%{q}%,descripcion.ilike.%{q}%").execute()
    except Exception:
        response = base.ilike("producto", f"%{q}%").execute()
    return response.data or []


@router.get("", response_model=List[ProductSearchItem])
def search(
    q: str = Query(..., min_length=1, description="Texto de búsqueda (producto o descripción)."),
) -> List[ProductSearchItem]:
    """
    Busca en tbl_licitaciones_detalle usando ILIKE en la columna producto
    (y descripcion si existe en la tabla).

    GET /search?q=Planta
    """
    try:
        data = _search_detalle(q)
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


@router.get("/products", response_model=List[ProductSearchItem])
def search_products(
    q: str = Query(..., min_length=1, description="Texto de búsqueda en producto."),
) -> List[ProductSearchItem]:
    """
    Misma búsqueda por producto (compatibilidad).
    GET /search/products?q=Planta
    """
    return search(q=q)