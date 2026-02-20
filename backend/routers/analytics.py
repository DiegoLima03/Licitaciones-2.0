"""
Dashboard: timeline por adjudicación–finalización y KPIs.
Endpoints de analítica: material trends, risk pipeline, sweet spots, price deviation.

Estados válidos (única lista en la app): DESCARTADA, EN ANÁLISIS, PRESENTADA,
ADJUDICADA, NO ADJUDICADA, TERMINADA. Las comparaciones son insensibles a mayúsculas.
"""

from datetime import datetime, timedelta
from typing import Any, Dict, List, Optional

import pandas as pd
from fastapi import APIRouter, HTTPException, Query, status

from backend.config import supabase_client, get_maestros
from backend.deps import CurrentUserDep
from backend.models import (
    CompetitorItem,
    KPIDashboard,
    MaterialTrendPoint,
    MaterialTrendResponse,
    PriceDeviationResult,
    PriceHistoryPoint,
    ProductAnalytics,
    RiskPipelineItem,
    SweetSpotItem,
    TimelineItem,
    VolumeMetrics,
)


router = APIRouter(prefix="/analytics", tags=["analytics"])

# Estados (solo los del desplegable; comparación por lower())
ESTADOS_OFERTADO = {"Adjudicada", "No Adjudicada", "Presentada", "Terminada"}
ESTADOS_ADJUDICADAS_TERMINADAS = {"Adjudicada", "Terminada"}
ESTADOS_DESCARTADA = {"Descartada"}
ESTADOS_EN_ANALISIS = {"EN ANÁLISIS"}

# Versión en minúsculas para comparación insensible a mayúsculas (tbl_estados puede tener "TERMINADA", etc.)
ESTADOS_OFERTADO_NORM = {s.lower() for s in ESTADOS_OFERTADO}
ESTADOS_ADJUDICADAS_TERMINADAS_NORM = {s.lower() for s in ESTADOS_ADJUDICADAS_TERMINADAS}
ESTADOS_DESCARTADA_NORM = {s.lower() for s in ESTADOS_DESCARTADA}
ESTADOS_EN_ANALISIS_NORM = {s.lower() for s in ESTADOS_EN_ANALISIS}

# Tipos de procedimiento facturables (excluimos AM y SDA "padre" para totales económicos)
TIPOS_FACTURABLES = {"ORDINARIO", "CONTRATO_BASADO"}


def _get_licitaciones_df(org_id: str) -> pd.DataFrame:
    """Licitaciones de la organización con columnas necesarias."""
    response = supabase_client.table("tbl_licitaciones").select(
        "id_licitacion, nombre, pres_maximo, id_estado, tipo_procedimiento, "
        "fecha_presentacion, fecha_adjudicacion, fecha_finalizacion"
    ).eq("organization_id", org_id).execute()
    data = response.data or []
    return pd.DataFrame(data)


def _enriquecer_estados(df: pd.DataFrame, maestros: Dict[str, Any]) -> pd.DataFrame:
    df = df.copy()
    df["pres_maximo"] = df["pres_maximo"].fillna(0.0)
    mapa_estados = maestros.get("estados_id_map", {})
    df["estado_nombre"] = df["id_estado"].map(mapa_estados).fillna("Desconocido")
    return df


def _build_timeline_all(df: pd.DataFrame) -> List[Dict[str, Any]]:
    """Todas las licitaciones para el timeline (con o sin fechas), para que el frontend muestre todas."""
    cols = ["id_licitacion", "nombre", "fecha_adjudicacion", "fecha_finalizacion", "estado_nombre", "pres_maximo"]
    if "estado_nombre" not in df.columns:
        cols = [c for c in cols if c in df.columns]
    return df[cols].to_dict(orient="records")


