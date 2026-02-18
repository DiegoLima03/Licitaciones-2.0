"""
CRUD de licitaciones (tbl_licitaciones).
Migrado desde src/views/tenders_list.py y tenders_detail.py.

Multi-tenant: Todas las operaciones filtran por organization_id del usuario autenticado.
Flujo de estados: POST /tenders/{id}/change-status con validaciones de negocio.
"""

from datetime import date
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, Depends, HTTPException, Query, status

from backend.config import supabase_client
from backend.deps import CurrentUserDep
from backend.models import (
    EstadoLicitacion,
    ESTADOS_BLOQUEO_EDICION,
    PartidaCreate,
    PartidaUpdate,
    TenderCreate,
    TenderStatusChange,
    TenderUpdate,
)


router = APIRouter(prefix="/tenders", tags=["tenders"])


def _get_tender_or_404(tender_id: int, org_id: str) -> dict:
    """Obtiene una licitación por ID o lanza 404."""
    resp = (
        supabase_client.table("tbl_licitaciones")
        .select("*")
        .eq("id_licitacion", tender_id)
        .eq("organization_id", org_id)
        .single()
        .execute()
    )
    if not resp.data:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Licitación no encontrada.")
    return resp.data


def _suma_presupuesto_licitacion(tender_id: int, org_id: str) -> float:
    """Suma de (unidades * pvu) de partidas activas. Para validar PRESENTADA."""
    resp = (
        supabase_client.table("tbl_licitaciones_detalle")
        .select("unidades, pvu")
        .eq("id_licitacion", tender_id)
        .eq("organization_id", org_id)
        .eq("activo", True)
        .execute()
    )
    total = 0.0
    for r in resp.data or []:
        u = float(r.get("unidades") or 0)
        p = float(r.get("pvu") or 0)
        total += u * p
    return total


@router.get("", response_model=List[dict])
def list_tenders(
    current_user: CurrentUserDep,
    estado_id: Optional[int] = Query(None, description="Filtrar por id_estado."),
    nombre: Optional[str] = Query(None, description="Buscar por nombre (ilike)."),
    pais: Optional[str] = Query(None, description="Filtrar por país: España o Portugal."),
) -> List[dict]:
    """
    Lista licitaciones con filtros opcionales.
    Solo devuelve licitaciones de la organización del usuario.
    """
    try:
        query = (
            supabase_client.table("tbl_licitaciones")
            .select("*")
            .eq("organization_id", str(current_user.org_id))
            .order("id_licitacion", desc=True)
        )
        if estado_id is not None:
            query = query.eq("id_estado", estado_id)
        if nombre:
            query = query.ilike("nombre", f"%{nombre}%")
        if pais and pais.strip():
            query = query.eq("pais", pais.strip())
        response = query.execute()
        return response.data or []
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error listando licitaciones: {e!s}",
        ) from e


@router.get("/{tender_id}", response_model=dict)
def get_tender(tender_id: int, current_user: CurrentUserDep) -> dict:
    """
    Obtiene el detalle completo de una licitación por ID, con sus partidas.
    Solo si pertenece a la organización del usuario.
    """
    try:
        response = (
            supabase_client.table("tbl_licitaciones")
            .select("*")
            .eq("id_licitacion", tender_id)
            .eq("organization_id", str(current_user.org_id))
            .single()
            .execute()
        )
        if not response.data:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Licitación no encontrada.",
            )
        licitacion: dict = response.data

        partidas_resp = (
            supabase_client.table("tbl_licitaciones_detalle")
            .select("*, tbl_productos(nombre)")
            .eq("id_licitacion", tender_id)
            .eq("organization_id", str(current_user.org_id))
            .order("lote")
            .order("id_detalle")
            .execute()
        )
        raw_partidas: List[dict] = partidas_resp.data or []
        partidas: List[dict] = []
        for p in raw_partidas:
            prod = p.get("tbl_productos") or {}
            partidas.append({
                **{k: v for k, v in p.items() if k != "tbl_productos"},
                "product_nombre": prod.get("nombre"),
            })

        return {
            **licitacion,
            "partidas": partidas,
        }
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error obteniendo licitación: {e!s}",
        ) from e


