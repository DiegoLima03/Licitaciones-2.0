"use client";

import * as React from "react";
import Image from "next/image";
import Link from "next/link";
import { AlertTriangle, ChevronDown, Search } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { CreateTenderDialog } from "@/components/licitaciones/create-tender-dialog";
import { PAIS_FLAG_SRC, PAIS_LABEL, PAISES_OPCIONES } from "@/lib/paises";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { ESTADO_NOMBRE_BY_ID_FALLBACK, getEstadoColorClass } from "@/lib/estados";
import { EstadosService, TendersService } from "@/services/api";
import { Badge } from "@/components/ui/badge";
import type { Estado, PaisLicitacion, Tender, TipoProcedimiento } from "@/types/api";

/** Mapa id_estado -> nombre desde tbl_estados; usa fallback si la API no devolvió datos. */
function buildEstadoNombreById(estados: Estado[]): Map<number, string> {
  const map = new Map<number, string>();
  estados.forEach((e) => {
    const id = Number(e.id_estado);
    if (!Number.isNaN(id) && e.nombre_estado) map.set(id, e.nombre_estado);
  });
  if (map.size === 0) {
    Object.entries(ESTADO_NOMBRE_BY_ID_FALLBACK).forEach(([id, nombre]) => map.set(Number(id), nombre));
  }
  return map;
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

/** Etiquetas cortas para el badge en el listado (Licitación, AM, SDA, Basado). */
const TIPO_BADGE_LABEL: Record<string, string> = {
  ORDINARIO: "Licitación",
  ACUERDO_MARCO: "AM",
  SDA: "SDA",
  CONTRATO_BASADO: "Basado",
};

function getTipoProcedimientoLabel(tipo: TipoProcedimiento | null | undefined): string {
  if (!tipo) return "Licitación";
  return TIPO_BADGE_LABEL[tipo] ?? tipo;
}

function getTipoBadgeVariant(
  tipo: TipoProcedimiento | null | undefined
): "default" | "success" | "warning" | "info" | "destructive" | "outline" {
  if (!tipo || tipo === "ORDINARIO") return "outline";
  if (tipo === "ACUERDO_MARCO" || tipo === "SDA") return "info";
  if (tipo === "CONTRATO_BASADO") return "success";
  return "outline";
}

export default function LicitacionesPage() {
  const [data, setData] = React.useState<Tender[]>([]);
  const [estados, setEstados] = React.useState<Estado[]>([]);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [searchNombre, setSearchNombre] = React.useState("");
  const [filterEstadoId, setFilterEstadoId] = React.useState<number | "">("");
  const [filterPais, setFilterPais] = React.useState<"" | PaisLicitacion>("");
  const [paisDropdownOpen, setPaisDropdownOpen] = React.useState(false);

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

  /** Nombres de estados desde tbl_estados (id_estado -> nombre_estado) para la tabla. */
  const estadoNombreById = React.useMemo(() => buildEstadoNombreById(estados), [estados]);

  /** Nombres de padres (raíz) para mostrar "Cuelga de" en derivados que salen por excepción (urgentes). */
  const parentNameById = React.useMemo(() => {
    const map = new Map<number, string>();
    data.filter((lic) => lic.id_licitacion_padre == null).forEach((lic) => {
      map.set(lic.id_licitacion, lic.nombre ?? `#${lic.id_licitacion}`);
    });
    return map;
  }, [data]);

  const sortedData = React.useMemo(() => {
    if (!data || data.length === 0) return [];
    return [...data].sort((a, b) => {
      const ua = getUrgencyInfo(a).urgent ? 1 : 0;
      const ub = getUrgencyInfo(b).urgent ? 1 : 0;
      if (ua !== ub) return ub - ua; // urgentes primero

      const fa = (a.fecha_presentacion ?? "").split("T")[0];
      const fb = (b.fecha_presentacion ?? "").split("T")[0];
      if (fa && fb && fa !== fb) return fa.localeCompare(fb); // más próximas primero

      return (b.pres_maximo ?? 0) - (a.pres_maximo ?? 0); // luego por presupuesto descendente
    });
  }, [data]);

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
          <CardTitle className="text-sm font-medium text-slate-800 shrink-0">
            Listado de licitaciones
          </CardTitle>
          <div className="flex flex-col items-end gap-3 ml-auto">
            <div className="flex items-center gap-3">
            <Popover open={paisDropdownOpen} onOpenChange={setPaisDropdownOpen}>
              <PopoverTrigger asChild>
                <button
                  type="button"
                  title="Filtrar por país"
                  className="flex h-9 min-w-[160px] items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                >
                  <span className="flex items-center gap-2">
                    {filterPais ? (
                      <>
                        <Image
                          src={PAIS_FLAG_SRC[filterPais]}
                          alt=""
                          width={24}
                          height={16}
                          unoptimized
                          className="h-4 w-6 rounded object-cover object-center"
                        />
                        <span>{PAIS_LABEL[filterPais]}</span>
                      </>
                    ) : (
                      <span className="text-slate-500">Todos los países</span>
                    )}
                  </span>
                  <ChevronDown className="h-4 w-4 shrink-0 text-slate-400" />
                </button>
              </PopoverTrigger>
              <PopoverContent align="start" className="w-[var(--radix-popover-trigger-width)] min-w-[160px] p-0">
                <div className="py-1">
                  <button
                    type="button"
                    onClick={() => {
                      setFilterPais("");
                      setPaisDropdownOpen(false);
                    }}
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100"
                  >
                    <span className="w-6 text-slate-400">—</span>
                    <span>Todos los países</span>
                  </button>
                  {PAISES_OPCIONES.map((p) => (
                    <button
                      key={p}
                      type="button"
                      onClick={() => {
                        setFilterPais(p);
                        setPaisDropdownOpen(false);
                      }}
                      className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100"
                    >
                      <Image
                        src={PAIS_FLAG_SRC[p]}
                        alt=""
                        width={24}
                        height={16}
                        unoptimized
                        className="h-4 w-6 rounded object-cover object-center"
                      />
                      <span>{PAIS_LABEL[p]}</span>
                    </button>
                  ))}
                </div>
              </PopoverContent>
            </Popover>
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
            </div>
            <div className="relative w-full min-w-[320px] max-w-md">
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
                  <th className="py-2 pr-4 text-center">Expediente</th>
                  <th className="py-2 pr-4 text-center">Nombre proyecto</th>
                  <th className="py-2 pr-4 text-center">Procedimiento</th>
                  <th className="py-2 pr-4 text-center">F. Presentación</th>
                  <th className="py-2 pr-4 text-center">Estado</th>
                  <th className="py-2 pr-4 text-center">Presupuesto (€)</th>
                </tr>
              </thead>
              <tbody>
                {sortedData.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-6 text-center text-sm text-slate-500">
                      No hay licitaciones. Crea una o ajusta el filtro.
                    </td>
                  </tr>
                ) : (
                  sortedData.map((lic) => {
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
                      <td className="py-2 pr-4 text-center">
                        <div className="flex flex-col items-center gap-1">
                          <Badge
                            variant={getTipoBadgeVariant(lic.tipo_procedimiento)}
                            className={`w-fit justify-center text-xs ${(!lic.tipo_procedimiento || lic.tipo_procedimiento === "ORDINARIO") ? "bg-slate-100 text-slate-700 border-slate-200" : ""}`}
                          >
                            {getTipoProcedimientoLabel(lic.tipo_procedimiento)}
                          </Badge>
                          {lic.id_licitacion_padre != null && (
                            <span className="text-center text-xs text-slate-500">
                              Cuelga de:{" "}
                              <Link
                                href={`/licitaciones/${lic.id_licitacion_padre}`}
                                className="font-medium text-slate-700 hover:underline"
                              >
                                {parentNameById.get(lic.id_licitacion_padre) ?? `#${lic.id_licitacion_padre}`}
                              </Link>
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="py-2 pr-4 text-center">
                        {lic.fecha_presentacion ? (
                          urgent && lic.fecha_presentacion ? (
                            <span
                              className="inline-flex items-center justify-center gap-1 rounded border border-red-200 bg-red-100 px-2 py-1 text-xs font-medium text-red-700"
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
                          className={`inline-block min-w-[130px] rounded-md border px-2.5 py-1 text-center text-sm font-medium ${getEstadoColorClass(lic.id_estado)}`}
                        >
                          {estadoNombreById.get(Number(lic.id_estado)) ?? ESTADO_NOMBRE_BY_ID_FALLBACK[Number(lic.id_estado)] ?? `Estado ${lic.id_estado}`}
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
