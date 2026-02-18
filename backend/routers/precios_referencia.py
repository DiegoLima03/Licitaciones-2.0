"""
Precios de referencia (tbl_precios_referencia).
Líneas de producto con precios que no pertenecen a ninguna licitación.
Aparecen en el buscador histórico junto a las partidas de licitaciones.
"""

from typing import List

from fastapi import APIRouter, HTTPException, status

from backend.config import supabase_client
from backend.deps import CurrentUserDep
from backend.models import PrecioReferencia, PrecioReferenciaCreate


router = APIRouter(prefix="/precios-referencia", tags=["precios-referencia"])


@router.get("", response_model=List[PrecioReferencia])
def list_precios_referencia(current_user: CurrentUserDep) -> List[PrecioReferencia]:
    """
    Lista todas las líneas de precios de referencia.

    GET /precios-referencia
    """
    try:
        response = (
            supabase_client.table("tbl_precios_referencia")
            .select("id, id_producto, pvu, pcu, unidades, proveedor, notas, fecha_presupuesto, tbl_productos(nombre)")
            .eq("organization_id", str(current_user.org_id))
            .order("fecha_presupuesto", desc=True)
            .execute()
        )
        rows = response.data or []
        return [
            PrecioReferencia(
                id=str(r["id"]),
                id_producto=int(r["id_producto"]),
                product_nombre=(r.get("tbl_productos") or {}).get("nombre"),
                pvu=float(r["pvu"]) if r.get("pvu") is not None else None,
                pcu=float(r["pcu"]) if r.get("pcu") is not None else None,
                unidades=float(r["unidades"]) if r.get("unidades") is not None else None,
                proveedor=r.get("proveedor"),
                notas=r.get("notas"),
                fecha_presupuesto=r.get("fecha_presupuesto"),
            )
            for r in rows
        ]
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error listando precios de referencia: {e!s}",
        ) from e


@router.post("", response_model=PrecioReferencia, status_code=status.HTTP_201_CREATED)
def create_precio_referencia(payload: PrecioReferenciaCreate, current_user: CurrentUserDep) -> PrecioReferencia:
    """
    Crea una línea de precio de referencia (producto, PVU, PCU, etc.).
    No está asociada a ninguna licitación; aparecerá en el buscador histórico.

    POST /precios-referencia
    """
    try:
        prod_resp = (
            supabase_client.table("tbl_productos")
            .select("nombre, organization_id")
            .eq("id", payload.id_producto)
            .eq("organization_id", str(current_user.org_id))
            .limit(1)
            .execute()
        )
        product_nombre = ""
        org_id = None
        if prod_resp.data and len(prod_resp.data) > 0:
            p0 = prod_resp.data[0]
            product_nombre = (p0.get("nombre") or "").strip()
            org_id = p0.get("organization_id")
        if not org_id:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Producto no encontrado o no pertenece a tu organización.",
            ) from None
        row = {
            "id_producto": payload.id_producto,
            "producto": product_nombre or None,
            "organization_id": org_id,
            "pvu": float(payload.pvu) if payload.pvu is not None else None,
            "pcu": float(payload.pcu) if payload.pcu is not None else None,
            "unidades": payload.unidades,
            "proveedor": (payload.proveedor or "").strip() or None,
            "notas": (payload.notas or "").strip() or None,
            "fecha_presupuesto": (payload.fecha_presupuesto or "").strip() or None,
        }
        if row["producto"] is None:
            row["producto"] = ""
        response = (
            supabase_client.table("tbl_precios_referencia")
            .insert(row)
            .execute()
        )
        data = response.data
        if not data or len(data) == 0:
            raise HTTPException(
                status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
                detail="No se devolvió la fila creada.",
            )
        r = data[0]
        id_producto = int(r["id_producto"])
        product_nombre = None
        try:
            prod_resp = (
                supabase_client.table("tbl_productos")
                .select("nombre")
                .eq("id", id_producto)
                .limit(1)
                .execute()
            )
            if prod_resp.data and len(prod_resp.data) > 0:
                product_nombre = prod_resp.data[0].get("nombre")
        except Exception:
            pass
        return PrecioReferencia(
            id=str(r["id"]),
            id_producto=id_producto,
            product_nombre=product_nombre,
            pvu=float(r["pvu"]) if r.get("pvu") is not None else None,
            pcu=float(r["pcu"]) if r.get("pcu") is not None else None,
            unidades=float(r["unidades"]) if r.get("unidades") is not None else None,
            proveedor=r.get("proveedor"),
            notas=r.get("notas"),
            fecha_presupuesto=r.get("fecha_presupuesto"),
        )
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error creando precio de referencia: {e!s}",
        ) from e