@router.post("/{tender_id}/change-status", response_model=dict)
def change_tender_status(
    tender_id: int,
    payload: TenderStatusChange,
    current_user: CurrentUserDep,
) -> dict:
    """
    Cambia el estado de una licitación (máquina de estados finita).

    POST /tenders/{id}/change-status
    Valida transiciones y reglas de negocio antes de aplicar el cambio.
    """
    org_id_str = str(current_user.org_id)
    licitacion = _get_tender_or_404(tender_id, org_id_str)
    id_estado_actual = int(licitacion.get("id_estado", 0))
    nuevo_id = payload.nuevo_estado_id

    # No hacer nada si es el mismo estado
    if id_estado_actual == nuevo_id:
        return {**licitacion, "message": "El estado ya era el solicitado."}

    # Resolver enums si aplica
    try:
        nuevo_estado = EstadoLicitacion(nuevo_id)
    except ValueError:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Estado {nuevo_id} no válido.",
        )

    # --- VALIDACIONES DE TRANSICIÓN ---

    if nuevo_estado == EstadoLicitacion.PRESENTADA:
        suma = _suma_presupuesto_licitacion(tender_id, org_id_str)
        if suma <= 0:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="No se puede presentar a coste cero. La suma del presupuesto (partidas activas) debe ser > 0.",
            )

    if nuevo_estado == EstadoLicitacion.EJECUCION:
        if id_estado_actual != EstadoLicitacion.ADJUDICADA.value:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="Solo se puede pasar a EJECUCIÓN desde ADJUDICADA.",
            )

    # Validaciones de payload (motivo_descarte, motivo_perdida, etc.) ya las hace Pydantic

    # --- APLICAR CAMBIO ---
    update_data: Dict[str, Any] = {"id_estado": nuevo_id}

    if nuevo_estado == EstadoLicitacion.ADJUDICADA and payload.importe_adjudicacion is not None:
        update_data["pres_maximo"] = payload.importe_adjudicacion
    if payload.fecha_adjudicacion is not None:
        update_data["fecha_adjudicacion"] = payload.fecha_adjudicacion.isoformat()
    if nuevo_estado == EstadoLicitacion.PRESENTADA:
        if not licitacion.get("fecha_presentacion"):
            update_data["fecha_presentacion"] = date.today().isoformat()

    # Guardar motivo en descripcion si se dispone (o en campo dedicado si existiera)
    if nuevo_estado == EstadoLicitacion.DESCARTADA and payload.motivo_descarte:
        desc_actual = licitacion.get("descripcion") or ""
        update_data["descripcion"] = f"{desc_actual}\n[MOTIVO DESCARTE]: {payload.motivo_descarte}".strip()
    if nuevo_estado == EstadoLicitacion.NO_ADJUDICADA and (payload.motivo_perdida or payload.competidor_ganador):
        desc_actual = licitacion.get("descripcion") or ""
        partes = []
        if payload.motivo_perdida:
            partes.append(f"Motivo: {payload.motivo_perdida}")
        if payload.competidor_ganador:
            partes.append(f"Ganador: {payload.competidor_ganador}")
        update_data["descripcion"] = f"{desc_actual}\n[PERDIDA]: {' | '.join(partes)}".strip()

    resp = (
        supabase_client.table("tbl_licitaciones")
        .update(update_data)
        .eq("id_licitacion", tender_id)
        .eq("organization_id", org_id_str)
        .execute()
    )
    if not resp.data:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Licitación no encontrada.")
    return {**resp.data[0], "message": "Estado actualizado correctamente."}


def _esta_bloqueado_edicion(tender_id: int, org_id_str: str) -> bool:
    """True si la licitación está en un estado que bloquea edición económica."""
    lic = _get_tender_or_404(tender_id, org_id_str)
    id_est = int(lic.get("id_estado", 0))
    return id_est in {e.value for e in ESTADOS_BLOQUEO_EDICION}


