"use client";

import * as React from "react";
import Link from "next/link";
import { HelpCircle } from "lucide-react";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { AnalyticsService } from "@/services/api";
import type { DashboardKPIs, TimelineItem } from "@/types/api";

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 0,
  }).format(value);
}

function formatDate(s: string | null | undefined) {
  if (!s) return "";
  const d = new Date(s + "T00:00:00");
  return isNaN(d.getTime()) ? "" : d.toLocaleDateString("es-ES", { day: "2-digit", month: "short", year: "numeric" });
}

function formatDateShort(s: string | null | undefined) {
  if (!s) return "";
  const d = new Date(s + "T00:00:00");
  if (isNaN(d.getTime())) return "";
  const day = d.getDate();
  const month = d.getMonth() + 1;
  const year = String(d.getFullYear()).slice(-2);
  return `${day}/${month}/${year}`;
}

function SkeletonCard() {
  return (
    <Card>
      <CardHeader>
        <div className="h-4 w-24 animate-pulse rounded bg-slate-200" />
      </CardHeader>
      <CardContent>
        <div className="h-8 w-20 animate-pulse rounded bg-slate-100" />
      </CardContent>
    </Card>
  );
}

function KpiWithHelp({
  title,
  value,
  help,
  className,
}: {
  title: string;
  value: React.ReactNode;
  help: string;
  className?: string;
}) {
  return (
    <Card className={className}>
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-1">
        <CardTitle className="text-sm font-medium text-slate-600">{title}</CardTitle>
        <span
          className="text-slate-400 transition-colors hover:text-slate-600"
          title={help}
          role="img"
          aria-label="Explicación"
        >
          <HelpCircle className="h-4 w-4" />
        </span>
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-semibold text-slate-900">{value}</div>
      </CardContent>
    </Card>
  );
}

function TimelineChart({ items }: { items: TimelineItem[] }) {
  if (items.length === 0) {
    return (
      <p className="py-8 text-center text-sm text-slate-500">
        No hay licitaciones con fecha de adjudicación y finalización para mostrar el timeline.
      </p>
    );
  }

  const displayItems = items.slice(0, 24);
  const dates = displayItems.flatMap((i) => {
    const a = i.fecha_adjudicacion ? new Date(i.fecha_adjudicacion + "T00:00:00").getTime() : null;
    const f = i.fecha_finalizacion ? new Date(i.fecha_finalizacion + "T00:00:00").getTime() : null;
    return [a, f].filter((x): x is number => typeof x === "number" && !Number.isNaN(x));
  });
  const minT = Math.min(...dates);
  const maxT = Math.max(...dates);
  const range = maxT - minT || 1;

  const minDate = new Date(minT);
  const maxDate = new Date(maxT);
  const minYear = minDate.getFullYear();
  const maxYear = maxDate.getFullYear();
  const quarters: { label: string; xPercent: number }[] = [];
  for (let y = minYear; y <= maxYear; y++) {
    for (let q = 1; q <= 4; q++) {
      const qStart = new Date(y, (q - 1) * 3, 1).getTime();
      if (qStart >= minT && qStart <= maxT) {
        quarters.push({
          label: `Q${q}`,
          xPercent: ((qStart - minT) / range) * 100,
        });
      }
    }
  }
  const yearLabel = minYear === maxYear ? `${minYear}` : `${minYear} - ${maxYear}`;

  return (
    <div className="w-full overflow-x-auto">
      <h3 className="mb-4 text-center text-xl font-semibold tracking-tight text-slate-800">
        TIMELINE
      </h3>

      <div className="min-w-[640px]">
        {/* Eje izquierdo: nombres + área de barras con fechas */}
        <div className="border-b border-slate-200">
          {displayItems.map((item) => {
            const t0 = item.fecha_adjudicacion
              ? new Date(item.fecha_adjudicacion + "T00:00:00").getTime()
              : minT;
            const t1 = item.fecha_finalizacion
              ? new Date(item.fecha_finalizacion + "T00:00:00").getTime()
              : maxT;
            const left = ((t0 - minT) / range) * 100;
            const width = ((t1 - t0) / range) * 100;
            return (
              <div
                key={item.id_licitacion}
                className="flex items-center gap-2 border-t border-slate-100 py-1.5 first:border-t-0"
              >
                <div
                  className="w-40 shrink-0 truncate text-xs font-medium text-slate-800"
                  title={item.nombre}
                >
                  {item.nombre}
                </div>
                <div className="flex min-w-0 flex-1 items-center gap-2">
                  <span className="w-14 shrink-0 text-right text-[11px] text-slate-600">
                    {formatDateShort(item.fecha_adjudicacion ?? undefined)}
                  </span>
                  <div className="relative h-6 flex-1 overflow-hidden rounded-sm bg-slate-100">
                    {/* Cuadrícula por trimestres */}
                    {quarters.map((q) => (
                      <div
                        key={`${item.id_licitacion}-${q.label}-${q.xPercent}`}
                        className="absolute top-0 bottom-0 w-px bg-slate-200/80"
                        style={{ left: `${q.xPercent}%` }}
                      />
                    ))}
                    <div
                      className="absolute inset-y-0 rounded-sm bg-teal-600 shadow-sm"
                      style={{
                        left: `${left}%`,
                        width: `${Math.max(width, 1)}%`,
                        boxShadow: "0 1px 2px rgba(0,0,0,0.08)",
                      }}
                      title={`${formatDate(item.fecha_adjudicacion ?? undefined)} → ${formatDate(item.fecha_finalizacion ?? undefined)}`}
                    />
                  </div>
                  <span className="w-14 shrink-0 text-left text-[11px] text-slate-600">
                    {formatDateShort(item.fecha_finalizacion ?? undefined)}
                  </span>
                </div>
              </div>
            );
          })}
        </div>

        {/* Eje temporal inferior: escala por trimestres */}
        <div className="relative mt-3 flex pb-6">
          <div className="w-40 shrink-0" />
          <div className="relative h-8 flex-1">
            {/* Barra de trimestres (estilo Gantt) */}
            <div className="absolute inset-x-0 bottom-0 h-6 rounded-sm bg-teal-100">
              {quarters.map((q) => (
                <span
                  key={`label-${q.label}-${q.xPercent}`}
                  className="absolute text-[10px] font-medium text-teal-800"
                  style={{ left: `${q.xPercent}%`, transform: "translateX(2px)", bottom: "4px" }}
                >
                  {q.label}
                </span>
              ))}
            </div>
            {/* Año(s) en los extremos */}
            <span className="absolute -bottom-5 left-0 text-[10px] font-semibold text-slate-500">
              {yearLabel}
            </span>
            <span className="absolute -bottom-5 right-0 text-[10px] font-semibold text-slate-500">
              {yearLabel}
            </span>
          </div>
        </div>
      </div>

      {items.length > 24 && (
        <p className="mt-3 text-center text-xs text-slate-500">
          Mostrando 24 de {items.length} licitaciones
        </p>
      )}
    </div>
  );
}

