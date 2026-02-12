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

export function MaterialTrendChart({
  data,
  materialName,
  isLoading,
  error,
  className,
}: MaterialTrendChartProps) {
  const containerRef = React.useRef<HTMLDivElement>(null);
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
    if (deps[1]) {
      const pvuSeries = chart.addSeries(LineSeries, { color: "#059669", lineWidth: 2 });
      pvuSeries.setData(pvuList.map((d) => ({ time: d.time as string, value: d.value })));
      pvuSeriesRef.current = pvuSeries;
    }
    if (deps[2]) {
      const pcuSeries = chart.addSeries(LineSeries, { color: "#64748b", lineWidth: 2 });
      pcuSeries.setData(pcuList.map((d) => ({ time: d.time as string, value: d.value })));
      pcuSeriesRef.current = pcuSeries;
    }
    chartRef.current = chart;
    return () => {
      chart.remove();
      chartRef.current = null;
      pvuSeriesRef.current = null;
      pcuSeriesRef.current = null;
    };
  }, deps);

  React.useEffect(() => {
    if (!chartRef.current || !deps[0]) return;
    const { pvu, pcu } = dataRef.current;
    if (deps[1] && pvuSeriesRef.current && pvu?.length) {
      pvuSeriesRef.current.setData(pvu.map((d) => ({ time: d.time as string, value: d.value })));
    }
    if (deps[2] && pcuSeriesRef.current && pcu?.length) {
      pcuSeriesRef.current.setData(pcu.map((d) => ({ time: d.time as string, value: d.value })));
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
    <div className={className}>
      <p className="mb-2 text-xs font-medium text-slate-500 dark:text-slate-400">
        Evolución de precio — {materialName}
        {hasPvu && hasPcu && " (Verde: PVU, Gris: PCU)"}
      </p>
      <div ref={containerRef} className="rounded-lg" />
    </div>
  );
}
