import os
from pathlib import Path
from typing import Dict, Any

from dotenv import load_dotenv
from supabase import Client, create_client

# Cargar .env desde la raíz del proyecto (donde se ejecuta uvicorn)
_env_path = Path(__file__).resolve().parent.parent / ".env"
load_dotenv(dotenv_path=_env_path)
# Por si se ejecuta desde otra ruta, intentar también el cwd
load_dotenv()

SUPABASE_URL: str | None = os.environ.get("SUPABASE_URL")
SUPABASE_KEY: str | None = os.environ.get("SUPABASE_KEY")
SUPABASE_JWT_SECRET: str | None = os.environ.get("SUPABASE_JWT_SECRET")

# Desarrollo: si es "true", la API acepta peticiones sin token (usuario dummy).
SKIP_AUTH: bool = os.environ.get("SKIP_AUTH", "").lower() in ("true", "1", "yes")


def init_connection() -> Client:
    """
    Inicializa la conexión a Supabase de forma Singleton (a nivel de proceso).

    Esta función replica la lógica de `src/config.py` pero sin depender de Streamlit.
    """
    if not SUPABASE_URL or not SUPABASE_KEY:
        raise RuntimeError(
            "Faltan las credenciales de Supabase. En el archivo .env (raíz del proyecto) define:\n"
            "  SUPABASE_URL=https://TU_PROJECT_REF.supabase.co\n"
            "  SUPABASE_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6... (anon o service_role key)\n"
            "Obtén ambos en: Supabase → tu proyecto → Settings → API."
        )
    url = SUPABASE_URL.strip()
    if not url.startswith("http://") and not url.startswith("https://"):
        raise RuntimeError(
            "SUPABASE_URL debe ser la URL completa del proyecto, por ejemplo:\n"
            "  https://abcdefgh.supabase.co\n"
            "No uses la clave pública/secret aquí. En .env tienes ahora algo tipo 'sb_publishable_...' "
            "en SUPABASE_URL; ese valor no es una URL. En Settings → API copia 'Project URL' en SUPABASE_URL."
        )
    return create_client(url, SUPABASE_KEY)


supabase_client: Client = init_connection()


def get_maestros(client: Client) -> Dict[str, Any]:
    """
    Carga los diccionarios de Estados y Tipos de Licitación.

    Lógica adaptada desde `src/config.py::get_maestros`, eliminando dependencias de Streamlit.
    """
    estados_db = client.table("tbl_estados").select("*").execute().data or []
    tipos_db = client.table("tbl_tipolicitacion").select("*").execute().data or []

    # Mapeos Estados
    mapa_estados_id_a_nombre = {e["id_estado"]: e["nombre_estado"] for e in estados_db}
    mapa_estados_nombre_a_id = {e["nombre_estado"]: e["id_estado"] for e in estados_db}
    lista_nombres_estados = list(mapa_estados_nombre_a_id.keys())

    # Mapeos Tipos
    mapa_tipos_id_a_nombre = {t["id_tipolicitacion"]: t["tipo"] for t in tipos_db}
    mapa_tipos_nombre_a_id = {t["tipo"]: t["id_tipolicitacion"] for t in tipos_db}
    lista_nombres_tipos = list(mapa_tipos_nombre_a_id.keys())

    return {
        "estados_id_map": mapa_estados_id_a_nombre,
        "estados_name_map": mapa_estados_nombre_a_id,
        "estados_list": lista_nombres_estados,
        "tipos_id_map": mapa_tipos_id_a_nombre,
        "tipos_name_map": mapa_tipos_nombre_a_id,
        "tipos_list": lista_nombres_tipos,
    }

