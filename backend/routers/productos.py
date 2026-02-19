"""
Búsqueda de productos (tbl_productos) para selectores/combobox del frontend.
"""

from typing import List

from fastapi import APIRouter, HTTPException, Query, status

from backend.config import supabase_client
from backend.deps import CurrentUserDep
from backend.models import ProductoSearchResult


router = APIRouter(prefix="/productos", tags=["productos"])


@router.get("/search", response_model=List[ProductoSearchResult])
def search_productos(
    current_user: CurrentUserDep,
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
        org_s = str(current_user.org_id)
        if only_with_precios_referencia:
            # Productos con precios de referencia
            ref_resp = (
                supabase_client.table("tbl_precios_referencia")
                .select("id_producto")
                .eq("organization_id", org_s)
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
                .eq("organization_id", org_s)
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
                .select("id, nombre, id_proveedor, nombre_proveedor")
                .in_("id", list(id_productos))
                .eq("organization_id", org_s)
                .ilike("nombre", f"%{q}%")
                .limit(limit)
                .order("nombre")
                .execute()
            )
        else:
            response = (
                supabase_client.table("tbl_productos")
                .select("id, nombre, id_proveedor, nombre_proveedor")
                .eq("organization_id", org_s)
                .ilike("nombre", f"%{q}%")
                .limit(limit)
                .order("nombre")
                .execute()
            )

        rows = response.data or []

        # Resolver nombre de proveedor a partir de id_proveedor y tbl_proveedores.
        # Si algo falla (tabla/columna no existe), hacemos fallback a nombre_proveedor de tbl_productos.
        proveedor_ids = {
            int(r["id_proveedor"])
            for r in rows
            if r.get("id_proveedor") is not None
        }
        proveedores_by_id: dict[int, str | None] = {}
        if proveedor_ids:
            try:
                prov_resp = (
                    supabase_client.table("tbl_proveedores")
                    .select("id, nombre")
                    .eq("organization_id", org_s)
                    .in_("id", list(proveedor_ids))
                    .execute()
                )
                for p in prov_resp.data or []:
                    try:
                        pid = int(p["id"])
                    except Exception:
                        continue
                    nombre = (p.get("nombre") or "").strip() or None
                    proveedores_by_id[pid] = nombre
            except Exception:
                proveedores_by_id = {}

        return [
            ProductoSearchResult(
                id=int(r["id"]),
                nombre=r.get("nombre") or "",
                nombre_proveedor=(
                    (
                        proveedores_by_id.get(int(r["id_proveedor"]))
                        if r.get("id_proveedor") is not None
                        else None
                    )
                    or (r.get("nombre_proveedor") or "").strip()
                    or None
                ),
            )
            for r in rows
        ]
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error buscando productos: {e!s}",
        ) from e
