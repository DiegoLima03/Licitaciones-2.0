"""
Servicio de licitaciones: lógica de negocio aislada de HTTP.

Recibe TendersRepository por inyección. Lanza excepciones de dominio
(ValueError, NotFoundError, ConflictError), no HTTPException.
"""

from datetime import date
from decimal import Decimal
from typing import Any, Dict, List, Optional

from backend.models import (
    ESTADOS_BLOQUEO_EDICION,
    EstadoLicitacion,
    PartidaCreate,
    PartidaUpdate,
    TenderCreate,
    TenderStatusChange,
    TenderUpdate,
)
from backend.repositories.tenders_repository import TendersRepository
from backend.services.exceptions import ConflictError, NotFoundError

# Campos que no se pueden modificar cuando estado >= PRESENTADA
CAMPOS_BLOQUEADOS_EDICION = {
    "pres_maximo", "descuento_global", "id_estado",
    "fecha_presentacion", "fecha_adjudicacion", "fecha_finalizacion", "pais",
}
CAMPOS_PERMITIDOS_CUANDO_BLOQUEADO = {
    "descripcion", "nombre", "numero_expediente", "id_tipolicitacion", "enlace_gober", "lotes_config",
}


class TenderService:
    """Lógica de negocio de licitaciones y partidas. Multi-tenant vía repositorio."""

    def __init__(self, repository: TendersRepository) -> None:
        self._repo = repository

    def list_tenders(
        self,
        estado_id: Optional[int] = None,
        nombre: Optional[str] = None,
        pais: Optional[str] = None,
    ) -> List[Dict[str, Any]]:
        """Lista licitaciones con filtros opcionales."""
        return self._repo.list_tenders(estado_id=estado_id, nombre=nombre, pais=pais)

    def get_tender(self, tender_id: int) -> Dict[str, Any]:
        """Detalle de licitación con partidas. Lanza NotFoundError si no existe."""
        out = self._repo.get_tender_with_details(tender_id)
        if not out:
            raise NotFoundError("Licitación no encontrada.")
        return out

    def create_tender(self, payload: TenderCreate) -> Dict[str, Any]:
        """Crea licitación en estado EN_ANALISIS. organization_id lo inyecta el repo."""
        row: Dict[str, Any] = {
            "nombre": payload.nombre,
            "pais": payload.pais,
            "numero_expediente": payload.numero_expediente or "",
            "pres_maximo": float(payload.pres_maximo) if payload.pres_maximo is not None else 0.0,
            "descripcion": payload.descripcion or "",
            "enlace_gober": payload.enlace_gober or None,
            "id_estado": EstadoLicitacion.EN_ANALISIS.value,
            "id_tipolicitacion": payload.id_tipolicitacion,
            "fecha_presentacion": payload.fecha_presentacion,
            "fecha_adjudicacion": payload.fecha_adjudicacion,
            "fecha_finalizacion": payload.fecha_finalizacion,
        }
        return self._repo.create(row)

    def _is_edition_blocked(self, tender_id: int) -> bool:
        """True si la licitación está en un estado que bloquea edición económica."""
        lic = self._repo.get_by_id(tender_id)
        if not lic:
            return True
        id_est = int(lic.get("id_estado", 0))
        return id_est in {e.value for e in ESTADOS_BLOQUEO_EDICION}

    def update_tender(self, tender_id: int, payload: TenderUpdate) -> Dict[str, Any]:
        """Actualiza licitación. Si estado >= PRESENTADA solo permite campos informativos."""
        self.get_tender(tender_id)  # asegura existencia
        update_data = payload.model_dump(exclude_unset=True, mode="json")
        if not update_data:
            return self.get_tender(tender_id)

        if self._is_edition_blocked(tender_id):
            bloqueados = set(update_data.keys()) & CAMPOS_BLOQUEADOS_EDICION
            if bloqueados:
                raise ValueError(
                    "No se pueden modificar campos económicos ni fechas cuando la licitación está "
                    "presentada o posterior. Use change-status para cambiar estado. "
                    f"Campos bloqueados enviados: {', '.join(sorted(bloqueados))}"
                )
            update_data = {k: v for k, v in update_data.items() if k in CAMPOS_PERMITIDOS_CUANDO_BLOQUEADO}

        updated = self._repo.update(tender_id, update_data)
        return updated

    def delete_tender(self, tender_id: int) -> None:
        """Elimina licitación. Lanza NotFoundError si no existe."""
        existing = self._repo.get_by_id(tender_id)
        if not existing:
            raise NotFoundError("Licitación no encontrada.")
        self._repo.delete(tender_id)

    def change_tender_status(self, tender_id: int, payload: TenderStatusChange) -> Dict[str, Any]:
        """
        Máquina de estados: valida transiciones y reglas de negocio, luego actualiza.
        Lanza ValueError para reglas de negocio, ConflictError si el estado cambió en concurrencia.
        """
        licitacion = self._repo.get_by_id(tender_id)
        if not licitacion:
            raise NotFoundError("Licitación no encontrada.")

        id_estado_actual = int(licitacion.get("id_estado", 0))
        nuevo_id = payload.nuevo_estado_id

        if id_estado_actual == nuevo_id:
            return {**licitacion, "message": "El estado ya era el solicitado."}

        try:
            nuevo_estado = EstadoLicitacion(nuevo_id)
        except ValueError:
            raise ValueError(f"Estado {nuevo_id} no válido.")

        if nuevo_estado == EstadoLicitacion.PRESENTADA:
            total = self._repo.get_active_budget_total(tender_id)
            if total <= Decimal("0"):
                raise ValueError(
                    "No se puede presentar a coste cero. La suma del presupuesto (partidas activas) debe ser > 0."
                )

        update_data: Dict[str, Any] = {"id_estado": nuevo_id}

        if nuevo_estado == EstadoLicitacion.ADJUDICADA and payload.importe_adjudicacion is not None:
            update_data["pres_maximo"] = float(payload.importe_adjudicacion)
        if payload.fecha_adjudicacion is not None:
            update_data["fecha_adjudicacion"] = payload.fecha_adjudicacion.isoformat()
        if nuevo_estado == EstadoLicitacion.PRESENTADA and not licitacion.get("fecha_presentacion"):
            update_data["fecha_presentacion"] = date.today().isoformat()

        if nuevo_estado == EstadoLicitacion.DESCARTADA and payload.motivo_descarte:
            desc = licitacion.get("descripcion") or ""
            update_data["descripcion"] = f"{desc}\n[MOTIVO DESCARTE]: {payload.motivo_descarte}".strip()
        if nuevo_estado == EstadoLicitacion.NO_ADJUDICADA and (payload.motivo_perdida or payload.competidor_ganador):
            desc = licitacion.get("descripcion") or ""
            partes = []
            if payload.motivo_perdida:
                partes.append(f"Motivo: {payload.motivo_perdida}")
            if payload.competidor_ganador:
                partes.append(f"Ganador: {payload.competidor_ganador}")
            update_data["descripcion"] = f"{desc}\n[PERDIDA]: {' | '.join(partes)}".strip()

        result = self._repo.update_tender_with_state_check(tender_id, update_data, id_estado_actual)
        if not result:
            raise ConflictError(
                "Conflicto de concurrencia: el estado de la licitación cambió. Recarga y vuelve a intentar."
            )
        return {**result, "message": "Estado actualizado correctamente."}

    def add_partida(self, tender_id: int, payload: PartidaCreate) -> Dict[str, Any]:
        """Añade partida. Lanza ValueError si la licitación está en estado de edición bloqueada."""
        if not self._repo.get_by_id(tender_id):
            raise NotFoundError("Licitación no encontrada.")
        if self._is_edition_blocked(tender_id):
            raise ValueError("No se pueden modificar partidas cuando la licitación ya está presentada o posterior.")

        row: Dict[str, Any] = {
            "lote": payload.lote or "General",
            "id_producto": payload.id_producto,
            "unidades": payload.unidades if payload.unidades is not None else 1.0,
            "pvu": float(payload.pvu) if payload.pvu is not None else 0.0,
            "pcu": float(payload.pcu) if payload.pcu is not None else 0.0,
            "pmaxu": float(payload.pmaxu) if payload.pmaxu is not None else 0.0,
            "activo": payload.activo if payload.activo is not None else True,
        }
        return self._repo.add_partida(tender_id, row)

    def update_partida(self, tender_id: int, detalle_id: int, payload: PartidaUpdate) -> Dict[str, Any]:
        """Actualiza partida. Lanza ValueError si edición bloqueada."""
        if not self._repo.get_by_id(tender_id):
            raise NotFoundError("Licitación no encontrada.")
        if self._is_edition_blocked(tender_id):
            raise ValueError("No se pueden modificar partidas cuando la licitación ya está presentada o posterior.")

        update_data = payload.model_dump(exclude_unset=True, mode="json")
        if not update_data:
            partida = self._repo.get_partida(tender_id, detalle_id)
            if not partida:
                raise NotFoundError("Partida no encontrada.")
            return partida
        try:
            return self._repo.update_partida(tender_id, detalle_id, update_data)
        except ValueError:
            raise NotFoundError("Partida no encontrada.")

    def delete_partida(self, tender_id: int, detalle_id: int) -> None:
        """Elimina partida. Lanza ValueError si edición bloqueada."""
        if not self._repo.get_by_id(tender_id):
            raise NotFoundError("Licitación no encontrada.")
        if self._is_edition_blocked(tender_id):
            raise ValueError("No se pueden modificar partidas cuando la licitación ya está presentada o posterior.")
        try:
            self._repo.delete_partida(tender_id, detalle_id)
        except ValueError:
            raise NotFoundError("Partida no encontrada.")
