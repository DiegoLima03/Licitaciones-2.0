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

/** Tarjeta KPI que muestra dos valores (uds | euros) separados por una barra */
function KpiDualWithHelp({
  title,
  valueUds,
  valueEuros,
  help,
  className,
}: {
  title: string;
  valueUds: React.ReactNode;
  valueEuros: React.ReactNode;
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
        <div className="flex flex-wrap items-baseline gap-3 text-2xl font-semibold text-slate-900">
          <span>{valueUds}</span>
          <span className="h-6 w-px bg-slate-300" aria-hidden />
          <span>{valueEuros}</span>
        </div>
      </CardContent>
    </Card>
  );
}

function parseTimelineDate(s: string | null | undefined): number | null {
  if (!s || typeof s !== "string" || !s.trim()) return null;
  const t = new Date(s.trim() + "T00:00:00").getTime();
  return Number.isNaN(t) ? null : t;
}

/** Meses en español abreviados para el eje de fechas */
const MESES = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];

function TimelineChart({ items }: { items: TimelineItem[] }) {
  const displayItems = React.useMemo(() => items.slice(0, 24), [items]);
  const itemsWithDates = React.useMemo(
    () =>
      displayItems.filter((i) => {
        const t0 = parseTimelineDate(i.fecha_adjudicacion);
        const t1 = parseTimelineDate(i.fecha_finalizacion);
        return t0 != null && t1 != null && t1 >= t0;
      }),
    [displayItems]
  );

  const dates = itemsWithDates.flatMap((i) => {
    const a = parseTimelineDate(i.fecha_adjudicacion);
    const f = parseTimelineDate(i.fecha_finalizacion);
    return [a, f].filter((x): x is number => typeof x === "number");
  });
  const minT = dates.length ? Math.min(...dates) : Date.now();
  const maxT = dates.length ? Math.max(...dates) : Date.now();
  const range = maxT - minT || 1;

  const minDate = new Date(minT);
  const maxDate = new Date(maxT);

  /** Ticks del eje de fechas: escalado para no saturar (1, 2 o 3 meses según rango) */
  const dateTicks = React.useMemo(() => {
    const ticks: { ts: number; label: string }[] = [];
    const monthsDiff = (maxDate.getFullYear() - minDate.getFullYear()) * 12 + (maxDate.getMonth() - minDate.getMonth()) + 1;
    const step = monthsDiff > 18 ? 3 : monthsDiff > 9 ? 2 : 1;
    const start = new Date(minDate.getFullYear(), minDate.getMonth(), 1);
    const end = new Date(maxDate.getFullYear(), maxDate.getMonth() + 1, 0);
    let cur = new Date(start);
    while (cur <= end) {
      const ts = cur.getTime();
      if (ts >= minT && ts <= maxT) {
        const year = cur.getFullYear();
        const shortYear = String(year).slice(-2);
        const monthCount = (cur.getFullYear() - minDate.getFullYear()) * 12 + (cur.getMonth() - minDate.getMonth());
        if (monthCount % step === 0) {
          ticks.push({
            ts,
            label: `${MESES[cur.getMonth()]} ${shortYear}`,
          });
        }
      }
      cur.setMonth(cur.getMonth() + 1);
    }
    if (ticks.length === 0) {
      ticks.push({ ts: minT, label: MESES[minDate.getMonth()] + " " + String(minDate.getFullYear()).slice(-2) });
    }
    return ticks;
  }, [minT, maxT, minDate, maxDate]);

  if (displayItems.length === 0) {
    return (
      <p className="py-8 text-center text-sm text-slate-500">
        No hay licitaciones para mostrar en el timeline.
      </p>
    );
  }

  const barHeight = 28;
  const axisLabelHeight = 40;

  return (
    <div className="w-full overflow-x-auto">
      <div className="min-w-[560px]">
        {/* Layout tipo Gantt: lateral = licitaciones, abajo = fechas */}
        <div className="flex">
          {/* Columna lateral: nombres de licitaciones */}
          <div className="flex shrink-0 flex-col border-r border-slate-200 pr-3">
            {displayItems.map((item, idx) => {
              const label = (item.nombre && String(item.nombre).trim()) || `Licitación ${item.id_licitacion}`;
              return (
                <div
                  key={`row-${item.id_licitacion}-${idx}`}
                  className="flex items-center border-b border-slate-100 py-1 last:border-b-0"
                  style={{ minHeight: barHeight }}
                >
                  <span
                    className="line-clamp-2 max-w-[200px] truncate text-xs font-medium text-slate-800"
                    title={label}
                  >
                    {label}
                  </span>
                </div>
              );
            })}
            {/* Espacio para el eje de fechas */}
            <div className="mt-2 flex items-end border-t border-slate-200 pt-2" style={{ minHeight: axisLabelHeight }}>
              <span className="text-[10px] font-medium uppercase tracking-wide text-slate-400">Licitaciones</span>
            </div>
          </div>

          {/* Área Gantt: barras + cuadrícula de fechas */}
          <div className="relative min-w-0 flex-1">
            {/* Línea roja "hoy" */}
            {(() => {
              const today = new Date();
              today.setHours(0, 0, 0, 0);
              const todayT = today.getTime();
              const inRange = todayT >= minT && todayT <= maxT;
              if (!inRange) return null;
              const leftPercent = ((todayT - minT) / range) * 100;
              return (
                <div
                  className="absolute top-0 z-10 w-1 bg-red-500 shadow-sm"
                  style={{
                    left: `${leftPercent}%`,
                    height: displayItems.length * (barHeight + 8),
                  }}
                  title={`Hoy: ${formatDate(today.toISOString().slice(0, 10))}`}
                />
              );
            })()}
            {/* Filas de barras */}
            {displayItems.map((item, idx) => {
              const t0 = parseTimelineDate(item.fecha_adjudicacion);
              const t1 = parseTimelineDate(item.fecha_finalizacion);
              const hasValidDates = t0 != null && t1 != null && t1 >= t0;

              return (
                <div
                  key={`bar-${item.id_licitacion}-${idx}`}
                  className="relative flex items-center border-b border-slate-100 py-1 last:border-b-0"
                  style={{ minHeight: barHeight }}
                >
                  {/* Líneas verticales de la cuadrícula (fechas) */}
                  {dateTicks.map((tick) => (
                    <div
                      key={`grid-${item.id_licitacion}-${tick.ts}`}
                      className="absolute top-0 bottom-0 w-px bg-slate-100"
                      style={{ left: `${((tick.ts - minT) / range) * 100}%` }}
                    />
                  ))}
                  {/* Barra de la licitación */}
                  <div className="absolute inset-x-0 inset-y-1 flex items-center">
                    {hasValidDates ? (
                      <div
                        className="absolute h-4 rounded bg-teal-600 shadow-sm transition-colors hover:bg-teal-700"
                        style={{
                          left: `${((t0! - minT) / range) * 100}%`,
                          width: `${Math.max(((t1! - t0!) / range) * 100, 2)}%`,
                        }}
                        title={`${formatDate(item.fecha_adjudicacion ?? undefined)} → ${formatDate(item.fecha_finalizacion ?? undefined)}`}
                      />
                    ) : (
                      <div className="absolute inset-x-2 flex h-4 items-center justify-center rounded bg-slate-100">
                        <span className="text-[10px] text-slate-400">
                          Sin fechas de adjudicación/finalización
                        </span>
                      </div>
                    )}
                  </div>
                </div>
              );
            })}

            {/* Eje de fechas abajo (estilo Gantt) */}
            <div
              className="relative mt-2 flex border-t border-slate-200 pt-2"
              style={{ minHeight: axisLabelHeight }}
            >
              {dateTicks.map((tick, i) => (
                <div
                  key={`grid-bot-${tick.ts}-${i}`}
                  className="absolute top-0 h-full border-l border-slate-200 first:border-l-0"
                  style={{
                    left: `${((tick.ts - minT) / range) * 100}%`,
                    width: 0,
                  }}
                />
              ))}
              {dateTicks.map((tick, i) => (
                <span
                  key={`label-${tick.ts}-${i}`}
                  className="absolute bottom-0 text-[10px] font-medium text-slate-600"
                  style={{
                    left: `${((tick.ts - minT) / range) * 100}%`,
                    transform: "translateX(-50%)",
                  }}
                >
                  {tick.label}
                </span>
              ))}
            </div>
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
          <KpiDualWithHelp
            title="Total oportunidades"
            valueUds={kpis.total_oportunidades_uds ?? 0}
            valueEuros={formatEuro(Number(kpis.total_oportunidades_euros ?? 0))}
            help="Nº: Suma de licitaciones registradas. €: Suma de presupuestos máximos (pres_maximo) de todas las licitaciones."
          />
          <KpiDualWithHelp
            title="Total ofertado"
            valueUds={kpis.total_ofertado_uds ?? 0}
            valueEuros={formatEuro(Number(kpis.total_ofertado_euros ?? 0))}
            help="Solo licitaciones Adjudicada, No Adjudicada, Presentada, Terminada. Nº: cantidad. €: suma de presupuestos máximos."
          />
          <KpiDualWithHelp
            title="Ratio ofertado/oportunidades"
            valueUds={`${(kpis.ratio_ofertado_oportunidades_uds ?? 0).toFixed(1)} %`}
            valueEuros={`${(kpis.ratio_ofertado_oportunidades_euros ?? 0).toFixed(1)} %`}
            help="(Total ofertado / Total oportunidades) × 100. En uds: por número de expedientes. En €: por importe de presupuestos."
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
          <KpiDualWithHelp
            title="% descartadas"
            valueUds={
              kpis.pct_descartadas_uds != null && kpis.pct_descartadas_uds !== undefined
                ? `${Number(kpis.pct_descartadas_uds).toFixed(1)} %`
                : "—"
            }
            valueEuros={
              kpis.pct_descartadas_euros != null && kpis.pct_descartadas_euros !== undefined
                ? `${Number(kpis.pct_descartadas_euros).toFixed(1)} %`
                : "—"
            }
            help="Uds: (nº licitaciones descartadas) / (total − análisis − valoración) × 100. €: mismo concepto aplicado a la suma de presupuestos."
          />
        </div>
      </section>
    </div>
  );
}
