"""
Repositorio de licitaciones (tbl_licitaciones) y partidas (tbl_licitaciones_detalle).

Todas las operaciones están scoped por organization_id vía BaseTenantRepository.
Métodos específicos de dominio: get_tender_with_details, get_active_budget_total.
"""

from decimal import Decimal
from typing import Any, Dict, List

from backend.repositories.base_repository import BaseTenantRepository


class TendersRepository(BaseTenantRepository):
    """
    Repositorio de tbl_licitaciones con PK id_licitacion.

    Operaciones genéricas vía base (get_all, get_by_id, create, update, delete).
    Métodos de dominio: get_tender_with_details, get_active_budget_total.
    """

    TABLE_LICITACIONES = "tbl_licitaciones"
    TABLE_DETALLE = "tbl_licitaciones_detalle"
    PK_LICITACION = "id_licitacion"
    PK_DETALLE = "id_detalle"

    def __init__(self, client, organization_id: str) -> None:
        super().__init__(
            client=client,
            organization_id=organization_id,
            table_name=self.TABLE_LICITACIONES,
            pk_column=self.PK_LICITACION,
        )

    def list_tenders(
        self,
        estado_id: int | None = None,
        nombre: str | None = None,
        pais: str | None = None,
    ) -> List[Dict[str, Any]]:
        """Lista licitaciones con filtros opcionales (orden id_licitacion desc)."""
        query = (
            self._client.table(self.TABLE_LICITACIONES)
            .select("*")
            .eq("organization_id", self._organization_id)
            .order(self.PK_LICITACION, desc=True)
        )
        if estado_id is not None:
            query = query.eq("id_estado", estado_id)
        if nombre and nombre.strip():
            query = query.ilike("nombre", f"%{nombre.strip()}%")
        if pais and pais.strip():
            query = query.eq("pais", pais.strip())
        response = query.execute()
        return list(response.data or [])

    def get_tender_with_details(self, tender_id: int) -> Dict[str, Any] | None:
        """
        Obtiene una licitación por ID con sus partidas (tbl_licitaciones_detalle)
        y nombres de producto. Solo si pertenece a la organización.
        """
        licitacion = self.get_by_id(tender_id)
        if not licitacion:
            return None
        partidas_raw = (
            self._client.table(self.TABLE_DETALLE)
            .select("*, tbl_productos(nombre, nombre_proveedor)")
            .eq("organization_id", self._organization_id)
            .eq(self.PK_LICITACION, tender_id)
            .order("lote")
            .order(self.PK_DETALLE)
            .execute()
        )
        raw_list: List[dict] = list(partidas_raw.data or [])
        partidas: List[Dict[str, Any]] = []
        for p in raw_list:
            prod = p.get("tbl_productos") or {}
            partidas.append({
                **{k: v for k, v in p.items() if k != "tbl_productos"},
                "product_nombre": prod.get("nombre"),
                "nombre_proveedor": (prod.get("nombre_proveedor") or "").strip() or None,
            })
        return {**licitacion, "partidas": partidas}

    def get_active_budget_total(self, tender_id: int) -> Decimal:
        """
        Total de presupuesto de partidas activas, usado para validar que
        no se presenta a coste cero.

        - Para tipos con unidades (1, 3, 5, etc.): suma (unidades * pvu)
        - Para tipos SIN unidades (2 y 4): suma directa de pvu
        """
        # Obtener tipo de licitación para decidir cómo sumar
        tipo_resp = (
            self._client.table(self.TABLE_LICITACIONES)
            .select("id_tipolicitacion")
            .eq("organization_id", self._organization_id)
            .eq(self.PK_LICITACION, tender_id)
            .limit(1)
            .execute()
        )
        tipo_id = None
        if tipo_resp.data:
            try:
                tipo_id = int(tipo_resp.data[0].get("id_tipolicitacion") or 0)
            except (TypeError, ValueError):
                tipo_id = None

        sin_unidades = tipo_id in (2, 4)

        if sin_unidades:
            response = (
                self._client.table(self.TABLE_DETALLE)
                .select("pvu")
                .eq("organization_id", self._organization_id)
                .eq(self.PK_LICITACION, tender_id)
                .eq("activo", True)
                .execute()
            )
        else:
            response = (
                self._client.table(self.TABLE_DETALLE)
                .select("unidades, pvu")
                .eq("organization_id", self._organization_id)
                .eq(self.PK_LICITACION, tender_id)
                .eq("activo", True)
                .execute()
            )

        total = Decimal("0")
        for r in (response.data or []):
            p = Decimal(str(r.get("pvu") or 0))
            if sin_unidades:
                total += p
            else:
                u = Decimal(str(r.get("unidades") or 0))
                total += u * p
        return total

    def get_partida(self, tender_id: int, detalle_id: int) -> Dict[str, Any] | None:
        """Obtiene una partida por id_licitacion e id_detalle, solo si pertenece a la organización."""
        response = (
            self._client.table(self.TABLE_DETALLE)
            .select("*, tbl_productos(nombre)")
            .eq("organization_id", self._organization_id)
            .eq(self.PK_LICITACION, tender_id)
            .eq(self.PK_DETALLE, detalle_id)
            .limit(1)
            .execute()
        )
        if not response.data or len(response.data) == 0:
            return None
        p = response.data[0]
        prod = p.get("tbl_productos") or {}
        return {**{k: v for k, v in p.items() if k != "tbl_productos"}, "product_nombre": prod.get("nombre")}

    def add_partida(self, tender_id: int, row: Dict[str, Any]) -> Dict[str, Any]:
        """Inserta una partida en tbl_licitaciones_detalle; organization_id se inyecta."""
        payload = {**row, "organization_id": self._organization_id, self.PK_LICITACION: tender_id}
        response = self._client.table(self.TABLE_DETALLE).insert(payload).execute()
        if not response.data:
            raise RuntimeError("Insert partida no devolvió datos.")
        return response.data[0]

    def update_partida(self, tender_id: int, detalle_id: int, data: Dict[str, Any]) -> Dict[str, Any]:
        """Actualiza una partida; solo si pertenece a la organización."""
        payload = {k: v for k, v in data.items() if k != "organization_id"}
        response = (
            self._client.table(self.TABLE_DETALLE)
            .update(payload)
            .eq("organization_id", self._organization_id)
            .eq(self.PK_LICITACION, tender_id)
            .eq(self.PK_DETALLE, detalle_id)
            .execute()
        )
        if not response.data:
            raise ValueError("Partida no encontrada.")
        return response.data[0]

    def delete_partida(self, tender_id: int, detalle_id: int) -> None:
        """Elimina una partida; solo si pertenece a la organización."""
        response = (
            self._client.table(self.TABLE_DETALLE)
            .delete()
            .eq("organization_id", self._organization_id)
            .eq(self.PK_LICITACION, tender_id)
            .eq(self.PK_DETALLE, detalle_id)
            .execute()
        )
        if response.data is not None and len(response.data) == 0:
            raise ValueError("Partida no encontrada.")

    def update_tender_with_state_check(
        self,
        tender_id: int,
        data: Dict[str, Any],
        expected_id_estado: int,
    ) -> Dict[str, Any] | None:
        """Actualiza la licitación solo si id_estado coincide (optimistic lock)."""
        response = (
            self._client.table(self.TABLE_LICITACIONES)
            .update(data)
            .eq("organization_id", self._organization_id)
            .eq(self.PK_LICITACION, tender_id)
            .eq("id_estado", expected_id_estado)
            .execute()
        )
        if not response.data or len(response.data) == 0:
            return None
        return response.data[0]
