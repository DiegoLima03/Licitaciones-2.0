import streamlit as st
import pandas as pd
from datetime import datetime
from src.utils import fmt_num, fmt_date 
from src.logic.excel_import import analizar_excel_licitacion, guardar_datos_importados
# AÑADIDO: Importamos la lógica de entregas
from src.logic.deliveries import guardar_entrega_completa, eliminar_entrega_completa, actualizar_entrega_completa, sincronizar_lineas_entrega, sincronizar_cambios_lineas

def render_detalle(client, maestros):
    # Recuperamos datos de sesión
    lic_data = st.session_state.get('licitacion_activa')
    if not lic_data:
        st.session_state['vista_gestor'] = "LISTADO"
        st.rerun()
        return

    lic_id = lic_data['id']
    
    # --- AISLAMIENTO DE VISTA (Fix del botón fantasma) ---
    with st.container(key="contenedor_vista_detalle"):
        
        # --- CABECERA DE NAVEGACIÓN ---
        c_btn, c_tit = st.columns([1, 5])
        with c_btn:
            if st.button("⬅ Volver al Listado", key="btn_volver_detalle"):
                st.session_state['licitacion_activa'] = None
                st.session_state['vista_gestor'] = "LISTADO"
                # Limpiamos preview si existiera
                if 'preview_import' in st.session_state: del st.session_state['preview_import']
                st.rerun()
                
        # --- CARGAR DATOS FRESCOS ---
        try:
            # Cabecera
            data = client.table("tbl_licitaciones").select("*").eq("id_licitacion", lic_id).single().execute().data
            # Detalle (Items) ordenados por Lote e ID
            items_db = client.table("tbl_licitaciones_detalle").select("*").eq("id_licitacion", lic_id).order('lote').order('id_detalle').execute().data
        except Exception as e:
            st.error(f"Error cargando licitación: {e}")
            return
        
        with c_tit:
            st.header(f"{data['nombre']}")
            # Alerta visual si hay lotes inactivos
            if items_db:
                df_check = pd.DataFrame(items_db)
                if 'activo' in df_check.columns and not df_check['activo'].all():
                    st.caption("⚠️ Visualizando Escenario Real (Lotes perdidos excluidos del presupuesto ganado).")

        # ==============================================================================
        # 🚦 ROUTER: MODO ENFOQUE (ESTO ES LO NUEVO)
        # ==============================================================================
        # Si estamos creando una entrega, cargamos el formulario y DETENEMOS (return) 
        # la ejecución aquí para que no se pinten las pestañas de abajo.
        if st.session_state.get('creando_entrega', False):
            render_formulario_alta_entrega(client, lic_id, data, items_db)
            return 

        # ==============================================================================
        # ✏️ SECCIÓN 1: EDICIÓN DE CABECERA
        # ==============================================================================
        with st.expander("✏️ Editar Datos Principales y Configuración", expanded=False):
            render_configuracion_cabecera(client, lic_id, data, maestros)

        # ==============================================================================
        # 📊 SECCIÓN 2: DASHBOARD (Filtrado por items activos)
        # ==============================================================================
        render_dashboard_completo(client, lic_id, data, items_db)

        # ==============================================================================
        # ⚖️ SECCIÓN 3: GESTIÓN DE LOTES (NUEVO)
        # ==============================================================================
        lotes_detectados = list(set([i.get('lote', 'General') for i in items_db])) if items_db else []
        
        # Solo mostramos esto si hay lotes distintos a "General" o más de uno
        if len(lotes_detectados) > 1 or (len(lotes_detectados) == 1 and lotes_detectados[0] != 'General'):
            with st.expander("⚖️ Gestión de Adjudicación (Ganado/Perdido por Lote)", expanded=False):
                st.info("Marca los lotes que has GANADO. Los desmarcados (Perdidos) no sumarán al importe de adjudicación.")
                
                cols_lotes = st.columns(len(lotes_detectados))
                for idx, lote_nombre in enumerate(sorted(lotes_detectados)):
                    items_lote = [i for i in items_db if i.get('lote') == lote_nombre]
                    activos_lote = len([i for i in items_lote if i.get('activo', True)])
                    es_ganado = activos_lote > 0 
                    
                    with cols_lotes[idx % len(cols_lotes)]:
                        st.markdown(f"**{lote_nombre}**")
                        nuevo_estado = st.toggle(f"Adjudicado", value=es_ganado, key=f"toggle_{lote_nombre}")
                        
                        if nuevo_estado != es_ganado:
                            client.table("tbl_licitaciones_detalle").update({"activo": nuevo_estado})\
                                .eq("id_licitacion", lic_id).eq("lote", lote_nombre).execute()
                            st.toast(f"Lote {lote_nombre} actualizado.")
                            st.rerun()

        # ==============================================================================
        # 📑 SECCIÓN 4: PESTAÑAS (TODAS RESTAURADAS)
        # ==============================================================================
        st.divider()
        tab_presu, tab_real, tab_rem = st.tabs(["💰 Presupuesto (Oferta)", "🚚 Real (Ejecución)", "📉 Remaining (Pendiente)"])
        
        with tab_presu:
            render_presupuesto_completo(client, lic_id, data, maestros, items_db)
        
        with tab_real:
            # Aquí llamamos a la nueva versión
            render_ejecucion_completa(client, lic_id, items_db)

        with tab_rem:
            render_remaining(client, lic_id, items_db)


