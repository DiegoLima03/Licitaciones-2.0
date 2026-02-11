"""
Dashboard: timeline por adjudicación–finalización y KPIs.
Estados usados en fórmulas (deben coincidir con tbl_estados.nombre_estado):
- Ofertado: Adjudicada, No Adjudicada, Presentada, Terminada
- Adjudicadas+Terminadas: Adjudicada, Terminada
- Descartadas / (total - Análisis - Valoración)
"""

from typing import Any, Dict, List, Optional

import pandas as pd
from fastapi import APIRouter, Query

from backend.config import supabase_client, get_maestros
from backend.models import KPIDashboard, TimelineItem


router = APIRouter(prefix="/analytics", tags=["analytics"])

# Nombres de estado que deben coincidir con tbl_estados (ajustar si hace falta)
ESTADOS_OFERTADO = {"Adjudicada", "No Adjudicada", "Presentada", "Terminada"}
ESTADOS_ADJUDICADAS_TERMINADAS = {"Adjudicada", "Terminada"}
ESTADOS_DESCARTADA = {"Descartada", "Desierta"}  # considerar descartadas
ESTADOS_ANALISIS_VALORACION = {"En Estudio", "Análisis", "Valoración"}  # excluir del denom. % descartadas


def _get_licitaciones_df() -> pd.DataFrame:
    """Todas las licitaciones con columnas necesarias."""
    response = supabase_client.table("tbl_licitaciones").select(
        "id_licitacion, nombre, pres_maximo, id_estado, "
        "fecha_presentacion, fecha_adjudicacion, fecha_finalizacion"
    ).execute()
    data = response.data or []
    return pd.DataFrame(data)


def _enriquecer_estados(df: pd.DataFrame, maestros: Dict[str, Any]) -> pd.DataFrame:
    df = df.copy()
    df["pres_maximo"] = df["pres_maximo"].fillna(0.0)
    mapa_estados = maestros.get("estados_id_map", {})
    df["estado_nombre"] = df["id_estado"].map(mapa_estados).fillna("Desconocido")
    return df


def _build_timeline(df: pd.DataFrame) -> List[Dict[str, Any]]:
    """Líneas del timeline: cada licitación con fecha_adjudicacion y fecha_finalizacion."""
    df = df.dropna(subset=["fecha_adjudicacion", "fecha_finalizacion"])
    df = df[df["fecha_adjudicacion"].astype(str).str.strip() != ""]
    df = df[df["fecha_finalizacion"].astype(str).str.strip() != ""]
    df["f_adj"] = pd.to_datetime(df["fecha_adjudicacion"], errors="coerce")
    df["f_fin"] = pd.to_datetime(df["fecha_finalizacion"], errors="coerce")
    df = df.dropna(subset=["f_adj", "f_fin"])
    df = df[df["f_fin"] >= df["f_adj"]]
    return df[["id_licitacion", "nombre", "fecha_adjudicacion", "fecha_finalizacion", "estado_nombre", "pres_maximo"]].to_dict(orient="records")


