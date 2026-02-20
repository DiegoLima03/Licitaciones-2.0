"""
CRUD de licitaciones (tbl_licitaciones).

Multi-tenant vía TenderService inyectado; el router solo recibe payload,
llama al servicio y mapea excepciones de dominio a HTTP.
"""

from typing import List, Optional

from fastapi import APIRouter, Depends, HTTPException, Query, status

from backend.deps import CurrentUserDep
from backend.models import PartidaCreate, PartidaUpdate, TenderCreate, TenderStatusChange, TenderUpdate
from backend.repositories.tenders_repository import TendersRepository
from backend.services.exceptions import ConflictError, NotFoundError
from backend.services.tenders_service import TenderService
from backend.config import supabase_client


router = APIRouter(prefix="/tenders", tags=["tenders"])


def get_tender_service(current_user: CurrentUserDep) -> TenderService:
    """Inyecta TenderService con repositorio scoped a la organización del usuario."""
    repo = TendersRepository(supabase_client, str(current_user.org_id))
    return TenderService(repo)


def _map_service_error(exc: Exception) -> HTTPException:
    """Mapea excepciones de dominio a HTTPException."""
    if isinstance(exc, NotFoundError):
        return HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=exc.message)
    if isinstance(exc, ConflictError):
        return HTTPException(status_code=status.HTTP_409_CONFLICT, detail=exc.message)
    if isinstance(exc, ValueError):
        return HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc))
    raise exc


@router.get("", response_model=List[dict])
def list_tenders(
    current_user: CurrentUserDep,
    estado_id: Optional[int] = Query(None, description="Filtrar por id_estado."),
    nombre: Optional[str] = Query(None, description="Buscar por nombre (ilike)."),
    pais: Optional[str] = Query(None, description="Filtrar por país: España o Portugal."),
    service: TenderService = Depends(get_tender_service),
) -> List[dict]:
    """Lista licitaciones con filtros opcionales. Solo de la organización del usuario."""
    try:
        return service.list_tenders(estado_id=estado_id, nombre=nombre, pais=pais)
    except (NotFoundError, ConflictError, ValueError) as e:
        raise _map_service_error(e)


@router.get("/parents", response_model=List[dict])
def list_parent_tenders(
    current_user: CurrentUserDep,
    service: TenderService = Depends(get_tender_service),
) -> List[dict]:
    """Lista licitaciones que pueden ser padre (AM/SDA) y están adjudicadas. Para selector al crear BASADO_AM/ESPECIFICO_SDA."""
    try:
        return service.get_parent_tenders()
    except (NotFoundError, ConflictError, ValueError) as e:
        raise _map_service_error(e)


@router.get("/{tender_id}", response_model=dict)
def get_tender(
    tender_id: int,
    current_user: CurrentUserDep,
    service: TenderService = Depends(get_tender_service),
) -> dict:
    """Detalle de licitación con partidas. Solo si pertenece a la organización."""
    try:
        return service.get_tender(tender_id)
    except (NotFoundError, ConflictError, ValueError) as e:
        raise _map_service_error(e)


@router.post("", response_model=dict, status_code=status.HTTP_201_CREATED)
def create_tender(
    payload: TenderCreate,
    current_user: CurrentUserDep,
    service: TenderService = Depends(get_tender_service),
) -> dict:
    """Crea licitación en estado EN_ANALISIS. organization_id del usuario."""
    try:
        return service.create_tender(payload)
    except (NotFoundError, ConflictError, ValueError) as e:
        raise _map_service_error(e)


@router.put("/{tender_id}", response_model=dict)
def update_tender(
    tender_id: int,
    payload: TenderUpdate,
    current_user: CurrentUserDep,
    service: TenderService = Depends(get_tender_service),
) -> dict:
    """Actualiza licitación. Si estado >= PRESENTADA solo campos informativos."""
    try:
        return service.update_tender(tender_id, payload)
    except (NotFoundError, ConflictError, ValueError) as e:
        raise _map_service_error(e)


@router.delete("/{tender_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_tender(
    tender_id: int,
    current_user: CurrentUserDep,
    service: TenderService = Depends(get_tender_service),
) -> None:
    """Borra licitación. Dependencias por CASCADE en BD."""
    try:
        service.delete_tender(tender_id)
    except (NotFoundError, ConflictError, ValueError) as e:
        raise _map_service_error(e)


@router.post("/{tender_id}/change-status", response_model=dict)
def change_tender_status(
    tender_id: int,
    payload: TenderStatusChange,
    current_user: CurrentUserDep,
    service: TenderService = Depends(get_tender_service),
) -> dict:
    """Cambia estado de la licitación (máquina de estados). Valida reglas de negocio."""
    try:
        return service.change_tender_status(tender_id, payload)
    except (NotFoundError, ConflictError, ValueError) as e:
        raise _map_service_error(e)


@router.post("/{tender_id}/partidas", response_model=dict, status_code=status.HTTP_201_CREATED)
def add_partida(
    tender_id: int,
    payload: PartidaCreate,
    current_user: CurrentUserDep,
    service: TenderService = Depends(get_tender_service),
) -> dict:
    """Añade partida al presupuesto. Bloqueado si estado >= PRESENTADA."""
    try:
        return service.add_partida(tender_id, payload)
    except (NotFoundError, ConflictError, ValueError) as e:
        raise _map_service_error(e)


@router.put("/{tender_id}/partidas/{detalle_id}", response_model=dict)
def update_partida(
    tender_id: int,
    detalle_id: int,
    payload: PartidaUpdate,
    current_user: CurrentUserDep,
    service: TenderService = Depends(get_tender_service),
) -> dict:
    """Actualiza partida. Bloqueado si estado >= PRESENTADA."""
    try:
        return service.update_partida(tender_id, detalle_id, payload)
    except (NotFoundError, ConflictError, ValueError) as e:
        raise _map_service_error(e)


@router.delete("/{tender_id}/partidas/{detalle_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_partida(
    tender_id: int,
    detalle_id: int,
    current_user: CurrentUserDep,
    service: TenderService = Depends(get_tender_service),
) -> None:
    """Elimina partida. Bloqueado si estado >= PRESENTADA."""
    try:
        service.delete_partida(tender_id, detalle_id)
    except (NotFoundError, ConflictError, ValueError) as e:
        raise _map_service_error(e)
