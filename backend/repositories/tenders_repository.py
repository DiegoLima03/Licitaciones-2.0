"""
Repositorio de licitaciones (tbl_licitaciones) y partidas (tbl_licitaciones_detalle).

Todas las operaciones están scoped por organization_id vía BaseTenantRepository.
Métodos específicos de dominio: get_tender_with_details, get_active_budget_total.
"""

from datetime import date, timedelta
from decimal import Decimal
from typing import Any, Dict, List

from backend.models import EstadoLicitacion
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
        """Lista licitaciones raíz y, excepcionalmente, derivados en análisis con
        fecha de presentación en ≤5 días. Filtros opcionales; orden id_licitacion desc."""
        query_raiz = (
            self._client.table(self.TABLE_LICITACIONES)
            .select("*")
            .eq("organization_id", self._organization_id)
            .is_("id_licitacion_padre", "null")
            .order(self.PK_LICITACION, desc=True)
        )
        if estado_id is not None:
            query_raiz = query_raiz.eq("id_estado", estado_id)
        if nombre and nombre.strip():
            query_raiz = query_raiz.ilike("nombre", f"%{nombre.strip()}%")
        if pais and pais.strip():
            query_raiz = query_raiz.eq("pais", pais.strip())
        response_raiz = query_raiz.execute()
        raiz_list = list(response_raiz.data or [])

        # Excepción: incluir derivados (hijos AM/SDA) solo si están en análisis y presentación en ≤5 días
        derivados_urgentes: List[Dict[str, Any]] = []
        if estado_id is None or estado_id == EstadoLicitacion.EN_ANALISIS.value:
            today = date.today()
            end = today + timedelta(days=5)
            start_iso = today.isoformat()
            end_iso = end.isoformat()
            query_deriv = (
                self._client.table(self.TABLE_LICITACIONES)
                .select("*")
                .eq("organization_id", self._organization_id)
                .not_.is_("id_licitacion_padre", "null")
                .eq("id_estado", EstadoLicitacion.EN_ANALISIS.value)
                .gte("fecha_presentacion", start_iso)
                .lte("fecha_presentacion", end_iso)
                .order(self.PK_LICITACION, desc=True)
                .execute()
            )
            derivados_urgentes = list(query_deriv.data or [])
            # Filtrar por nombre/pais si aplican
            if nombre and nombre.strip():
                n = nombre.strip().lower()
                derivados_urgentes = [d for d in derivados_urgentes if n in (d.get("nombre") or "").lower()]
            if pais and pais.strip():
                p = pais.strip()
                derivados_urgentes = [d for d in derivados_urgentes if d.get("pais") == p]
            # Restringir a fecha realmente en ventana (por si el campo es timestamp)
            def _date_in_window(row: Dict[str, Any]) -> bool:
                fp = row.get("fecha_presentacion")
                if not fp:
                    return False
                try:
                    d = date.fromisoformat(str(fp).split("T")[0])
                    return today <= d <= end
                except (ValueError, TypeError):
                    return False
            derivados_urgentes = [d for d in derivados_urgentes if _date_in_window(d)]

        # Evitar duplicados por id y ordenar por id desc
        seen = {r["id_licitacion"] for r in raiz_list}
        extra = [d for d in derivados_urgentes if d["id_licitacion"] not in seen]
        merged = raiz_list + extra
        merged.sort(key=lambda x: x[self.PK_LICITACION], reverse=True)
        return merged

    def get_contratos_derivados(self, id_licitacion_padre: int) -> List[Dict[str, Any]]:
        """Licitaciones hijo (CONTRATO_BASADO) cuyo id_licitacion_padre es el dado."""
        response = (
            self._client.table(self.TABLE_LICITACIONES)
            .select("*")
            .eq("organization_id", self._organization_id)
            .eq("id_licitacion_padre", id_licitacion_padre)
            .order(self.PK_LICITACION, desc=True)
            .execute()
        )
        return list(response.data or [])

    def get_tender_with_details(self, tender_id: int) -> Dict[str, Any] | None:
        """
        Obtiene una licitación por ID con sus partidas (tbl_licitaciones_detalle)
        y nombres de producto. Solo si pertenece a la organización.
        Si es AM o SDA, incluye contratos_derivados (licitaciones con id_licitacion_padre == tender_id).
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
        out: Dict[str, Any] = {**licitacion, "partidas": partidas}
        tipo_proc = licitacion.get("tipo_procedimiento")
        tipo_str = (tipo_proc.upper() if isinstance(tipo_proc, str) else "") or ""
        if tipo_str in ("ACUERDO_MARCO", "SDA"):
            out["contratos_derivados"] = self.get_contratos_derivados(tender_id)
        else:
            out["contratos_derivados"] = []
        # Para contratos derivados, incluir datos del padre (acceso desde el padre).
        id_padre = licitacion.get("id_licitacion_padre")
        if id_padre is not None:
            padre = self.get_by_id(int(id_padre))
            out["licitacion_padre"] = (
                {"id_licitacion": padre["id_licitacion"], "nombre": padre.get("nombre"), "numero_expediente": padre.get("numero_expediente")}
                if padre
                else None
            )
        else:
            out["licitacion_padre"] = None
        return out

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

    def get_parent_tenders(self) -> List[Dict[str, Any]]:
        """
        Licitaciones que pueden ser padre (AM o SDA) y están adjudicadas.
        Para que el frontend las liste al crear un contrato BASADO_AM / ESPECIFICO_SDA.
        """
        response = (
            self._client.table(self.TABLE_LICITACIONES)
            .select("*")
            .eq("organization_id", self._organization_id)
            .in_("tipo_procedimiento", ["ACUERDO_MARCO", "SDA"])
            .eq("id_estado", EstadoLicitacion.ADJUDICADA.value)
            .order(self.PK_LICITACION, desc=True)
            .execute()
        )
        return list(response.data or [])

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
