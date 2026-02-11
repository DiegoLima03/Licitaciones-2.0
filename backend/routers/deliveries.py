"""
Gestión de entregas (tbl_entregas + tbl_licitaciones_real).
Migrado desde src/logic/deliveries.py.
Endpoint transaccional: cabecera + líneas; rollback si falla.
"""

from typing import Any, Dict, List, Optional

from fastapi import APIRouter, HTTPException, Query, status

from backend.config import supabase_client
from backend.models import DeliveryCreate


router = APIRouter(prefix="/deliveries", tags=["deliveries"])


@router.get("", response_model=List[dict])
def list_deliveries(
    licitacion_id: Optional[int] = Query(None, description="Filtrar por licitación."),
) -> List[dict]:
    """
    Lista entregas. Si se pasa licitacion_id, devuelve solo las de esa licitación
    con sus líneas (tbl_entregas + tbl_licitaciones_real).

    GET /deliveries
    GET /deliveries?licitacion_id=1
    """
    try:
        query = (
            supabase_client.table("tbl_entregas")
            .select("*")
            .order("fecha_entrega", desc=True)
        )
        if licitacion_id is not None:
            query = query.eq("id_licitacion", licitacion_id)
        response = query.execute()
        entregas = response.data or []
        result: List[dict] = []
        for ent in entregas:
            id_e = ent.get("id_entrega")
            lineas_resp = (
                supabase_client.table("tbl_licitaciones_real")
                .select("*")
                .eq("id_entrega", id_e)
                .order("id_real")
                .execute()
            )
            result.append({
                **ent,
                "lineas": lineas_resp.data or [],
            })
        return result
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error listando entregas: {e!s}",
        ) from e


def _get_mapa_ids(licitacion_id: int) -> Dict[str, int]:
    """Obtiene mapa 'Lote - Producto' -> id_detalle para la licitación."""
    response = (
        supabase_client.table("tbl_licitaciones_detalle")
        .select("id_detalle, lote, producto")
        .eq("id_licitacion", licitacion_id)
        .eq("activo", True)
        .execute()
    )
    mapa: Dict[str, int] = {}
    for r in response.data or []:
        lote = r.get("lote") or "Gen"
        prod = r.get("producto") or ""
        key = f"{lote} - {prod}"
        mapa[key] = r["id_detalle"]
    return mapa


@router.post("", response_model=dict, status_code=status.HTTP_201_CREATED)
def create_delivery(payload: DeliveryCreate) -> dict:
    """
    Crea una entrega (cabecera en tbl_entregas, líneas en tbl_licitaciones_real).
    Si falla la inserción de líneas, hace rollback borrando la cabecera.

    POST /deliveries
    """
    cabecera = payload.cabecera
    try:
        insert_cab: dict[str, Any] = {
            "id_licitacion": payload.id_licitacion,
            "fecha_entrega": cabecera.fecha,
            "codigo_albaran": cabecera.codigo_albaran,
            "observaciones": cabecera.observaciones or "",
        }
        if cabecera.cliente is not None:
            insert_cab["cliente"] = cabecera.cliente
        res_cab = (
            supabase_client.table("tbl_entregas")
            .insert(insert_cab)
            .execute()
        )
        if not res_cab.data:
            raise HTTPException(
                status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
                detail="Error creando la cabecera de la entrega.",
            )
        new_id_entrega = res_cab.data[0]["id_entrega"]
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error creando cabecera: {e!s}",
        ) from e

    mapa_ids = _get_mapa_ids(payload.id_licitacion)
    lineas_a_insertar: List[dict[str, Any]] = []

    for line in payload.lineas:
        qty = float(line.cantidad)
        cost = float(line.coste_unit)
        if qty == 0 and cost == 0:
            continue
        nombre_prod = line.concepto_partida
        id_detalle = mapa_ids.get(nombre_prod)
        articulo_final = nombre_prod
        if id_detalle and " - " in nombre_prod:
            articulo_final = nombre_prod.split(" - ", 1)[1]
        prov_linea = (line.proveedor or "").strip()
        lineas_a_insertar.append({
            "id_licitacion": payload.id_licitacion,
            "id_entrega": new_id_entrega,
            "id_detalle": id_detalle,
            "fecha_entrega": cabecera.fecha,
            "articulo": articulo_final,
            "cantidad": qty,
            "pcu": cost,
            "proveedor": prov_linea,
            "estado": "EN ESPERA",
            "cobrado": False,
        })

    if not lineas_a_insertar:
        supabase_client.table("tbl_entregas").delete().eq(
            "id_entrega", new_id_entrega
        ).execute()
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="El documento no tenía líneas válidas.",
        )

    try:
        supabase_client.table("tbl_licitaciones_real").insert(
            lineas_a_insertar
        ).execute()
    except Exception as e:
        supabase_client.table("tbl_entregas").delete().eq(
            "id_entrega", new_id_entrega
        ).execute()
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error guardando líneas; entrega cancelada: {e!s}",
        ) from e

    return {
        "id_entrega": new_id_entrega,
        "message": f"Documento guardado con {len(lineas_a_insertar)} líneas.",
        "lines_count": len(lineas_a_insertar),
    }


@router.delete("/{delivery_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_delivery(delivery_id: int) -> None:
    """
    Elimina una entrega y sus líneas (cascade manual).
    DELETE /deliveries/{id}
    """
    try:
        supabase_client.table("tbl_licitaciones_real").delete().eq(
            "id_entrega", delivery_id
        ).execute()
        supabase_client.table("tbl_entregas").delete().eq(
            "id_entrega", delivery_id
        ).execute()
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error eliminando entrega: {e!s}",
        ) from e
