"""
Roles de la organización. Sin legacy: solo los 5 roles definidos.

Jerarquía:
  - admin (máximo)
  - admin_planta | admin_licitaciones (debajo de admin)
  - member_planta (debajo de admin_planta) | member_licitaciones (debajo de admin_licitaciones)
"""

ROLES_VALIDOS = {
    "admin",
    "admin_planta",
    "admin_licitaciones",
    "member_planta",
    "member_licitaciones",
}

DEFAULT_ROLE = "member_licitaciones"

# Roles que cada actor puede eliminar (solo usuarios por debajo en la jerarquía)
ROLES_ACTOR_CAN_DELETE: dict[str, set[str]] = {
    "admin": {"admin_planta", "admin_licitaciones", "member_planta", "member_licitaciones"},
    "admin_planta": {"member_planta"},
    "admin_licitaciones": {"member_licitaciones"},
    "member_planta": set(),
    "member_licitaciones": set(),
}


def can_delete_user(actor_role: str, target_role: str) -> bool:
    """True si el actor puede eliminar a un usuario con target_role (por debajo en la jerarquía)."""
    allowed = ROLES_ACTOR_CAN_DELETE.get(actor_role) or set()
    return normalize_role(target_role) in allowed


def normalize_role(role: str | None) -> str:
    """Devuelve siempre uno de ROLES_VALIDOS. Rol antiguo 'member' → member_licitaciones."""
    if not role or not str(role).strip():
        return DEFAULT_ROLE
    r = str(role).strip().lower()
    if r in ROLES_VALIDOS:
        return r
    if r == "member":
        return "member_licitaciones"
    if r == "admin":
        return "admin"
    return DEFAULT_ROLE
