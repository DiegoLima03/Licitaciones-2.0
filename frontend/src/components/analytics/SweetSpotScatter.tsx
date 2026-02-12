"use client";

import * as React from "react";
import {
  Legend,
  ResponsiveContainer,
  Scatter,
  ScatterChart,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import type { SweetSpotItem } from "@/types/api";

export interface SweetSpotScatterProps {
  data: SweetSpotItem[];
  isLoading?: boolean;
  error?: Error | null;
  className?: string;
}

const ADJUDICADA = "Adjudicada";
const COLOR_ADJ = "#059669";
const COLOR_PERD = "#dc2626";

export function SweetSpotScatter({
  data,
  isLoading,
  error,
  className,
}: SweetSpotScatterProps) {
  const points = React.useMemo(() => {
    return data.map((d) => ({
      ...d,
      x: d.presupuesto,
      y: d.estado === ADJUDICADA ? 1 : 0,
      fill: d.estado === ADJUDICADA ? COLOR_ADJ : COLOR_PERD,
    }));
  }, [data]);

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
        <span className="text-sm text-slate-500">Cargando sweet spots…</span>
      </div>
    );
  }

  if (points.length === 0) {
    return (
      <div
        className={
          "flex min-h-[280px] items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 " +
          (className ?? "")
        }
      >
        No hay licitaciones cerradas (Adjudicada/Perdida)
      </div>
    );
  }

  return (
    <div className={className}>
      <p className="mb-2 text-xs font-medium text-slate-500 dark:text-slate-400">
        Sweet spots — Presupuesto vs resultado (Verde: Adjudicada, Rojo: Perdida)
      </p>
      <div className="h-[280px] w-full">
        <ResponsiveContainer width="100%" height="100%">
          <ScatterChart margin={{ top: 12, right: 12, left: 0, bottom: 0 }}>
            <XAxis
              type="number"
              dataKey="x"
              name="Presupuesto"
              tickFormatter={(v) => `€${(v / 1000).toFixed(0)}k`}
              tick={{ fontSize: 11 }}
              className="text-slate-600 dark:text-slate-400"
            />
            <YAxis
              type="number"
              dataKey="y"
              name="Estado"
              domain={[-0.5, 1.5]}
              tick={false}
              hide
            />
            <Tooltip
              cursor={{ strokeDasharray: "3 3" }}
              formatter={(value: number, name: string, props: { payload?: SweetSpotItem }) => {
                const p = props.payload;
                if (!p) return [value, name];
                return [`${p.cliente} — €${p.presupuesto.toLocaleString("es-ES")} (${p.estado})`, name];
              }}
            />
            <Legend />
            <Scatter name="Adjudicada" data={points.filter((p) => p.estado === ADJUDICADA)} fill={COLOR_ADJ} />
            <Scatter name="Perdida / No Adjudicada" data={points.filter((p) => p.estado !== ADJUDICADA)} fill={COLOR_PERD} />
          </ScatterChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
}
