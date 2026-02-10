from typing import Any, Dict

import pandas as pd
from fastapi import APIRouter

from backend.config import supabase_client, get_maestros
from backend.models import KPIDashboard


router = APIRouter(prefix="/analytics", tags=["analytics"])


def calcular_kpis_generales(client: Any, maestros: Dict[str, Any]) -> Dict[str, Any]:
    """
    Lógica original tomada de `src/logic/dashboard_analytics.py`.

    Calcula los KPIs principales basándose en todas las licitaciones registradas.
    Retorna un diccionario con métricas listas para visualizar.
    """
    # 1. Obtener datos mínimos necesarios (ID, Presupuesto, Estado)
    try:
        response = client.table("tbl_licitaciones").select(
            "id_licitacion, nombre, pres_maximo, id_estado, tipo_de_licitacion, "
            "fecha_presentacion, fecha_adjudicacion, fecha_finalizacion"
        ).execute()
        data = response.data
    except Exception as e:  # noqa: F841  # Mantener comportamiento de logging simple
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
            "df_tipos": pd.Series(dtype=float),
            "df_timeline": pd.DataFrame(),
        }

    df = pd.DataFrame(data)

    # 2. Enriquecer con nombres de estados
    # Usamos el mapa de IDs cargado en maestros
    mapa_estados = maestros.get("estados_id_map", {})
    mapa_tipos = maestros.get("tipos_id_map", {})
    df["estado_nombre"] = df["id_estado"].map(mapa_estados).fillna("Desconocido")
    df["tipo_nombre"] = df["tipo_de_licitacion"].map(mapa_tipos).fillna("Sin Clasificar")

    # Limpieza de valores numéricos
    df["pres_maximo"] = df["pres_maximo"].fillna(0.0)

    # 3. Cálculos de Métricas
    total_count = len(df)
    total_monto_historico = df["pres_maximo"].sum()

    # Definición de Grupos de Estado (Ajustar según los nombres reales en tu BD)
    # Pipeline: Lo que está vivo pero no cerrado
    estados_pipeline = ["En Estudio", "Presentada", "Pendiente de Fallo", "Pendiente"]
    # Adjudicado: Lo ganado
    estado_ganado = "Adjudicada"

    # Filtrado y Sumas
    pipeline_df = df[df["estado_nombre"].isin(estados_pipeline)]
    pipeline_monto = pipeline_df["pres_maximo"].sum()

    adjudicado_df = df[df["estado_nombre"] == estado_ganado]
    adjudicado_monto = adjudicado_df["pres_maximo"].sum()

    # Tasa de Éxito (Win Rate)
    # Fórmula simple: Ganadas / Total * 100
    win_rate = (len(adjudicado_df) / total_count * 100) if total_count > 0 else 0.0

    # 4. Preparación de Datos para Gráficos
    # A) Evolución Mensual
    df["fecha_dt"] = pd.to_datetime(df["fecha_presentacion"], errors="coerce")
    df_dates = df.dropna(subset=["fecha_dt"]).copy()
    if not df_dates.empty:
        df_dates["mes_anio"] = df_dates["fecha_dt"].dt.strftime("%Y-%m")
        df_mensual = df_dates.groupby("mes_anio")["pres_maximo"].sum().sort_index()
    else:
        df_mensual = pd.Series(dtype=float)

    # B) Distribución por Tipo
    df_tipos = df.groupby("tipo_nombre")["pres_maximo"].sum().sort_values(ascending=False)

    # C) Timeline (Cronograma)
    # Usamos Fecha Adjudicación como inicio, si no existe, usamos Presentación
    df["fecha_inicio_dt"] = pd.to_datetime(df["fecha_adjudicacion"], errors="coerce").fillna(
        df["fecha_dt"]
    )

    if "fecha_finalizacion" in df.columns:
        df["fecha_fin_dt"] = pd.to_datetime(df["fecha_finalizacion"], errors="coerce")
    else:
        df["fecha_fin_dt"] = pd.NaT

    # Filtramos solo las que tengan fechas válidas para el gráfico
    df_timeline = df.dropna(subset=["fecha_inicio_dt", "fecha_fin_dt"]).copy()
    # Aseguramos que fin >= inicio
    df_timeline = df_timeline[df_timeline["fecha_fin_dt"] >= df_timeline["fecha_inicio_dt"]]

    return {
        "total_count": total_count,
        "pipeline_monto": pipeline_monto,
        "adjudicado_monto": adjudicado_monto,
        "win_rate": win_rate,
        "total_monto_historico": total_monto_historico,
        "df_mensual": df_mensual,
        "df_tipos": df_tipos,
        "df_timeline": df_timeline,
    }


@router.get("/kpis", response_model=KPIDashboard)
def get_kpis() -> KPIDashboard:
    """
    Endpoint que expone los KPIs generales del dashboard.

    GET /analytics/kpis
    """
    maestros = get_maestros(supabase_client)
    kpis_raw = calcular_kpis_generales(supabase_client, maestros)

    # Adaptar estructuras de pandas a tipos JSON-serializables sin cambiar la lógica de negocio.
    df_mensual_series: pd.Series = kpis_raw.get("df_mensual", pd.Series(dtype=float))
    df_tipos_series: pd.Series = kpis_raw.get("df_tipos", pd.Series(dtype=float))
    df_timeline_df: pd.DataFrame = kpis_raw.get("df_timeline", pd.DataFrame())

    return KPIDashboard(
        total_count=int(kpis_raw["total_count"]),
        pipeline_monto=float(kpis_raw["pipeline_monto"]),
        adjudicado_monto=float(kpis_raw["adjudicado_monto"]),
        win_rate=float(kpis_raw["win_rate"]),
        total_monto_historico=float(kpis_raw["total_monto_historico"]),
        df_mensual=df_mensual_series.to_dict(),
        df_tipos=df_tipos_series.to_dict(),
        df_timeline=df_timeline_df.to_dict(orient="records"),
    )

