import os
from typing import Dict, Any

from dotenv import load_dotenv
from supabase import Client, create_client


load_dotenv()

SUPABASE_URL: str | None = os.environ.get("SUPABASE_URL")
SUPABASE_KEY: str | None = os.environ.get("SUPABASE_KEY")


def init_connection() -> Client:
    """
    Inicializa la conexión a Supabase de forma Singleton (a nivel de proceso).

    Esta función replica la lógica de `src/config.py` pero sin depender de Streamlit.
    """
    if not SUPABASE_URL or not SUPABASE_KEY:
        raise RuntimeError(
            "Faltan las credenciales de Supabase en las variables de entorno "
            "(SUPABASE_URL, SUPABASE_KEY)."
        )
    return create_client(SUPABASE_URL, SUPABASE_KEY)


supabase_client: Client = init_connection()


def get_maestros(client: Client) -> Dict[str, Any]:
    """
    Carga los diccionarios de Estados y Tipos de Licitación.

    Lógica adaptada desde `src/config.py::get_maestros`, eliminando dependencias de Streamlit.
    """
    estados_db = client.table("tbl_estados").select("*").execute().data
    tipos_db = client.table("tbl_tipolicitacion").select("*").execute().data

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

