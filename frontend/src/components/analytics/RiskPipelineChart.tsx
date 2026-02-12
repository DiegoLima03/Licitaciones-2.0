"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import type { RiskPipelineItem } from "@/types/api";

export interface RiskPipelineChartProps {
  data: RiskPipelineItem[];
  isLoading?: boolean;
  error?: Error | null;
  className?: string;
}

export function RiskPipelineChart(props: RiskPipelineChartProps) {
  const { data, isLoading, error, className } = props;

  if (error) {
    return (
      <div className={"flex min-h-[280px] items-center justify-center rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 " + (className ?? "")}>
        {error.message}
      </div>
    );
  }

  if (isLoading) {
    return (
      <div className={"flex min-h-[280px] animate-pulse items-center justify-center rounded-lg border border-slate-200 bg-slate-100 " + (className ?? "")}>
        <span className="text-sm text-slate-500">Cargando pipeline…</span>
      </div>
    );
  }

  if (data.length === 0) {
    return (
      <div className={"flex min-h-[280px] items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-sm text-slate-500 " + (className ?? "")}>
        No hay licitaciones en estudio
      </div>
    );
  }

  return (
    <div className={className}>
      <p className="mb-2 text-xs font-medium text-slate-500">Pipeline por categoría (bruto vs ajustado por riesgo)</p>
      <div className="h-[280px] w-full">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data} margin={{ top: 12, right: 12, left: 0, bottom: 0 }}>
            <CartesianGrid strokeDasharray="3 3" />
            <XAxis dataKey="category" tick={{ fontSize: 11 }} />
            <YAxis tick={{ fontSize: 11 }} tickFormatter={(v) => "€" + (v / 1000).toFixed(0) + "k"} />
            <Tooltip />
            <Legend />
            <Bar dataKey="pipeline_bruto" name="Pipeline bruto" fill="#64748b" radius={[2, 2, 0, 0]} />
            <Bar dataKey="pipeline_ajustado" name="Pipeline ajustado" fill="#059669" radius={[2, 2, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
}
