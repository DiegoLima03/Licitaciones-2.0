import streamlit as st
from src.utils import fmt_num, boton_volver
from src.logic.dashboard_analytics import calcular_kpis_generales

def render_kpi_dashboard(client, maestros):
    # 1. Cabecera con Botón Volver
    c_back, c_tit = st.columns([1, 5])
    
    with c_back:
        boton_volver('MENU')
        
    with c_tit:
        st.title("📊 Cuadro de Mando (KPIs)")
    
    st.markdown("---")
    
    # 2. Cálculo de KPIs
    kpis = calcular_kpis_generales(client, maestros)
    
    # 3. Estructura de Pestañas para Configuración
    tab_metrics, tab_charts = st.tabs(["📊 Métricas Clave", "📈 Análisis Gráfico"])
    
    with tab_metrics:
        st.subheader("Rendimiento Global")
        
        k1, k2, k3, k4 = st.columns(4)
        
        with k1:
            st.metric("Licitaciones Totales", kpis['total_count'], delta="Expedientes", delta_color="inverse")
            
        with k2:
            st.metric("Pipeline (En Curso)", f"{fmt_num(kpis['pipeline_monto'])} €", help="Suma de Presupuestos en estado 'En Estudio' o 'Presentada'")
            
        with k3:
            st.metric("Cartera Adjudicada", f"{fmt_num(kpis['adjudicado_monto'])} €", delta="Ganado", delta_color="inverse")
            
        with k4:
            st.metric("Tasa de Adjudicación", f"{kpis['win_rate']:.1f}%", help="% de licitaciones ganadas sobre el total.")
            
        st.divider()
        
        st.subheader("Volumen Financiero")
        st.metric("Volumen Histórico Total", f"{fmt_num(kpis.get('total_monto_historico', 0))} €", help="Suma total de todos los presupuestos registrados")
        st.caption("💡 Este panel muestra métricas agregadas de todas las licitaciones registradas en el sistema.")

    with tab_charts:
        st.markdown("### 📈 Análisis Visual")
        
        c_chart1, c_chart2 = st.columns(2)
        
        with c_chart1:
            st.markdown("**Evolución del Volumen Licitado (Mensual)**")
            if not kpis['df_mensual'].empty:
                st.bar_chart(kpis['df_mensual'], color="#D9534F") # Rojo suave
            else:
                st.info("No hay datos temporales suficientes.")
                
        with c_chart2:
            st.markdown("**Distribución por Tipo de Licitación**")
            if not kpis['df_tipos'].empty:
                st.bar_chart(kpis['df_tipos'], horizontal=True, color="#C9302C") # Rojo más oscuro
            else:
                st.info("No hay datos de tipos suficientes.")