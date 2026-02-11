"""
Precios de referencia (tbl_precios_referencia).
Líneas de producto con precios que no pertenecen a ninguna licitación.
Aparecen en el buscador histórico junto a las partidas de licitaciones.
"""

from typing import List

from fastapi import APIRouter, HTTPException, status

from backend.config import supabase_client
from backend.models import PrecioReferencia, PrecioReferenciaCreate


router = APIRouter(prefix="/precios-referencia", tags=["precios-referencia"])


@router.get("", response_model=List[PrecioReferencia])
def list_precios_referencia() -> List[PrecioReferencia]:
    """
    Lista todas las líneas de precios de referencia.

    GET /precios-referencia
    """
    try:
        response = (
            supabase_client.table("tbl_precios_referencia")
            .select("id, id_producto, pvu, pcu, unidades, proveedor, notas, fecha_creacion, creado_por, tbl_productos(nombre)")
            .order("fecha_creacion", desc=True)
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
                fecha_creacion=r.get("fecha_creacion"),
                creado_por=str(r["creado_por"]) if r.get("creado_por") else None,
            )
            for r in rows
        ]
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error listando precios de referencia: {e!s}",
        ) from e


@router.post("", response_model=PrecioReferencia, status_code=status.HTTP_201_CREATED)
def create_precio_referencia(payload: PrecioReferenciaCreate) -> PrecioReferencia:
    """
    Crea una línea de precio de referencia (producto, PVU, PCU, etc.).
    No está asociada a ninguna licitación; aparecerá en el buscador histórico.

    POST /precios-referencia
    """
    try:
        row = {
            "id_producto": payload.id_producto,
            "pvu": payload.pvu,
            "pcu": payload.pcu,
            "unidades": payload.unidades,
            "proveedor": (payload.proveedor or "").strip() or None,
            "notas": (payload.notas or "").strip() or None,
        }
        response = (
            supabase_client.table("tbl_precios_referencia")
            .insert(row)
            .select("id, id_producto, pvu, pcu, unidades, proveedor, notas, fecha_creacion, creado_por, tbl_productos(nombre)")
            .execute()
        )
        data = response.data
        if not data or len(data) == 0:
            raise HTTPException(
                status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
                detail="No se devolvió la fila creada.",
            )
        r = data[0]
        prod = r.get("tbl_productos") or {}
        return PrecioReferencia(
            id=str(r["id"]),
            id_producto=int(r["id_producto"]),
            product_nombre=prod.get("nombre"),
            pvu=float(r["pvu"]) if r.get("pvu") is not None else None,
            pcu=float(r["pcu"]) if r.get("pcu") is not None else None,
            unidades=float(r["unidades"]) if r.get("unidades") is not None else None,
            proveedor=r.get("proveedor"),
            notas=r.get("notas"),
            fecha_creacion=r.get("fecha_creacion"),
            creado_por=str(r["creado_por"]) if r.get("creado_por") else None,
        )
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error creando precio de referencia: {e!s}",
        ) from e