def _compute_margen_ponderado(
    client: Any,
    id_licitaciones: List[int],
    presupuestado: bool,
    org_id: str,
) -> Optional[float]:
    """Margen medio ponderado: venta-weighted. presupuestado=True usa detalle (pvu,pcu,unidades); False usa real."""
    if not id_licitaciones:
        return None
    if presupuestado:
        # Sum (pvu*ud - pcu*ud) / Sum(pvu*ud) por licitación, luego ponderar por venta total
        det = client.table("tbl_licitaciones_detalle").select(
            "id_licitacion, unidades, pvu, pcu"
        ).eq("organization_id", org_id).in_("id_licitacion", id_licitaciones).eq("activo", True).execute()
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
        ).eq("organization_id", org_id).in_("id_licitacion", id_licitaciones).execute()
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
        det = client.table("tbl_licitaciones_detalle").select("id_detalle, pvu").eq("organization_id", org_id).in_("id_detalle", id_detalles).execute()
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
    current_user: CurrentUserDep,
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
    try:
        maestros = get_maestros(supabase_client)
        df = _get_licitaciones_df(str(current_user.org_id))
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error inicializando KPIs: {e!s}",
        ) from e

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

    try:
        df = _enriquecer_estados(df, maestros)
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error procesando KPIs: {e!s}",
        ) from e

    # Normalizar nombre de estado para comparación insensible a mayúsculas (ej. "TERMINADA" en BD)
    df["_estado_norm"] = df["estado_nombre"].astype(str).str.strip().str.lower()

    # Solo facturables para totales económicos y timeline (excluir ACUERDO_MARCO y SDA "padre")
    tipo = df.get("tipo_procedimiento")
    if tipo is not None:
        mask_facturable = tipo.fillna("ORDINARIO").astype(str).str.upper().isin(TIPOS_FACTURABLES)
    else:
        mask_facturable = pd.Series(True, index=df.index)
    df_fact = df[mask_facturable]
    timeline_records = _build_timeline_all(df_fact)

    # Total oportunidades = solo licitaciones facturables (ORDINARIO, BASADO_AM, ESPECIFICO_SDA)
    total_oportunidades_uds = len(df_fact)
    total_oportunidades_euros = float(df_fact["pres_maximo"].sum())

    # Total ofertado = facturables en estados Adjudicada, No Adjudicada, Presentada, Terminada
    mask_ofertado = df_fact["_estado_norm"].isin(ESTADOS_OFERTADO_NORM)
    df_ofertado = df_fact[mask_ofertado]
    total_ofertado_uds = len(df_ofertado)
    total_ofertado_euros = float(df_ofertado["pres_maximo"].sum())

    # Ratios ofertado/oportunidades
    ratio_ofertado_oportunidades_uds = (
        (total_ofertado_uds / total_oportunidades_uds * 100) if total_oportunidades_uds else 0.0
    )
    ratio_ofertado_oportunidades_euros = (
        (total_ofertado_euros / total_oportunidades_euros * 100) if total_oportunidades_euros else 0.0
    )

    # Adjudicadas + Terminadas (solo facturables)
    mask_adj_ter = df_fact["_estado_norm"].isin(ESTADOS_ADJUDICADAS_TERMINADAS_NORM)
    count_adj_ter = mask_adj_ter.sum()
    ratio_adjudicadas_terminadas_ofertado = (
        (count_adj_ter / total_ofertado_uds * 100) if total_ofertado_uds else 0.0
    )

    # Margen medio ponderado (adjudicadas + terminadas, solo facturables)
    ids_adj_ter = df_fact.loc[mask_adj_ter, "id_licitacion"].astype(int).tolist()
    org_s = str(current_user.org_id)
    margen_presu = _compute_margen_ponderado(supabase_client, ids_adj_ter, presupuestado=True, org_id=org_s)
    margen_real = _compute_margen_ponderado(supabase_client, ids_adj_ter, presupuestado=False, org_id=org_s)

    # % descartadas = descartadas / (total facturables - en análisis)
    mask_des = df_fact["_estado_norm"].isin(ESTADOS_DESCARTADA_NORM)
    mask_an_val = df_fact["_estado_norm"].isin(ESTADOS_EN_ANALISIS_NORM)
    count_des = mask_des.sum()
    denom = total_oportunidades_uds - mask_an_val.sum()
    pct_descartadas_uds = (count_des / denom * 100) if denom and denom > 0 else None
    euros_des = float(df_fact.loc[mask_des, "pres_maximo"].sum())
    euros_total_menos_an_val = float(df_fact.loc[~mask_an_val, "pres_maximo"].sum())
    pct_descartadas_euros = (euros_des / euros_total_menos_an_val * 100) if euros_total_menos_an_val else None

    # Ratio adjudicación = (Adjudicadas+Terminadas) / (Adjudicadas+No Adjudicadas+Terminadas)
    # Ofertado ya es ese conjunto; entonces ratio_adjudicadas_terminadas_ofertado es el ratio adjudicación en uds.
    ratio_adjudicacion = ratio_adjudicadas_terminadas_ofertado / 100.0 if total_ofertado_uds else 0.0

    def _norm_timeline_record(r: Dict[str, Any]) -> TimelineItem:
        raw_nombre = r.get("nombre")
        nombre = (str(raw_nombre).strip() if raw_nombre is not None and str(raw_nombre) != "nan" else "") or f"Licitación {r['id_licitacion']}"
        raw_adj = r.get("fecha_adjudicacion")
        raw_fin = r.get("fecha_finalizacion")
        fecha_adj = str(raw_adj).strip() if raw_adj is not None and str(raw_adj) != "nan" else None
        fecha_fin = str(raw_fin).strip() if raw_fin is not None and str(raw_fin) != "nan" else None
        return TimelineItem(
            id_licitacion=int(r["id_licitacion"]),
            nombre=nombre,
            fecha_adjudicacion=fecha_adj or None,
            fecha_finalizacion=fecha_fin or None,
            estado_nombre=r.get("estado_nombre"),
            pres_maximo=float(r["pres_maximo"]) if r.get("pres_maximo") is not None else None,
        )

    timeline_items = [_norm_timeline_record(r) for r in timeline_records]

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


