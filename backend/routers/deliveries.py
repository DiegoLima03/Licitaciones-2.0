"""
Gestión de entregas (tbl_entregas + tbl_licitaciones_real).
Migrado desde src/logic/deliveries.py.
Endpoint transaccional: cabecera + líneas; rollback si falla.
"""

from typing import Any, Dict, List, Optional

from fastapi import APIRouter, HTTPException, Query, status

from backend.config import supabase_client
from backend.deps import CurrentUserDep
from backend.models import CurrentUser, DeliveryCreate, DeliveryLineUpdate, ESTADOS_PERMITEN_ENTREGAS


router = APIRouter(prefix="/deliveries", tags=["deliveries"])


def _org_str(user: CurrentUser) -> str:
    return str(user.org_id)


@router.get("", response_model=List[dict])
def list_deliveries(
    current_user: CurrentUserDep,
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
            .eq("organization_id", _org_str(current_user))
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
                .select("*, tbl_productos(nombre)")
                .eq("id_entrega", id_e)
                .eq("organization_id", _org_str(current_user))
                .order("id_real")
                .execute()
            )
            raw_lineas = lineas_resp.data or []
            lineas: List[dict] = []
            for lin in raw_lineas:
                prod = lin.get("tbl_productos") or {}
                lineas.append({
                    **{k: v for k, v in lin.items() if k != "tbl_productos"},
                    "product_nombre": prod.get("nombre"),
                })
            result.append({
                **ent,
                "lineas": lineas,
            })
        return result
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error listando entregas: {e!s}",
        ) from e


def _get_mapa_id_detalle_by_id_producto(licitacion_id: int, org_id: str) -> Dict[int, int]:
    """Obtiene mapa id_producto -> id_detalle (primera partida con ese producto) para la licitación."""
    response = (
        supabase_client.table("tbl_licitaciones_detalle")
        .select("id_detalle, id_producto")
        .eq("id_licitacion", licitacion_id)
        .eq("organization_id", org_id)
        .eq("activo", True)
        .execute()
    )
    mapa: Dict[int, int] = {}
    for r in response.data or []:
        id_p = r.get("id_producto")
        if id_p is not None and id_p not in mapa:
            mapa[int(id_p)] = int(r["id_detalle"])
    return mapa


@router.post("", response_model=dict, status_code=status.HTTP_201_CREATED)
def create_delivery(payload: DeliveryCreate, current_user: CurrentUserDep) -> dict:
    """
    Crea una entrega (cabecera en tbl_entregas, líneas en tbl_licitaciones_real).
    Si falla la inserción de líneas, hace rollback borrando la cabecera.
    Solo permitido si la licitación está en ADJUDICADA o EJECUCIÓN.

    POST /deliveries
    """
    # Verificar licitación existe y pertenece a la org del usuario
    lic_resp = (
        supabase_client.table("tbl_licitaciones")
        .select("id_estado, organization_id")
        .eq("id_licitacion", payload.id_licitacion)
        .eq("organization_id", _org_str(current_user))
        .execute()
    )
    if not lic_resp.data:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Licitación no encontrada.",
        )
    id_estado = int(lic_resp.data[0].get("id_estado", 0))
    if id_estado not in {e.value for e in ESTADOS_PERMITEN_ENTREGAS}:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="No se pueden imputar entregas a una licitación no adjudicada. El estado debe ser ADJUDICADA o EJECUCIÓN.",
        )

    cabecera = payload.cabecera
    try:
        insert_cab: dict[str, Any] = {
            "id_licitacion": payload.id_licitacion,
            "organization_id": _org_str(current_user),
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

    mapa_id_detalle = _get_mapa_id_detalle_by_id_producto(payload.id_licitacion, _org_str(current_user))
    lineas_a_insertar: List[dict[str, Any]] = []

    for line in payload.lineas:
        qty = float(line.cantidad)
        cost = float(line.coste_unit)
        if qty == 0 and cost == 0:
            continue
        id_producto = int(line.id_producto)
        id_detalle = line.id_detalle
        prov_linea = (line.proveedor or "").strip()
        lineas_a_insertar.append({
            "id_licitacion": payload.id_licitacion,
            "organization_id": _org_str(current_user),
            "id_entrega": new_id_entrega,
            "id_detalle": id_detalle,
            "id_producto": id_producto,
            "fecha_entrega": cabecera.fecha,
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


@router.patch("/lines/{id_real}", response_model=dict)
def update_delivery_line(id_real: int, payload: DeliveryLineUpdate, current_user: CurrentUserDep) -> dict:
    """
    Actualiza estado y/o cobrado de una línea de entrega (tbl_licitaciones_real).
    PATCH /deliveries/lines/{id_real}
    """
    updates: Dict[str, Any] = {}
    if payload.estado is not None:
        updates["estado"] = payload.estado
    if payload.cobrado is not None:
        updates["cobrado"] = payload.cobrado
    if not updates:
        return {"id_real": id_real, "message": "Nada que actualizar."}
    try:
        (
            supabase_client.table("tbl_licitaciones_real")
            .update(updates)
            .eq("id_real", id_real)
            .eq("organization_id", _org_str(current_user))
            .execute()
        )
        return {"id_real": id_real, "message": "Línea actualizada."}
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error actualizando línea: {e!s}",
        ) from e


@router.delete("/{delivery_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_delivery(delivery_id: int, current_user: CurrentUserDep) -> None:
    """
    Elimina una entrega y sus líneas (cascade manual).
    DELETE /deliveries/{id}
    """
    try:
        org_s = _org_str(current_user)
        supabase_client.table("tbl_licitaciones_real").delete().eq(
            "id_entrega", delivery_id
        ).eq("organization_id", org_s).execute()
        supabase_client.table("tbl_entregas").delete().eq(
            "id_entrega", delivery_id
        ).eq("organization_id", org_s).execute()
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error eliminando entrega: {e!s}",
        ) from e
