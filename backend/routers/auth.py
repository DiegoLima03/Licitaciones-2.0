from typing import Any, Dict, Optional

from fastapi import APIRouter, HTTPException, status

from backend.config import supabase_client
from backend.models import UserLogin, UserResponse


router = APIRouter(prefix="/auth", tags=["auth"])


def autenticar_usuario(client: Any, email: str, password: str) -> Optional[Dict[str, Any]]:
    """
    Lógica original de autenticación migrada desde `src/logic/auth.py`.

    Verifica si el email y password coinciden en la base de datos.
    Retorna el objeto usuario (dict) si es correcto, o None si falla.
    """
    # Buscamos el usuario por email
    response = client.table("tbl_usuarios").select("*").eq("email", email).execute()

    if not response.data:
        return None  # Usuario no existe

    usuario = response.data[0]

    # Verificación simple de contraseña
    # NOTA: En producción real, aquí usaríamos bcrypt.checkpw()
    if usuario.get("password") == password:
        return usuario
    else:
        return None  # Contraseña incorrecta


@router.post("/login", response_model=UserResponse)
def login(payload: UserLogin) -> UserResponse:
    """
    Endpoint de autenticación.

    POST /auth/login
    Recibe credenciales y devuelve el usuario autenticado (incluyendo su rol).
    """
    usuario = autenticar_usuario(supabase_client, payload.email, payload.password)

    if not usuario:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Credenciales inválidas.",
        )

    # Adaptamos el dict de Supabase al modelo de respuesta.
    user_response = UserResponse(
        id=usuario.get("id") or usuario.get("id_usuario"),
        email=usuario.get("email", ""),
        rol=usuario.get("rol"),
        nombre=usuario.get("nombre") or usuario.get("nombre_usuario"),
    )

    return user_response

