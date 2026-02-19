"""
Autenticación: Supabase Auth + fallback tbl_usuarios.

- POST /auth/login: Acepta email + contraseña.
- GET /auth/me: Perfil del usuario (requiere Bearer token).
- POST /auth/users: Crear usuario (requiere admin, usa Supabase Admin API).
"""

import time
from typing import Any

import jwt
from fastapi import APIRouter, Depends, HTTPException, status

from backend.config import supabase_client, SUPABASE_JWT_SECRET
from backend.deps import get_current_user
from backend.models import CurrentUser, UserLogin, UserResponse
from backend.roles import DEFAULT_ROLE, ROLES_VALIDOS, can_delete_user, normalize_role
from pydantic import BaseModel, Field


router = APIRouter(prefix="/auth", tags=["auth"])

JWT_AUDIENCE_LEGACY = "veraleza-legacy"


def _get_or_create_profile(user_id: str, email: str, default_org_id: str) -> dict[str, Any]:
    """
    Obtiene el perfil del usuario. Si no existe (usuarios creados antes del trigger),
    crea uno con la organización por defecto.
    """
    resp = (
        supabase_client.table("profiles")
        .select("organization_id, role, full_name")
        .eq("id", user_id)
        .execute()
    )
    if resp.data and len(resp.data) > 0:
        return resp.data[0]

    # Perfil inexistente: crear con rol por defecto
    supabase_client.table("profiles").insert({
        "id": user_id,
        "organization_id": default_org_id,
        "role": DEFAULT_ROLE,
    }).execute()

    return {"organization_id": default_org_id, "role": DEFAULT_ROLE, "full_name": None}


def _login_tbl_usuarios(email: str, password: str) -> dict | None:
    """Fallback: autenticación contra tbl_usuarios."""
    try:
        resp = supabase_client.table("tbl_usuarios").select("*").eq("email", email).execute()
        if not resp.data or len(resp.data) == 0:
            return None
        usuario = resp.data[0]
        if usuario.get("password") != password:
            return None

        org_id = "00000000-0000-0000-0000-000000000001"
        try:
            org_resp = supabase_client.table("organizations").select("id").limit(1).execute()
            if org_resp.data:
                org_id = str(org_resp.data[0]["id"])
        except Exception:
            pass

        raw_rol = (usuario.get("rol") or "").strip().lower()
        role = "admin" if raw_rol == "admin" else DEFAULT_ROLE

        access_token = None
        if SUPABASE_JWT_SECRET:
            payload = {
                "sub": f"legacy-{usuario.get('id_usuario', usuario.get('id', ''))}",
                "email": usuario.get("email", ""),
                "aud": JWT_AUDIENCE_LEGACY,
                "org_id": org_id,
                "role": role,
                "exp": int(time.time()) + 86400 * 7,
            }
            access_token = jwt.encode(
                payload, SUPABASE_JWT_SECRET, algorithm="HS256"
            )

        return {
            "id": usuario.get("id_usuario") or usuario.get("id"),
            "email": usuario.get("email", ""),
            "organization_id": org_id,
            "role": role,
            "rol": role,
            "full_name": usuario.get("nombre"),
            "nombre": usuario.get("nombre"),
            "access_token": access_token,
        }
    except Exception:
        return None


@router.post("/login", response_model=dict)
def login(payload: UserLogin) -> dict:
    """
    Inicio de sesión con email y contraseña.

    POST /auth/login
    Body: { "email": "...", "password": "..." }

    Primero intenta Supabase Auth. Si falla (ej. migración no ejecutada), prueba tbl_usuarios.
    Con Supabase Auth devuelve access_token para Bearer. Con tbl_usuarios, access_token es null.
    """
    # 1. Intentar Supabase Auth
    try:
        auth_resp = supabase_client.auth.sign_in_with_password({
            "email": payload.email,
            "password": payload.password,
        })
    except Exception:
        auth_resp = None

    if auth_resp and auth_resp.session and auth_resp.user:
        session = auth_resp.session
        user = auth_resp.user

        # Obtener organización por defecto
        try:
            org_resp = supabase_client.table("organizations").select("id").limit(1).execute()
            default_org_id = str(org_resp.data[0]["id"]) if org_resp.data else None
        except Exception:
            default_org_id = None

        if default_org_id:
            profile = _get_or_create_profile(user.id, user.email or "", default_org_id)
            org_id = profile.get("organization_id") or default_org_id
            role = normalize_role(profile.get("role"))

            return {
                "id": user.id,
                "email": user.email or "",
                "organization_id": org_id,
                "role": role,
                "full_name": profile.get("full_name"),
                "nombre": profile.get("full_name"),
                "rol": role,
                "access_token": session.access_token,
            }

    # 2. Fallback: tbl_usuarios
    legacy = _login_tbl_usuarios(payload.email, payload.password)
    if legacy:
        return legacy

    raise HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Credenciales inválidas.",
    )


