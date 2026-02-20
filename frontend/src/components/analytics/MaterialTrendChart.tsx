"use client";

import * as React from "react";
import { createChart, LineSeries, type IChartApi, type ISeriesApi } from "lightweight-charts";
import type { MaterialTrendPoint, MaterialTrendResponse } from "@/types/api";

export interface MaterialTrendChartProps {
  /** Respuesta del API: PVU (referencia + detalle) y PCU (referencia + real). */
  data: MaterialTrendResponse;
  materialName: string;
  isLoading?: boolean;
  error?: Error | null;
  className?: string;
}

function formatDateLabel(time: string | undefined): string {
  if (!time || typeof time !== "string") return "—";
  const d = time.slice(0, 10);
  const [y, m, day] = d.split("-");
  const months = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
  const mi = parseInt(m, 10) - 1;
  return `${day} ${months[mi] ?? m} '${(y ?? "").slice(-2)}`;
}

/** Normaliza param.time del crosshair (string ISO, BusinessDay o timestamp) a "YYYY-MM-DD". */
function normalizeTimeToYMD(t: unknown): string | null {
  if (t == null) return null;
  if (typeof t === "string") {
    const s = t.slice(0, 10);
    if (/^\d{8}$/.test(s)) return `${s.slice(0, 4)}-${s.slice(4, 6)}-${s.slice(6, 8)}`;
    return s;
  }
  if (typeof t === "number") {
    const d = new Date(t * 1000);
    return d.toISOString().slice(0, 10);
  }
  if (typeof t === "object" && t !== null && "year" in t && "month" in t && "day" in t) {
    const { year: y, month: m, day: d } = t as { year: number; month: number; day: number };
    const pad = (n: number) => String(n).padStart(2, "0");
    return `${y}-${pad(m)}-${pad(d)}`;
  }
  return null;
}

