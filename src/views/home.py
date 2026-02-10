### src/views/home.py
import streamlit as st
from src.logic.auth import cerrar_sesion

def render_menu():
    # Barra superior con info del usuario
    col_saludo, col_logout = st.columns([6, 1])
    nombre = st.session_state.get('usuario_nombre', 'Usuario')
    rol = st.session_state.get('usuario_rol', 'N/A')
    
    col_saludo.markdown(f"👋 Hola, **{nombre}** (Rol: `{rol}`)")
    if col_logout.button("Salir"):
        cerrar_sesion()

    st.title("Veraleza - Central de Licitaciones")
    st.markdown("---")
    
    col1, col2 = st.columns(2)
    
    # --- OPCIÓN 1: VISIBLE PARA TODOS ---
    with col1:
        st.info("🔍 Consultar Precios Históricos")
        st.write("Busca productos en licitaciones pasadas.")
        if st.button("Ir al Buscador ➡", use_container_width=True):
            st.session_state['app_mode'] = 'BUSCADOR'
            st.rerun()
            
    # --- OPCIÓN 2: PROTEGIDA (SOLO ADMIN) ---
    with col2:
        if rol == 'ADMIN':
            st.success("📂 Gestión de Licitaciones")
            st.write("Crear, editar y controlar obras.")
            if st.button("Ir al Gestor ➡", use_container_width=True):
                st.session_state['app_mode'] = 'GESTOR'
                st.rerun()
        else:
            # Si NO es admin, mostramos esto bloqueado u oculto
            st.warning("🔒 Gestión de Licitaciones")
            st.write("Acceso restringido a Administradores.")
            st.button("🚫 Acceso Denegado", disabled=True, use_container_width=True)