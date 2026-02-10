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
    """
    Dashboard de Rentabilidad Real vs Presupuesto.
    LÓGICA CLAVE:
    - Ingreso Real = Cantidad Real * PVU (del Presupuesto)
    - Coste Real   = Cantidad Real * PCU (del Real)
    """
    # 1. DATOS DE PRESUPUESTO (Para obtener los PVU pactados)
    items_activos = [i for i in items_db if i.get('activo', True) is True]
    
    # Mapa para buscar rápidamente el precio de venta acordado de cada partida
    # Key: id_detalle -> Value: pvu
    mapa_pvu = {i['id_detalle']: float(i.get('pvu', 0) or 0) for i in items_activos}
    
    # Cálculos del Presupuesto (Teórico)
    pres_total_venta = sum([float(i.get('unidades',0)*i.get('pvu',0)) for i in items_activos])
    pres_total_coste = sum([float(i.get('unidades',0)*i.get('pcu',0)) for i in items_activos])
    pres_beneficio = pres_total_venta - pres_total_coste
    
    # 2. DATOS REALES (Ejecución)
    items_real = client.table("tbl_licitaciones_real").select("*").eq("id_licitacion", lic_id).execute().data
    
    # Acumuladores
    real_venta_ejecutada = 0.0  # Valor de lo producido a precio de venta
    real_coste_total = 0.0      # Coste real incurrido
    real_facturado = 0.0
    real_cobrado = 0.0
    
    if items_real:
        for ir in items_real:
            # Datos de la línea real
            qty_real = float(ir.get('cantidad', 0) or 0)
            coste_unit_real = float(ir.get('pcu', 0) or 0)
            id_link = ir.get('id_detalle')
            estado = ir.get('estado', 'EN ESPERA')
            cobrado = ir.get('cobrado', False)
            
            # A. CÁLCULO DE COSTE (Real * Real)
            linea_coste = qty_real * coste_unit_real
            real_coste_total += linea_coste
            
            # B. CÁLCULO DE VENTA (Real * Presupuesto)
            # Buscamos a cuánto vendimos esta partida en el presupuesto
            pvu_pactado = mapa_pvu.get(id_link, 0.0)
            
            linea_venta = qty_real * pvu_pactado
            real_venta_ejecutada += linea_venta
            
            # C. ACUMULADORES DE ESTADO
            if estado == 'FACTURADO':
                real_facturado += linea_venta
            
            if cobrado:
                real_cobrado += linea_venta

    # 3. RESULTADOS FINANCIEROS
    real_beneficio = real_venta_ejecutada - real_coste_total
    
    # Evitar división por cero en el margen
    if real_venta_ejecutada != 0:
        real_margen_pct = (real_beneficio / real_venta_ejecutada) * 100
    else:
        real_margen_pct = 0.0

    # 4. VISUALIZACIÓN
    with st.expander("📊 Estadísticas y Rentabilidad", expanded=True):
        # Fila Superior: PREVISIÓN
        c1, c2, c3, c4 = st.columns(4)
        c1.metric("Presupuesto Venta", f"{fmt_num(pres_total_venta)} €", help="Total ofertado en partidas activas")
        c2.metric("Coste Previsto", f"{fmt_num(pres_total_coste)} €")
        c3.metric("Beneficio Previsto", f"{fmt_num(pres_beneficio)} €")
        margen_previsto = (pres_beneficio / pres_total_venta * 100) if pres_total_venta else 0
        c4.metric("Margen Previsto", f"{fmt_num(margen_previsto)} %")

        st.divider()
        
        # Fila Inferior: REALIDAD
        r1, r2, r3, r4 = st.columns(4)
        
        r1.metric("Importe Facturado", f"{fmt_num(real_facturado)} €", 
                  help="Valor venta de partidas en estado FACTURADO")
        
        r2.metric("Importe Cobrado", f"{fmt_num(real_cobrado)} €", 
                  help="Valor venta de partidas marcadas COBRADO")
        
        # Color del beneficio (Verde si > 0, Rojo si < 0)
        delta_color = "normal" if real_beneficio >= 0 else "inverse"
        
        r3.metric("Beneficio Real", f"{fmt_num(real_beneficio)} €", 
                  delta="Ganancia neta (Venta Ejecutada - Coste Real)", delta_color=delta_color)
                  
        r4.metric("Margen Real", f"{fmt_num(real_margen_pct)} %",
                  help="Beneficio Real / Venta Ejecutada")

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
    """
    Vista principal de la pestaña 'Real (Ejecución)'.
    Estructura el layout y delega la renderización reactiva a fragmentos.
    """
    st.subheader("📦 Gestión de Albaranes y Partes de Trabajo")
    
    # 1. PREPARACIÓN DE DATOS
    items_activos = [i for i in items_db if i.get('activo', True)]
    mapa_presu_ids = {f"{(i.get('lote') or 'General')} - {i['producto']}": i['id_detalle'] for i in items_activos}

    # 2. BOTÓN ACTIVADOR (NUEVA ENTREGA)
    if not st.session_state.get('creando_entrega', False):
        if st.button("➕ Registrar Nuevo Documento / Albarán", type="primary", use_container_width=True):
            st.session_state['creando_entrega'] = True
            st.rerun()
        
    # 3. MODO CREACIÓN (Si aplica)
    # (La lógica de render_formulario_alta_entrega se maneja en el router principal, 
    # pero si se quisiera inline, iría aquí).

    # 4. LISTADO DE ENTREGAS (HISTÓRICO EDITABLE)
    st.divider()
    st.markdown("### 📜 Histórico de Documentos")
    
    try:
        # Traemos las entregas (cabeceras)
        entregas = client.table("tbl_entregas").select("*").eq("id_licitacion", lic_id).order("fecha_entrega", desc=True).execute().data
    except Exception as e:
        st.error(f"Error conexión: {e}")
        entregas = []
    
    if entregas:
        for ent in entregas:
            # LLAMADA AL FRAGMENTO AISLADO
            # Pasamos 'ent' y 'client' para que cada albarán se gestione independientemente
            render_fragmento_entrega(client, ent, lic_id, items_db)
    else:
        st.info("No hay documentos registrados.")

    # 5. SOPORTE LEGADO
    _render_huerfanas(client, lic_id)