# ==============================================================================
# 🧩 FUNCIONES AUXILIARES
# ==============================================================================

def render_configuracion_cabecera(client, lic_id, data, maestros):
    """Formulario para editar la cabecera de la licitación."""
    with st.form(key=f"form_cabecera_{lic_id}"):
        tipo_lic = data.get('tipo_de_licitacion')

        if tipo_lic == 1:
            c1, c2 = st.columns(2)
            n_exp = c1.text_input("Expediente", value=data.get('numero_expediente', ''))
            n_pres = c2.number_input("Presupuesto", value=float(data.get('pres_maximo', 0) or 0))
            # El descuento no se muestra, pero mantenemos su valor para la actualización
            n_dto = float(data.get('descuento_global', 0) or 0)
        else:
            c1, c2, c3 = st.columns(3)
            n_exp = c1.text_input("Expediente", value=data.get('numero_expediente', ''))
            n_pres = c2.number_input("Presupuesto", value=float(data.get('pres_maximo', 0) or 0))
            n_dto = c3.number_input("Baja Global (%)", value=float(data.get('descuento_global', 0) or 0))

        c4, c5, c6, c7 = st.columns(4)
        def to_date(s): return datetime.strptime(s, "%Y-%m-%d").date() if s else datetime.now().date()
        
        # APLICAMOS FORMATO EUROPEO AL WIDGET
        n_fp = c4.date_input("F. Presentación", value=to_date(data.get('fecha_presentacion')), format="DD/MM/YYYY")
        n_fa = c5.date_input("F. Adjudicación", value=to_date(data.get('fecha_adjudicacion')), format="DD/MM/YYYY")
        n_ff = c6.date_input("F. Finalización", value=to_date(data.get('fecha_finalizacion')), format="DD/MM/YYYY")
        
        curr_id = data.get('id_estado')
        curr_st_name = maestros['estados_id_map'].get(curr_id, "")
        idx_st = maestros['estados_list'].index(curr_st_name) if curr_st_name in maestros['estados_list'] else 0
        n_st = c7.selectbox("Estado", maestros['estados_list'], index=idx_st)

        if st.form_submit_button("💾 Actualizar Cabecera"):
            client.table("tbl_licitaciones").update({
                "numero_expediente": n_exp, "pres_maximo": n_pres, "descuento_global": n_dto,
                "id_estado": maestros['estados_name_map'][n_st],
                "fecha_presentacion": str(n_fp), "fecha_adjudicacion": str(n_fa), "fecha_finalizacion": str(n_ff)
            }).eq("id_licitacion", lic_id).execute()
            
            if data.get('tipo_de_licitacion') == 2:
                recalcular_tipo_2(client, lic_id, n_dto)
                st.toast("✅ PVU de todas las líneas recalculado correctamente.")
            
            st.success("Datos actualizados.")
            st.rerun()

