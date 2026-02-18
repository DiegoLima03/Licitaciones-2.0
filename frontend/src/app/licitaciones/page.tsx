"use client";

import * as React from "react";
import Link from "next/link";
import { AlertTriangle, Search } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { CreateTenderDialog } from "@/components/licitaciones/create-tender-dialog";
import { EstadosService, TendersService } from "@/services/api";
import type { Estado, PaisLicitacion, Tender } from "@/types/api";

const PAISES_FILTRO: { value: "" | PaisLicitacion; label: string }[] = [
  { value: "", label: "Todos los países" },
  { value: "España", label: "🇪🇸 España" },
  { value: "Portugal", label: "🇵🇹 Portugal" },
];

const ESTADO_COLOR_CLASSES = [
  "bg-sky-100 text-sky-800 border-sky-200",      // info
  "bg-amber-100 text-amber-800 border-amber-200",  // warning
  "bg-emerald-100 text-emerald-800 border-emerald-200", // success
  "bg-rose-100 text-rose-800 border-rose-200",   // destructive
  "bg-slate-100 text-slate-800 border-slate-200", // default
  "bg-violet-100 text-violet-800 border-violet-200", // extra
] as const;

function getEstadoColorClass(idEstado: number, estados: Estado[]): string {
  const idx = estados.findIndex((e) => e.id_estado === idEstado);
  if (idx >= 0 && idx < ESTADO_COLOR_CLASSES.length) return ESTADO_COLOR_CLASSES[idx];
  return ESTADO_COLOR_CLASSES[4];
}

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 0,
  }).format(value);
}

/** EN ANÁLISIS = id 3 (alineado con backend EstadoLicitacion.EN_ANALISIS) */
const ID_ESTADO_EN_ANALISIS = 3;

function getUrgencyInfo(lic: Tender): { urgent: boolean; deadline: string | null } {
  if (lic.id_estado !== ID_ESTADO_EN_ANALISIS) return { urgent: false, deadline: null };
  const fPresentacion = lic.fecha_presentacion;
  if (!fPresentacion || typeof fPresentacion !== "string") return { urgent: false, deadline: null };
  const fecha = new Date(fPresentacion.split("T")[0]);
  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);
  fecha.setHours(0, 0, 0, 0);
  const diffMs = fecha.getTime() - hoy.getTime();
  const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24));
  const isUrgent = diffDays >= 0 && diffDays <= 5;
  return {
    urgent: isUrgent,
    deadline: isUrgent ? fPresentacion.split("T")[0] : null,
  };
}

function formatFechaCorta(isoDate: string): string {
  const [y, m, d] = isoDate.split("-");
  return `${d}/${m}/${y}`;
}

