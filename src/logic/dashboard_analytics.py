### src/logic/dashboard_analytics.py
import pandas as pd
from typing import Dict, Any, List

def calcular_kpis_generales(client, maestros: Dict[str, Any]) -> Dict[str, Any]:
    """
    Obtiene todas las licitaciones y calcula métricas de alto nivel.
    Retorna un diccionario con los valores calculados.
    """
    try:
        # 1. Traer todas las licitaciones (solo columnas necesarias para ser eficiente)
        res = client.table("tbl_licitaciones").select("id_licitacion, pres_maximo, id_estado").execute()
        data = res.data
        
        if not data:
            return {
                "total_count": 0,
                "pipeline_monto": 0.0,
                "adjudicado_monto": 0.0,
                "win_rate": 0.0,
                "desglose_estados": {}
            }

        df = pd.DataFrame(data)
        
        # Asegurar tipos numéricos
        df['pres_maximo'] = df['pres_maximo'].fillna(0).astype(float)

        # 2. Identificar IDs de estados clave usando el diccionario de maestros
        # Buscamos variaciones comunes de nombres por si acaso, o usamos exactos
        map_nombres = maestros.get('estados_name_map', {})
        
        # Identificamos IDs (asumiendo nombres estándar, ajusta según tu DB real)
        id_adjudicada = map_nombres.get('Adjudicada') or map_nombres.get('ADJUDICADA')
        id_estudio = map_nombres.get('En Estudio') or map_nombres.get('EN ESTUDIO')
        id_presentada = map_nombres.get('Presentada') or map_nombres.get('PRESENTADA')
        
        # 3. Cálculos
        
        # A) Total expedientes
        total_count = len(df)
        
        # B) Pipeline (Dinero en juego: Estudio + Presentada)
        ids_pipeline = [i for i in [id_estudio, id_presentada] if i is not None]
        monto_pipeline = df[df['id_estado'].isin(ids_pipeline)]['pres_maximo'].sum()
        
        # C) Adjudicado (Dinero ganado)
        monto_adjudicado = 0.0
        conteo_ganadas = 0
        if id_adjudicada:
            ganadas_df = df[df['id_estado'] == id_adjudicada]
            monto_adjudicado = ganadas_df['pres_maximo'].sum()
            conteo_ganadas = len(ganadas_df)
            
        # D) Tasa de Éxito (Win Rate)
        # Definimos éxito como: Ganadas / Total. 
        # (Opcional: Podría ser Ganadas / (Ganadas + Perdidas) si tuviéramos estado 'Rechazada')
        win_rate = (conteo_ganadas / total_count * 100) if total_count > 0 else 0.0

        return {
            "total_count": total_count,
            "pipeline_monto": monto_pipeline,
            "adjudicado_monto": monto_adjudicado,
            "win_rate": win_rate,
            "total_monto_historico": df['pres_maximo'].sum() # Volumen histórico total
        }

    except Exception as e:
        print(f"Error calculando KPIs: {e}")
        return {
            "total_count": 0,
            "pipeline_monto": 0,
            "adjudicado_monto": 0,
            "win_rate": 0,
            "total_monto_historico": 0
        }