"""
Capa de servicios: lógica de negocio aislada de HTTP.

Los servicios reciben repositorios por inyección y lanzan excepciones
de dominio (ValueError o backend.services.exceptions), no HTTPException.
"""

from backend.services.tenders_service import TenderService

__all__ = ["TenderService"]