# 1. NUEVO CALLBACK (Poner antes de las funciones de renderizado o junto a los helpers)
def callback_actualizar_lineas(key_editor, cache_key, client, lic_id, entrega_id, mapa_reverso_ids, fecha_entrega_str):
    """
    Callback Optimista: Ahora incluye fecha_entrega_str para evitar fechas NULL al insertar.
    """
    state = st.session_state.get(key_editor)
    if not state: return
    
    # Referencia al DF en caché (para actualizarlo localmente sin re-query)
    df_cache = st.session_state[cache_key]
    cambios_locales = False

    # 1. DELETE
    if "deleted_rows" in state and state["deleted_rows"]:
        indices = state["deleted_rows"]
        ids_to_delete = []
        indices_to_drop = []
        
        for idx in indices:
            try:
                # Obtenemos ID Real
                id_real = df_cache.iloc[idx]['id_real']
                ids_to_delete.append(int(id_real))
                indices_to_drop.append(df_cache.index[idx])
            except: pass
        
        if ids_to_delete:
            # BD Update
            client.table("tbl_licitaciones_real").delete().in_("id_real", ids_to_delete).execute()
            # Local Cache Update
            df_cache = df_cache.drop(indices_to_drop).reset_index(drop=True)
            cambios_locales = True
            st.toast(f"🗑️ Eliminado")

    # 2. INSERT
    if "added_rows" in state and state["added_rows"]:
        new_rows = state["added_rows"]
        to_insert_bd = []
        rows_visual = []

        for row in new_rows:
            nombre_articulo = row.get("articulo", "")
            resolved_id_detalle = mapa_reverso_ids.get(nombre_articulo)
            
            # Objeto para BD
            obj_bd = {
                "id_licitacion": lic_id,
                "id_entrega": entrega_id,
                "id_detalle": resolved_id_detalle,
                "fecha_entrega": fecha_entrega_str, # <--- CORRECCIÓN CRÍTICA
                "articulo": nombre_articulo, # Guardamos el nombre "Lote - Prod" o "Prod" según lógica
                "proveedor": row.get("proveedor", ""),
                "cantidad": float(row.get("cantidad", 0) or 0),
                "pcu": float(row.get("pcu", 0) or 0),
                "estado": row.get("estado", "EN ESPERA"),
                "cobrado": row.get("cobrado", False)
            }
            
            # Insertamos y RECUPERAMOS el ID generado (importante para futuras ediciones)
            res = client.table("tbl_licitaciones_real").insert(obj_bd).execute()
            if res.data:
                # Añadimos a la caché local el registro completo que devolvió la BD
                rows_visual.append(res.data[0])

        if rows_visual:
            new_df = pd.DataFrame(rows_visual)
            # Asegurar compatibilidad de columnas si el DF original estaba vacío
            df_cache = pd.concat([df_cache, new_df], ignore_index=True)
            cambios_locales = True
            st.toast("✨ Nueva línea")

    # 3. UPDATE
    if "edited_rows" in state and state["edited_rows"]:
        edited_rows = state["edited_rows"]
        for idx, changes in edited_rows.items():
            try:
                # Índice y ID real
                row_idx = int(idx)
                id_real = df_cache.iloc[row_idx]['id_real']
                
                payload = {}
                # Actualizar Payload BD y Caché Local
                if "articulo" in changes: 
                    payload["articulo"] = changes["articulo"]
                    payload["id_detalle"] = mapa_reverso_ids.get(changes["articulo"])
                    df_cache.at[row_idx, 'articulo'] = changes["articulo"]
                    # id_detalle no suele estar en el DF visual, pero si estuviera, se actualiza
                
                if "proveedor" in changes: 
                    payload["proveedor"] = changes["proveedor"]
                    df_cache.at[row_idx, 'proveedor'] = changes["proveedor"]
                    
                if "cantidad" in changes: 
                    payload["cantidad"] = changes["cantidad"]
                    df_cache.at[row_idx, 'cantidad'] = changes["cantidad"]
                    
                if "pcu" in changes: 
                    payload["pcu"] = changes["pcu"]
                    df_cache.at[row_idx, 'pcu'] = changes["pcu"]
                    
                if "estado" in changes: 
                    payload["estado"] = changes["estado"]
                    df_cache.at[row_idx, 'estado'] = changes["estado"]
                    
                if "cobrado" in changes: 
                    payload["cobrado"] = changes["cobrado"]
                    df_cache.at[row_idx, 'cobrado'] = changes["cobrado"]

                if payload:
                    client.table("tbl_licitaciones_real").update(payload).eq("id_real", int(id_real)).execute()
                    cambios_locales = True
            except Exception as e:
                print(f"Error update: {e}")
        
        if cambios_locales:
             st.toast("💾 Guardado")

    # FINAL: Actualizamos la sesión con el DF modificado para que el renderizado sea instantáneo
    if cambios_locales:
        st.session_state[cache_key] = df_cache