def _compute_margen_ponderado(
    client: Any,
    id_licitaciones: List[int],
    presupuestado: bool,
) -> Optional[float]:
    """Margen medio ponderado: venta-weighted. presupuestado=True usa detalle (pvu,pcu,unidades); False usa real."""
    if not id_licitaciones:
        return None
    if presupuestado:
        # Sum (pvu*ud - pcu*ud) / Sum(pvu*ud) por licitación, luego ponderar por venta total
        det = client.table("tbl_licitaciones_detalle").select(
            "id_licitacion, unidades, pvu, pcu"
        ).in_("id_licitacion", id_licitaciones).eq("activo", True).execute()
        rows = det.data or []
        if not rows:
            return None
        df = pd.DataFrame(rows)
        df["unidades"] = pd.to_numeric(df["unidades"], errors="coerce").fillna(0)
        df["pvu"] = pd.to_numeric(df["pvu"], errors="coerce").fillna(0)
        df["pcu"] = pd.to_numeric(df["pcu"], errors="coerce").fillna(0)
        df["venta"] = df["unidades"] * df["pvu"]
        df["coste"] = df["unidades"] * df["pcu"]
        tot_venta = df["venta"].sum()
        tot_beneficio = (df["venta"] - df["coste"]).sum()
        if tot_venta and tot_venta > 0:
            return float((tot_beneficio / tot_venta) * 100)
        return None
    else:
        # Real: tbl_licitaciones_real cantidad, pcu; venta aproximada con pvu de detalle por id_detalle
        real = client.table("tbl_licitaciones_real").select(
            "id_licitacion, id_detalle, cantidad, pcu"
        ).in_("id_licitacion", id_licitaciones).execute()
        real_rows = real.data or []
        if not real_rows:
            return None
        df_real = pd.DataFrame(real_rows)
        df_real["cantidad"] = pd.to_numeric(df_real["cantidad"], errors="coerce").fillna(0)
        df_real["pcu"] = pd.to_numeric(df_real["pcu"], errors="coerce").fillna(0)
        df_real["coste_real"] = df_real["cantidad"] * df_real["pcu"]
        # Obtener pvu por id_detalle para venta
        id_detalles = df_real["id_detalle"].dropna().unique().tolist()
        if not id_detalles:
            return None
        det = client.table("tbl_licitaciones_detalle").select("id_detalle, pvu").in_("id_detalle", id_detalles).execute()
        pvu_map = {r["id_detalle"]: float(r.get("pvu") or 0) for r in (det.data or [])}
        df_real["pvu"] = df_real["id_detalle"].map(pvu_map).fillna(0)
        df_real["venta_real"] = df_real["cantidad"] * df_real["pvu"]
        tot_venta = df_real["venta_real"].sum()
        tot_beneficio = (df_real["venta_real"] - df_real["coste_real"]).sum()
        if tot_venta and tot_venta > 0:
            return float((tot_beneficio / tot_venta) * 100)
        return None


