from typing import Dict

from pydantic import BaseModel, Field


class RolePermissionsMatrix(BaseModel):
    """Matriz de permisos por rol y funcionalidad."""

    matrix: Dict[str, Dict[str, bool]] = Field(
        default_factory=dict,
        description="role -> feature -> allowed",
    )


__all__ = ["RolePermissionsMatrix"]