def render_dashboard_completo(client, lic_id, data_lic, items_db):
    """Dashboard filtrando solo por items ACTIVO=True."""
    # Filtramos items activos
    items_activos = [i for i in items_db if i.get('activo', True) is True]
    
    pres_maximo_pliego = float(data_lic.get('pres_maximo', 0) or 0)
    tipo_lic = data_lic.get('tipo_de_licitacion')
    
    t_venta_prevista = 0
    t_coste_previsto = 0
    mapa_precios_venta = {} 
    
    # 1. Construimos mapa de precios (necesario para cálculos reales posteriores)
    for i in items_activos:
        mapa_precios_venta[i['id_detalle']] = float(i.get('pvu', 0) or 0)

    # 2. Lógica diferenciada por Tipo de Licitación
    if tipo_lic == 2:
        # --- TIPO 2: BAJA SOBRE PRESUPUESTO ---
        # Venta = Presupuesto Base - Descuento Global
        dto_global = float(data_lic.get('descuento_global', 0) or 0)
        t_venta_prevista = pres_maximo_pliego * (1 - (dto_global / 100))
        
        # Coste = Estimación basada en el margen medio de los precios unitarios
        suma_pvu_unitarios = 0
        suma_pcu_unitarios = 0
        for i in items_activos:
            suma_pvu_unitarios += float(i.get('pvu', 0) or 0)
            suma_pcu_unitarios += float(i.get('pcu', 0) or 0)
            
        # Ratio de coste medio (cuánto coste hay por cada euro de venta unitaria)
        ratio_coste = (suma_pcu_unitarios / suma_pvu_unitarios) if suma_pvu_unitarios > 0 else 0
        t_coste_previsto = t_venta_prevista * ratio_coste
        
    else:
        # --- TIPO ESTÁNDAR / OTROS ---
        # Suma directa de (Unidades * Precio)
        for i in items_activos:
            u = float(i.get('unidades', 0) or 0)
            v = float(i.get('pvu', 0) or 0)
            c = float(i.get('pcu', 0) or 0)
            
            t_venta_prevista += (u * v)
            t_coste_previsto += (u * c)
    
    t_beneficio_previsto = t_venta_prevista - t_coste_previsto
    t_margen_previsto_pct = (t_beneficio_previsto / t_venta_prevista * 100) if t_venta_prevista > 0 else 0
    
    # Datos Reales
    items_real = client.table("tbl_licitaciones_real").select("*").eq("id_licitacion", lic_id).execute().data
    real_coste_total = 0
    real_facturado = 0
    real_cobrado = 0
    real_produccion = 0
    
    if items_real:
        for ir in items_real:
            qty_r = float(ir.get('cantidad', 0) or 0)
            coste_unit_r = float(ir.get('pcu', 0) or 0)
            id_link = ir.get('id_detalle')
            estado_r = ir.get('estado', 'EN ESPERA')
            es_cobrado = ir.get('cobrado', False)
            
            real_coste_total += (qty_r * coste_unit_r)
            
            # Usamos el precio del mapa (0 si el lote está inactivo)
            precio_venta_ref = mapa_precios_venta.get(id_link, 0.0)
            valor_venta_linea = qty_r * precio_venta_ref
            real_produccion += valor_venta_linea
            
            if estado_r == 'FACTURADO':
                real_facturado += valor_venta_linea
            if es_cobrado:
                real_cobrado += valor_venta_linea

    real_beneficio = real_produccion - real_coste_total
    real_margen_pct = (real_beneficio / real_produccion * 100) if real_produccion > 0 else 0

    with st.expander("📊 Estadísticas y Rentabilidad", expanded=True):
        c1, c2, c3, c4, c5 = st.columns(5)
        c1.metric("Presupuesto Base", f"{fmt_num(pres_maximo_pliego)} €")
        c2.metric("Ofertado (Ganado)", f"{fmt_num(t_venta_prevista)} €", help="Suma de partidas activas/ganadas")
        c3.metric("Coste Estimado", f"{fmt_num(t_coste_previsto)} €")
        c4.metric("Beneficio Previsto", f"{fmt_num(t_beneficio_previsto)} €")
        c5.metric("Margen Previsto", f"{fmt_num(t_margen_previsto_pct)} %")

        st.divider()
        r1, r2, r3, r4, r5 = st.columns(5)
        r1.metric("Producción (Valor)", f"{fmt_num(real_produccion)} €", help="Valor de venta de lo ejecutado (Albaranes)")
        r2.metric("Coste Real", f"{fmt_num(real_coste_total)} €")
        r3.metric("Beneficio Real", f"{fmt_num(real_beneficio)} €", delta=f"{fmt_num(real_margen_pct)}% Margen")
        r4.metric("Facturado", f"{fmt_num(real_facturado)} €")
        r5.metric("Cobrado", f"{fmt_num(real_cobrado)} €")