# ---------- Endpoints de analítica avanzada ----------

# Estado "en estudio" y licitaciones cerradas para sweet spot (solo estados del desplegable)
ESTADO_EN_ESTUDIO = "EN ANÁLISIS"
ESTADOS_SWEET_SPOT = {"Adjudicada", "No Adjudicada", "Terminada"}
DEVIATION_THRESHOLD_PCT = 10.0


def _norm_date(fecha: Any) -> Optional[str]:
    """Extrae YYYY-MM-DD de fecha (ISO o string)."""
    if not fecha:
        return None
    s = str(fecha).split("T")[0] if isinstance(fecha, str) else str(fecha)[:10]
    return s if len(s) >= 10 else None


@router.get("/material-trends/{material_name}", response_model=MaterialTrendResponse)
def get_material_trends(material_name: str, current_user: CurrentUserDep) -> MaterialTrendResponse:
    """
    GET /analytics/material-trends/{material_name}
    Histórico temporal de precios: PVU desde precios_referencia + licitaciones_detalle;
    PCU desde precios_referencia + licitaciones_real (fecha vía tbl_entregas).
    """
    try:
        org_s = str(current_user.org_id)
        prod_resp = (
            supabase_client.table("tbl_productos")
            .select("id")
            .eq("organization_id", org_s)
            .ilike("nombre", f"%{material_name}%")
            .execute()
        )
        product_ids = [r["id"] for r in (prod_resp.data or []) if r.get("id") is not None]
        if not product_ids:
            return MaterialTrendResponse(pvu=[], pcu=[])

        pvu_points: List[MaterialTrendPoint] = []
        pcu_points: List[MaterialTrendPoint] = []

        # --- PVU: precios_referencia (pvu, fecha_presupuesto) ---
        ref_resp = (
            supabase_client.table("tbl_precios_referencia")
            .select("id_producto, pvu, pcu, fecha_presupuesto")
            .eq("organization_id", org_s)
            .in_("id_producto", product_ids)
            .order("fecha_presupuesto")
            .execute()
        )
        for r in (ref_resp.data or []):
            time_str = _norm_date(r.get("fecha_presupuesto"))
            if not time_str:
                continue
            if r.get("pvu") is not None:
                try:
                    pvu_points.append(MaterialTrendPoint(time=time_str, value=round(float(r["pvu"]), 2)))
                except (TypeError, ValueError):
                    pass
            if r.get("pcu") is not None:
                try:
                    pcu_points.append(MaterialTrendPoint(time=time_str, value=round(float(r["pcu"]), 2)))
                except (TypeError, ValueError):
                    pass

        # --- PVU: licitaciones_detalle (pvu) con fecha de licitación ---
        det_resp = (
            supabase_client.table("tbl_licitaciones_detalle")
            .select("id_licitacion, pvu, unidades")
            .eq("organization_id", org_s)
            .in_("id_producto", product_ids)
            .eq("activo", True)
            .execute()
        )
        det_rows = det_resp.data or []
        id_lics = list({r["id_licitacion"] for r in det_rows if r.get("id_licitacion") is not None})
        lic_fechas: Dict[int, str] = {}
        if id_lics:
            lic_resp = (
                supabase_client.table("tbl_licitaciones")
                .select("id_licitacion, fecha_presentacion, fecha_adjudicacion")
                .eq("organization_id", org_s)
                .in_("id_licitacion", id_lics)
                .execute()
            )
            for row in (lic_resp.data or []):
                lid = row.get("id_licitacion")
                if lid is None:
                    continue
                time_str = _norm_date(row.get("fecha_adjudicacion") or row.get("fecha_presentacion"))
                if time_str:
                    lic_fechas[int(lid)] = time_str
        for r in det_rows:
            pvu = r.get("pvu")
            id_lic = r.get("id_licitacion")
            if pvu is None or id_lic is None:
                continue
            time_str = lic_fechas.get(int(id_lic))
            if not time_str:
                continue
            try:
                pvu_points.append(MaterialTrendPoint(time=time_str, value=round(float(pvu), 2)))
            except (TypeError, ValueError):
                pass

        # --- PCU: licitaciones_real (pcu) con fecha de entrega ---
        id_detalles: List[int] = []
        det_for_real = (
            supabase_client.table("tbl_licitaciones_detalle")
            .select("id_detalle")
            .eq("organization_id", org_s)
            .in_("id_producto", product_ids)
            .execute()
        )
        for r in (det_for_real.data or []):
            if r.get("id_detalle") is not None:
                id_detalles.append(int(r["id_detalle"]))
        if id_detalles:
            real_resp = (
                supabase_client.table("tbl_licitaciones_real")
                .select("id_detalle, id_entrega, pcu")
                .eq("organization_id", org_s)
                .in_("id_detalle", id_detalles)
                .execute()
            )
            real_rows = real_resp.data or []
            id_entregas = list({r["id_entrega"] for r in real_rows if r.get("id_entrega") is not None})
            entrega_fechas: Dict[Any, str] = {}
            if id_entregas:
                ent_resp = (
                    supabase_client.table("tbl_entregas")
                    .select("id_entrega, fecha_entrega")
                    .eq("organization_id", org_s)
                    .in_("id_entrega", id_entregas)
                    .execute()
                )
                for row in (ent_resp.data or []):
                    eid = row.get("id_entrega")
                    t = _norm_date(row.get("fecha_entrega"))
                    if eid is not None and t:
                        entrega_fechas[eid] = t
            for r in real_rows:
                pcu = r.get("pcu")
                id_ent = r.get("id_entrega")
                if pcu is None or id_ent is None:
                    continue
                time_str = entrega_fechas.get(id_ent)
                if not time_str:
                    continue
                try:
                    pcu_points.append(MaterialTrendPoint(time=time_str, value=round(float(pcu), 2)))
                except (TypeError, ValueError):
                    pass

        # Ordenar y desduplicar por fecha (quedarse con el último valor por día)
        def dedup_sorted(points: List[MaterialTrendPoint]) -> List[MaterialTrendPoint]:
            if not points:
                return []
            df = pd.DataFrame([{"time": p.time, "value": p.value} for p in points])
            df = df.sort_values("time").drop_duplicates(subset=["time"], keep="last")
            return [MaterialTrendPoint(time=row["time"], value=round(float(row["value"]), 2)) for _, row in df.iterrows()]

        pvu_points = dedup_sorted(pvu_points)
        pcu_points = dedup_sorted(pcu_points)
        return MaterialTrendResponse(pvu=pvu_points, pcu=pcu_points)
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error obteniendo tendencia de material: {e!s}",
        ) from e