export default function Home() {
  const [kpis, setKpis] = React.useState<DashboardKPIs | null>(null);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [fechaDesde, setFechaDesde] = React.useState<string>("");
  const [fechaHasta, setFechaHasta] = React.useState<string>("");

  React.useEffect(() => {
    let cancelled = false;
    setLoading(true);
    const filters =
      fechaDesde || fechaHasta
        ? {
            fecha_adjudicacion_desde: fechaDesde || undefined,
            fecha_adjudicacion_hasta: fechaHasta || undefined,
          }
        : undefined;
    AnalyticsService.getKpis(filters)
      .then((data) => {
        if (!cancelled) {
          setKpis(data);
          setError(null);
        }
      })
      .catch((e) => {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : "No se pudieron cargar los KPIs");
          setKpis(null);
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [fechaDesde, fechaHasta]);

  if (loading) {
    return (
      <div className="flex flex-1 flex-col gap-8">
        <header className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Dashboard</h1>
            <p className="mt-1 text-sm text-slate-500">Indicadores y timeline de licitaciones.</p>
          </div>
        </header>
        <section className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
        </section>
        <p className="text-sm text-slate-500">Cargando datos…</p>
      </div>
    );
  }

  if (error || !kpis) {
    return (
      <div className="flex flex-1 flex-col gap-8">
        <h1 className="text-2xl font-semibold text-slate-900">Dashboard</h1>
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          No se pudieron cargar los KPIs. Comprueba que el backend esté en marcha.
          {error && <p className="mt-2 font-medium">Detalle: {error}</p>}
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-1 flex-col gap-8">
      <header className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Dashboard</h1>
          <p className="mt-1 text-sm text-slate-500">
            Oportunidades, ofertado, ratios y timeline por adjudicación–finalización.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2">
            <span className="text-xs font-medium text-slate-600">Filtro por fecha adjudicación</span>
            <input
              type="date"
              value={fechaDesde}
              onChange={(e) => setFechaDesde(e.target.value)}
              className="h-8 rounded border border-slate-200 bg-white px-2 text-sm text-slate-800"
              title="Desde (incluido)"
            />
            <span className="text-slate-400">—</span>
            <input
              type="date"
              value={fechaHasta}
              onChange={(e) => setFechaHasta(e.target.value)}
              className="h-8 rounded border border-slate-200 bg-white px-2 text-sm text-slate-800"
              title="Hasta (incluido)"
            />
            {(fechaDesde || fechaHasta) && (
              <button
                type="button"
                onClick={() => {
                  setFechaDesde("");
                  setFechaHasta("");
                }}
                className="text-xs font-medium text-slate-500 underline hover:text-slate-700"
              >
                Quitar filtro
              </button>
            )}
          </div>
          <Link
            href="/licitaciones"
            className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            Ver licitaciones
          </Link>
        </div>
      </header>

      {/* Timeline */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base font-medium text-slate-800">Timeline</CardTitle>
          <p className="text-xs text-slate-500">
            Cada barra representa una licitación desde la fecha de adjudicación hasta la fecha de finalización.
          </p>
        </CardHeader>
        <CardContent>
          <TimelineChart items={kpis.timeline} />
        </CardContent>
      </Card>

      {/* KPIs */}
      <section>
        <h2 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">
          Indicadores
        </h2>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          <KpiWithHelp
            title="Total oportunidades (nº)"
            value={kpis.total_oportunidades_uds ?? 0}
            help="Suma de todas las licitaciones registradas (número de expedientes)."
          />
          <KpiWithHelp
            title="Total oportunidades (€)"
            value={formatEuro(Number(kpis.total_oportunidades_euros ?? 0))}
            help="Suma de los presupuestos máximos (pres_maximo) de todas las licitaciones registradas."
          />
          <KpiWithHelp
            title="Total ofertado (nº)"
            value={kpis.total_ofertado_uds ?? 0}
            help="Mismo concepto que oportunidades pero solo licitaciones en estado: Adjudicada, No Adjudicada, Presentada, Terminada."
          />
          <KpiWithHelp
            title="Total ofertado (€)"
            value={formatEuro(Number(kpis.total_ofertado_euros ?? 0))}
            help="Suma de presupuestos máximos solo de licitaciones Adjudicada, No Adjudicada, Presentada, Terminada."
          />
          <KpiWithHelp
            title="Ratio ofertado/oportunidades (uds)"
            value={`${(kpis.ratio_ofertado_oportunidades_uds ?? 0).toFixed(1)} %`}
            help="(Total ofertado en nº / Total oportunidades en nº) × 100."
          />
          <KpiWithHelp
            title="Ratio ofertado/oportunidades (€)"
            value={`${(kpis.ratio_ofertado_oportunidades_euros ?? 0).toFixed(1)} %`}
            help="(Total ofertado en € / Total oportunidades en €) × 100."
          />
          <KpiWithHelp
            title="Ratio (Adj.+Term.)/Total ofertado"
            value={`${(kpis.ratio_adjudicadas_terminadas_ofertado ?? 0).toFixed(1)} %`}
            help="(Adjudicadas + Terminadas) / Total ofertado × 100 (en número de licitaciones)."
          />
          <KpiWithHelp
            title="Ratio adjudicación"
            value={(kpis.ratio_adjudicacion ?? 0).toFixed(2)}
            help="(Adjudicadas + Terminadas) / (Adjudicadas + No Adjudicadas + Terminadas). Valor entre 0 y 1."
          />
          <KpiWithHelp
            title="Margen medio ponderado (presup.)"
            value={
              kpis.margen_medio_ponderado_presupuestado != null && kpis.margen_medio_ponderado_presupuestado !== undefined
                ? `${Number(kpis.margen_medio_ponderado_presupuestado).toFixed(1)} %`
                : "—"
            }
            help="Margen medio ponderado por venta en licitaciones Adjudicadas y Terminadas, usando datos presupuestados (partidas: pvu, pcu, unidades). Fórmula: (Σ beneficio presup.) / (Σ venta presup.) × 100."
          />
          <KpiWithHelp
            title="Margen medio ponderado (real)"
            value={
              kpis.margen_medio_ponderado_real != null && kpis.margen_medio_ponderado_real !== undefined
                ? `${Number(kpis.margen_medio_ponderado_real).toFixed(1)} %`
                : "—"
            }
            help="Margen medio ponderado por venta en licitaciones Adjudicadas y Terminadas, usando datos reales (entregas/albaranes). Fórmula: (Σ beneficio real) / (Σ venta real) × 100."
          />
          <KpiWithHelp
            title="% descartadas (uds)"
            value={
              kpis.pct_descartadas_uds != null && kpis.pct_descartadas_uds !== undefined
                ? `${Number(kpis.pct_descartadas_uds).toFixed(1)} %`
                : "—"
            }
            help="% descartadas = (número de licitaciones descartadas) / (total licitaciones − análisis − valoración) × 100."
          />
          <KpiWithHelp
            title="% descartadas (€)"
            value={
              kpis.pct_descartadas_euros != null && kpis.pct_descartadas_euros !== undefined
                ? `${Number(kpis.pct_descartadas_euros).toFixed(1)} %`
                : "—"
            }
            help="% descartadas en importe = (suma presupuestos de descartadas) / (suma presupuestos de licitaciones que no están en análisis ni valoración) × 100."
          />
        </div>
      </section>
    </div>
  );
}