def render_presupuesto_completo(client, lic_id, data, maestros, items_db):
    tipo_id = data.get('tipo_de_licitacion')
    nombre_tipo = maestros['tipos_id_map'].get(tipo_id, "Estándar")
    dto_global = float(data.get('descuento_global') or 0)
    
    st.info(f"Modo: **{nombre_tipo}**")

    # --- A. IMPORTADOR EXCEL (FLUJO DE 2 PASOS) ---
    with st.expander("📂 Importar Presupuesto desde Excel (Masivo)", expanded=False):
        
        # 1. Subida de Archivo
        uploaded_file = st.file_uploader("1️⃣ Selecciona tu archivo Excel", type=["xlsx"])
        
        # Limpieza de sesión si cambian el archivo
        if uploaded_file is None and 'preview_import' in st.session_state:
            del st.session_state['preview_import']
        
        # 2. Botón de Análisis
        if uploaded_file:
            if st.button("🔍 Analizar Excel", key="btn_analizar_excel"):
                ok, res = analizar_excel_licitacion(uploaded_file, tipo_id)
                if ok:
                    st.session_state['preview_import'] = res # Guardamos DF en sesión
                    st.success("Análisis completado.")
                else:
                    st.error(res)

        # 3. Previsualización y Confirmación
        if 'preview_import' in st.session_state:
            df_prev = st.session_state['preview_import']
            
            st.divider()
            st.markdown(f"**Resultado del Análisis:** Se han detectado **{len(df_prev)}** líneas.")
            
            # Mostramos resumen de Lotes encontrados
            lotes_encontrados = df_prev['lote'].unique()
            st.write(f"🏷️ **Lotes detectados:** {', '.join(lotes_encontrados)}")
            
            # Mostramos una tabla con las primeras 5 filas de ejemplo
            st.caption("👀 Previsualización (Primeras 5 filas):")
            st.dataframe(df_prev.head(), use_container_width=True)
            
            col_ok, col_cancel = st.columns([1, 1])
            
            # 4. Botón FINAL de Importar
            if col_ok.button("🚀 Confirmar e Importar a Base de Datos", type="primary", key="btn_confirmar_import"):
                ok, msg = guardar_datos_importados(df_prev, lic_id, client, tipo_id, dto_global)
                if ok:
                    st.success(msg)
                    del st.session_state['preview_import'] # Limpiamos memoria
                    st.rerun()
                else:
                    st.error(msg)
            
            if col_cancel.button("❌ Cancelar Importación", key="btn_cancel_import"):
                del st.session_state['preview_import']
                st.rerun()
    
    # --- B. FORMULARIOS MANUALES (ESPECÍFICOS POR TIPO + CAMPO LOTE) ---
    if tipo_id == 1:
        st.caption("ℹ️ Desglose completo (Unidades, PMax, PVU, PCU).")
        with st.form("form_presu_tipo1"):
            lote = st.text_input("Lote / Zona", value="General")
            c1, c2 = st.columns([3, 1])
            prod = c1.text_input("Producto")
            uds = c2.number_input("Uds", 1.0)
            c3, c4, c5 = st.columns(3)
            p_max = c3.number_input("P.Máx", 0.0)
            pvu = c4.number_input("PVU", 0.0)
            pcu = c5.number_input("PCU", 0.0)
            if st.form_submit_button("➕ Añadir"):
                client.table("tbl_licitaciones_detalle").insert({
                    "id_licitacion": lic_id, "lote": lote, "producto": prod, 
                    "unidades": uds, "pmaxu": p_max, "pvu": pvu, "pcu": pcu, "activo": True
                }).execute()
                st.rerun()

    elif tipo_id == 2:
        st.caption(f"ℹ️ El PVU se calculará automáticamente aplicando un **{dto_global}%** de descuento al P.Máx.")
        with st.form("form_presu_tipo2"):
            lote = st.text_input("Lote / Zona", value="General")
            prod = st.text_input("Producto")
            c1, c2 = st.columns(2)
            p_max = c1.number_input("P.Máx cuadro", 0.0)
            pcu = c2.number_input("PCU", 0.0)
            pvu_calc = p_max * (1 - (dto_global / 100))
            if st.form_submit_button("➕ Añadir con Baja"):
                client.table("tbl_licitaciones_detalle").insert({
                    "id_licitacion": lic_id, "lote": lote, "producto": prod, 
                    "unidades": 1, "pmaxu": p_max, "pvu": pvu_calc, "pcu": pcu, "activo": True
                }).execute()
                st.rerun()

    elif tipo_id == 3:
        with st.form("form_presu_tipo3"):
            lote = st.text_input("Lote / Zona", value="General")
            c1, c2 = st.columns([3, 1])
            prod = c1.text_input("Producto")
            uds = c2.number_input("Uds", 1.0)
            c3, c4 = st.columns(2)
            pvu = c3.number_input("PVU", 0.0)
            pcu = c4.number_input("PCU", 0.0)
            if st.form_submit_button("➕ Añadir"):
                client.table("tbl_licitaciones_detalle").insert({
                    "id_licitacion": lic_id, "lote": lote, "producto": prod, 
                    "unidades": uds, "pmaxu": 0, "pvu": pvu, "pcu": pcu, "activo": True
                }).execute()
                st.rerun()

    elif tipo_id == 4:
        with st.form("form_presu_alzado"):
            lote = st.text_input("Lote / Zona", value="General")
            c1, c2 = st.columns([3, 2])
            concepto = c1.text_input("Concepto Global")
            importe = c2.number_input("Importe Total (€)", 0.0)
            if st.form_submit_button("➕ Añadir Alzado"):
                client.table("tbl_licitaciones_detalle").insert({
                    "id_licitacion": lic_id, "lote": lote, "producto": concepto, 
                    "unidades": 1, "pvu": importe, "pcu": 0, "pmaxu": 0, "activo": True
                }).execute()
                st.rerun()
    else:
        with st.form("form_presu_std"):
            lote = st.text_input("Lote / Zona", value="General")
            c1, c2, c3 = st.columns([3, 1, 1])
            prod = c1.text_input("Prod"); uds = c2.number_input("Uds", 1.0); pvu = c3.number_input("PVU", 0.0)
            if st.form_submit_button("Añadir"):
                client.table("tbl_licitaciones_detalle").insert({
                    "id_licitacion": lic_id, "lote": lote, "producto": prod, 
                    "unidades": uds, "pvu": pvu, "activo": True
                }).execute()
                st.rerun()

    # --- C. EDITOR MASIVO ---
    st.divider()
    st.markdown("##### 📋 Detalle del Presupuesto (Edición en Lote)")
    
    if items_db:
        df = pd.DataFrame(items_db)
        
        df["pvu"] = df["pvu"].fillna(0).astype(float)
        df["pcu"] = df["pcu"].fillna(0).astype(float)
        df["beneficio_u"] = df["pvu"] - df["pcu"]

        # --- SOLUCIÓN VISUAL: SEMÁFORO ---
        # Creamos una columna visual para ver el estado sin salir del editor
        df["semaforo"] = df["beneficio_u"].apply(lambda x: "🟢" if x >= 0 else "🔴")
        
        # Definimos el orden poniendo el semáforo justo antes del beneficio
        if tipo_id == 2:
            column_order = ["lote", "producto", "pmaxu", "pvu", "pcu", "semaforo", "beneficio_u"]
        else:
            column_order = ["lote", "producto", "unidades", "pmaxu", "pvu", "pcu", "semaforo", "beneficio_u"]

        cfg_base = {
            "semaforo": st.column_config.TextColumn("Est.", width=15, disabled=True, help="Verde: Beneficio Positivo | Rojo: Pérdidas"),
            "lote": st.column_config.TextColumn("Lote", width="small"),
            "producto": st.column_config.TextColumn("Producto", width="large", required=True),
            "unidades": st.column_config.NumberColumn("Uds"),
            "pmaxu": st.column_config.NumberColumn("P.Base", format="%.2f €"),
            "pcu": st.column_config.NumberColumn("PCU", format="%.2f €"),
            "beneficio_u": st.column_config.NumberColumn("Bº Unit.", disabled=True), 
        }

        if tipo_id == 2:
            cfg_base["pvu"] = st.column_config.NumberColumn("PVU (Calc)", disabled=True, format="%.2f €")
        else:
            cfg_base["pvu"] = st.column_config.NumberColumn("PVU", format="%.2f €")

        with st.form(f"editor_lotes_{lic_id}"):
            edited_df = st.data_editor(
                df, 
                column_config=cfg_base, 
                column_order=column_order, 
                num_rows="dynamic", 
                use_container_width=True, 
                hide_index=True, 
                key=f"editor_{lic_id}"
            )
            
            if st.form_submit_button("💾 Guardar Todos los Cambios"):
                records = edited_df.to_dict('records')
                to_upsert = []
                ids_actuales = []
                
                for r in records:
                    if not r.get("producto"): continue
                    
                    val_pmax = float(r.get('pmaxu', 0) or 0)
                    val_pvu = float(r.get('pvu', 0) or 0)
                    if tipo_id == 2: val_pvu = val_pmax * (1 - (dto_global / 100))

                    obj = {
                        "id_licitacion": lic_id,
                        "lote": r.get('lote', 'General'),
                        "producto": r['producto'],
                        "unidades": float(r.get('unidades', 1) or 1),
                        "pmaxu": val_pmax, "pvu": val_pvu, "pcu": float(r.get('pcu', 0) or 0),
                        "activo": r.get('activo', True) 
                    }
                    if pd.notna(r.get('id_detalle')):
                        obj['id_detalle'] = r['id_detalle']
                        ids_actuales.append(r['id_detalle'])
                    to_upsert.append(obj)
                
                ids_originales = [i['id_detalle'] for i in items_db]
                ids_borrar = list(set(ids_originales) - set(ids_actuales))
                
                if ids_borrar: client.table("tbl_licitaciones_detalle").delete().in_("id_detalle", ids_borrar).execute()
                if to_upsert: client.table("tbl_licitaciones_detalle").upsert(to_upsert).execute()
                
                st.success("Tabla actualizada"); st.rerun()

