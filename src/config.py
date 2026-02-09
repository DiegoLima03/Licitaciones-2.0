### src/config.py
import os
import streamlit as st
from supabase import create_client, Client
from dotenv import load_dotenv

# Cargar variables de entorno
load_dotenv()
URL = os.environ.get("SUPABASE_URL")
KEY = os.environ.get("SUPABASE_KEY")

@st.cache_resource
def init_connection() -> Client:
    """Inicializa la conexión a Supabase de forma Singleton."""
    if not URL or not KEY:
        st.error("Faltan las credenciales de Supabase en el archivo .env")
        st.stop()
    return create_client(URL, KEY)

def get_maestros(client: Client):
    """
    Carga y cachea los diccionarios de Estados y Tipos de Licitación.
    Devuelve diccionarios para mapeo rápido ID <-> Nombre.
    """
    try:
        estados_db = client.table("tbl_estados").select("*").execute().data
        tipos_db = client.table("tbl_tipolicitacion").select("*").execute().data
        
        # Mapeos Estados
        mapa_estados_id_a_nombre = {e['id_estado']: e['nombre_estado'] for e in estados_db}
        mapa_estados_nombre_a_id = {e['nombre_estado']: e['id_estado'] for e in estados_db}
        lista_nombres_estados = list(mapa_estados_nombre_a_id.keys())
        
        # Mapeos Tipos
        mapa_tipos_id_a_nombre = {t['id_tipolicitacion']: t['tipo'] for t in tipos_db}
        mapa_tipos_nombre_a_id = {t['tipo']: t['id_tipolicitacion'] for t in tipos_db}
        lista_nombres_tipos = list(mapa_tipos_nombre_a_id.keys())

        return {
            "estados_id_map": mapa_estados_id_a_nombre,
            "estados_name_map": mapa_estados_nombre_a_id,
            "estados_list": lista_nombres_estados,
            "tipos_id_map": mapa_tipos_id_a_nombre,
            "tipos_name_map": mapa_tipos_nombre_a_id,
            "tipos_list": lista_nombres_tipos
        }
    except Exception as e:
        st.error(f"Error cargando datos maestros: {e}")
        st.stop()