@router.get("/risk-adjusted-pipeline", response_model=List[RiskPipelineItem])
def get_risk_adjusted_pipeline(current_user: CurrentUserDep) -> List[RiskPipelineItem]:
    """
    GET /analytics/risk-adjusted-pipeline
    Comparativa solo para licitaciones EN ANÁLISIS: barra 1 = suma (pvu*unidades) del detalle;
    barra 2 = misma suma usando la media de precio por artículo (tbl_precios_referencia).
    """
    try:
        # Solo licitaciones en estado EN ANÁLISIS
        maestros = get_maestros(supabase_client)
        estados_name_map = maestros.get("estados_name_map", {})
        analisis_norm = (ESTADO_EN_ESTUDIO or "").strip().lower()
        id_estado_analisis = None
        for nombre, id_est in estados_name_map.items():
            if (nombre or "").strip().lower() == analisis_norm:
                id_estado_analisis = id_est
                break
        if id_estado_analisis is None:
            return [
                RiskPipelineItem(category="Comparativa", pipeline_bruto=0.0, pipeline_ajustado=0.0),
            ]
        org_s = str(current_user.org_id)
        lic_resp = (
            supabase_client.table("tbl_licitaciones")
            .select("id_licitacion")
            .eq("organization_id", org_s)
            .eq("id_estado", id_estado_analisis)
            .execute()
        )
        id_licitaciones_analisis = [r["id_licitacion"] for r in (lic_resp.data or []) if r.get("id_licitacion") is not None]
        if not id_licitaciones_analisis:
            return [
                RiskPipelineItem(category="Comparativa", pipeline_bruto=0.0, pipeline_ajustado=0.0),
            ]

        # Detalles activos solo de esas licitaciones: id_licitacion, id_producto, pvu, unidades
        det_resp = (
            supabase_client.table("tbl_licitaciones_detalle")
            .select("id_producto, pvu, unidades")
            .eq("organization_id", org_s)
            .eq("activo", True)
            .in_("id_licitacion", id_licitaciones_analisis)
            .execute()
        )
        rows = det_resp.data or []
        if not rows:
            return [
                RiskPipelineItem(
                    category="Comparativa",
                    pipeline_bruto=0.0,
                    pipeline_ajustado=0.0,
                )
            ]

        # Barra 1: venta presupuestada = suma (pvu * unidades)
        venta_presupuestada = 0.0
        lineas: List[Dict[str, Any]] = []
        for r in rows:
            pvu = float(r.get("pvu") or 0)
            ud = float(r.get("unidades") or 0)
            venta_presupuestada += pvu * ud
            id_producto = r.get("id_producto")
            if id_producto is not None:
                lineas.append({"id_producto": int(id_producto), "pvu": pvu, "unidades": ud})

        # Medias de precio por producto desde tbl_precios_referencia
        product_ids = list({ln["id_producto"] for ln in lineas})
        ref_resp = (
            supabase_client.table("tbl_precios_referencia")
            .select("id_producto, pvu")
            .eq("organization_id", org_s)
            .in_("id_producto", product_ids)
            .execute()
        )
        ref_rows = ref_resp.data or []
        # id_producto -> lista de pvu
        pvu_por_producto: Dict[int, List[float]] = {}
        for ref in ref_rows:
            pid = ref.get("id_producto")
            pvu_val = ref.get("pvu")
            if pid is not None and pvu_val is not None:
                pid = int(pid)
                pvu_por_producto.setdefault(pid, []).append(float(pvu_val))
        avg_pvu: Dict[int, float] = {}
        for pid, vals in pvu_por_producto.items():
            avg_pvu[pid] = sum(vals) / len(vals)

        # Barra 2: venta a precio medio = suma (media_pvu[id_producto] * unidades); si no hay media usamos pvu del detalle
        venta_a_precio_medio = 0.0
        for ln in lineas:
            pid = ln["id_producto"]
            ud = ln["unidades"]
            pvu_medio = avg_pvu.get(pid, ln["pvu"])
            venta_a_precio_medio += pvu_medio * ud

        return [
            RiskPipelineItem(
                category="Comparativa",
                pipeline_bruto=round(venta_presupuestada, 2),
                pipeline_ajustado=round(venta_a_precio_medio, 2),
            )
        ]
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error obteniendo comparativa venta vs precio medio: {e!s}",
        ) from e