# ==============================================================================
# 🔥 NUEVA LÓGICA DE EJECUCIÓN POR ENTREGAS (Cabecera + Líneas)
# ==============================================================================
def render_ejecucion_completa(client, lic_id, items_db):
    st.subheader("📦 Gestión de Albaranes y Partes de Trabajo")
    
    # 1. PREPARACIÓN DE DATOS
    items_activos = [i for i in items_db if i.get('activo', True)]
    mapa_presu_ids = {f"{i.get('lote','Gen')} - {i['producto']}": i['id_detalle'] for i in items_activos}
    opciones_select = sorted(list(mapa_presu_ids.keys())) + ["➕ Gasto NO Presupuestado / Extra"]

    # 2. BOTÓN ACTIVADOR
    if not st.session_state.get('creando_entrega', False):
        if st.button("➕ Registrar Nuevo Documento / Albarán", type="primary", use_container_width=True):
            st.session_state['creando_entrega'] = True
            st.rerun()
        
    # 3. FORMULARIO DE CREACIÓN (IN-LINE, aunque ahora usamos más el modo enfoque)
    if st.session_state.get('creando_entrega', False):
        # Esta lógica está duplicada visualmente en render_formulario_alta_entrega, 
        # pero por si acaso se usa in-line, actualizamos el date_input.
        pass 

    # 4. LISTADO DE ENTREGAS (HISTÓRICO EDITABLE)
    st.divider()
    st.markdown("### 📜 Histórico de Documentos")
    
    try:
        entregas = client.table("tbl_entregas").select("*").eq("id_licitacion", lic_id).order("fecha_entrega", desc=True).execute().data
    except Exception as e:
        entregas = []
    
    if entregas:
        for ent in entregas:
            id_e = ent['id_entrega']
            # USO DE FMT_DATE PARA TÍTULO EXPANDER
            fecha_fmt = fmt_date(ent['fecha_entrega'])
            titulo = f"📦 {fecha_fmt} | Ref: **{ent['codigo_albaran']}**"
            
            # Lógica para mantener el expander abierto tras recargar
            is_expanded = (st.session_state.get('expander_activo') == id_e)
            
            with st.expander(titulo, expanded=is_expanded):
                c_head, c_edit, c_del = st.columns([5, 1, 1])
                c_head.caption(f"📝 Notas: {ent.get('observaciones','')}")
                
                # --- BOTÓN EDITAR ---
                if c_edit.button("✏️", key=f"edit_ent_{id_e}"):
                    # 1. Guardamos ID y Datos de Cabecera
                    st.session_state['id_entrega_en_edicion'] = id_e
                    st.session_state['datos_entrega_edit'] = {
                        "fecha": ent['fecha_entrega'],
                        "albaran": ent['codigo_albaran'],
                        "notas": ent.get('observaciones', '')
                    }
                    
                    # 2. Recuperamos líneas para pre-cargar el DataFrame
                    lineas_edit = client.table("tbl_licitaciones_real").select("*").eq("id_entrega", id_e).execute().data
                    
                    # 3. Reconstruimos el formato para el Editor (Mapeo Inverso ID -> Nombre Combo)
                    rev_map = {v: k for k, v in mapa_presu_ids.items()}
                    data_edit = []
                    
                    for l in lineas_edit:
                        idd = l.get('id_detalle')
                        # Si tiene ID, usamos el nombre del combo, si no, el nombre guardado (Extra)
                        concepto = rev_map.get(idd, l.get('articulo', ''))
                        
                        data_edit.append({
                            "Concepto / Partida": concepto,
                            "Proveedor": l.get('proveedor', ''),
                            "Cantidad": float(l.get('cantidad', 0)),
                            "Coste Unit.": float(l.get('pcu', 0))
                        })
                    
                    if not data_edit:
                        st.session_state['df_entrega_temp'] = pd.DataFrame(columns=["Concepto / Partida", "Proveedor", "Cantidad", "Coste Unit."])
                    else:
                        st.session_state['df_entrega_temp'] = pd.DataFrame(data_edit)
                        
                    st.session_state['creando_entrega'] = True
                    st.rerun()

                # --- BOTÓN ELIMINAR ---
                if c_del.button("🗑️", key=f"del_ent_{id_e}"):
                    if eliminar_entrega_completa(client, id_e):
                        # Limpiamos el estado si borramos el activo
                        if st.session_state.get('expander_activo') == id_e:
                            st.session_state['expander_activo'] = None
                        st.success("Eliminado"); st.rerun()
                
                # Cargamos líneas
                lineas = client.table("tbl_licitaciones_real").select("*").eq("id_entrega", id_e).order("id_real").execute().data
                
                # Inicializamos DataFrame siempre (vacío o con datos) para permitir añadir filas
                if lineas:
                    df_l = pd.DataFrame(lineas)
                else:
                    df_l = pd.DataFrame(columns=["id_real", "articulo", "proveedor", "cantidad", "pcu", "estado", "cobrado", "fecha_entrega"])

                # Handler local para cambios silenciosos
                def on_change_lines(k, df_orig):
                    changes = st.session_state[k]
                    sincronizar_cambios_lineas(client, changes, df_orig)

                editor_key = f"editor_lines_{id_e}"

                # --- EDICIÓN COMPLETA ---
                edited_lines = st.data_editor(
                    df_l,
                    column_config={
                        "id_real": None, "id_licitacion": None, "id_entrega": None, "id_detalle": None, "fecha_entrega": None, "created_at": None,
                        "articulo": st.column_config.TextColumn("Concepto", width="large"), # Habilitado
                        "proveedor": st.column_config.TextColumn("Proveedor", width="medium"), # Habilitado
                        "cantidad": st.column_config.NumberColumn("Cant", format="%.2f"), # Habilitado
                        "pcu": st.column_config.NumberColumn("Coste", format="%.2f €"), # Habilitado
                        "estado": st.column_config.SelectboxColumn("Estado", options=["EN ESPERA", "ENTREGADO", "FACTURADO"], required=True),
                        "cobrado": st.column_config.CheckboxColumn("Cobrado")
                    },
                    column_order=["articulo", "proveedor", "cantidad", "pcu", "estado", "cobrado"],
                    use_container_width=True,
                    hide_index=True,
                    num_rows="dynamic", # Permite añadir/borrar filas
                    key=editor_key,
                    on_change=on_change_lines,
                    kwargs={"k": editor_key, "df_orig": df_l}
                )
    else:
        st.info("No hay documentos registrados.")

    # 5. SOPORTE LEGADO
    lineas_huerfanas = client.table("tbl_licitaciones_real").select("*").eq("id_licitacion", lic_id).is_("id_entrega", "null").execute().data
    if lineas_huerfanas:
        st.divider()
        with st.expander("⚠️ Movimientos Antiguos (Sin Agrupar)", expanded=False):
            # Aquí también podríamos formatear la columna fecha si quisiéramos ser exhaustivos
            df_huerfanas = pd.DataFrame(lineas_huerfanas)
            if 'fecha_entrega' in df_huerfanas.columns:
                 # Aplicamos formato solo visual
                 df_huerfanas['fecha_entrega'] = df_huerfanas['fecha_entrega'].apply(fmt_date)
            
            st.dataframe(df_huerfanas[['fecha_entrega', 'articulo', 'cantidad', 'pcu']], use_container_width=True)                               