@router.get("/kpis", response_model=KPIDashboard)
def get_kpis(
    fecha_adjudicacion_desde: Optional[str] = Query(
        None,
        description="Filtrar licitaciones con fecha_adjudicación >= esta fecha (YYYY-MM-DD).",
    ),
    fecha_adjudicacion_hasta: Optional[str] = Query(
        None,
        description="Filtrar licitaciones con fecha_adjudicación <= esta fecha (YYYY-MM-DD).",
    ),
) -> KPIDashboard:
    """
    GET /analytics/kpis
    Timeline (adjudicación → finalización) y KPIs del dashboard.
    Opcional: ?fecha_adjudicacion_desde=2024-01-01&fecha_adjudicacion_hasta=2024-12-31
    para filtrar por rango de fecha de adjudicación.
    """
    maestros = get_maestros(supabase_client)
    df = _get_licitaciones_df()
    if df.empty:
        return KPIDashboard(
            timeline=[],
            total_oportunidades_uds=0,
            total_oportunidades_euros=0.0,
            total_ofertado_uds=0,
            total_ofertado_euros=0.0,
            ratio_ofertado_oportunidades_uds=0.0,
            ratio_ofertado_oportunidades_euros=0.0,
            ratio_adjudicadas_terminadas_ofertado=0.0,
            margen_medio_ponderado_presupuestado=None,
            margen_medio_ponderado_real=None,
            pct_descartadas_uds=None,
            pct_descartadas_euros=None,
            ratio_adjudicacion=0.0,
        )

    # Filtro temporal por fecha de adjudicación
    if fecha_adjudicacion_desde or fecha_adjudicacion_hasta:
        f_adj = pd.to_datetime(df["fecha_adjudicacion"], errors="coerce")
        mask = pd.Series(True, index=df.index)
        if fecha_adjudicacion_desde:
            mask = mask & (f_adj >= pd.Timestamp(fecha_adjudicacion_desde))
        if fecha_adjudicacion_hasta:
            mask = mask & (f_adj <= pd.Timestamp(fecha_adjudicacion_hasta))
        df = df[mask]
        if df.empty:
            return KPIDashboard(
                timeline=[],
                total_oportunidades_uds=0,
                total_oportunidades_euros=0.0,
                total_ofertado_uds=0,
                total_ofertado_euros=0.0,
                ratio_ofertado_oportunidades_uds=0.0,
                ratio_ofertado_oportunidades_euros=0.0,
                ratio_adjudicadas_terminadas_ofertado=0.0,
                margen_medio_ponderado_presupuestado=None,
                margen_medio_ponderado_real=None,
                pct_descartadas_uds=None,
                pct_descartadas_euros=None,
                ratio_adjudicacion=0.0,
            )

    df = _enriquecer_estados(df, maestros)
    timeline_records = _build_timeline(df)

    # Total oportunidades = todas las licitaciones
    total_oportunidades_uds = len(df)
    total_oportunidades_euros = float(df["pres_maximo"].sum())

    # Total ofertado = solo estados en ESTADOS_OFERTADO
    mask_ofertado = df["estado_nombre"].isin(ESTADOS_OFERTADO)
    df_ofertado = df[mask_ofertado]
    total_ofertado_uds = len(df_ofertado)
    total_ofertado_euros = float(df_ofertado["pres_maximo"].sum())

    # Ratios ofertado/oportunidades
    ratio_ofertado_oportunidades_uds = (
        (total_ofertado_uds / total_oportunidades_uds * 100) if total_oportunidades_uds else 0.0
    )
    ratio_ofertado_oportunidades_euros = (
        (total_ofertado_euros / total_oportunidades_euros * 100) if total_oportunidades_euros else 0.0
    )

    # Adjudicadas + Terminadas
    mask_adj_ter = df["estado_nombre"].isin(ESTADOS_ADJUDICADAS_TERMINADAS)
    count_adj_ter = mask_adj_ter.sum()
    ratio_adjudicadas_terminadas_ofertado = (
        (count_adj_ter / total_ofertado_uds * 100) if total_ofertado_uds else 0.0
    )

    # Margen medio ponderado (adjudicadas + terminadas)
    ids_adj_ter = df.loc[mask_adj_ter, "id_licitacion"].astype(int).tolist()
    margen_presu = _compute_margen_ponderado(supabase_client, ids_adj_ter, presupuestado=True)
    margen_real = _compute_margen_ponderado(supabase_client, ids_adj_ter, presupuestado=False)

    # % descartadas = descartadas / (total - análisis - valoración)
    mask_des = df["estado_nombre"].isin(ESTADOS_DESCARTADA)
    mask_an_val = df["estado_nombre"].isin(ESTADOS_ANALISIS_VALORACION)
    count_des = mask_des.sum()
    denom = total_oportunidades_uds - mask_an_val.sum()
    pct_descartadas_uds = (count_des / denom * 100) if denom and denom > 0 else None
    euros_des = float(df.loc[mask_des, "pres_maximo"].sum())
    euros_total_menos_an_val = float(df[~mask_an_val]["pres_maximo"].sum())
    pct_descartadas_euros = (euros_des / euros_total_menos_an_val * 100) if euros_total_menos_an_val else None

    # Ratio adjudicación = (Adjudicadas+Terminadas) / (Adjudicadas+No Adjudicadas+Terminadas)
    # Ofertado ya es ese conjunto; entonces ratio_adjudicadas_terminadas_ofertado es el ratio adjudicación en uds.
    ratio_adjudicacion = ratio_adjudicadas_terminadas_ofertado / 100.0 if total_ofertado_uds else 0.0

    timeline_items = [
        TimelineItem(
            id_licitacion=int(r["id_licitacion"]),
            nombre=str(r.get("nombre", "")),
            fecha_adjudicacion=r.get("fecha_adjudicacion"),
            fecha_finalizacion=r.get("fecha_finalizacion"),
            estado_nombre=r.get("estado_nombre"),
            pres_maximo=float(r["pres_maximo"]) if r.get("pres_maximo") is not None else None,
        )
        for r in timeline_records
    ]

    return KPIDashboard(
        timeline=timeline_items,
        total_oportunidades_uds=int(total_oportunidades_uds),
        total_oportunidades_euros=total_oportunidades_euros,
        total_ofertado_uds=int(total_ofertado_uds),
        total_ofertado_euros=total_ofertado_euros,
        ratio_ofertado_oportunidades_uds=round(ratio_ofertado_oportunidades_uds, 2),
        ratio_ofertado_oportunidades_euros=round(ratio_ofertado_oportunidades_euros, 2),
        ratio_adjudicadas_terminadas_ofertado=round(ratio_adjudicadas_terminadas_ofertado, 2),
        margen_medio_ponderado_presupuestado=round(margen_presu, 2) if margen_presu is not None else None,
        margen_medio_ponderado_real=round(margen_real, 2) if margen_real is not None else None,
        pct_descartadas_uds=round(pct_descartadas_uds, 2) if pct_descartadas_uds is not None else None,
        pct_descartadas_euros=round(pct_descartadas_euros, 2) if pct_descartadas_euros is not None else None,
        ratio_adjudicacion=round(ratio_adjudicacion, 4),
    )
