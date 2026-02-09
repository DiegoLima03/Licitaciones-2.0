import pandas as pd

def calcular_kpis_generales(client, maestros):
    """
    Calcula los KPIs principales basándose en todas las licitaciones registradas.
    Retorna un diccionario con métricas listas para visualizar.
    """
    # 1. Obtener datos mínimos necesarios (ID, Presupuesto, Estado)
    try:
        response = client.table("tbl_licitaciones").select("id_licitacion, pres_maximo, id_estado, tipo_de_licitacion, fecha_presentacion").execute()
        data = response.data
    except Exception as e:
        print(f"Error calculando KPIs: {e}")
        data = []

    # Estructura por defecto si no hay datos
    if not data:
        return {
            "total_count": 0,
            "pipeline_monto": 0.0,
            "adjudicado_monto": 0.0,
            "win_rate": 0.0,
            "total_monto_historico": 0.0,
            "df_mensual": pd.Series(dtype=float),
            "df_tipos": pd.Series(dtype=float)
        }

    df = pd.DataFrame(data)

    # 2. Enriquecer con nombres de estados
    # Usamos el mapa de IDs cargado en maestros
    mapa_estados = maestros.get('estados_id_map', {})
    mapa_tipos = maestros.get('tipos_id_map', {})
    df['estado_nombre'] = df['id_estado'].map(mapa_estados).fillna('Desconocido')
    df['tipo_nombre'] = df['tipo_de_licitacion'].map(mapa_tipos).fillna('Sin Clasificar')
    
    # Limpieza de valores numéricos
    df['pres_maximo'] = df['pres_maximo'].fillna(0.0)

    # 3. Cálculos de Métricas
    total_count = len(df)
    total_monto_historico = df['pres_maximo'].sum()

    # Definición de Grupos de Estado (Ajustar según los nombres reales en tu BD)
    # Pipeline: Lo que está vivo pero no cerrado
    estados_pipeline = ['En Estudio', 'Presentada', 'Pendiente de Fallo', 'Pendiente']
    # Adjudicado: Lo ganado
    estado_ganado = 'Adjudicada'

    # Filtrado y Sumas
    pipeline_df = df[df['estado_nombre'].isin(estados_pipeline)]
    pipeline_monto = pipeline_df['pres_maximo'].sum()

    adjudicado_df = df[df['estado_nombre'] == estado_ganado]
    adjudicado_monto = adjudicado_df['pres_maximo'].sum()

    # Tasa de Éxito (Win Rate)
    # Fórmula simple: Ganadas / Total * 100
    win_rate = (len(adjudicado_df) / total_count * 100) if total_count > 0 else 0.0

    # 4. Preparación de Datos para Gráficos
    # A) Evolución Mensual
    df['fecha_dt'] = pd.to_datetime(df['fecha_presentacion'], errors='coerce')
    df_dates = df.dropna(subset=['fecha_dt']).copy()
    if not df_dates.empty:
        df_dates['mes_anio'] = df_dates['fecha_dt'].dt.strftime('%Y-%m')
        df_mensual = df_dates.groupby('mes_anio')['pres_maximo'].sum().sort_index()
    else:
        df_mensual = pd.Series(dtype=float)

    # B) Distribución por Tipo
    df_tipos = df.groupby('tipo_nombre')['pres_maximo'].sum().sort_values(ascending=False)

    return {
        "total_count": total_count,
        "pipeline_monto": pipeline_monto,
        "adjudicado_monto": adjudicado_monto,
        "win_rate": win_rate,
        "total_monto_historico": total_monto_historico,
        "df_mensual": df_mensual,
        "df_tipos": df_tipos
    }