def render_remaining(client, lic_id, items_db):
    st.subheader("Control de Pendientes (Remaining)")
    st.markdown("Comparativa entre **Unidades Presupuestadas** vs **Unidades Ejecutadas**.")

    # 1. Obtenemos Presupuesto (solo activos)
    budget_items = [i for i in items_db if i.get('activo', True)]
    
    # 2. Obtenemos Ejecutado (Real) y sumamos por id_detalle
    real_items = client.table("tbl_licitaciones_real").select("id_detalle, cantidad").eq("id_licitacion", lic_id).execute().data
    
    sumas_real = {}
    for r in real_items:
        id_d = r.get('id_detalle')
        if id_d:
            sumas_real[id_d] = sumas_real.get(id_d, 0) + float(r.get('cantidad', 0) or 0)
            
    # 3. Construimos la Tabla Cruzada
    if budget_items:
        # Agrupación por (Lote, Producto) para evitar duplicados visuales
        agrupado = {}

        for b in budget_items:
            id_d = b['id_detalle']
            lote = b.get('lote', 'Gen')
            prod_raw = b.get('producto', '')
            key = (lote, prod_raw.strip().lower())

            u_presu = float(b.get('unidades', 0) or 0)
            u_real = float(sumas_real.get(id_d, 0))

            if key not in agrupado:
                agrupado[key] = {"Lote": lote, "Producto": prod_raw, "Presupuestado": 0.0, "Ejecutado": 0.0}
            
            agrupado[key]["Presupuestado"] += u_presu
            agrupado[key]["Ejecutado"] += u_real

        rows = []
        for v in agrupado.values():
            u_p = v["Presupuestado"]
            u_r = v["Ejecutado"]
            pendiente = u_p - u_r
            progreso = (min(max(u_r / u_p, 0.0), 1.0) * 100) if u_p > 0 else 0.0
            
            rows.append({
                "Lote": v["Lote"],
                "Producto": v["Producto"],
                "Presupuestado": u_p,
                "Ejecutado": u_r,
                "Pendiente": pendiente,
                "Progreso": progreso
            })
            
        df = pd.DataFrame(rows)
        st.dataframe(
            df,
            use_container_width=True,
            hide_index=True,
            column_config={
                "Lote": st.column_config.TextColumn("Lote", width="small"),
                "Producto": st.column_config.TextColumn("Partida", width="large"),
                "Presupuestado": st.column_config.NumberColumn("Ud. Presu.", format="%.2f"),
                "Ejecutado": st.column_config.NumberColumn("Ud. Real", format="%.2f"),
                "Pendiente": st.column_config.NumberColumn("Falta por Servir", format="%.2f"),
                "Progreso": st.column_config.ProgressColumn("Estado", format="%.0f%%", min_value=0, max_value=100)
            }
        )
    else:
        st.info("No hay partidas en el presupuesto para calcular pendientes.")

