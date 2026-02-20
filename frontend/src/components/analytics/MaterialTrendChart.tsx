"use client";

import * as React from "react";
import { createChart, LineSeries, type IChartApi, type ISeriesApi } from "lightweight-charts";
import type { MaterialTrendPoint, MaterialTrendResponse } from "@/types/api";

export interface MaterialTrendChartProps {
  /** Respuesta del API: PVU (venta) y PCU (compra) para dos líneas de tendencia. */
  data: MaterialTrendResponse;
  materialName: string;
  isLoading?: boolean;
  error?: Error | null;
  className?: string;
}

const COLOR_PVU = "#059669"; // verde (venta)
const COLOR_PCU = "#d97706"; // ámbar (compra)

function formatDateLabel(time: string | undefined): string {
  if (!time || typeof time !== "string") return "—";
  const d = time.slice(0, 10);
  const [y, m, day] = d.split("-");
  const months = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
  const mi = parseInt(m, 10) - 1;
  return `${day} ${months[mi] ?? m} '${(y ?? "").slice(-2)}`;
}

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
  const seriesPvuRef = React.useRef<ISeriesApi<"Line"> | null>(null);
  const seriesPcuRef = React.useRef<ISeriesApi<"Line"> | null>(null);
  const dataRef = React.useRef(data);

  const safeData = data ?? { pvu: [], pcu: [] };
  const pvuList = safeData.pvu ?? [];
  const pcuList = safeData.pcu ?? [];
  const hasData = pvuList.length > 0 || pcuList.length > 0;

  dataRef.current = safeData;

  const toPoint = (d: { time: string; value: unknown }) => ({
    time: d.time as string,
    value: Number(d.value) || 0,
  });
  const deps: [boolean, string, number, number] = [hasData, materialName, pvuList.length, pcuList.length];

  React.useEffect(() => {
    if (!containerRef.current || !hasData) return;
    const chart = createChart(containerRef.current, {
      layout: {
        background: { type: "solid", color: "transparent" },
        textColor: "#ffffff",
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
    const seriesPvu = chart.addSeries(LineSeries, { color: COLOR_PVU, lineWidth: 2, title: "PVU" });
    const seriesPcu = chart.addSeries(LineSeries, { color: COLOR_PCU, lineWidth: 2, title: "PCU" });
    if (pvuList.length) seriesPvu.setData(pvuList.map(toPoint));
    if (pcuList.length) seriesPcu.setData(pcuList.map(toPoint));
    seriesPvuRef.current = seriesPvu;
    seriesPcuRef.current = seriesPcu;
    chartRef.current = chart;

    const crosshairHandler = (param: Parameters<Parameters<IChartApi["subscribeCrosshairMove"]>[0]>[0]) => {
      const tooltipEl = tooltipRef.current;
      if (!tooltipEl) return;
      const timeStr = normalizeTimeToYMD(param.time);
      if (param.point == null || timeStr == null || param.point.x < 0 || param.point.y < 0) {
        tooltipEl.style.display = "none";
        return;
      }
      const { pvu: pvuData } = dataRef.current;
      let pvuVal: number | null = null;
      let pcuVal: number | null = null;
      let unidades: number | null = null;
      if (param.seriesData) {
        if (seriesPvuRef.current) {
          const p = param.seriesData.get(seriesPvuRef.current) as { value?: number } | undefined;
          if (p?.value != null) pvuVal = p.value;
        }
        if (seriesPcuRef.current) {
          const p = param.seriesData.get(seriesPcuRef.current) as { value?: number } | undefined;
          if (p?.value != null) pcuVal = p.value;
        }
      }
      const point = (pvuData ?? []).find(
        (d) => normalizeTimeToYMD(d.time) === timeStr || String(d.time).slice(0, 10) === timeStr
      );
      if (point && "unidades" in point) {
        const u = point.unidades;
        unidades = u === null || u === undefined ? null : Number(u);
      }
      // Si no tenemos PVU del crosshair, intentar del punto de datos
      if (pvuVal == null && point && "value" in point) pvuVal = Number((point as { value?: unknown }).value) || null;
      const pcuPoint = (dataRef.current.pcu ?? []).find(
        (d) => normalizeTimeToYMD(d.time) === timeStr || String(d.time).slice(0, 10) === timeStr
      );
      if (pcuVal == null && pcuPoint && "value" in pcuPoint) pcuVal = Number((pcuPoint as { value?: unknown }).value) || null;

      tooltipEl.style.display = "block";
      tooltipEl.style.left = `${param.point.x + 12}px`;
      tooltipEl.style.top = `${param.point.y}px`;
      const unidadesText =
        unidades !== null && unidades !== undefined ? Number(unidades).toLocaleString("es-ES") : "—";
      tooltipEl.innerHTML = [
        `<div class="font-semibold text-slate-200">${formatDateLabel(timeStr)}</div>`,
        pvuVal != null ? `<div><span class="text-emerald-400">PVU (venta):</span> ${pvuVal.toFixed(2)} €</div>` : "",
        pcuVal != null ? `<div><span class="text-amber-400">PCU (compra):</span> ${pcuVal.toFixed(2)} €</div>` : "",
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
      seriesPvuRef.current = null;
      seriesPcuRef.current = null;
    };
  }, deps);

  React.useEffect(() => {
    if (!chartRef.current || !hasData) return;
    const { pvu, pcu } = dataRef.current;
    if (seriesPvuRef.current && pvu?.length) seriesPvuRef.current.setData(pvu.map(toPoint));
    if (seriesPcuRef.current && pcu?.length) seriesPcuRef.current.setData(pcu.map(toPoint));
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
        Evolución del precio — {materialName}
      </p>
      <div className="mb-1 flex items-center gap-4 text-xs">
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-0.5 w-4 rounded" style={{ backgroundColor: COLOR_PVU }} />
          <span className="text-slate-600 dark:text-slate-400">PVU (venta)</span>
        </span>
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-0.5 w-4 rounded" style={{ backgroundColor: COLOR_PCU }} />
          <span className="text-slate-600 dark:text-slate-400">PCU (compra)</span>
        </span>
      </div>
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
