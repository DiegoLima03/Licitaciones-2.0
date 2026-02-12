"use client";

import * as React from "react";
import { createChart, type IChartApi, type ISeriesApi } from "lightweight-charts";
import type { MaterialTrendPoint } from "@/types/api";

export interface MaterialTrendChartProps {
  data: MaterialTrendPoint[];
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
  const seriesRef = React.useRef<ISeriesApi<"Line"> | null>(null);

  React.useEffect(() => {
    if (!containerRef.current || data.length === 0) return;
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
    const lineSeries = chart.addLineSeries({ color: "#059669", lineWidth: 2 });
    const seriesData = data.map((d) => ({ time: d.time as string, value: d.value }));
    lineSeries.setData(seriesData);
    chartRef.current = chart;
    seriesRef.current = lineSeries;
    return () => {
      chart.remove();
      chartRef.current = null;
      seriesRef.current = null;
    };
  }, [data.length, materialName]);

  React.useEffect(() => {
    if (!chartRef.current || !seriesRef.current || data.length === 0) return;
    const seriesData = data.map((d) => ({ time: d.time as string, value: d.value }));
    seriesRef.current.setData(seriesData);
  }, [data]);

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

  if (data.length === 0) {
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
      </p>
      <div ref={containerRef} className="rounded-lg" />
    </div>
  );
}