export function MaterialTrendChart({
  data,
  materialName,
  isLoading,
  error,
  className,
}: MaterialTrendChartProps) {
  const containerRef = React.useRef<HTMLDivElement>(null);
  const tooltipRef = React.useRef<HTMLDivElement>(null);
  const chartRef = React.useRef<IChartApi | null>(null);
  const pvuSeriesRef = React.useRef<ISeriesApi<"Line"> | null>(null);
  const pcuSeriesRef = React.useRef<ISeriesApi<"Line"> | null>(null);
  const dataRef = React.useRef(data);

  const safeData = data ?? { pvu: [], pcu: [] };
  const pvuList = safeData.pvu ?? [];
  const pcuList = safeData.pcu ?? [];
  const hasPvu = pvuList.length > 0;
  const hasPcu = pcuList.length > 0;
  const hasData = hasPvu || hasPcu;

  dataRef.current = safeData;

  // Array de dependencias de longitud fija (6) en todos los efectos
  const deps: [boolean, boolean, boolean, string, number, number] = [
    hasData,
    hasPvu,
    hasPcu,
    materialName,
    pvuList.length,
    pcuList.length,
  ];

  React.useEffect(() => {
    if (!containerRef.current || !deps[0]) return;
    const chart = createChart(containerRef.current, {
      layout: {
        background: { type: "solid", color: "transparent" },
        textColor: "var(--foreground, #171717)",
      },
      grid: {
        vertLines: { color: "rgba(0,0,0,0.06)" },
        horzLines: { color: "rgba(0,0,0,0.06)" },
      },
      width: containerRef.current.clientWidth,
      height: 280,
      timeScale: { timeVisible: true, secondsVisible: false },
      rightPriceScale: { borderVisible: true },
    });
    const toPoint = (d: { time: string; value: unknown }) => ({
      time: d.time as string,
      value: Number(d.value) || 0,
    });
    if (deps[1]) {
      const pvuSeries = chart.addSeries(LineSeries, { color: "#059669", lineWidth: 2 });
      pvuSeries.setData(pvuList.map(toPoint));
      pvuSeriesRef.current = pvuSeries;
    }
    if (deps[2]) {
      const pcuSeries = chart.addSeries(LineSeries, { color: "#64748b", lineWidth: 2 });
      pcuSeries.setData(pcuList.map(toPoint));
      pcuSeriesRef.current = pcuSeries;
    }
    chartRef.current = chart;

    const crosshairHandler = (param: Parameters<Parameters<IChartApi["subscribeCrosshairMove"]>[0]>[0]) => {
      const tooltipEl = tooltipRef.current;
      if (!tooltipEl) return;
      const timeStr = normalizeTimeToYMD(param.time);
      if (param.point == null || timeStr == null || param.point.x < 0 || param.point.y < 0) {
        tooltipEl.style.display = "none";
        return;
      }
      const { pvu: pvuData, pcu: pcuData } = dataRef.current;
      let pvuVal: number | null = null;
      let pcuVal: number | null = null;
      let unidades: number | null = null;
      if (pvuSeriesRef.current && param.seriesData) {
        const p = param.seriesData.get(pvuSeriesRef.current) as { value?: number } | undefined;
        if (p?.value != null) pvuVal = p.value;
      }
      if (pcuSeriesRef.current && param.seriesData) {
        const p = param.seriesData.get(pcuSeriesRef.current) as { value?: number } | undefined;
        if (p?.value != null) pcuVal = p.value;
      }
      const pvuPoint = (pvuData ?? []).find(
        (d) => normalizeTimeToYMD(d.time) === timeStr || String(d.time).slice(0, 10) === timeStr
      );
      if (pvuPoint && "unidades" in pvuPoint) {
        const u = pvuPoint.unidades;
        unidades = u === null || u === undefined ? null : Number(u);
      }

      tooltipEl.style.display = "block";
      tooltipEl.style.left = `${param.point.x + 12}px`;
      tooltipEl.style.top = `${param.point.y}px`;
      const unidadesText =
        unidades !== null && unidades !== undefined
          ? Number(unidades).toLocaleString("es-ES")
          : "—";
      tooltipEl.innerHTML = [
        `<div class="font-semibold text-slate-200">${formatDateLabel(timeStr)}</div>`,
        pvuVal != null ? `<div><span class="text-emerald-400">PVU (venta):</span> ${pvuVal.toFixed(2)} €</div>` : "",
        pcuVal != null ? `<div><span class="text-slate-400">PCU (coste):</span> ${pcuVal.toFixed(2)} €</div>` : "",
        `<div><span class="text-slate-400">Unidades vendidas:</span> ${unidadesText}</div>`,
      ].filter(Boolean).join("");
    };
    chart.subscribeCrosshairMove(crosshairHandler);
    return () => {
      if (typeof chart.unsubscribeCrosshairMove === "function") {
        chart.unsubscribeCrosshairMove(crosshairHandler);
      }
      chart.remove();
      chartRef.current = null;
      pvuSeriesRef.current = null;
      pcuSeriesRef.current = null;
    };
  }, deps);

  React.useEffect(() => {
    if (!chartRef.current || !deps[0]) return;
    const { pvu, pcu } = dataRef.current;
    const toPoint = (d: { time: string; value: unknown }) => ({
      time: d.time as string,
      value: Number(d.value) || 0,
    });
    if (deps[1] && pvuSeriesRef.current && pvu?.length) {
      pvuSeriesRef.current.setData(pvu.map(toPoint));
    }
    if (deps[2] && pcuSeriesRef.current && pcu?.length) {
      pcuSeriesRef.current.setData(pcu.map(toPoint));
    }
  }, deps);

  React.useEffect(() => {
    const el = containerRef.current;
    if (!el || !chartRef.current) return;
    const ro = new ResizeObserver((entries) => {
      const { width } = entries[0]?.contentRect ?? { width: el.clientWidth };
      chartRef.current?.applyOptions({ width });
    });
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  if (error) {
    return (
      <div
        className={
          "flex min-h-[280px] items-center justify-center rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-400 " +
          (className ?? "")
        }
      >
        {error.message}
      </div>
    );
  }

  if (isLoading) {
    return (
      <div
        className={
          "flex min-h-[280px] animate-pulse items-center justify-center rounded-lg border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800 " +
          (className ?? "")
        }
      >
        <span className="text-sm text-slate-500">Cargando tendencia…</span>
      </div>
    );
  }

  if (!hasData) {
    return (
      <div
        className={
          "flex min-h-[280px] items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 " +
          (className ?? "")
        }
      >
        Sin datos para «{materialName}»
      </div>
    );
  }

  return (
    <div className={`relative ${className ?? ""}`}>
      <p className="mb-2 text-xs font-medium text-slate-500 dark:text-slate-400">
        Evolución de precio — {materialName}
        {hasPvu && hasPcu && " (Verde: PVU precio venta · Gris: PCU precio coste)"}
      </p>
      <div className="relative">
        <div ref={containerRef} className="rounded-lg" />
        <div
          data-trend-tooltip
          ref={tooltipRef}
          className="pointer-events-none absolute z-20 hidden rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-xs text-slate-100 shadow-lg"
          style={{ display: "none" }}
        />
      </div>
    </div>
  );
}