@router.get("/sweet-spots", response_model=List[SweetSpotItem])
def get_sweet_spots(current_user: CurrentUserDep) -> List[SweetSpotItem]:
    """
    GET /analytics/sweet-spots
    Licitaciones cerradas (Adjudicada, No Adjudicada, Terminada) con presupuesto y estado para scatter.
    """
    try:
        maestros = get_maestros(supabase_client)
        estados_id_map = maestros.get("estados_id_map", {})
        estados_name_map = maestros.get("estados_name_map", {})
        # IDs de estados cerrados (comparación insensible a mayúsculas: BD puede tener "NO ADJUDICADA", "TERMINADA")
        sweet_spot_norm = {s.lower().strip() for s in ESTADOS_SWEET_SPOT}
        ids_cerrados = [
            id_estado
            for nombre_estado, id_estado in estados_name_map.items()
            if (nombre_estado or "").strip().lower() in sweet_spot_norm
        ]
        if not ids_cerrados:
            return []

        lic_resp = (
            supabase_client.table("tbl_licitaciones")
            .select("id_licitacion, nombre, numero_expediente, pres_maximo, id_estado")
            .eq("organization_id", str(current_user.org_id))
            .in_("id_estado", ids_cerrados)
            .execute()
        )
        rows = lic_resp.data or []
        result: List[SweetSpotItem] = []
        for r in rows:
            id_lic = r.get("id_licitacion")
            pres = float(r.get("pres_maximo") or 0)
            id_estado = r.get("id_estado")
            estado_nombre = estados_id_map.get(id_estado, "Desconocido")
            cliente = str(r.get("nombre") or r.get("numero_expediente") or id_lic)
            result.append(
                SweetSpotItem(
                    id=str(id_lic),
                    presupuesto=round(pres, 2),
                    estado=estado_nombre,
                    cliente=cliente,
                )
            )
        return result
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error obteniendo sweet spots: {e!s}",
        ) from e


