"""
Repositorio base multi-tenant para Supabase.

Garantiza que todas las operaciones aplican de forma inmutable el filtro
organization_id, evitando fugas de datos entre organizaciones.
No expone el client sin el filtro; las operaciones se realizan siempre en el ámbito del tenant.
"""

from typing import Any, Dict, List, Optional

from supabase import Client


class BaseTenantRepository:
    """
    Repositorio base que scopea todas las operaciones por organization_id.

    Inicialización con supabase_client y organization_id (str o UUID).
    Los métodos get_all, get_by_id, create, update y delete aplican siempre
    .eq("organization_id", self.organization_id) antes de .execute().
    """

    def __init__(
        self,
        client: Client,
        organization_id: str,
        table_name: str,
        pk_column: str = "id",
    ) -> None:
        self._client = client
        self._organization_id = str(organization_id)
        self._table_name = table_name
        self._pk_column = pk_column

    @property
    def organization_id(self) -> str:
        """Identificador de la organización (inmutable para el scope del repositorio)."""
        return self._organization_id

    def _table(self):
        """Acceso a la tabla (siempre usado junto con _tenant_filter en los métodos)."""
        return self._client.table(self._table_name)

    def get_all(
        self,
        select: str = "*",
        order_by: Optional[str] = None,
        order_desc: bool = False,
        **extra_eq: Any,
    ) -> List[Dict[str, Any]]:
        """
        Lista todos los registros de la tabla para esta organización.

        extra_eq: filtros adicionales .eq(key, value) aplicados además de organization_id.
        """
        query = (
            self._table()
            .select(select)
            .eq("organization_id", self._organization_id)
        )
        for key, value in extra_eq.items():
            query = query.eq(key, value)
        if order_by:
            query = query.order(order_by, desc=order_desc)
        response = query.execute()
        return list(response.data or [])

    def get_by_id(
        self,
        pk_value: Any,
        select: str = "*",
        pk_column: Optional[str] = None,
    ) -> Optional[Dict[str, Any]]:
        """
        Obtiene un registro por su clave primaria, solo si pertenece a la organización.
        """
        col = pk_column or self._pk_column
        response = (
            self._table()
            .select(select)
            .eq("organization_id", self._organization_id)
            .eq(col, pk_value)
            .limit(1)
            .execute()
        )
        if not response.data or len(response.data) == 0:
            return None
        return response.data[0]

    def create(self, data: Dict[str, Any]) -> Dict[str, Any]:
        """
        Inserta un registro. organization_id se inyecta siempre desde el repositorio;
        si el payload incluye organization_id, se sobrescribe por seguridad.
        """
        payload = {**data, "organization_id": self._organization_id}
        response = self._table().insert(payload).execute()
        if not response.data:
            raise RuntimeError("Insert no devolvió datos.")
        return response.data[0] if isinstance(response.data, list) else response.data

    def update(
        self,
        pk_value: Any,
        data: Dict[str, Any],
        pk_column: Optional[str] = None,
    ) -> Dict[str, Any]:
        """
        Actualiza un registro por PK. Solo afecta a filas con este organization_id.
        No permite cambiar organization_id (se ignora si viene en data).
        """
        col = pk_column or self._pk_column
        payload = {k: v for k, v in data.items() if k != "organization_id"}
        if not payload:
            row = self.get_by_id(pk_value, pk_column=col)
            if not row:
                raise ValueError("Registro no encontrado.")
            return row
        response = (
            self._table()
            .update(payload)
            .eq("organization_id", self._organization_id)
            .eq(col, pk_value)
            .execute()
        )
        if not response.data:
            raise ValueError("Registro no encontrado o sin cambios.")
        return response.data[0] if isinstance(response.data, list) else response.data

    def delete(self, pk_value: Any, pk_column: Optional[str] = None) -> None:
        """
        Elimina un registro por PK. Solo borra si pertenece a esta organización.
        """
        col = pk_column or self._pk_column
        response = (
            self._table()
            .delete()
            .eq("organization_id", self._organization_id)
            .eq(col, pk_value)
            .execute()
        )
        # Supabase delete puede devolver data vacía; no lanzamos si no hay filas
        # (el registro podría no existir o no pertenecer al tenant)
        if response.data and len(response.data) == 0:
            raise ValueError("Registro no encontrado.")