def recalcular_tipo_2(client, lic_id, nuevo_dto):
    # Optimización: Leemos todo para hacer upsert masivo (más rápido y seguro que ir una a una)
    items = client.table("tbl_licitaciones_detalle").select("*").eq("id_licitacion", lic_id).execute().data
    if items:
        for i in items:
            pmax = float(i.get('pmaxu', 0) or 0)
            nuevo_pvu = pmax * (1 - (nuevo_dto / 100))
            i['pvu'] = nuevo_pvu
        
        # Enviamos todos los cambios de una sola vez
        client.table("tbl_licitaciones_detalle").upsert(items).execute()

            # ==============================================================================
# 📝 VISTA 2: MODO ENFOQUE (Solo Formulario de Alta)
# ==============================================================================
def render_formulario_alta_entrega(client, lic_id, data, items_db):
    """
    Muestra SOLO el formulario de creación de entrega ocupando toda la pantalla.
    """
    def cancelar_edicion():
        st.session_state['creando_entrega'] = False
        if 'id_entrega_en_edicion' in st.session_state: del st.session_state['id_entrega_en_edicion']
        if 'datos_entrega_edit' in st.session_state: del st.session_state['datos_entrega_edit']
        if 'df_entrega_temp' in st.session_state: del st.session_state['df_entrega_temp']

    st.button("⬅ Cancelar y Volver", on_click=cancelar_edicion)
    
    # --- LÓGICA DE MODO (CREAR vs EDITAR) ---
    id_edit = st.session_state.get('id_entrega_en_edicion')
    datos_edit = st.session_state.get('datos_entrega_edit', {})
    
    if id_edit:
        titulo_form = "✏️ Editando Entrega / Albarán"
        try:
            d_val = datetime.strptime(datos_edit.get('fecha'), "%Y-%m-%d").date()
        except:
            d_val = datetime.now()
        val_alb = datos_edit.get('albaran', '')
        val_notas = datos_edit.get('notas', '')
        btn_label = "🔄 Actualizar Documento"
    else:
        titulo_form = f"📦 Nueva Entrega / Albarán para: *{data['nombre']}*"
        d_val = datetime.now()
        val_alb = ""
        val_notas = ""
        btn_label = "💾 Guardar Documento y Finalizar"

    st.markdown(f"### {titulo_form}")
    st.info("Rellena los datos y guarda para volver al dashboard.")

    items_activos = [i for i in items_db if i.get('activo', True)]
    mapa_presu_ids = {f"{i.get('lote','Gen')} - {i['producto']}": i['id_detalle'] for i in items_activos}
    opciones_select = sorted(list(mapa_presu_ids.keys())) + ["➕ Gasto NO Presupuestado / Extra"]

    with st.container(border=True):
        # Usamos st.form para evitar recargas constantes al editar la tabla
        with st.form("frm_alta_entrega", clear_on_submit=False):
            c1, c2 = st.columns(2)
            # APLICAMOS FORMATO EUROPEO AL WIDGET
            fecha = c1.date_input("Fecha Documento", value=d_val, format="DD/MM/YYYY")
            alb = c2.text_input("Nº Albarán / Referencia / Parte", value=val_alb)
            notas = st.text_area("Notas Globales", value=val_notas, height=60, placeholder="Comentarios generales...")
            
            st.divider()
            st.markdown("**Detalle de Líneas (Indica el proveedor en cada línea):**")
            
            if 'df_entrega_temp' not in st.session_state:
                st.session_state['df_entrega_temp'] = pd.DataFrame(
                    [{"Concepto / Partida": "", "Proveedor": "", "Cantidad": 0.0, "Coste Unit.": 0.0}] * 3
                )

            col_cfg = {
                "Concepto / Partida": st.column_config.SelectboxColumn(
                    "Partida Presupuesto", options=opciones_select, width="large", required=True
                ),
                "Proveedor": st.column_config.TextColumn("Proveedor", width="medium", required=True),
                "Cantidad": st.column_config.NumberColumn("Cant.", min_value=0.0, step=0.1, format="%.2f"),
                "Coste Unit.": st.column_config.NumberColumn("Coste (€)", min_value=0.0, step=0.01, format="%.2f €")
            }
            
            edited_df = st.data_editor(
                st.session_state['df_entrega_temp'],
                column_config=col_cfg,
                num_rows="dynamic",
                use_container_width=True,
                key="editor_nueva_entrega_full"
            )
            
            st.write("")
            submitted = st.form_submit_button(btn_label, type="primary", use_container_width=True)
        
        if submitted:
            # Guardamos estado temporal por si falla la validación y se recarga
            st.session_state['df_entrega_temp'] = edited_df
            
            if not alb:
                st.error("⚠️ Falta el Nº de Referencia.")
            else:
                cabecera = {"fecha": fecha, "albaran": alb, "notas": notas}
                
                if id_edit:
                    # MODO EDICIÓN
                    ok, msg = actualizar_entrega_completa(client, id_edit, lic_id, cabecera, edited_df, mapa_presu_ids)
                else:
                    # MODO CREACIÓN
                    ok, msg = guardar_entrega_completa(client, lic_id, cabecera, edited_df, mapa_presu_ids)
                
                if ok:
                    st.success(msg)
                    # Limpieza completa
                    cancelar_edicion() 
                    st.rerun()
                else:
                    st.error(msg)