@router.get("/price-deviation-check", response_model=PriceDeviationResult)
def get_price_deviation_check(
    current_user: CurrentUserDep,
    material_name: str = Query(..., description="Nombre del material/insumo."),
    current_price: float = Query(..., ge=0, description="Precio actual a comparar (PVU o PCU)."),
) -> PriceDeviationResult:
    """
    GET /analytics/price-deviation-check?material_name=...&current_price=...
    Compara el precio actual con la media histórica del último año (referencia + detalle + real).
    """
    try:
        org_s = str(current_user.org_id)
        prod_resp = (
            supabase_client.table("tbl_productos")
            .select("id")
            .eq("organization_id", org_s)
            .ilike("nombre", f"%{material_name}%")
            .execute()
        )
        product_ids = [r["id"] for r in (prod_resp.data or []) if r.get("id") is not None]
        if not product_ids:
            return PriceDeviationResult(
                is_deviated=True,
                deviation_percentage=0.0,
                historical_avg=0.0,
                recommendation="No hay histórico para este material. Revisar precio manualmente.",
            )

        one_year_ago = (datetime.utcnow() - timedelta(days=365)).strftime("%Y-%m-%d")
        values: List[float] = []

        # PVU y PCU: precios_referencia (último año)
        ref_resp = (
            supabase_client.table("tbl_precios_referencia")
            .select("pvu, pcu, fecha_presupuesto")
            .eq("organization_id", org_s)
            .in_("id_producto", product_ids)
            .execute()
        )
        for r in (ref_resp.data or []):
            pvu = r.get("pvu")
            if pvu is None:
                continue
            try:
                v = float(pvu)
            except (TypeError, ValueError):
                continue
            if _norm_date(r.get("fecha_presupuesto")) and str(r.get("fecha_presupuesto", ""))[:10] >= one_year_ago:
                values.append(v)

        # PVU: licitaciones_detalle (todos los presupuestados, con o sin fecha en último año)
        det_resp = (
            supabase_client.table("tbl_licitaciones_detalle")
            .select("id_licitacion, pvu")
            .eq("organization_id", org_s)
            .in_("id_producto", product_ids)
            .eq("activo", True)
            .execute()
        )
        for r in (det_resp.data or []):
            pvu = r.get("pvu")
            if pvu is None:
                continue
            try:
                values.append(float(pvu))
            except (TypeError, ValueError):
                pass

        # Histórico para desviación: solo PVU (referencia + detalle) para comparar con precio actual
        historical_avg = float(sum(values) / len(values)) if values else 0.0
        if historical_avg <= 0:
            return PriceDeviationResult(
                is_deviated=True,
                deviation_percentage=0.0,
                historical_avg=0.0,
                recommendation="Sin histórico reciente. Verificar precio con el mercado.",
            )
        deviation_percentage = ((current_price - historical_avg) / historical_avg) * 100
        is_deviated = abs(deviation_percentage) > DEVIATION_THRESHOLD_PCT
        if is_deviated and deviation_percentage > 0:
            recommendation = (
                f"Precio {deviation_percentage:.1f}% por encima de la media del último año (€{historical_avg:.2f}). "
                "Revisar si el coste actual está justificado."
            )
        elif is_deviated and deviation_percentage < 0:
            recommendation = (
                f"Precio {abs(deviation_percentage):.1f}% por debajo de la media del último año (€{historical_avg:.2f}). "
                "Confirmar que el proveedor y la calidad son correctos."
            )
        else:
            recommendation = f"Precio alineado con la media histórica (€{historical_avg:.2f})."
        return PriceDeviationResult(
            is_deviated=is_deviated,
            deviation_percentage=round(deviation_percentage, 2),
            historical_avg=round(historical_avg, 2),
            recommendation=recommendation,
        )
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error en comprobación de desviación: {e!s}",
        ) from e


# ---------- Product Analytics (ficha técnica por producto) ----------

MA_WINDOW = 5  # Ventana para Moving Average del forecast


