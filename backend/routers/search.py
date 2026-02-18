"""
Buscador histórico.
Misma lógica que analítica (tendencia/desviación): productos con datos en
tbl_precios_referencia O en tbl_licitaciones_detalle; luego filtrar por nombre.
- tbl_licitaciones_detalle: producto, PVU, unidades, licitación; PCU/proveedor desde tbl_licitaciones_real.
- tbl_precios_referencia: líneas sin licitación (producto, pvu, pcu, unidades, proveedor).
"""

from typing import Dict, List, Optional, Tuple

from fastapi import APIRouter, HTTPException, Query, status

from backend.config import supabase_client
from backend.models import ProductSearchItem


router = APIRouter(prefix="/search", tags=["search"])

def _get_proveedor_display(proveedor_raw: Optional[str], producto: Optional[dict]) -> Optional[str]:
    """Proveedor: de la línea si existe, si no nombre_proveedor del producto."""
    if proveedor_raw and str(proveedor_raw).strip():
        return str(proveedor_raw).strip()
    prod = producto or {}
    nom = (prod.get("nombre_proveedor") or "").strip()
    return nom or None


def _producto_ids_con_historico_y_nombre(q: str) -> List[int]:
    """
    Productos que coinciden con q (nombre o referencia) y que tienen histórico
    en precios_referencia o licitaciones_detalle.
    INVERSIÓN: buscamos en tbl_productos PRIMERO (evita límite 1000 de Supabase
    al cargar 500k+ filas de precios_referencia).
    """
    # 1. Buscar en tbl_productos por nombre o referencia
    pat = f"%{q}%"
    by_nombre = (
        supabase_client.table("tbl_productos")
        .select("id")
        .ilike("nombre", pat)
        .limit(300)
        .execute()
    )
    by_ref = (
        supabase_client.table("tbl_productos")
        .select("id")
        .ilike("referencia", pat)
        .limit(300)
        .execute()
    )
    candidatos = list({
        int(r["id"])
        for r in (by_nombre.data or []) + (by_ref.data or [])
        if r.get("id") is not None
    })
    if not candidatos:
        return []
    # 2. Filtrar solo los que tienen datos en precios_referencia o detalle
    # (consultas con .in_() traen solo esos ids, sin pisar el límite global)
    ref_resp = (
        supabase_client.table("tbl_precios_referencia")
        .select("id_producto")
        .in_("id_producto", candidatos)
        .limit(10000)
        .execute()
    )
    det_resp = (
        supabase_client.table("tbl_licitaciones_detalle")
        .select("id_producto")
        .in_("id_producto", candidatos)
        .eq("activo", True)
        .limit(5000)
        .execute()
    )
    con_historico = set()
    for r in (ref_resp.data or []) + (det_resp.data or []):
        if r.get("id_producto") is not None:
            con_historico.add(int(r["id_producto"]))
    return [pid for pid in candidatos if pid in con_historico]


def _search_detalle(id_productos: List[int]) -> List[dict]:
    """Busca en tbl_licitaciones_detalle por id_producto (solo partidas activas)."""
    if not id_productos:
        return []
    response = (
        supabase_client.table("tbl_licitaciones_detalle")
        .select("*, tbl_licitaciones(nombre, numero_expediente), tbl_productos(nombre, nombre_proveedor)")
        .in_("id_producto", id_productos)
        .eq("activo", True)
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


def _search_precios_referencia(id_productos: List[int]) -> List[ProductSearchItem]:
    """Busca en tbl_precios_referencia por id_producto."""
    try:
        if not id_productos:
            return []
        response = (
            supabase_client.table("tbl_precios_referencia")
            .select("id_producto, pvu, pcu, unidades, proveedor, tbl_productos(nombre, nombre_proveedor)")
            .in_("id_producto", id_productos)
            .execute()
        )
        rows = response.data or []
        return [
            ProductSearchItem(
                id_producto=r.get("id_producto"),
                producto=(r.get("tbl_productos") or {}).get("nombre") or "",
                pvu=float(r["pvu"]) if r.get("pvu") is not None else None,
                pcu=float(r["pcu"]) if r.get("pcu") is not None else None,
                unidades=float(r["unidades"]) if r.get("unidades") is not None else None,
                licitacion_nombre=None,
                numero_expediente=None,
                proveedor=_get_proveedor_display(r.get("proveedor"), r.get("tbl_productos")),
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
        id_productos = _producto_ids_con_historico_y_nombre(q)
        data = _search_detalle(id_productos)
        id_detalles = list({item["id_detalle"] for item in data if item.get("id_detalle") is not None})
        pcu_by_id, proveedor_by_id = _get_pcu_and_proveedor_from_real(id_detalles)

        results: List[ProductSearchItem] = []
        for item in data:
            lic = item.get("tbl_licitaciones") or {}
            prod = item.get("tbl_productos") or {}
            id_d = item.get("id_detalle")
            pcu = pcu_by_id.get(id_d) if id_d is not None else None
            prov_raw = proveedor_by_id.get(id_d) if id_d is not None else None
            proveedor = _get_proveedor_display(prov_raw, prod)
            results.append(
                ProductSearchItem(
                    id_producto=item.get("id_producto"),
                    producto=prod.get("nombre") or "",
                    pvu=item.get("pvu"),
                    pcu=pcu,
                    unidades=item.get("unidades"),
                    licitacion_nombre=lic.get("nombre"),
                    numero_expediente=lic.get("numero_expediente"),
                    proveedor=proveedor,
                )
            )
        results.extend(_search_precios_referencia(id_productos))
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