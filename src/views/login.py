### src/views/login.py
import streamlit as st
import time  # <--- ¡ESTA LÍNEA ES LA QUE FALTABA!
from src.logic.auth import autenticar_usuario

def render_login(client):
    """Renderiza el formulario de inicio de sesión centrado"""
    
    # Columnas para centrar el formulario visualmente
    col1, col2, col3 = st.columns([1, 2, 1])
    
    with col2:
        st.header("🔐 Iniciar Sesión")
        st.markdown("Bienvenido a **Veraleza Licitaciones**")
        
        with st.form("frm_login"):
            email = st.text_input("Correo electrónico")
            password = st.text_input("Contraseña", type="password")
            
            submit = st.form_submit_button("Entrar", use_container_width=True)
            
            if submit:
                if not email or not password:
                    st.warning("Por favor completa ambos campos.")
                else:
                    user = autenticar_usuario(client, email, password)
                    
                    if user:
                        # ¡ÉXITO! Guardamos datos en sesión
                        st.session_state['usuario_logueado'] = True
                        st.session_state['usuario_rol'] = user['rol']     # 'ADMIN' o 'CONSULTOR'
                        st.session_state['usuario_nombre'] = user['nombre']
                        st.session_state['app_mode'] = 'MENU' # Mandamos al menú
                        
                        st.success(f"Bienvenido, {user['nombre']}")
                        time.sleep(1) # Pequeña pausa para ver el mensaje
                        st.rerun()
                    else:
                        st.error("Credenciales incorrectas.")