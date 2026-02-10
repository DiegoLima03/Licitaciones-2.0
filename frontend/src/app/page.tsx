"use client";

import * as React from "react";
import { ArrowUpRight, TrendingUp } from "lucide-react";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

/** Coincide con la respuesta GET /api/analytics/kpis del backend */
export interface DashboardKPIs {
  total_count: number;
  pipeline_monto: number;
  adjudicado_monto: number;
  win_rate: number;
  total_monto_historico: number;
  df_mensual?: Record<string, number>;
  df_tipos?: Record<string, number>;
  df_timeline?: Record<string, unknown>[];
}

const API_BASE =
  typeof window !== "undefined"
    ? (process.env.NEXT_PUBLIC_API_URL ||
        `${window.location.protocol}//${window.location.hostname}:8000`)
    : process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000";

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 0,
  }).format(value);
}

function SkeletonCard() {
  return (
    <Card>
      <CardHeader>
        <div className="h-4 w-24 animate-pulse rounded bg-slate-200" />
      </CardHeader>
      <CardContent>
        <div className="h-8 w-20 animate-pulse rounded bg-slate-100" />
        <div className="mt-2 h-3 w-28 animate-pulse rounded bg-slate-100" />
      </CardContent>
    </Card>
  );
}

export default function Home() {
  const [kpis, setKpis] = React.useState<DashboardKPIs | null>(null);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);

  React.useEffect(() => {
    let cancelled = false;

    async function fetchKpis() {
      try {
        const res = await fetch(`${API_BASE}/api/analytics/kpis`);
        if (!res.ok) {
          throw new Error(`Error ${res.status}`);
        }
        const data: DashboardKPIs = await res.json();
        if (!cancelled) {
          setKpis(data);
          setError(null);
        }
      } catch (err) {
        if (!cancelled) {
          setError(
            err instanceof Error ? err.message : "No se pudieron cargar los KPIs"
          );
          setKpis(null);
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    fetchKpis();
    return () => {
      cancelled = true;
    };
  }, []);

  if (loading) {
    return (
      <div className="flex flex-1 flex-col gap-8">
        <header className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
              Dashboard general
            </h1>
            <p className="mt-1 text-sm text-slate-500">
              Visión rápida del pipeline de licitaciones y cartera adjudicada.
            </p>
          </div>
          <div className="h-8 w-24 animate-pulse rounded-full bg-slate-200" />
        </header>

        <section className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
        </section>

        <p className="text-sm text-slate-500">Cargando datos del backend…</p>
      </div>
    );
  }

  if (error || !kpis) {
    return (
      <div className="flex flex-1 flex-col gap-8">
        <h1 className="text-2xl font-semibold text-slate-900">
          Dashboard general
        </h1>
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          No se pudieron cargar los KPIs. Comprueba que el backend esté en marcha
          en {API_BASE} y que el endpoint GET /api/analytics/kpis responda.
          {error && (
            <p className="mt-2 font-medium">Detalle: {error}</p>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-1 flex-col gap-8">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
            Dashboard general
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Visión rápida del pipeline de licitaciones y cartera adjudicada.
          </p>
        </div>
        <div className="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
          <TrendingUp className="h-4 w-4" />
          Win Rate {kpis.win_rate.toFixed(1)}%
        </div>
      </header>

      <section className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader>
            <CardTitle>Expedientes</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex items-baseline justify-between">
              <p className="text-3xl font-semibold text-slate-900">
                {kpis.total_count}
              </p>
              <span className="text-xs text-slate-500">Total registrados</span>
            </div>
          </CardContent>
        </Card>

        <Card className="border-amber-100 bg-amber-50/60">
          <CardHeader>
            <CardTitle className="text-amber-900">Pipeline</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex items-baseline justify-between">
              <p className="text-2xl font-semibold text-amber-900">
                {formatEuro(kpis.pipeline_monto)}
              </p>
              <span className="text-xs text-amber-700">
                En Estudio, Presentada, Pendiente…
              </span>
            </div>
          </CardContent>
        </Card>

        <Card className="border-emerald-100 bg-emerald-50/60">
          <CardHeader>
            <CardTitle className="text-emerald-900">Adjudicado</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex items-baseline justify-between">
              <p className="text-2xl font-semibold text-emerald-900">
                {formatEuro(kpis.adjudicado_monto)}
              </p>
              <span className="text-xs text-emerald-700">
                Cartera ya ganada
              </span>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Win Rate</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex items-baseline justify-between">
              <p className="text-3xl font-semibold text-slate-900">
                {kpis.win_rate.toFixed(1)}%
              </p>
              <span className="text-xs text-slate-500">
                Adjudicadas / Total
              </span>
            </div>
          </CardContent>
        </Card>
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
        <p>
          Datos calculados por el backend (Python) a partir de{" "}
          <code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs">
            tbl_licitaciones
          </code>{" "}
          y estados: Pipeline = En Estudio, Presentada, Pendiente de Fallo,
          Pendiente; Adjudicado = Adjudicada.
        </p>
      </section>
    </div>
  );
}
