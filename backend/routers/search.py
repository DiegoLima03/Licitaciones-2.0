"""
Buscador histórico.
- tbl_licitaciones_detalle: producto, PVU, unidades, licitación; PCU/proveedor desde tbl_licitaciones_real.
- tbl_precios_referencia: líneas sin licitación (producto, pvu, pcu, unidades, proveedor).
"""

from typing import Dict, List, Optional, Tuple

from fastapi import APIRouter, HTTPException, Query, status

from backend.config import supabase_client
from backend.models import ProductSearchItem


router = APIRouter(prefix="/search", tags=["search"])


def _search_producto_ids(q: str) -> List[int]:
    """Devuelve ids de tbl_productos cuyo nombre coincide con la búsqueda."""
    response = (
        supabase_client.table("tbl_productos")
        .select("id")
        .ilike("nombre", f"%{q}%")
        .limit(500)
        .execute()
    )
    return [int(r["id"]) for r in (response.data or []) if r.get("id") is not None]


def _search_detalle(q: str) -> List[dict]:
    """Busca en tbl_licitaciones_detalle por id_producto (nombre en tbl_productos)."""
    id_productos = _search_producto_ids(q)
    if not id_productos:
        return []
    response = (
        supabase_client.table("tbl_licitaciones_detalle")
        .select("*, tbl_licitaciones(nombre, numero_expediente), tbl_productos(nombre)")
        .in_("id_producto", id_productos)
        .execute()
    )
    return response.data or []


def _get_pcu_and_proveedor_from_real(id_detalles: List[int]) -> Tuple[Dict[int, float], Dict[int, Optional[str]]]:
    """
    Obtiene PCU y proveedor desde tbl_licitaciones_real.
    Por cada id_detalle devuelve el último pcu y proveedor (orden por id_real desc).
    """
    if not id_detalles:
        return {}, {}
    response = (
        supabase_client.table("tbl_licitaciones_real")
        .select("id_detalle, pcu, proveedor")
        .in_("id_detalle", id_detalles)
        .order("id_real", desc=True)
        .execute()
    )
    rows = response.data or []
    pcu_by_id: Dict[int, float] = {}
    proveedor_by_id: Dict[int, Optional[str]] = {}
    for r in rows:
        id_d = r.get("id_detalle")
        if id_d is not None and id_d not in pcu_by_id:
            pcu = r.get("pcu")
            if pcu is not None:
                pcu_by_id[id_d] = float(pcu)
            proveedor_by_id[id_d] = r.get("proveedor") if r.get("proveedor") else None
    return pcu_by_id, proveedor_by_id


def _search_precios_referencia(q: str) -> List[ProductSearchItem]:
    """Busca en tbl_precios_referencia por id_producto (nombre en tbl_productos)."""
    try:
        id_productos = _search_producto_ids(q)
        if not id_productos:
            return []
        response = (
            supabase_client.table("tbl_precios_referencia")
            .select("pvu, pcu, unidades, proveedor, tbl_productos(nombre)")
            .in_("id_producto", id_productos)
            .execute()
        )
        rows = response.data or []
        return [
            ProductSearchItem(
                producto=(r.get("tbl_productos") or {}).get("nombre") or "",
                pvu=float(r["pvu"]) if r.get("pvu") is not None else None,
                pcu=float(r["pcu"]) if r.get("pcu") is not None else None,
                unidades=float(r["unidades"]) if r.get("unidades") is not None else None,
                licitacion_nombre=None,
                numero_expediente=None,
                proveedor=r.get("proveedor"),
            )
            for r in rows
        ]
    except Exception:
        return []


@router.get("", response_model=List[ProductSearchItem])
def search(
    q: str = Query(..., min_length=1, description="Texto de búsqueda por producto."),
) -> List[ProductSearchItem]:
    """
    Busca por producto en tbl_licitaciones_detalle y en tbl_precios_referencia.
    GET /search?q=Planta
    """
    try:
        data = _search_detalle(q)
        id_detalles = list({item["id_detalle"] for item in data if item.get("id_detalle") is not None})
        pcu_by_id, proveedor_by_id = _get_pcu_and_proveedor_from_real(id_detalles)

        results: List[ProductSearchItem] = []
        for item in data:
            lic = item.get("tbl_licitaciones") or {}
            prod = item.get("tbl_productos") or {}
            id_d = item.get("id_detalle")
            pcu = pcu_by_id.get(id_d) if id_d is not None else None
            proveedor = proveedor_by_id.get(id_d) if id_d is not None else None
            results.append(
                ProductSearchItem(
                    producto=prod.get("nombre") or "",
                    pvu=item.get("pvu"),
                    pcu=pcu,
                    unidades=item.get("unidades"),
                    licitacion_nombre=lic.get("nombre"),
                    numero_expediente=lic.get("numero_expediente"),
                    proveedor=proveedor,
                )
            )
        # Añadir líneas de precios de referencia (sin licitación)
        results.extend(_search_precios_referencia(q))
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