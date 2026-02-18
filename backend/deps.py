"""
Dependencias de seguridad para FastAPI.

Proporciona get_current_user que valida el JWT de Supabase Auth
y enriquece el usuario con organization_id desde public.profiles.

Caché de profiles por user_id para evitar consultas repetidas en cada request.
"""

import threading
import time
from typing import Annotated, Tuple

from uuid import UUID

import jwt
from fastapi import Depends, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from backend.config import supabase_client, SKIP_AUTH
from backend.models import CurrentUser

from backend.config import SUPABASE_JWT_SECRET


security = HTTPBearer(auto_error=False)

# Caché de profiles: user_id -> (org_id, role, expiry_timestamp)
# TTL 60 segundos para equilibrar rendimiento y actualizaciones de rol/org
_PROFILE_CACHE: dict[str, Tuple[UUID, str, float]] = {}
_PROFILE_CACHE_LOCK = threading.Lock()
_PROFILE_CACHE_TTL_SECONDS = 60


def _get_cached_profile(user_id: str) -> Tuple[UUID, str] | None:
    """Devuelve (org_id, role) si está en caché y no expirado; None si miss."""
    with _PROFILE_CACHE_LOCK:
        entry = _PROFILE_CACHE.get(user_id)
        if not entry:
            return None
        org_id, role, expiry = entry
        if time.monotonic() >= expiry:
            del _PROFILE_CACHE[user_id]
            return None
        return (org_id, role)


def _set_cached_profile(user_id: str, org_id: UUID, role: str) -> None:
    """Guarda profile en caché con TTL."""
    with _PROFILE_CACHE_LOCK:
        _PROFILE_CACHE[user_id] = (org_id, role, time.monotonic() + _PROFILE_CACHE_TTL_SECONDS)


def _get_dummy_user() -> CurrentUser:
    """Usuario dummy para desarrollo cuando SKIP_AUTH=true."""
    try:
        org_resp = supabase_client.table("organizations").select("id").limit(1).execute()
        org_id = UUID(str(org_resp.data[0]["id"])) if org_resp.data else UUID("00000000-0000-0000-0000-000000000001")
    except Exception:
        org_id = UUID("00000000-0000-0000-0000-000000000001")
    return CurrentUser(
        user_id="dev-dummy-user",
        email="dev@localhost",
        org_id=org_id,
        role="admin",
    )


async def get_current_user(
    credentials: Annotated[HTTPAuthorizationCredentials | None, Depends(security)],
) -> CurrentUser:
    """
    Valida el JWT Bearer y devuelve el usuario con organization_id.

    1. Si SKIP_AUTH=true y no hay token, devuelve usuario dummy (desarrollo).
    2. Extrae el token del header Authorization.
    3. Verifica el token con el JWT secret de Supabase.
    4. Obtiene el profile (organization_id, role) desde public.profiles.
    5. Devuelve CurrentUser enriquecido.
    """
    if credentials is None:
        if SKIP_AUTH:
            return _get_dummy_user()
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="No se proporcionó token de autorización.",
            headers={"WWW-Authenticate": "Bearer"},
        )

    token = credentials.credentials

    if not SUPABASE_JWT_SECRET:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="SUPABASE_JWT_SECRET no configurado. Añade la variable en .env.",
        )

    # Intentar Supabase Auth (aud=authenticated) o token legacy (aud=veraleza-legacy)
    payload = None
    for audience in ("authenticated", "veraleza-legacy"):
        try:
            payload = jwt.decode(
                token,
                SUPABASE_JWT_SECRET,
                audience=audience,
                algorithms=["HS256"],
            )
            break
        except jwt.InvalidAudienceError:
            continue
        except jwt.ExpiredSignatureError:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Token expirado.",
                headers={"WWW-Authenticate": "Bearer"},
            )
        except jwt.InvalidTokenError:
            continue

    # Fallback: si la verificación local falla (ej. Supabase usa ECC), validar con la API
    if payload is None:
        try:
            user_resp = supabase_client.auth.get_user(token)
            if user_resp and user_resp.user:
                user_id = str(user_resp.user.id)
                email = user_resp.user.email or ""
                payload = {"sub": user_id, "email": email, "aud": "authenticated"}
            else:
                raise HTTPException(
                    status_code=status.HTTP_401_UNAUTHORIZED,
                    detail="Token inválido.",
                    headers={"WWW-Authenticate": "Bearer"},
                )
        except HTTPException:
            raise
        except Exception:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Token inválido o expirado.",
                headers={"WWW-Authenticate": "Bearer"},
            )

    # Token legacy: org_id viene en el payload
    if payload.get("aud") == "veraleza-legacy":
        user_id = payload.get("sub", "")
        email = payload.get("email", "")
        org_id = payload.get("org_id")
        role = payload.get("role", "member")
        if not org_id:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Token legacy sin organización.",
            )
        return CurrentUser(
            user_id=user_id,
            email=email,
            org_id=UUID(org_id) if isinstance(org_id, str) else org_id,
            role=role,
        )

    user_id = payload.get("sub")
    email = payload.get("email") or ""

    if not user_id:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Token malformado: falta sub.",
            headers={"WWW-Authenticate": "Bearer"},
        )

    # Caché: evitar consultar profiles en cada request
    cached = _get_cached_profile(user_id)
    if cached:
        org_id, role = cached
        return CurrentUser(
            user_id=user_id,
            email=email,
            org_id=org_id,
            role=role,
        )

    # Obtener profile (organization_id, role) desde public.profiles
    try:
        profile_resp = (
            supabase_client.table("profiles")
            .select("organization_id, role")
            .eq("id", user_id)
            .execute()
        )
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error obteniendo perfil: {e!s}",
        ) from e

    if not profile_resp.data or len(profile_resp.data) == 0:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="No existe perfil para este usuario. Contacta al administrador.",
        )

    profile = profile_resp.data[0]
    org_id = profile.get("organization_id")
    role = profile.get("role") or "member"

    if not org_id:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Usuario sin organización asignada.",
        )

    # org_id puede venir como string UUID desde Supabase
    if isinstance(org_id, str):
        org_id = UUID(org_id)

    _set_cached_profile(user_id, org_id, role)

    return CurrentUser(
        user_id=user_id,
        email=email,
        org_id=org_id,
        role=role,
    )


async def get_current_active_user_with_org(
    user: Annotated[CurrentUser, Depends(get_current_user)],
) -> CurrentUser:
    """
    Garantiza que el usuario tiene organización activa.
    get_current_user ya rechaza usuarios sin org_id, este wrapper es explícito
    para endpoints que requieren multi-tenancy estricto.
    """
    if not user.org_id:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Usuario sin organización asignada.",
        )
    return user


# Alias para inyección en routers (multi-tenant)
CurrentUserDep = Annotated[CurrentUser, Depends(get_current_active_user_with_org)]
