"""
Gestión de Gastos Extraordinarios (tbl_gastos_proyecto).

Viajes, Hoteles, Combustible, Dietas asociados a licitaciones en fase de ejecución.
Entidad separada de entregas/materiales.
"""

from decimal import Decimal
from typing import Any, Dict, List
from uuid import UUID

from fastapi import APIRouter, HTTPException, Path, status

from backend.config import supabase_client
from backend.deps import CurrentUserDep
from backend.models import (
    CurrentUser,
    ESTADOS_PERMITEN_ENTREGAS,
    ProjectExpense,
    ProjectExpenseCreate,
    ProjectExpenseUpdate,
    TipoGasto,
)


router = APIRouter(prefix="/expenses", tags=["expenses"])


def _org_str(user: CurrentUser) -> str:
    return str(user.org_id)


# Etiquetas legibles por tipo de gasto (para UI)
TIPOS_GASTO_LABELS: Dict[str, str] = {
    "ALOJAMIENTO": "Alojamiento",
    "TRANSPORTE": "Transporte",
    "DIETAS": "Dietas",
    "SUMINISTROS": "Suministros",
    "COMBUSTIBLE": "Combustible",
    "HOTEL": "Hotel",
    "OTROS": "Otros",
}


@router.get("/tipos", response_model=List[Dict[str, str]])
def list_expense_types() -> List[Dict[str, str]]:
    """
    Lista los tipos de gasto disponibles (para selectores/dropdowns).
    GET /expenses/tipos
    """
    return [
        {"value": t.value, "label": TIPOS_GASTO_LABELS.get(t.value, t.value)}
        for t in TipoGasto
    ]


def _row_to_project_expense(row: Dict[str, Any]) -> ProjectExpense:
    """Convierte una fila de Supabase a ProjectExpense."""
    return ProjectExpense(
        id=UUID(str(row["id"])),
        id_licitacion=int(row["id_licitacion"]),
        id_usuario=UUID(str(row["id_usuario"])),
        organization_id=UUID(str(row["organization_id"])),
        tipo_gasto=str(row["tipo_gasto"]),
        importe=Decimal(str(row["importe"])),
        fecha=row["fecha"],
        descripcion=row.get("descripcion") or "",
        url_comprobante=str(row["url_comprobante"]),
        estado=str(row["estado"]),
        created_at=row["created_at"].isoformat() if hasattr(row["created_at"], "isoformat") else str(row["created_at"]),
    )


@router.post("", response_model=ProjectExpense, status_code=status.HTTP_201_CREATED)
def create_expense(payload: ProjectExpenseCreate, current_user: CurrentUserDep) -> ProjectExpense:
    """
    Crea un gasto extraordinario.
    Solo permitido si la licitación está en ADJUDICADA o EJECUCIÓN (no TERMINADA).

    POST /expenses
    """
    org_s = _org_str(current_user)

    lic_resp = (
        supabase_client.table("tbl_licitaciones")
        .select("id_estado, organization_id")
        .eq("id_licitacion", payload.id_licitacion)
        .eq("organization_id", org_s)
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
            detail="No se pueden añadir gastos. La licitación debe estar en ADJUDICADA o EJECUCIÓN (no TERMINADA).",
        )

    insert_data: Dict[str, Any] = {
        "id_licitacion": payload.id_licitacion,
        "id_usuario": current_user.user_id,
        "organization_id": org_s,
        "tipo_gasto": payload.tipo_gasto.value,
        "importe": float(payload.importe),
        "fecha": payload.fecha.isoformat(),
        "descripcion": payload.descripcion or "",
        "url_comprobante": payload.url_comprobante,
    }

    try:
        res = supabase_client.table("tbl_gastos_proyecto").insert(insert_data).execute()
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error creando gasto: {e!s}",
        ) from e

    if not res.data or len(res.data) == 0:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Error creando gasto.",
        )

    return _row_to_project_expense(res.data[0])


@router.get("/licitacion/{id_licitacion}", response_model=List[ProjectExpense])
def list_expenses_by_licitacion(
    current_user: CurrentUserDep,
    id_licitacion: int = Path(..., description="ID de la licitación."),
) -> List[ProjectExpense]:
    """
    Lista los gastos extraordinarios de una licitación.

    GET /expenses/licitacion/{id}
    """
    org_s = _org_str(current_user)

    lic_resp = (
        supabase_client.table("tbl_licitaciones")
        .select("id_licitacion")
        .eq("id_licitacion", id_licitacion)
        .eq("organization_id", org_s)
        .execute()
    )
    if not lic_resp.data:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Licitación no encontrada.",
        )

    try:
        res = (
            supabase_client.table("tbl_gastos_proyecto")
            .select("*")
            .eq("id_licitacion", id_licitacion)
            .eq("organization_id", org_s)
            .order("fecha", desc=True)
            .order("created_at", desc=True)
            .execute()
        )
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error listando gastos: {e!s}",
        ) from e

    return [_row_to_project_expense(row) for row in (res.data or [])]


@router.patch("/{expense_id}/status", response_model=ProjectExpense)
def update_expense_status(
    current_user: CurrentUserDep,
    expense_id: UUID = Path(..., description="UUID del gasto."),
    payload: ProjectExpenseUpdate = ...,
) -> ProjectExpense:
    """
    Aprueba o rechaza un gasto (solo admin).
    Opcionalmente corrige el importe.

    PATCH /expenses/{id}/status
    """
    if current_user.role != "admin":
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Solo un administrador puede aprobar o rechazar gastos.",
        )

    org_s = _org_str(current_user)

    updates: Dict[str, Any] = {}
    if payload.estado is not None:
        updates["estado"] = payload.estado.value
    if payload.importe is not None:
        updates["importe"] = float(payload.importe)

    if not updates:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Debe indicar estado (APROBADO/RECHAZADO) y/o importe.",
        )

    try:
        res = (
            supabase_client.table("tbl_gastos_proyecto")
            .update(updates)
            .eq("id", str(expense_id))
            .eq("organization_id", org_s)
            .execute()
        )
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error actualizando gasto: {e!s}",
        ) from e

    if not res.data or len(res.data) == 0:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Gasto no encontrado.",
        )

    return _row_to_project_expense(res.data[0])


@router.delete("/{expense_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_expense(
    current_user: CurrentUserDep,
    expense_id: UUID = Path(..., description="UUID del gasto."),
) -> None:
    """
    Elimina un gasto. Solo permitido si está en estado PENDIENTE.

    DELETE /expenses/{id}
    """
    org_s = _org_str(current_user)

    try:
        existing = (
            supabase_client.table("tbl_gastos_proyecto")
            .select("id, estado")
            .eq("id", str(expense_id))
            .eq("organization_id", org_s)
            .execute()
        )
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error consultando gasto: {e!s}",
        ) from e

    if not existing.data or len(existing.data) == 0:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Gasto no encontrado.",
        )

    if existing.data[0].get("estado") != "PENDIENTE":
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Solo se pueden eliminar gastos en estado PENDIENTE.",
        )

    try:
        supabase_client.table("tbl_gastos_proyecto").delete().eq(
            "id", str(expense_id)
        ).eq("organization_id", org_s).execute()
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error eliminando gasto: {e!s}",
        ) from e
