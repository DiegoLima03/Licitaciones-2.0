### src/views/tenders_list.py
import streamlit as st
from datetime import datetime
from src.utils import fmt_num, boton_volver
from src.logic.dashboard_analytics import calcular_kpis_generales # <--- NUEVA IMPORTACIÓN

def ir_a_nueva_licitacion():
    st.session_state['vista_gestor'] = "NUEVA"

def volver_al_listado():
    st.session_state['vista_gestor'] = "LISTADO"

def abrir_detalle_licitacion(id_lic, nombre_lic):
    st.session_state['licitacion_activa'] = {'id': id_lic, 'nombre': nombre_lic}
    st.session_state['vista_gestor'] = "DETALLE"

def render_kpis_summary(kpis_data: dict):
    """Sub-componente de UI para renderizar las tarjetas de métricas."""
    if not kpis_data: return

    st.markdown("### 📈 Rendimiento Global")
    
    # Diseño en 4 columnas
    k1, k2, k3, k4 = st.columns(4)
    
    with k1:
        st.metric(
            label="Licitaciones Totales",
            value=kpis_data['total_count'],
            delta="Expedientes"
        )
    
    with k2:
        # Pipeline: Lo que estamos "cocinando"
        st.metric(
            label="Pipeline (En Curso)",
            value=f"{fmt_num(kpis_data['pipeline_monto'])} €",
            help="Suma de Presupuestos en estado 'En Estudio' o 'Presentada'"
        )

    with k3:
        # Adjudicado: Lo que ya es nuestro
        st.metric(
            label="Cartera Adjudicada",
            value=f"{fmt_num(kpis_data['adjudicado_monto'])} €",
            delta="Ganado",
            delta_color="normal" # Verde por defecto
        )

    with k4:
        # Tasa de éxito
        st.metric(
            label="Tasa de Adjudicación",
            value=f"{kpis_data['win_rate']:.1f}%",
            help="% de licitaciones en estado 'Adjudicada' sobre el total."
        )
    
    st.divider()

def render_listado(client, maestros):
    # Usamos un contenedor único para aislar el renderizado
    with st.container():
        
        # --- 1. CABECERA ---
        c_back, c_tit, c_new = st.columns([1, 4, 1.5]) 
        
        with c_back:
            boton_volver('MENU')
        
        with c_tit:
            st.title("📂 Mis Licitaciones")
            
        with c_new:
            st.button("➕ Nueva Licitación", on_click=ir_a_nueva_licitacion, key="btn_nueva_lic_principal")
        
        # --- 2. DASHBOARD DE KPIs (NUEVO BLOQUE) ---
        # Calculamos los KPIs al vuelo usando la lógica separada
        kpis = calcular_kpis_generales(client, maestros)
        render_kpis_summary(kpis)

        # --- 3. FILTROS Y LISTADO ---
        filtro = st.text_input("🔍 Buscar por nombre:", "")
        
        query = client.table("tbl_licitaciones").select("*").order("id_licitacion", desc=True)
        if filtro:
            query = query.ilike("nombre", f"%{filtro}%")
        
        datos = query.execute().data
        
        if datos:
            # Cabeceras de la tabla
            c1, c2, c3, c4, c5 = st.columns([1, 3, 2, 2, 2])
            c1.markdown("**Exp**")
            c2.markdown("**Proyecto**")
            c3.markdown("**Estado**")
            c4.markdown("**Presup**")
            c5.markdown("**Acción**")
            st.markdown("---")

            lista_maestra_estados = maestros.get('estados_list', [])
            
            for lic in datos:
                c1, c2, c3, c4, c5 = st.columns([1, 3, 2, 2, 2])
                
                c1.write(lic.get('numero_expediente', '-'))
                c2.write(f"*{lic.get('nombre')}*")
                
                curr_id = lic.get('id_estado')
                curr_name = maestros['estados_id_map'].get(curr_id)
                
                if curr_name and curr_name in lista_maestra_estados:
                    idx = lista_maestra_estados.index(curr_name)
                    opciones = lista_maestra_estados
                else:
                    idx = 0
                    opciones = ["⚠️ Sin Asignar"] + lista_maestra_estados
                
                new_state_selection = c3.selectbox(
                    "Estado", 
                    options=opciones, 
                    index=idx, 
                    key=f"lst_{lic['id_licitacion']}", 
                    label_visibility="collapsed"
                )
                
                if new_state_selection != "⚠️ Sin Asignar":
                     if new_state_selection != curr_name:
                        new_id = maestros['estados_name_map'].get(new_state_selection)
                        if new_id:
                            client.table("tbl_licitaciones").update({"id_estado": new_id})\
                                .eq("id_licitacion", lic['id_licitacion']).execute()
                            st.toast(f"✅ Estado cambiado a: {new_state_selection}")
                            st.rerun()

                c4.write(f"{fmt_num(lic.get('pres_maximo'))} €")
                
                c5.button(
                    "📂 Abrir", 
                    key=f"btn_{lic['id_licitacion']}",
                    on_click=abrir_detalle_licitacion,
                    args=(lic['id_licitacion'], lic['nombre'])
                )
        else:
            st.info("No se encontraron licitaciones.")

def render_nueva(client, maestros):
    with st.container():
        st.button("⬅ Cancelar y Volver al Listado", on_click=volver_al_listado)

        st.header("📝 Nueva Licitación")
        
        with st.form("frm_nueva"):
            c1, c2 = st.columns(2)
            nombre = c1.text_input("Nombre del Proyecto")
            exp = c2.text_input("Nº Expediente")
            
            c_f1, c_f2, c_f3 = st.columns(3)
            # APLICAMOS FORMATO EUROPEO AQUI
            f_pres = c_f1.date_input("F. Presentación", value=datetime.now(), format="DD/MM/YYYY") 
            f_adj = c_f2.date_input("F. Adjudicación", value=datetime.now(), format="DD/MM/YYYY")
            f_fin = c_f3.date_input("F. Finalización", value=datetime.now(), format="DD/MM/YYYY")

            c3, c4, c5 = st.columns(3)
            pres = c3.number_input("Presupuesto Max (€)", step=100.0)
            
            list_est = maestros.get('estados_list', [])
            if not list_est:
                st.error("Error: No hay estados cargados en el sistema.")
                est = None
            else:
                est = c4.selectbox("Estado Inicial", list_est)
                
            tip = c5.selectbox("Tipo", maestros.get('tipos_list', []))
            desc = st.text_area("Notas / Descripción")
            
            if st.form_submit_button("Guardar"):
                if not nombre:
                    st.error("El nombre es obligatorio")
                    return
                
                if not est:
                    st.error("No se puede guardar sin un estado válido.")
                    return

                obj = {
                    "nombre": nombre, 
                    "numero_expediente": exp, 
                    "pres_maximo": pres, 
                    "descripcion": desc,
                    "id_estado": maestros['estados_name_map'][est],
                    "tipo_de_licitacion": maestros['tipos_name_map'].get(tip),
                    "fecha_presentacion": str(f_pres), 
                    "fecha_adjudicacion": str(f_adj),
                    "fecha_finalizacion": str(f_fin)
                }
                
                res = client.table("tbl_licitaciones").insert(obj).execute()
                
                st.success("Licitación creada correctamente.")
                st.session_state['vista_gestor'] = "LISTADO"
                st.rerun()