@router.get("/product/{product_id}", response_model=ProductAnalytics)
def get_product_analytics(product_id: int, current_user: CurrentUserDep) -> ProductAnalytics:
    """
    GET /analytics/product/{id}
    Analíticas avanzadas por producto: price_history, volume_metrics, competitor_analysis, forecast.
    """
    try:
        org_s = str(current_user.org_id)
        # Nombre del producto (verificar pertenece a la org)
        prod_resp = (
            supabase_client.table("tbl_productos")
            .select("id, nombre")
            .eq("id", product_id)
            .eq("organization_id", org_s)
            .single()
            .execute()
        )
        if not prod_resp.data:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail="Producto no encontrado.",
            )
        product_name = str(prod_resp.data.get("nombre") or "")

        # Detalle: partidas de licitaciones con este producto + fecha adjudicación
        det_resp = (
            supabase_client.table("tbl_licitaciones_detalle")
            .select("id_detalle, id_licitacion, pvu, unidades")
            .eq("organization_id", org_s)
            .eq("id_producto", product_id)
            .execute()
        )
        det_rows = det_resp.data or []
        id_licitaciones = list({r["id_licitacion"] for r in det_rows if r.get("id_licitacion") is not None})
        id_detalles = [r["id_detalle"] for r in det_rows if r.get("id_detalle") is not None]

        # Fechas de adjudicación por licitación
        lic_fechas: Dict[int, str] = {}
        if id_licitaciones:
            lic_resp = (
                supabase_client.table("tbl_licitaciones")
                .select("id_licitacion, fecha_adjudicacion")
                .eq("organization_id", org_s)
                .in_("id_licitacion", id_licitaciones)
                .execute()
            )
            for r in (lic_resp.data or []):
                lid = r.get("id_licitacion")
                f = r.get("fecha_adjudicacion")
                if lid is not None and f:
                    lic_fechas[int(lid)] = str(f).split("T")[0][:10]

        # Precios de referencia (pvu y pcu con fecha_presupuesto)
        ref_resp = (
            supabase_client.table("tbl_precios_referencia")
            .select("pvu, pcu, unidades, fecha_presupuesto")
            .eq("organization_id", org_s)
            .eq("id_producto", product_id)
            .execute()
        )
        ref_rows = ref_resp.data or []

        # Unidades vendidas = desde tbl_precios_referencia donde PCU es NULL (albaranes de venta), por fecha_presupuesto
        def _pcu_es_nulo(pcu: Any) -> bool:
            """True si no hay coste (NULL, vacío o 0)."""
            if pcu is None:
                return True
            if isinstance(pcu, str) and (not pcu.strip() or pcu.strip().lower() == "null"):
                return True
            try:
                return float(pcu) == 0
            except (TypeError, ValueError):
                return False

        unidades_vendidas_by_date: Dict[str, float] = {}
        for r in ref_rows:
            if not _pcu_es_nulo(r.get("pcu")):
                continue
            time_str = _norm_date(r.get("fecha_presupuesto"))
            if not time_str:
                continue
            try:
                qty = float(r.get("unidades") or 0)
            except (TypeError, ValueError):
                continue
            unidades_vendidas_by_date[time_str] = unidades_vendidas_by_date.get(time_str, 0.0) + qty

        # Construir price_history (PVU); unidades del tooltip = unidades vendidas por fecha (referencia con PCU NULL)
        hist_rows: List[Dict[str, Any]] = []
        for r in det_rows:
            pvu = r.get("pvu")
            id_lic = r.get("id_licitacion")
            if pvu is None or id_lic is None:
                continue
            try:
                value = float(pvu)
            except (TypeError, ValueError):
                continue
            time_str = lic_fechas.get(int(id_lic))
            if not time_str:
                continue
            hist_rows.append({"time": time_str, "value": value})
        for r in ref_rows:
            pvu = r.get("pvu")
            fecha = r.get("fecha_presupuesto")
            if pvu is None or not fecha:
                continue
            try:
                value = float(pvu)
            except (TypeError, ValueError):
                continue
            time_str = _norm_date(fecha)
            if not time_str:
                continue
            hist_rows.append({"time": time_str, "value": value})

        df_hist = pd.DataFrame(hist_rows)
        if not df_hist.empty:
            df_hist = df_hist.sort_values("time")
            df_hist = df_hist.groupby("time", as_index=False).agg(value=("value", "last"))
        price_history = [
            PriceHistoryPoint(
                time=row["time"],
                value=round(float(row["value"]), 2),
                unidades=round(unidades_vendidas_by_date.get(row["time"], 0.0), 2),
            )
            for _, row in df_hist.iterrows()
        ]

        # PCU history: precios_referencia (pcu) + licitaciones_real (pcu con fecha de entrega)
        hist_pcu_rows: List[Dict[str, Any]] = []
        for r in ref_rows:
            pcu = r.get("pcu")
            fecha = r.get("fecha_presupuesto")
            if pcu is None or not fecha:
                continue
            try:
                value = float(pcu)
            except (TypeError, ValueError):
                continue
            time_str = str(fecha).split("T")[0][:10]
            hist_pcu_rows.append({"time": time_str, "value": value})
        if id_detalles:
            real_resp = (
                supabase_client.table("tbl_licitaciones_real")
                .select("id_detalle, id_entrega, pcu")
                .eq("organization_id", org_s)
                .in_("id_detalle", id_detalles)
                .execute()
            )
            real_rows = real_resp.data or []
            id_entregas = list({r["id_entrega"] for r in real_rows if r.get("id_entrega") is not None})
            entrega_fechas: Dict[Any, str] = {}
            if id_entregas:
                ent_resp = (
                    supabase_client.table("tbl_entregas")
                    .select("id_entrega, fecha_entrega")
                    .eq("organization_id", org_s)
                    .in_("id_entrega", id_entregas)
                    .execute()
                )
                for row in (ent_resp.data or []):
                    eid = row.get("id_entrega")
                    t = _norm_date(row.get("fecha_entrega"))
                    if eid is not None and t:
                        entrega_fechas[eid] = t
            for r in real_rows:
                pcu = r.get("pcu")
                id_ent = r.get("id_entrega")
                if pcu is None or id_ent is None:
                    continue
                time_str = entrega_fechas.get(id_ent)
                if not time_str:
                    continue
                try:
                    hist_pcu_rows.append({"time": time_str, "value": float(pcu)})
                except (TypeError, ValueError):
                    pass
        df_pcu = pd.DataFrame(hist_pcu_rows)
        if not df_pcu.empty:
            df_pcu = df_pcu.sort_values("time").drop_duplicates(subset=["time"], keep="last")
        price_history_pcu = [
            PriceHistoryPoint(time=row["time"], value=round(float(row["value"]), 2))
            for _, row in df_pcu.iterrows()
        ]

        # Volume metrics: total licitado (sum pvu*unidades), oferentes promedio (distinct licitaciones / count)
        total_licitado = 0.0
        for r in det_rows:
            pvu = r.get("pvu")
            un = r.get("unidades")
            if pvu is not None and un is not None:
                try:
                    total_licitado += float(pvu) * float(un)
                except (TypeError, ValueError):
                    pass
        num_licitaciones = len(id_licitaciones) if id_licitaciones else 0
        cantidad_oferentes_promedio = float(num_licitaciones) if num_licitaciones else 0.0
        volume_metrics = VolumeMetrics(
            total_licitado=round(total_licitado, 2),
            cantidad_oferentes_promedio=round(cantidad_oferentes_promedio, 2),
        )

        # Competitor analysis: top 3 proveedores desde tbl_licitaciones_real (por id_detalle)
        competitor_analysis: List[CompetitorItem] = []
        if id_detalles:
            real_resp = (
                supabase_client.table("tbl_licitaciones_real")
                .select("id_detalle, proveedor, pcu")
                .eq("organization_id", org_s)
                .in_("id_detalle", id_detalles)
                .execute()
            )
            real_rows = real_resp.data or []
            if real_rows:
                df_real = pd.DataFrame(real_rows)
                df_real["pcu"] = pd.to_numeric(df_real["pcu"], errors="coerce").fillna(0)
                df_real["proveedor"] = df_real["proveedor"].fillna("—")
                agg = df_real.groupby("proveedor").agg(
                    precio_medio=("pcu", "mean"),
                    cantidad_adjudicaciones=("id_detalle", "count"),
                ).reset_index()
                agg = agg.sort_values("cantidad_adjudicaciones", ascending=False).head(3)
                competitor_analysis = [
                    CompetitorItem(
                        empresa=str(row["proveedor"]),
                        precio_medio=round(float(row["precio_medio"]), 2),
                        cantidad_adjudicaciones=int(row["cantidad_adjudicaciones"]),
                    )
                    for _, row in agg.iterrows()
                ]

        # Forecast: Moving Average de los últimos MA_WINDOW puntos
        forecast_val: Optional[float] = None
        if len(price_history) >= 2:
            values = [p.value for p in price_history]
            window = min(MA_WINDOW, len(values))
            ma = sum(values[-window:]) / window
            forecast_val = round(ma, 2)

        # Precio referencia medio (desde tbl_precios_referencia)
        precios_ref = [float(r["pvu"]) for r in ref_rows if r.get("pvu") is not None]
        precio_referencia_medio = round(sum(precios_ref) / len(precios_ref), 2) if precios_ref else None

        return ProductAnalytics(
            product_id=product_id,
            product_name=product_name,
            price_history=price_history,
            price_history_pcu=price_history_pcu,
            volume_metrics=volume_metrics,
            competitor_analysis=competitor_analysis,
            forecast=forecast_val,
            precio_referencia_medio=precio_referencia_medio,
        )
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error obteniendo analíticas de producto: {e!s}",
        ) from e
