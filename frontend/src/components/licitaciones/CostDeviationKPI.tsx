"use client";

import * as React from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { TrendingDown, TrendingUp } from "lucide-react";

export interface CostDeviationKPIProps {
  costePresupuestado: number | null | undefined;
  costeReal: number | null | undefined;
  gastosExtraordinarios?: number | null;
  className?: string;
}

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 0,
    minimumFractionDigits: 0,
  }).format(value);
}

export function CostDeviationKPI({
  costePresupuestado,
  costeReal,
  gastosExtraordinarios = null,
  className,
}: CostDeviationKPIProps) {
  const presu = costePresupuestado ?? 0;
  const real = costeReal ?? 0;
  const extra = gastosExtraordinarios ?? 0;
  const realTotal = real + extra;
  const hasPresu = presu > 0;
  const hasReal = realTotal > 0;
  const hasData = hasPresu || hasReal;
  const deviationPct = hasPresu && realTotal > 0 ? ((realTotal - presu) / presu) * 100 : null;
  const isOver = deviationPct != null && deviationPct > 0;
  const isUnder = deviationPct != null && deviationPct < 0;

  if (!hasData) return null;

  return (
    <Card className={className}>
      <CardHeader className="pb-1">
        <CardTitle className="text-sm font-medium text-slate-600">Desviación de coste</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-wrap items-center gap-4">
        <div className="flex items-center gap-2">
          <span className="text-xs text-slate-500">Presupuestado</span>
          <span className="font-semibold text-slate-800">{formatEuro(presu)}</span>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-xs text-slate-500">Real / histórico</span>
          <span className="font-semibold text-slate-800">{formatEuro(realTotal)}</span>
          {extra > 0 && <span className="text-xs text-slate-500">(+ {formatEuro(extra)} extra)</span>}
        </div>
        {deviationPct != null && (
          <div
            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-sm font-medium ${
              isOver ? "bg-red-100 text-red-800" : isUnder ? "bg-emerald-100 text-emerald-800" : "bg-slate-100 text-slate-700"
            }`}
          >
            {isOver && <TrendingUp className="h-3.5 w-3.5" />}
            {isUnder && <TrendingDown className="h-3.5 w-3.5" />}
            <span>{isOver ? "+" : ""}{deviationPct.toFixed(1)}% desviación</span>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
