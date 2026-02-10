"""
CRUD de licitaciones (tbl_licitaciones).
Migrado desde src/views/tenders_list.py y tenders_detail.py.
"""

from typing import Any, List, Optional

from fastapi import APIRouter, HTTPException, Query, status

from backend.config import supabase_client
from backend.models import TenderCreate, TenderUpdate


router = APIRouter(prefix="/tenders", tags=["tenders"])


@router.get("", response_model=List[dict])
def list_tenders(
    estado_id: Optional[int] = Query(None, description="Filtrar por id_estado."),
    nombre: Optional[str] = Query(None, description="Buscar por nombre (ilike)."),
) -> List[dict]:
    """
    Lista licitaciones con filtros opcionales.

    GET /tenders
    GET /tenders?estado_id=1
    GET /tenders?nombre=obra
    """
    try:
        query = (
            supabase_client.table("tbl_licitaciones")
            .select("*")
            .order("id_licitacion", desc=True)
        )
        if estado_id is not None:
            query = query.eq("id_estado", estado_id)
        if nombre:
            query = query.ilike("nombre", f"%{nombre}%")
        response = query.execute()
        return response.data or []
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error listando licitaciones: {e!s}",
        ) from e


@router.get("/{tender_id}", response_model=dict)
def get_tender(tender_id: int) -> dict:
    """
    Obtiene el detalle completo de una licitación por ID, con sus partidas
    (join con tbl_licitaciones_detalle), según lógica de src/views/tenders_detail.py.

    GET /tenders/{id}
    """
    try:
        response = (
            supabase_client.table("tbl_licitaciones")
            .select("*")
            .eq("id_licitacion", tender_id)
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
            .select("*")
            .eq("id_licitacion", tender_id)
            .order("lote")
            .order("id_detalle")
            .execute()
        )
        partidas: List[dict] = partidas_resp.data or []

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


@router.post("", response_model=dict, status_code=status.HTTP_201_CREATED)
def create_tender(payload: TenderCreate) -> dict:
    """
    Crea una nueva licitación.

    POST /tenders
    """
    try:
        row: dict[str, Any] = {
            "nombre": payload.nombre,
            "numero_expediente": payload.numero_expediente or "",
            "pres_maximo": payload.pres_maximo or 0.0,
            "descripcion": payload.descripcion or "",
            "id_estado": payload.id_estado,
            "tipo_de_licitacion": payload.tipo_de_licitacion,
            "fecha_presentacion": payload.fecha_presentacion,
            "fecha_adjudicacion": payload.fecha_adjudicacion,
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


@router.put("/{tender_id}", response_model=dict)
def update_tender(tender_id: int, payload: TenderUpdate) -> dict:
    """
    Actualiza una licitación existente.

    PUT /tenders/{id}
    """
    try:
        update_data = payload.model_dump(exclude_unset=True)
        if not update_data:
            return get_tender(tender_id)
        response = (
            supabase_client.table("tbl_licitaciones")
            .update(update_data)
            .eq("id_licitacion", tender_id)
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