@router.post("/{tender_id}/partidas", response_model=dict, status_code=status.HTTP_201_CREATED)
def add_partida(tender_id: int, payload: PartidaCreate, current_user: CurrentUserDep) -> dict:
    """
    Añade una partida manual al presupuesto de la licitación.
    Solo si la licitación pertenece a la organización del usuario.
    Bloqueado si el estado es >= PRESENTADA.
    """
    org_id_str = str(current_user.org_id)
    if _esta_bloqueado_edicion(tender_id, org_id_str):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="No se pueden modificar partidas cuando la licitación ya está presentada o posterior.",
        )
    try:
        check = (
            supabase_client.table("tbl_licitaciones")
            .select("id_licitacion, organization_id")
            .eq("id_licitacion", tender_id)
            .eq("organization_id", org_id_str)
            .execute()
        )
        if not check.data:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Licitación no encontrada.",
            )
        row: dict[str, Any] = {
            "id_licitacion": tender_id,
            "organization_id": org_id_str,
            "lote": payload.lote or "General",
            "id_producto": payload.id_producto,
            "unidades": payload.unidades if payload.unidades is not None else 1.0,
            "pvu": payload.pvu or 0.0,
            "pcu": payload.pcu or 0.0,
            "pmaxu": payload.pmaxu or 0.0,
            "activo": payload.activo if payload.activo is not None else True,
        }
        response = (
            supabase_client.table("tbl_licitaciones_detalle").insert(row).execute()
        )
        if not response.data:
            raise HTTPException(
                status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
                detail="No se devolvió la partida creada.",
            )
        return response.data[0]
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error creando partida: {e!s}",
        ) from e


@router.put("/{tender_id}/partidas/{detalle_id}", response_model=dict)
def update_partida(tender_id: int, detalle_id: int, payload: PartidaUpdate, current_user: CurrentUserDep) -> dict:
    """
    Actualiza una partida existente del presupuesto (tbl_licitaciones_detalle).
    Bloqueado si el estado es >= PRESENTADA.

    PUT /tenders/{id}/partidas/{detalle_id}
    """
    org_id_str = str(current_user.org_id)
    if _esta_bloqueado_edicion(tender_id, org_id_str):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="No se pueden modificar partidas cuando la licitación ya está presentada o posterior.",
        )
    try:
        check = (
            supabase_client.table("tbl_licitaciones_detalle")
            .select("id_detalle")
            .eq("id_licitacion", tender_id)
            .eq("id_detalle", detalle_id)
            .eq("organization_id", str(current_user.org_id))
            .execute()
        )
        if not check.data:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Partida no encontrada.",
            )
        update_data = payload.model_dump(exclude_unset=True)
        if not update_data:
            partidas_resp = (
                supabase_client.table("tbl_licitaciones_detalle")
                .select("*, tbl_productos(nombre)")
                .eq("id_licitacion", tender_id)
                .eq("id_detalle", detalle_id)
                .eq("organization_id", str(current_user.org_id))
                .single()
                .execute()
            )
            if not partidas_resp.data:
                raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Partida no encontrada.")
            p = partidas_resp.data
            prod = p.get("tbl_productos") or {}
            return {**{k: v for k, v in p.items() if k != "tbl_productos"}, "product_nombre": prod.get("nombre")}
        response = (
            supabase_client.table("tbl_licitaciones_detalle")
            .update(update_data)
            .eq("id_licitacion", tender_id)
            .eq("id_detalle", detalle_id)
            .eq("organization_id", str(current_user.org_id))
            .execute()
        )
        if not response.data:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Partida no encontrada.",
            )
        return response.data[0]
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error actualizando partida: {e!s}",
        ) from e


@router.delete("/{tender_id}/partidas/{detalle_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_partida(tender_id: int, detalle_id: int, current_user: CurrentUserDep) -> None:
    """
    Elimina una partida del presupuesto (tbl_licitaciones_detalle).
    Bloqueado si el estado es >= PRESENTADA.

    DELETE /tenders/{id}/partidas/{detalle_id}
    """
    org_id_str = str(current_user.org_id)
    if _esta_bloqueado_edicion(tender_id, org_id_str):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="No se pueden modificar partidas cuando la licitación ya está presentada o posterior.",
        )
    try:
        supabase_client.table("tbl_licitaciones_detalle").delete().eq(
            "id_licitacion", tender_id
        ).eq("id_detalle", detalle_id        ).eq("organization_id", org_id_str).execute()
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error eliminando partida: {e!s}",
        ) from e


