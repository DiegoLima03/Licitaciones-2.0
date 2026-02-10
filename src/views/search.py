### src/views/search.py
import streamlit as st
import pandas as pd
from src.utils import fmt_num, boton_volver # <--- Importamos tu función

def render_buscador(client):
    # 1. Cabecera con Botón Volver (Usando columnas para alinear)
    c_back, c_tit = st.columns([1, 5])
    
    with c_back:
        # Llamamos a tu función de utils para volver al MENU
        boton_volver('MENU')
        
    with c_tit:
        st.title("🔍 Buscador Histórico")
    
    st.markdown("Busca por producto para ver precios ofertados anteriormente.")
    
    # 2. Resto del buscador (sin cambios)
    search_term = st.text_input("Producto:", placeholder="Ej: Planta, Tierra...")
    
    if search_term:
        try:
            res = client.table("tbl_licitaciones_detalle")\
                .select("*, tbl_licitaciones(nombre, numero_expediente)")\
                .ilike("producto", f"%{search_term}%")\
                .execute().data
            
            if res:
                st.write(f"✅ **{len(res)}** resultados:")
                rows = []
                for item in res:
                    lic = item.get('tbl_licitaciones') or {}
                    rows.append({
                        "Producto": item['producto'],
                        "PVU": f"{fmt_num(item['pvu'])} €",
                        "PCU": f"{fmt_num(item['pcu'])} €",
                        "Uds": item['unidades'],
                        "Licitación": lic.get('nombre', 'Desc'),
                        "Expediente": lic.get('numero_expediente', '-')
                    })
                st.dataframe(pd.DataFrame(rows), use_container_width=True)
            else:
                st.warning("No se encontraron coincidencias.")
        except Exception as e:
            st.error(f"Error: {e}")