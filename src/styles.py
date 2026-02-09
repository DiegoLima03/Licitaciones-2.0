### src/styles.py
import streamlit as st

def cargar_estilo_veraleza():
    """Aplica los estilos CSS personalizados de Veraleza con optimización Anti-Ghosting."""
    
    # --- VARIABLES DE COLOR ---
    color_borde_input = "#A89F68"  
    color_boton_fondo = "#807840" 
    color_boton_hover = "#6b6333"
    color_placeholder = "#5F7D95"  
    radio_borde = "8px"            

    estilo = f"""
    <style>
        /* 1. ESTILOS GENERALES */
        .stApp {{
            background-color: #F3F1ED;
            color: #4A4A4A;
        }}
        header[data-testid="stHeader"] {{
            background-color: #F3F1ED !important;
        }}
        section[data-testid="stSidebar"] {{
            background-color: #EBE9E4; 
            border-right: 1px solid #D6D3CD;
        }}

        /* 2. ESTILOS INPUTS */
        div[data-testid="stTextInput"] input {{
            border: 2px solid {color_borde_input} !important;
            border-radius: {radio_borde} !important;
            background-color: #FFFFFF !important;
            color: #333333 !important;
            padding: 0.5rem !important;
        }}
        div[data-testid="stTextInput"] input:focus {{
            border-color: {color_borde_input} !important;
            box-shadow: none !important; 
            outline: none !important;
        }}

        /* 3. ESTILOS BOTONES */
        div[data-testid="stButton"] > button {{
            background-color: {color_boton_fondo} !important;
            color: #FFFFFF !important;
            border-radius: {radio_borde} !important;
            padding: 0.6rem 1.2rem !important;
            font-weight: 600 !important;
            transition: all 0.2s ease !important;
            width: auto !important;
        }}

        div[data-testid="stButton"] > button:hover {{
            background-color: {color_boton_hover} !important;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1) !important;
        }}

        /* ================================================================= */
        /* 4. FIX DEFINITIVO PARA EL GHOSTING (REVISADO)                     */
        /* ================================================================= */
        
        /* Oculta elementos que están en proceso de borrado (stale) */
        div[data-stale="true"] {{
            display: none !important;
        }}

        /* Elimina la transición de opacidad de Streamlit que causa el efecto fantasma */
        .element-container, .stVerticalBlock, .stHorizontalBlock {{
            transition: none !important;
            animation: none !important;
        }}

        /* Forzar ocultación inmediata de elementos con opacidad baja */
        [style*="opacity: 0"], [style*="opacity:0"] {{
            display: none !important;
            height: 0 !important;
            margin: 0 !important;
        }}

        /* Evitar que el contenedor principal salte al cargar */
        [data-testid="stVerticalBlock"] > div:empty {{
            display: none !important;
        }}
    </style>
    """
    st.markdown(estilo, unsafe_allow_html=True)