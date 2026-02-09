### src/utils.py
import streamlit as st
from datetime import datetime, date

def navegar_a(destino: str):
    """
    Función centralizada para cambiar de vista.
    Usa esto en lugar de cambiar el session_state manualmente.
    """
    st.session_state['app_mode'] = destino
    st.rerun()

def fmt_num(valor) -> str:
    """Convierte un valor numérico a formato español estándar."""
    if valor is None or str(valor) == "":
        return "0,00"
    try:
        val_float = float(valor)
        # Formato ES: punto mil, coma decimal
        return f"{val_float:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
    except:
        return "0,00"

def fmt_date(valor) -> str:
    """
    Convierte una fecha (string ISO o objeto date) a formato europeo DD/MM/YYYY.
    Ejemplo: '2023-12-31' -> '31/12/2023'
    """
    if not valor:
        return ""
    
    try:
        # Si ya es un objeto fecha, formateamos directo
        if isinstance(valor, (date, datetime)):
            return valor.strftime("%d/%m/%Y")
        
        # Si es string, intentamos parsear formato ISO (YYYY-MM-DD)
        if isinstance(valor, str):
            # Recortamos por si viene con hora (YYYY-MM-DDTHH:MM:SS)
            valor_clean = valor.split("T")[0]
            dt = datetime.strptime(valor_clean, "%Y-%m-%d")
            return dt.strftime("%d/%m/%Y")
            
        return str(valor)
    except Exception:
        # Si falla (formato desconocido), devolvemos el valor original
        return str(valor)

def get_clean_number(row, col_name, df_columns) -> float:
    """Helper seguro para extraer números de dataframes sucios."""
    if col_name not in df_columns: return 0.0
    val = row[col_name]
    s_val = str(val).strip()
    if not s_val or s_val.lower() == 'nan': return 0.0
    
    if isinstance(val, (int, float)):
        return float(val)
    try:
        s_val = s_val.replace('€', '').strip()
        # Detección formato europeo vs americano
        if ',' in s_val and '.' in s_val:
             if s_val.find(',') < s_val.find('.'):
                 s_val = s_val.replace(',', '') # Americano 1,000.00
             else:
                 s_val = s_val.replace('.', '').replace(',', '.') # Europeo 1.000,00
        elif ',' in s_val:
            s_val = s_val.replace(',', '.')
            
        return float(s_val)
    except:
        return 0.0

def boton_volver(destino='MENU'):
    """Crea un botón de volver estándar usando la nueva navegación."""
    if st.button("⬅ Volver"):
        navegar_a(destino)