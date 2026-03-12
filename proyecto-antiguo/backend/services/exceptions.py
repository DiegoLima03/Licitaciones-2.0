"""
Excepciones de dominio para la capa de servicios.

El router las traduce a HTTPException (404, 400, 409) según el tipo.
No dependen de FastAPI.
"""


class DomainError(Exception):
    """Base para errores de negocio."""

    def __init__(self, message: str) -> None:
        self.message = message
        super().__init__(message)


class NotFoundError(DomainError):
    """Recurso no encontrado o no pertenece al tenant."""


class ConflictError(DomainError):
    """Conflicto de concurrencia (ej. estado cambiado entre lectura y escritura)."""