@st.fragment
def render_fragmento_entrega(client, ent, lic_id, items_db):
    id_e = ent['id_entrega']
    
    # 1. Preparar Mapeos (Rápidos, en memoria)
    items_activos = [i for i in items_db if i.get('activo', True)]
    opciones_select = [f"{(i.get('lote') or 'General')} - {i['producto']}" for i in items_activos]
    opciones_select.sort()
    opciones_select.append("➕ Gasto NO Presupuestado / Extra")
    
    mapa_visual = {i['producto']: f"{(i.get('lote') or 'General')} - {i['producto']}" for i in items_activos}
    mapa_reverso_ids = {f"{(i.get('lote') or 'General')} - {i['producto']}": i['id_detalle'] for i in items_activos}

    # 2. GESTIÓN DE CACHÉ (La magia de la velocidad)
    cache_key = f"cache_lines_{id_e}"
    
    # Solo consultamos a Supabase si NO tenemos los datos en memoria
    if cache_key not in st.session_state:
        data_bd = client.table("tbl_licitaciones_real").select("*").eq("id_entrega", id_e).order("id_real").execute().data
        df_init = pd.DataFrame(data_bd)
        
        # Si está vacío, inicializamos columnas
        if df_init.empty:
            df_init = pd.DataFrame(columns=["id_real", "articulo", "proveedor", "cantidad", "pcu", "estado", "cobrado"])
        else:
            # Aplicamos corrección visual una sola vez al cargar
            df_init['articulo'] = df_init['articulo'].apply(lambda x: mapa_visual.get(x, x))
            
        st.session_state[cache_key] = df_init

    # Usamos el DF de la caché para renderizar
    df_l = st.session_state[cache_key].reset_index(drop=True)

    # 3. RENDERIZADO VISUAL
    fecha_dt = datetime.strptime(ent['fecha_entrega'], "%Y-%m-%d").date() if ent['fecha_entrega'] else datetime.now().date()
    titulo = f"📦 {ent['fecha_entrega']} | {ent['codigo_albaran']}"
    
    with st.expander(titulo, expanded=False):
        c1, c2, c3 = st.columns([2, 2, 1])
        new_date = c1.date_input("Fecha", value=fecha_dt, key=f"d_{id_e}", format="DD/MM/YYYY")
        new_alb = c2.text_input("Ref. Albarán", value=ent['codigo_albaran'], key=f"a_{id_e}")
        
        if c3.button("💾 Cabecera", key=f"btn_save_head_{id_e}"):
            client.table("tbl_entregas").update({
                "fecha_entrega": str(new_date), "codigo_albaran": new_alb
            }).eq("id_entrega", id_e).execute()
            st.rerun()
            
        st.divider()
        if st.button("🗑️ Borrar Doc", key=f"del_ent_{id_e}", type="secondary"):
             if eliminar_entrega_completa(client, id_e):
                # Limpiamos caché si borramos
                if cache_key in st.session_state: del st.session_state[cache_key]
                st.rerun()

        # 4. EDITOR CONECTADO A CACHÉ
        key_editor = f"editor_lines_{id_e}"
        st.data_editor(
            df_l,
            column_config={
                "id_real": None, "id_licitacion": None, "id_entrega": None, "id_detalle": None, 
                "fecha_entrega": None, "created_at": None,
                "articulo": st.column_config.SelectboxColumn("Concepto", options=opciones_select, width="large", required=True),
                "proveedor": st.column_config.TextColumn("Proveedor", width="medium"),
                "cantidad": st.column_config.NumberColumn("Cant", format="%.2f"),
                "pcu": st.column_config.NumberColumn("Coste", format="%.2f €"),
                "estado": st.column_config.SelectboxColumn("Estado", options=["EN ESPERA", "ENTREGADO", "FACTURADO"], required=True),
                "cobrado": st.column_config.CheckboxColumn("Cobrado", default=False)
            },
            column_order=["articulo", "proveedor", "cantidad", "pcu", "estado", "cobrado"],
            use_container_width=True,
            hide_index=True,
            num_rows="dynamic",
            key=key_editor,
            on_change=callback_actualizar_lineas,
            # AÑADIDO: ent['fecha_entrega'] como argumento
            args=(key_editor, cache_key, client, lic_id, id_e, mapa_reverso_ids, ent['fecha_entrega'])
        )


def _render_huerfanas(client, lic_id):
    """Helper para renderizar líneas antiguas fuera de la lógica principal."""
    lineas_huerfanas = client.table("tbl_licitaciones_real").select("*").eq("id_licitacion", lic_id).is_("id_entrega", "null").execute().data
    if lineas_huerfanas:
        st.divider()
        with st.expander("⚠️ Movimientos Antiguos (Sin Agrupar)", expanded=False):
            df_huerfanas = pd.DataFrame(lineas_huerfanas)
            if 'fecha_entrega' in df_huerfanas.columns:
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
    mapa_presu_ids = {f"{(i.get('lote') or 'General')} - {i['producto']}": i['id_detalle'] for i in items_activos}
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