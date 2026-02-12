"""
Dashboard: timeline por adjudicación–finalización y KPIs.
Endpoints de analítica: material trends, risk pipeline, sweet spots, price deviation.
Estados usados en fórmulas (deben coincidir con tbl_estados.nombre_estado):
- Ofertado: Adjudicada, No Adjudicada, Presentada, Terminada
- Adjudicadas+Terminadas: Adjudicada, Terminada
- Descartadas / (total - Análisis - Valoración)
"""

from datetime import datetime, timedelta
from typing import Any, Dict, List, Optional

import pandas as pd
from fastapi import APIRouter, HTTPException, Query, status

from backend.config import supabase_client, get_maestros
from backend.models import (
    KPIDashboard,
    MaterialTrendPoint,
    PriceDeviationResult,
    RiskPipelineItem,
    SweetSpotItem,
    TimelineItem,
)


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


# ---------- Endpoints de analítica avanzada ----------

ESTADO_EN_ESTUDIO = "En Estudio"
ESTADOS_SWEET_SPOT = {"Adjudicada", "Perdida", "No Adjudicada"}  # cerradas para sweet spot
DEVIATION_THRESHOLD_PCT = 10.0


@router.get("/material-trends/{material_name}", response_model=List[MaterialTrendPoint])
def get_material_trends(material_name: str) -> List[MaterialTrendPoint]:
    """
    GET /analytics/material-trends/{material_name}
    Histórico temporal de precios de un insumo (por nombre de producto). Formato para Lightweight Charts.
    """
    try:
        # Productos cuyo nombre coincida (ilike)
        prod_resp = (
            supabase_client.table("tbl_productos")
            .select("id")
            .ilike("nombre", f"%{material_name}%")
            .execute()
        )
        product_ids = [r["id"] for r in (prod_resp.data or []) if r.get("id") is not None]
        if not product_ids:
            return []

        # Precios de referencia con fecha (PVU como valor)
        precios_resp = (
            supabase_client.table("tbl_precios_referencia")
            .select("id_producto, pvu, fecha_creacion")
            .in_("id_producto", product_ids)
            .order("fecha_creacion")
            .execute()
        )
        rows = precios_resp.data or []
        if not rows:
            return []

        out: List[MaterialTrendPoint] = []
        for r in rows:
            fecha = r.get("fecha_creacion")
            if not fecha:
                continue
            # fecha_creacion puede ser ISO con hora; tomar solo YYYY-MM-DD
            time_str = str(fecha).split("T")[0] if isinstance(fecha, str) else str(fecha)[:10]
            pvu = r.get("pvu")
            if pvu is None:
                continue
            try:
                value = float(pvu)
            except (TypeError, ValueError):
                continue
            out.append(MaterialTrendPoint(time=time_str, value=round(value, 2)))
        return out
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error obteniendo tendencia de material: {e!s}",
        ) from e


