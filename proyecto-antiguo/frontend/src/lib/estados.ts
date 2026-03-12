/**
 * Nombres y colores de estados (tbl_estados).
 * Fallbacks para cuando la API de estados no carga o para consistencia de colores.
 */

import type { Estado } from "@/types/api";

/** Nombres por defecto por id_estado (alineados con tbl_estados). */
export const ESTADO_NOMBRE_BY_ID_FALLBACK: Record<number, string> = {
  2: "Descartada",
  3: "En análisis",
  4: "Presentada",
  5: "Adjudicada",
  6: "No adjudicada",
  7: "Terminada",
};

/** Colores por id_estado para badges. */
export const ESTADO_COLOR_BY_ID: Record<number, string> = {
  2: "bg-rose-100 text-rose-800 border-rose-200",
  3: "bg-amber-100 text-amber-800 border-amber-200",
  4: "bg-sky-100 text-sky-800 border-sky-200",
  5: "bg-emerald-100 text-emerald-800 border-emerald-200",
  6: "bg-rose-100 text-rose-800 border-rose-200",
  7: "bg-slate-100 text-slate-800 border-slate-200",
};

export function getEstadoNombre(
  idEstado: number | string | null | undefined,
  estados: Estado[]
): string {
  const id = Number(idEstado);
  if (Number.isNaN(id)) return `Estado ${idEstado ?? "?"}`;
  const e = estados.find((x) => Number(x.id_estado) === id);
  return e?.nombre_estado ?? ESTADO_NOMBRE_BY_ID_FALLBACK[id] ?? `Estado ${id}`;
}

export function getEstadoColorClass(idEstado: number | string | null | undefined): string {
  const id = Number(idEstado);
  if (!Number.isNaN(id) && ESTADO_COLOR_BY_ID[id]) return ESTADO_COLOR_BY_ID[id];
  return "bg-slate-100 text-slate-800 border-slate-200";
}
