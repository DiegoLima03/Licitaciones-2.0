from typing import Optional
from uuid import UUID

from pydantic import BaseModel, Field


class UserLogin(BaseModel):
  """
  Credenciales para login directo contra Supabase Auth.
  El frontend normalmente usa signInWithPassword() y pasa el JWT al backend.
  Este modelo se mantiene por compatibilidad o para un endpoint de verificación.
  """

  email: str = Field(..., description="Correo electrónico del usuario.")
  password: str = Field(..., description="Contraseña (solo si el backend hace login; normalmente el frontend lo hace).")


class UserResponse(BaseModel):
  """
  Respuesta de autenticación con datos del perfil y organización.
  Usado en /auth/me y /auth/login (verificación de sesión).
  """

  id: str = Field(..., description="UUID del usuario (auth.users.id).")
  email: str = Field(..., description="Correo electrónico del usuario.")
  organization_id: UUID = Field(..., description="UUID de la organización del usuario.")
  role: str = Field(..., description="Rol: admin, admin_planta, admin_licitaciones, member_planta, member_licitaciones.")
  full_name: Optional[str] = Field(None, description="Nombre completo.")
  nombre: Optional[str] = Field(None, description="Alias de full_name para compatibilidad.")


class CurrentUser(BaseModel):
  """
  Usuario autenticado inyectado por get_current_user.
  Usado internamente en dependencias y routers.
  """

  user_id: str = Field(..., description="UUID del usuario (auth.users.id).")
  email: str = Field(..., description="Correo electrónico.")
  org_id: UUID = Field(..., description="UUID de la organización.")
  role: str = Field(default="member_licitaciones", description="Rol en la organización.")