@router.post("", response_model=dict, status_code=status.HTTP_201_CREATED)
def create_tender(payload: TenderCreate, current_user: CurrentUserDep) -> dict:
    """
    Crea una nueva licitación.
    organization_id se asigna automáticamente desde el usuario autenticado.
    """
    try:
        row: dict[str, Any] = {
            "organization_id": str(current_user.org_id),
            "nombre": payload.nombre,
            "pais": payload.pais,
            "numero_expediente": payload.numero_expediente or "",
            "pres_maximo": payload.pres_maximo or 0.0,
            "descripcion": payload.descripcion or "",
            "id_estado": payload.id_estado,
            "id_tipolicitacion": payload.id_tipolicitacion,
            "fecha_presentacion": payload.fecha_presentacion,
            "fecha_adjudicacion": payload.fecha_adjudicacion,
            "fecha_finalizacion": payload.fecha_finalizacion,
        }
        response = supabase_client.table("tbl_licitaciones").insert(row).execute()
        if not response.data:
            raise HTTPException(
                status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
                detail="No se devolvió la licitación creada.",
            )
        return response.data[0]
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error creando licitación: {e!s}",
        ) from e


# Campos que no se pueden modificar cuando estado >= PRESENTADA (usar change-status para estado)
CAMPOS_BLOQUEADOS_EDICION = {"pres_maximo", "descuento_global", "id_estado", "fecha_presentacion", "fecha_adjudicacion", "fecha_finalizacion", "pais"}


@router.put("/{tender_id}", response_model=dict)
def update_tender(tender_id: int, payload: TenderUpdate, current_user: CurrentUserDep) -> dict:
    """
    Actualiza una licitación existente.
    Si estado >= PRESENTADA: solo permite editar descripcion, nombre, numero_expediente (campos informativos).
    Para cambiar estado use POST /tenders/{id}/change-status.

    PUT /tenders/{id}
    """
    org_id_str = str(current_user.org_id)
    update_data = payload.model_dump(exclude_unset=True)
    if not update_data:
        return get_tender(tender_id, current_user)

    if _esta_bloqueado_edicion(tender_id, org_id_str):
        bloqueados_enviados = set(update_data.keys()) & CAMPOS_BLOQUEADOS_EDICION
        if bloqueados_enviados:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail=f"No se pueden modificar campos económicos ni fechas cuando la licitación está presentada o posterior. Use POST /tenders/{{id}}/change-status para cambiar estado. Campos bloqueados enviados: {', '.join(sorted(bloqueados_enviados))}",
            )
        # Solo permitir: descripcion, nombre, numero_expediente, id_tipolicitacion
        permitidos = {"descripcion", "nombre", "numero_expediente", "id_tipolicitacion"}
        update_data = {k: v for k, v in update_data.items() if k in permitidos}

    try:
        response = (
            supabase_client.table("tbl_licitaciones")
            .update(update_data)
            .eq("id_licitacion", tender_id)
            .eq("organization_id", org_id_str)
            .execute()
        )
        if not response.data:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Licitación no encontrada.",
            )
        return response.data[0]
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error actualizando licitación: {e!s}",
        ) from e


@router.delete("/{tender_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_tender(tender_id: int, current_user: CurrentUserDep) -> None:
    """
    Borra una licitación y sus datos relacionados (cascade manual).
    Orden: tbl_licitaciones_real -> tbl_entregas -> tbl_licitaciones_detalle -> tbl_licitaciones.

    DELETE /tenders/{id}
    """
    try:
        # Verificar que existe
        check = (
            supabase_client.table("tbl_licitaciones")
            .select("id_licitacion")
            .eq("id_licitacion", tender_id)
            .eq("organization_id", str(current_user.org_id))
            .execute()
        )
        if not check.data:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Licitación no encontrada.",
            )
        org_id_str = str(current_user.org_id)
        supabase_client.table("tbl_licitaciones_real").delete().eq(
            "id_licitacion", tender_id
        ).eq("organization_id", org_id_str).execute()
        supabase_client.table("tbl_entregas").delete().eq(
            "id_licitacion", tender_id
        ).eq("organization_id", org_id_str).execute()
        supabase_client.table("tbl_licitaciones_detalle").delete().eq(
            "id_licitacion", tender_id
        ).eq("organization_id", org_id_str).execute()
        supabase_client.table("tbl_licitaciones").delete().eq(
            "id_licitacion", tender_id
        ).eq("organization_id", org_id_str).execute()
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error eliminando licitación: {e!s}",
        ) from e
