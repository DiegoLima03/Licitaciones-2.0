### main.py
import streamlit as st
import time 
from src.config import init_connection, get_maestros
from src.styles import cargar_estilo_veraleza
from src.views import home, search, tenders_list, tenders_detail, login 

# 1. Configuración Pagina
st.set_page_config(page_title="Veraleza", page_icon="Imagenes/Icono.png", layout="wide")

# 2. Inicialización de Estilos y Conexión
cargar_estilo_veraleza()
supabase = init_connection()
maestros = get_maestros(supabase)

# 3. Gestión de Estado Global
if 'usuario_logueado' not in st.session_state:
    st.session_state['usuario_logueado'] = False
if 'usuario_rol' not in st.session_state:
    st.session_state['usuario_rol'] = None
if 'app_mode' not in st.session_state:
    st.session_state['app_mode'] = 'LOGIN'
if 'vista_gestor' not in st.session_state:
    st.session_state['vista_gestor'] = 'LISTADO'
if 'licitacion_activa' not in st.session_state:
    st.session_state['licitacion_activa'] = None

# 4. Lógica de Seguridad
if not st.session_state['usuario_logueado']:
    st.session_state['app_mode'] = 'LOGIN'

# 5. Enrutador Principal con Placeholder Único
# Definimos el contenedor vacío justo aquí para que sea el eje de la app
main_container = st.empty()

with main_container.container():
    mode = st.session_state['app_mode']

    if mode == 'LOGIN':
        login.render_login(supabase)

    elif mode == 'MENU':
        home.render_menu()

    elif mode == 'BUSCADOR':
        search.render_buscador(supabase)

    elif mode == 'GESTOR':
        # Seguridad de Rol
        if st.session_state['usuario_rol'] != 'ADMIN':
            st.error("No tienes permisos para acceder a esta sección.")
            if st.button("Volver al Menú"):
                st.session_state['app_mode'] = 'MENU'
                st.rerun()
            st.stop()

        vista = st.session_state.get('vista_gestor', 'LISTADO')
        
        if vista == 'LISTADO':
            tenders_list.render_listado(supabase, maestros)
        elif vista == 'NUEVA':
            tenders_list.render_nueva(supabase, maestros)
        elif vista == 'DETALLE':
            if st.session_state['licitacion_activa']:
                tenders_detail.render_detalle(supabase, maestros)
            else:
                st.session_state['vista_gestor'] = 'LISTADO'
                st.rerun()