export default function LicitacionesPage() {
  const [data, setData] = React.useState<Tender[]>([]);
  const [estados, setEstados] = React.useState<Estado[]>([]);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [searchNombre, setSearchNombre] = React.useState("");
  const [filterEstadoId, setFilterEstadoId] = React.useState<number | "">("");
  const [filterPais, setFilterPais] = React.useState<"" | PaisLicitacion>("");

  React.useEffect(() => {
    EstadosService.getAll().then(setEstados).catch(() => setEstados([]));
  }, []);

  const fetchLicitaciones = React.useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const list = await TendersService.getAll({
        nombre: searchNombre.trim() || undefined,
        estado_id:
          filterEstadoId !== "" && Number.isFinite(Number(filterEstadoId))
            ? Number(filterEstadoId)
            : undefined,
        pais: filterPais || undefined,
      });
      setData(list);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Error al cargar licitaciones");
      setData([]);
    } finally {
      setLoading(false);
    }
  }, [searchNombre, filterEstadoId, filterPais]);

  React.useEffect(() => {
    fetchLicitaciones();
  }, [fetchLicitaciones]);

  return (
    <div className="flex flex-1 flex-col gap-6">
      <header className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
            Mis licitaciones
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Gestión rápida del pipeline: estados, presupuesto y detalle.
          </p>
        </div>
        <CreateTenderDialog triggerLabel="Nueva Licitación" onSuccess={fetchLicitaciones} />
      </header>

      <Card>
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-4">
          <CardTitle className="text-sm font-medium text-slate-800">
            Listado de licitaciones
          </CardTitle>
          <div className="flex flex-wrap items-center gap-3">
            <select
              value={filterPais}
              onChange={(e) => setFilterPais(e.target.value as "" | PaisLicitacion)}
              className="h-9 min-w-[140px] rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"
              title="Filtrar por país"
            >
              {PAISES_FILTRO.map((opt) => (
                <option key={opt.value || "todos"} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
            <select
              value={filterEstadoId}
              onChange={(e) => {
                const v = e.target.value;
                setFilterEstadoId(v === "" ? "" : Number(v));
              }}
              className="h-9 min-w-[160px] rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"
              title="Filtrar por estado"
            >
              <option value="">Todos los estados</option>
              {estados.map((est) => (
                <option key={est.id_estado} value={est.id_estado}>
                  {est.nombre_estado}
                </option>
              ))}
            </select>
            <div className="relative w-full max-w-xs">
              <Search className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
              <input
                type="text"
                value={searchNombre}
                onChange={(e) => setSearchNombre(e.target.value)}
                onBlur={() => fetchLicitaciones()}
                onKeyDown={(e) => e.key === "Enter" && fetchLicitaciones()}
                placeholder="Buscar por nombre o expediente…"
                className="h-9 w-full rounded-lg border border-slate-200 bg-white pl-8 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
              />
            </div>
          </div>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          {error && (
            <p className="mb-3 text-sm text-red-600">{error}</p>
          )}
          {loading ? (
            <p className="py-6 text-sm text-slate-500">Cargando licitaciones…</p>
          ) : (
            <table className="min-w-full text-left text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <th className="py-2 pr-4">Expediente</th>
                  <th className="py-2 pr-4">Nombre proyecto</th>
                  <th className="py-2 pr-4">F. Presentación</th>
                  <th className="py-2 pr-4">Estado</th>
                  <th className="py-2 pr-4 text-right">Presupuesto (€)</th>
                </tr>
              </thead>
              <tbody>
                {data.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="py-6 text-center text-sm text-slate-500">
                      No hay licitaciones. Crea una o ajusta el filtro.
                    </td>
                  </tr>
                ) : (
                  data.map((lic) => {
                    const { urgent } = getUrgencyInfo(lic);
                    return (
                    <tr
                      key={lic.id_licitacion}
                      className={`border-b border-slate-100 last:border-0 hover:bg-slate-50 ${urgent ? "animate-pulse bg-red-50" : ""}`}
                    >
                      <td className="py-2 pr-4 text-xs font-medium text-slate-500">
                        <Link
                          href={`/licitaciones/${lic.id_licitacion}`}
                          className="hover:underline"
                        >
                          {lic.numero_expediente ?? "—"}
                        </Link>
                      </td>
                      <td className="max-w-xs py-2 pr-4 text-sm font-medium text-slate-900">
                        <Link
                          href={`/licitaciones/${lic.id_licitacion}`}
                          className="hover:underline"
                        >
                          {lic.nombre}
                        </Link>
                      </td>
                      <td className="py-2 pr-4">
                        {lic.fecha_presentacion ? (
                          urgent && lic.fecha_presentacion ? (
                            <span
                              className="inline-flex items-center gap-1 rounded border border-red-200 bg-red-100 px-2 py-1 text-xs font-medium text-red-700"
                              title="¡Menos de 5 días para presentación!"
                            >
                              <AlertTriangle
                                className="h-3.5 w-3.5 shrink-0"
                                aria-hidden
                              />
                              {formatFechaCorta(lic.fecha_presentacion.split("T")[0])}
                            </span>
                          ) : (
                            <span className="text-sm text-slate-600">
                              {formatFechaCorta(lic.fecha_presentacion.split("T")[0])}
                            </span>
                          )
                        ) : (
                          <span className="text-slate-400">—</span>
                        )}
                      </td>
                      <td className="py-2 pr-4">
                        <span
                          className={`inline-block min-w-[130px] rounded-md border px-2.5 py-1 text-center text-sm font-medium ${getEstadoColorClass(lic.id_estado, estados)}`}
                        >
                          {estados.find((e) => e.id_estado === lic.id_estado)?.nombre_estado ??
                            `Estado ${lic.id_estado}`}
                        </span>
                      </td>
                      <td className="py-2 pr-4 text-right text-sm font-semibold text-slate-900">
                        {formatEuro(Number(lic.pres_maximo) || 0)}
                      </td>
                    </tr>
                  );})
                )}
              </tbody>
            </table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
