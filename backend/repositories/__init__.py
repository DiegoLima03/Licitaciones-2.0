"""
Repositorios con aislamiento multi-tenant estricto.

Todos los repositorios heredan de BaseTenantRepository y aplican
siempre el filtro organization_id para evitar fugas de datos entre organizaciones.
"""

from backend.repositories.base_repository import BaseTenantRepository
from backend.repositories.tenders_repository import TendersRepository

__all__ = ["BaseTenantRepository", "TendersRepository"]
