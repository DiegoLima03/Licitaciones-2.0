"""
Servicio de licitaciones: lógica de negocio aislada de HTTP.

Recibe TendersRepository por inyección. Lanza excepciones de dominio
(ValueError, NotFoundError, ConflictError), no HTTPException.
"""

import logging
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
    TipoProcedimiento,
)
from backend.repositories.tenders_repository import TendersRepository
from backend.services.exceptions import ConflictError, NotFoundError

# Campos que no se pueden modificar cuando estado >= PRESENTADA
CAMPOS_BLOQUEADOS_EDICION = {
    "pres_maximo", "descuento_global", "id_estado",
    "fecha_presentacion", "fecha_adjudicacion", "fecha_finalizacion", "pais",
}
CAMPOS_PERMITIDOS_CUANDO_BLOQUEADO = {
    "descripcion", "nombre", "numero_expediente", "id_tipolicitacion", "enlace_gober", "enlace_sharepoint", "lotes_config",
    "tipo_procedimiento", "id_licitacion_padre",
}

logger = logging.getLogger(__name__)


def _mutate_gober_url(url: Optional[str]) -> Optional[str]:
    """
    Mutador de URL Gover: si contiene '/tenders/', se reemplaza por '/public/' antes de guardar.
    """
    if not url or "/tenders/" not in url:
        return url
    mutated = url.replace("/tenders/", "/public/", 1)
    logger.info("Gover URL mutada: /tenders/ -> /public/ (guardando en BD)")
    return mutated


def _normalize_lotes_config(lotes: Any) -> List[Dict[str, Any]]:
    """
    Normaliza lotes a lista simple: solo los lotes a los que Veraleza se presenta.
    Solo se guardan nombre y ganado; se ignora trazabilidad de lotes descartados.
    """
    if not lotes or not isinstance(lotes, list):
        return []
    out: List[Dict[str, Any]] = []
    for x in lotes:
        if not isinstance(x, dict):
            continue
        out.append({
            "nombre": str(x.get("nombre", "")).strip() or "Lote",
            "ganado": bool(x.get("ganado", False)),
        })
    return out


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
        """Lista licitaciones con filtros opcionales. Contratos Basado (id_licitacion_padre) se tratan como licitaciones estándar e individuales en el listado, sin subdivisiones de lotes anidadas."""
        return self._repo.list_tenders(estado_id=estado_id, nombre=nombre, pais=pais)

    def get_tender(self, tender_id: int) -> Dict[str, Any]:
        """Detalle de licitación con partidas. Lanza NotFoundError si no existe."""
        out = self._repo.get_tender_with_details(tender_id)
        if not out:
            raise NotFoundError("Licitación no encontrada.")
        return out

    def get_parent_tenders(self) -> List[Dict[str, Any]]:
        """Licitaciones AM/SDA adjudicadas para usar como padre al crear CONTRATO_BASADO."""
        return self._repo.get_parent_tenders()

    def create_tender(self, payload: TenderCreate) -> Dict[str, Any]:
        """Crea licitación en estado EN_ANALISIS. organization_id lo inyecta el repo.
        Si se pasa id_licitacion_padre, se fuerza tipo_procedimiento a CONTRATO_BASADO.
        URL Gover: se muta /tenders/ -> /public/ antes de guardar."""
        if payload.id_licitacion_padre is not None:
            tipo = TipoProcedimiento.CONTRATO_BASADO
        else:
            tipo = payload.tipo_procedimiento if payload.tipo_procedimiento is not None else TipoProcedimiento.ORDINARIO
        row: Dict[str, Any] = {
            "nombre": payload.nombre,
            "pais": payload.pais,
            "numero_expediente": payload.numero_expediente or "",
            "pres_maximo": float(payload.pres_maximo) if payload.pres_maximo is not None else 0.0,
            "descripcion": payload.descripcion or "",
            "enlace_gober": _mutate_gober_url(payload.enlace_gober or None),
            "enlace_sharepoint": (payload.enlace_sharepoint or "").strip() or None,
            "id_estado": EstadoLicitacion.EN_ANALISIS.value,
            "id_tipolicitacion": payload.id_tipolicitacion,
            "fecha_presentacion": payload.fecha_presentacion,
            "fecha_adjudicacion": payload.fecha_adjudicacion,
            "fecha_finalizacion": payload.fecha_finalizacion,
            "tipo_procedimiento": tipo.value if isinstance(tipo, TipoProcedimiento) else tipo,
            "id_licitacion_padre": payload.id_licitacion_padre,
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
        """Actualiza licitación. Si estado >= PRESENTADA solo permite campos informativos.
        URL Gover: se muta /tenders/ -> /public/. Lotes: se guardan solo nombre y ganado (lista simple)."""
        self.get_tender(tender_id)  # asegura existencia
        update_data = payload.model_dump(exclude_unset=True, mode="json")
        if not update_data:
            return self.get_tender(tender_id)

        if "enlace_gober" in update_data:
            update_data["enlace_gober"] = _mutate_gober_url(update_data.get("enlace_gober"))
        if "lotes_config" in update_data:
            update_data["lotes_config"] = _normalize_lotes_config(update_data["lotes_config"])

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
        - PRESENTADA: cambio directo, sin validaciones ni bloqueos.
        - ADJUDICADA: validación ERP: todas las partidas activas deben tener id_producto (Belneo).
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

        # Transición a PRESENTADA: cambio directo, sin bloqueos ni validaciones complejas.
        # (Sin comprobación de presupuesto > 0 ni flujos de aprobación.)

        # Validación diferida ERP: solo al pasar a ADJUDICADA, todas las partidas deben tener id_producto (Belneo).
        if nuevo_estado == EstadoLicitacion.ADJUDICADA:
            detalle = self._repo.get_tender_with_details(tender_id)
            partidas = detalle.get("partidas") or []
            # Solo partidas activas cuentan para la validación (presupuesto efectivo).
            partidas_activas = [p for p in partidas if p.get("activo") is True]
            sin_producto = [p for p in partidas_activas if p.get("id_producto") is None]
            if sin_producto:
                ids_detalle = [p.get("id_detalle") for p in sin_producto if p.get("id_detalle") is not None]
                logger.warning(
                    "Adjudicación rechazada: partidas sin id_producto (ERP Belneo). id_detalle=%s",
                    ids_detalle,
                )
                raise ValueError(
                    "Para adjudicar, todas las líneas de presupuesto deben tener un producto de Belneo (id_producto). "
                    "Partidas con solo nombre libre no son válidas. Corrija las partidas y vuelva a intentar."
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
            "nombre_producto_libre": payload.nombre_producto_libre,
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
