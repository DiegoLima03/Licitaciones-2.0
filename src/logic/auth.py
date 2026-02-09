### src/logic/auth.py
import streamlit as st
import time

def autenticar_usuario(client, email, password):
    """
    Verifica si el email y password coinciden en la base de datos.
    Retorna el objeto usuario (dict) si es correcto, o None si falla.
    """
    try:
        # Buscamos el usuario por email
        response = client.table("tbl_usuarios").select("*").eq("email", email).execute()
        
        if not response.data:
            return None # Usuario no existe
        
        usuario = response.data[0]
        
        # Verificación simple de contraseña
        # NOTA: En producción real, aquí usaríamos bcrypt.checkpw()
        if usuario['password'] == password:
            return usuario
        else:
            return None # Contraseña incorrecta
            
    except Exception as e:
        st.error(f"Error de conexión: {e}")
        return None

def cerrar_sesion():
    """Limpia la sesión y recarga la página"""
    st.session_state['usuario_logueado'] = None
    st.session_state['usuario_rol'] = None
    st.session_state['usuario_nombre'] = None
    st.session_state['app_mode'] = 'LOGIN'
    st.rerun()