"use client";

import * as React from "react";
import { ChevronDown, ChevronRight, Package } from "lucide-react";
import type { ScheduledDelivery } from "@/types/api";

export interface ScheduledDeliveriesAccordionProps {
  deliveries: ScheduledDelivery[];
  className?: string;
}

function formatDate(dateStr: string | undefined) {
  if (!dateStr) return "—";
  return new Date(dateStr + "T00:00:00").toLocaleDateString("es-ES", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

/** Progress 0..100 según status (placeholder: PENDIENTE=0, ENTREGADO=100, etc.). */
function progressFromStatus(status: string | null | undefined): number {
  if (!status) return 0;
  const s = String(status).toUpperCase();
  if (s.includes("ENTREGADO") || s === "COMPLETADO") return 100;
  if (s.includes("EN CURSO") || s.includes("PARCIAL")) return 50;
  return 0;
}

export function ScheduledDeliveriesAccordion({ deliveries, className }: ScheduledDeliveriesAccordionProps) {
  const [openId, setOpenId] = React.useState<string | null>(deliveries[0]?.id ?? null);

  if (!deliveries.length) {
    return (
      <div className={`rounded-lg border border-slate-200 bg-slate-50 p-4 text-center text-sm text-slate-500 ${className ?? ""}`}>
        No hay entregas programadas.
      </div>
    );
  }

  return (
    <div className={`space-y-1 ${className ?? ""}`}>
      <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">
        Entregas mensuales programadas
      </p>
      {deliveries.map((d) => {
        const isOpen = openId === d.id;
        const progress = progressFromStatus(d.status);
        return (
          <div
            key={d.id}
            className="rounded-lg border border-slate-200 bg-white shadow-sm"
          >
            <button
              type="button"
              className="flex w-full items-center gap-2 px-4 py-3 text-left hover:bg-slate-50"
              onClick={() => setOpenId(isOpen ? null : d.id)}
            >
              {isOpen ? (
                <ChevronDown className="h-4 w-4 shrink-0 text-slate-500" />
              ) : (
                <ChevronRight className="h-4 w-4 shrink-0 text-slate-500" />
              )}
              <Package className="h-4 w-4 shrink-0 text-slate-400" />
              <span className="flex-1 font-medium text-slate-800">
                {formatDate(d.delivery_date)}
              </span>
              {d.status && (
                <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                  {d.status}
                </span>
              )}
            </button>
            {isOpen && (
              <div className="border-t border-slate-100 px-4 pb-3 pt-2">
                <div className="mb-2 flex justify-between text-xs text-slate-500">
                  <span>Progreso</span>
                  <span>{progress}%</span>
                </div>
                <div className="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                  <div
                    className="h-full rounded-full bg-emerald-500 transition-all"
                    style={{ width: `${progress}%` }}
                  />
                </div>
                {d.description && (
                  <p className="mt-2 text-sm text-slate-600">{d.description}</p>
                )}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
