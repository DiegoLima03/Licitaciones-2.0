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
    only_with_precios_referencia: bool = Query(
        False,
        description="Si true, solo devuelve productos que tengan al menos un precio de referencia.",
    ),
) -> List[ProductoSearchResult]:
    """
    Búsqueda asíncrona de productos por nombre (y referencia si existe).
    Devuelve id y nombre para poblar combobox/selectores.

    GET /productos/search?q=Planta
    GET /productos/search?q=Planta&only_with_precios_referencia=true  (con datos para tendencia/desviación: referencia o presupuestados en licitaciones)
    """
    try:
        if only_with_precios_referencia:
            # Productos con precios de referencia
            ref_resp = (
                supabase_client.table("tbl_precios_referencia")
                .select("id_producto")
                .execute()
            )
            id_productos = set()
            for r in (ref_resp.data or []):
                if r.get("id_producto") is not None:
                    id_productos.add(int(r["id_producto"]))
            # Productos presupuestados en licitaciones (tbl_licitaciones_detalle)
            det_resp = (
                supabase_client.table("tbl_licitaciones_detalle")
                .select("id_producto")
                .eq("activo", True)
                .execute()
            )
            for r in (det_resp.data or []):
                if r.get("id_producto") is not None:
                    id_productos.add(int(r["id_producto"]))
            if not id_productos:
                return []
            response = (
                supabase_client.table("tbl_productos")
                .select("id, nombre")
                .in_("id", list(id_productos))
                .ilike("nombre", f"%{q}%")
                .limit(limit)
                .order("nombre")
                .execute()
            )
        else:
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