@router.get("/risk-adjusted-pipeline", response_model=List[RiskPipelineItem])
def get_risk_adjusted_pipeline() -> List[RiskPipelineItem]:
    """
    GET /analytics/risk-adjusted-pipeline
    Agrupa licitaciones 'En Estudio' por categoría (tipo); pipeline bruto y ajustado por win rate histórico.
    """
    try:
        maestros = get_maestros(supabase_client)
        estados_map = maestros.get("estados_id_map", {})
        tipos_map = maestros.get("tipos_id_map", {})
        # Nombre de estado -> id para filtrar "En Estudio"
        name_to_estado_id = maestros.get("estados_name_map", {})
        id_estudio = name_to_estado_id.get(ESTADO_EN_ESTUDIO)
        if id_estudio is None:
            return []

        lic_resp = (
            supabase_client.table("tbl_licitaciones")
            .select("id_licitacion, pres_maximo, id_estado, tipo_de_licitacion")
            .eq("id_estado", id_estudio)
            .execute()
        )
        rows = lic_resp.data or []
        if not rows:
            return []

        # Win rate global: ratio adjudicación (Adjudicadas+Terminadas / Ofertado)
        df_all = _get_licitaciones_df()
        if df_all.empty:
            win_rate = 0.2
        else:
            df_all = _enriquecer_estados(df_all, maestros)
            mask_ofertado = df_all["estado_nombre"].isin(ESTADOS_OFERTADO)
            mask_adj = df_all["estado_nombre"].isin(ESTADOS_ADJUDICADAS_TERMINADAS)
            total_ofertado = mask_ofertado.sum()
            total_adj = mask_adj.sum()
            win_rate = (total_adj / total_ofertado) if total_ofertado and total_ofertado > 0 else 0.2

        # Agrupar por tipo
        agg: Dict[str, Dict[str, Any]] = {}
        for r in rows:
            pres = float(r.get("pres_maximo") or 0)
            tipo_id = r.get("tipo_de_licitacion")
            category = tipos_map.get(tipo_id, f"Tipo {tipo_id}" if tipo_id is not None else "Sin tipo")
            if category not in agg:
                agg[category] = {"pipeline_bruto": 0.0, "pipeline_ajustado": 0.0}
            agg[category]["pipeline_bruto"] += pres
        for cat, v in agg.items():
            v["pipeline_ajustado"] = round(v["pipeline_bruto"] * win_rate, 2)
            v["pipeline_bruto"] = round(v["pipeline_bruto"], 2)

        return [
            RiskPipelineItem(category=k, pipeline_bruto=v["pipeline_bruto"], pipeline_ajustado=v["pipeline_ajustado"])
            for k, v in agg.items()
        ]
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error obteniendo pipeline ajustado: {e!s}",
        ) from e


@router.get("/sweet-spots", response_model=List[SweetSpotItem])
def get_sweet_spots() -> List[SweetSpotItem]:
    """
    GET /analytics/sweet-spots
    Licitaciones cerradas (Adjudicada / Perdida o No Adjudicada) con presupuesto y estado para scatter.
    """
    try:
        maestros = get_maestros(supabase_client)
        estados_id_map = maestros.get("estados_id_map", {})
        estados_name_map = maestros.get("estados_name_map", {})
        # IDs de estados que consideramos "cerrados" para sweet spot
        ids_cerrados = [estados_name_map.get(n) for n in ESTADOS_SWEET_SPOT if estados_name_map.get(n) is not None]
        if not ids_cerrados:
            return []

        lic_resp = (
            supabase_client.table("tbl_licitaciones")
            .select("id_licitacion, nombre, numero_expediente, pres_maximo, id_estado")
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
    material_name: str = Query(..., description="Nombre del material/insumo."),
    current_price: float = Query(..., ge=0, description="Precio actual a comparar."),
) -> PriceDeviationResult:
    """
    GET /analytics/price-deviation-check?material_name=...&current_price=...
    Compara el precio actual con la media histórica del último año. Recomendación si desviación > 10%.
    """
    try:
        # Productos por nombre
        prod_resp = (
            supabase_client.table("tbl_productos")
            .select("id")
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

        # Último año: filtrar por fecha_creacion (Supabase no tiene date_trunc en filtro fácil)
        precios_resp = (
            supabase_client.table("tbl_precios_referencia")
            .select("pvu, fecha_creacion")
            .in_("id_producto", product_ids)
            .execute()
        )
        rows = precios_resp.data or []
        one_year_ago = (datetime.utcnow() - timedelta(days=365)).strftime("%Y-%m-%d")
        values: List[float] = []
        for r in rows:
            pvu = r.get("pvu")
            if pvu is None:
                continue
            try:
                v = float(pvu)
            except (TypeError, ValueError):
                continue
            fecha = r.get("fecha_creacion")
            if fecha and str(fecha)[:10] >= one_year_ago:
                values.append(v)
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
