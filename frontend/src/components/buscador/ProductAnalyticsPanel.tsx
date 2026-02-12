"use client";

import * as React from "react";
import { useQuery } from "@tanstack/react-query";
import { Bar, BarChart, XAxis, YAxis, ResponsiveContainer, Cell } from "recharts";
import { AnalyticsService } from "@/services/api";
import type { ProductAnalytics } from "@/types/api";
import { MaterialTrendChart } from "@/components/analytics/MaterialTrendChart";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { X } from "lucide-react";

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 2,
  }).format(value);
}

export function ProductAnalyticsPanel({
  productId,
  onClose,
}: {
  productId: number;
  onClose: () => void;
}) {
  const {
    data,
    isLoading,
    error,
    isFetching,
  } = useQuery({
    queryKey: ["product-analytics", productId],
    queryFn: () => AnalyticsService.getProductAnalytics(productId),
    enabled: productId > 0,
  });

  const trendBadge = React.useMemo(() => {
    if (!data || data.price_history.length < 2) return null;
    const values = data.price_history.map((p) => p.value);
    const last = values[values.length - 1];
    const avg = values.reduce((a, b) => a + b, 0) / values.length;
    const isUp = last >= avg;
    return { isUp, last, avg };
  }, [data]);

  return (
    <div className="flex h-full flex-col overflow-hidden bg-slate-900 text-slate-100">
      <header className="shrink-0 border-b border-slate-700 px-6 py-4">
        <div className="flex items-start justify-between gap-2">
          <div>
            <h2 className="text-lg font-semibold text-slate-100">
              {data?.product_name ?? "Cargando…"}
            </h2>
            {data && (
              <p className="mt-1 text-xs text-slate-400">
                ID producto: {data.product_id}
              </p>
            )}
          </div>
          <Button
            variant="ghost"
            size="icon"
            className="text-slate-400 hover:bg-slate-800 hover:text-slate-100"
            onClick={onClose}
          >
            <X className="h-5 w-5" />
          </Button>
        </div>
      </header>

      <div className="flex-1 overflow-y-auto px-6 pb-6">
        {isLoading || isFetching ? (
          <div className="space-y-4">
            <Skeleton className="h-6 w-48 bg-slate-700" />
            <Skeleton className="h-[280px] w-full bg-slate-700" />
            <Skeleton className="h-8 w-32 bg-slate-700" />
            <Skeleton className="h-[200px] w-full bg-slate-700" />
          </div>
        ) : error ? (
          <p className="py-6 text-sm text-red-400">
            {error instanceof Error ? error.message : "Error al cargar analíticas"}
          </p>
        ) : data ? (
          <ProductAnalyticsContent data={data} trendBadge={trendBadge} />
        ) : null}
      </div>
    </div>
  );
}

function ProductAnalyticsContent({
  data,
  trendBadge,
}: {
  data: ProductAnalytics;
  trendBadge: { isUp: boolean; last: number; avg: number } | null;
}) {
  const barData = React.useMemo(() => {
    const items: { name: string; valor: number; fill: string }[] = [];
    const precioMedio =
      data.price_history.length > 0
        ? data.price_history.reduce((a, p) => a + p.value, 0) / data.price_history.length
        : 0;
    if (data.precio_referencia_medio != null) {
      items.push({ name: "Ref. media", valor: data.precio_referencia_medio, fill: "#64748b" });
    }
    items.push({ name: "Adj. real", valor: precioMedio, fill: "#10b981" });
    return items;
  }, [data]);

  return (
    <div className="space-y-6">
      {trendBadge && (
        <div className="flex items-center gap-2">
          <span className="text-xs text-slate-400">Tendencia vs media:</span>
          <Badge
            variant={trendBadge.isUp ? "success" : "destructive"}
            className={trendBadge.isUp ? "bg-emerald-600 text-white" : "bg-red-600 text-white"}
          >
            {trendBadge.isUp ? "↑ Subiendo" : "↓ Bajando"}
          </Badge>
          <span className="text-xs text-slate-500">
            Último {formatEuro(trendBadge.last)} · Media {formatEuro(trendBadge.avg)}
          </span>
        </div>
      )}

      <section>
        <h3 className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">
          Evolución del precio (TradingView)
        </h3>
        <div className="rounded-lg border border-slate-700 bg-slate-800/50 p-2">
          <MaterialTrendChart
            data={{ pvu: data.price_history ?? [], pcu: [] }}
            materialName={data.product_name}
            isLoading={false}
            error={null}
          />
        </div>
      </section>

      <section>
        <h3 className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">
          Métricas de volumen
        </h3>
        <div className="grid grid-cols-2 gap-3">
          <div className="rounded-lg border border-slate-700 bg-slate-800/50 p-3">
            <p className="text-xs text-slate-500">Total licitado</p>
            <p className="text-lg font-semibold text-emerald-400">
              {formatEuro(data.volume_metrics.total_licitado)}
            </p>
          </div>
          <div className="rounded-lg border border-slate-700 bg-slate-800/50 p-3">
            <p className="text-xs text-slate-500">Oferentes promedio</p>
            <p className="text-lg font-semibold text-slate-200">
              {data.volume_metrics.cantidad_oferentes_promedio.toFixed(1)}
            </p>
          </div>
        </div>
      </section>

      {data.competitor_analysis.length > 0 && (
        <section>
          <h3 className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">
            Top competidores
          </h3>
          <ul className="space-y-2">
            {data.competitor_analysis.map((c, i) => (
              <li
                key={c.empresa}
                className="flex items-center justify-between rounded-lg border border-slate-700 bg-slate-800/50 px-3 py-2"
              >
                <span className="text-sm font-medium text-slate-200">
                  {i + 1}. {c.empresa}
                </span>
                <span className="text-sm text-emerald-400">
                  {formatEuro(c.precio_medio)} · {c.cantidad_adjudicaciones} adj.
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section>
        <h3 className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">
          Precio de referencia vs adjudicación real
        </h3>
        <div className="h-[200px] rounded-lg border border-slate-700 bg-slate-800/50 p-2">
          {barData.length > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={barData} margin={{ top: 8, right: 8, left: 8, bottom: 8 }}>
                <XAxis dataKey="name" tick={{ fill: "#94a3b8", fontSize: 11 }} />
                <YAxis tick={{ fill: "#94a3b8", fontSize: 11 }} tickFormatter={(v) => `€${v.toFixed(2)}`} />
                <Bar dataKey="valor" radius={4}>
                  {barData.map((entry, index) => (
                    <Cell key={`cell-${index}`} fill={entry.fill} />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          ) : (
            <div className="flex h-full items-center justify-center text-xs text-slate-500">
              Sin datos para comparar
            </div>
          )}
        </div>
      </section>

      {data.forecast != null && (
        <div className="rounded-lg border border-slate-700 bg-slate-800/50 p-3">
          <p className="text-xs text-slate-500">Proyección (MA)</p>
          <p className="text-xl font-semibold text-amber-400">{formatEuro(data.forecast)}</p>
        </div>
      )}
    </div>
  );
}