class UserCreatePayload(UserLogin):
    """Payload para crear usuario (email, password + opcional full_name y role)."""
    full_name: str | None = None
    role: str | None = None


class UserRoleUpdate(BaseModel):
    """Payload para actualizar el rol de un usuario."""
    role: str = Field(..., description="Nuevo rol: admin, admin_planta, admin_licitaciones, member_planta, member_licitaciones.")


class UserPasswordUpdate(BaseModel):
    """Payload para cambiar la contraseña de un usuario (solo admin)."""
    password: str = Field(..., min_length=6, description="Nueva contraseña (mínimo 6 caracteres).")


@router.post("/users", response_model=dict, status_code=status.HTTP_201_CREATED)
def create_user(
    payload: UserCreatePayload,
    current_user: CurrentUser = Depends(get_current_user),
) -> dict:
    """
    Crea un nuevo usuario en Supabase Auth (solo admin).

    POST /auth/users
    Body: { "email": "...", "password": "...", "full_name": "..." (opcional), "role": "..." (opcional) }
    Requiere: Bearer token de un usuario con role=admin.
    """
    if current_user.role != "admin":
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Solo administradores pueden crear usuarios.",
        )

    role = (payload.role and payload.role.strip()) or DEFAULT_ROLE
    if role not in ROLES_VALIDOS:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Rol inválido. Valores permitidos: {', '.join(sorted(ROLES_VALIDOS))}",
        )

    try:
        # Supabase Admin API (requiere service_role key)
        resp = supabase_client.auth.admin.create_user({
            "email": payload.email,
            "password": payload.password,
            "email_confirm": True,
            "user_metadata": {"full_name": payload.full_name or ""},
        })
    except Exception as e:
        msg = str(e).lower()
        if "already" in msg or "exist" in msg or "duplicate" in msg:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail="Ya existe un usuario con ese email.",
            ) from e
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"No se pudo crear el usuario: {e!s}",
        ) from e

    user = resp.user
    if not user:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="No se devolvió el usuario creado.",
        )

    try:
        supabase_client.table("profiles").update({
            "organization_id": str(current_user.org_id),
            "full_name": payload.full_name,
            "role": role,
        }).eq("id", user.id).execute()
    except Exception:
        pass

    return {
        "id": user.id,
        "email": user.email,
        "message": "Usuario creado y asignado a tu organización.",
    }


@router.get("/users", response_model=list[dict])
def list_users(
    current_user: CurrentUser = Depends(get_current_user),
) -> list[dict]:
    """
    Lista los usuarios de la organización del admin (solo admin).

    GET /auth/users
    Requiere: Bearer token de un usuario con role=admin.
    """
    if current_user.role != "admin":
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Solo administradores pueden listar usuarios.",
        )

    try:
        resp = supabase_client.rpc(
            "get_org_users",
            {"p_org_id": str(current_user.org_id)},
        ).execute()
    except Exception as e:
        if "function" in str(e).lower() and "does not exist" in str(e).lower():
            # Migración org_users_rpc.sql no ejecutada: fallback a profiles sin email
            resp = (
                supabase_client.table("profiles")
                .select("id, full_name, role")
                .eq("organization_id", str(current_user.org_id))
                .order("full_name")
                .execute()
            )
            return [
                {
                    "id": str(r["id"]),
                    "email": None,
                    "full_name": r.get("full_name"),
                    "role": normalize_role(r.get("role")),
                }
                for r in (resp.data or [])
            ]
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error al listar usuarios: {e!s}",
        ) from e

    return [
        {
            "id": str(r["id"]),
            "email": r.get("email"),
            "full_name": r.get("full_name"),
            "role": normalize_role(r.get("role")),
        }
        for r in (resp.data or [])
    ]


@router.patch("/users/{user_id}", response_model=dict)
def update_user_role(
    user_id: str,
    payload: UserRoleUpdate,
    current_user: CurrentUser = Depends(get_current_user),
) -> dict:
    """
    Actualiza el rol de un usuario de la organización (solo admin).

    PATCH /auth/users/{user_id}
    Body: { "role": uno de admin, admin_planta, admin_licitaciones, member_planta, member_licitaciones }
    Requiere: Bearer token de un usuario con role=admin.
    """
    if current_user.role != "admin":
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Solo administradores pueden cambiar roles.",
        )
    if payload.role not in ROLES_VALIDOS:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Rol inválido. Valores permitidos: {', '.join(sorted(ROLES_VALIDOS))}",
        )

    # Solo actualizar si el usuario pertenece a nuestra organización
    upd = (
        supabase_client.table("profiles")
        .update({"role": payload.role})
        .eq("id", user_id)
        .eq("organization_id", str(current_user.org_id))
        .execute()
    )
    if not upd.data or len(upd.data) == 0:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Usuario no encontrado o no pertenece a tu organización.",
        )
    return {"id": user_id, "role": payload.role}


@router.patch("/users/{user_id}/password", response_model=dict)
def update_user_password(
    user_id: str,
    payload: UserPasswordUpdate,
    current_user: CurrentUser = Depends(get_current_user),
) -> dict:
    """
    Cambia la contraseña de un usuario de la organización (solo admin).

    PATCH /auth/users/{user_id}/password
    Body: { "password": "nueva_contraseña" }
    Requiere: Bearer token de un usuario con role=admin.
    """
    if current_user.role != "admin":
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Solo administradores pueden cambiar contraseñas.",
        )

    # Verificar que el usuario pertenece a nuestra organización
    check = (
        supabase_client.table("profiles")
        .select("id")
        .eq("id", user_id)
        .eq("organization_id", str(current_user.org_id))
        .limit(1)
        .execute()
    )
    if not check.data or len(check.data) == 0:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Usuario no encontrado o no pertenece a tu organización.",
        )

    try:
        supabase_client.auth.admin.update_user_by_id(user_id, {"password": payload.password})
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"No se pudo actualizar la contraseña: {e!s}",
        ) from e

    return {"id": user_id, "message": "Contraseña actualizada correctamente."}


@router.delete("/users/{user_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_user(
    user_id: str,
    current_user: CurrentUser = Depends(get_current_user),
) -> None:
    """
    Elimina un usuario de la organización. Solo puedes eliminar usuarios con rol inferior al tuyo.

    DELETE /auth/users/{user_id}
    - admin: puede eliminar a admin_planta, admin_licitaciones, member_planta, member_licitaciones.
    - admin_planta: solo puede eliminar a member_planta.
    - admin_licitaciones: solo puede eliminar a member_licitaciones.
    """
    profile_resp = (
        supabase_client.table("profiles")
        .select("id, role")
        .eq("id", user_id)
        .eq("organization_id", str(current_user.org_id))
        .limit(1)
        .execute()
    )
    if not profile_resp.data or len(profile_resp.data) == 0:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Usuario no encontrado o no pertenece a tu organización.",
        )
    target_role = normalize_role(profile_resp.data[0].get("role"))
    if not can_delete_user(current_user.role, target_role):
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="No puedes eliminar a un usuario con tu mismo rol o superior.",
        )

    try:
        supabase_client.auth.admin.delete_user(user_id)
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"No se pudo eliminar el usuario: {e!s}",
        ) from e

    try:
        supabase_client.table("profiles").delete().eq("id", user_id).execute()
    except Exception:
        pass


@router.get("/me", response_model=UserResponse)
def get_me(current_user: CurrentUser = Depends(get_current_user)) -> UserResponse:
    """
    Devuelve el perfil del usuario autenticado.    GET /auth/me
    Requiere: Header Authorization: Bearer <jwt>
    """
    return UserResponse(
        id=current_user.user_id,
        email=current_user.email,
        organization_id=current_user.org_id,
        role=current_user.role,
        full_name=None,
        nombre=None,
    )


@router.patch("/me/password", response_model=dict)
def update_my_password(
    payload: UserPasswordUpdate,
    current_user: CurrentUser = Depends(get_current_user),
) -> dict:
    """
    Cambia la contraseña del usuario autenticado (cuenta propia).
    PATCH /auth/me/password
    Body: { "password": "nueva_contraseña" }
    """
    try:
        supabase_client.auth.admin.update_user_by_id(
            current_user.user_id, {"password": payload.password}
        )
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"No se pudo actualizar la contraseña: {e!s}",
        ) from e
    return {"message": "Contraseña actualizada correctamente."}