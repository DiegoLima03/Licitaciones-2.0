"use client";

import * as React from "react";
import Link from "next/link";
import { ArrowLeft, Search as SearchIcon } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { SearchService } from "@/services/api";
import type { SearchResult } from "@/types/api";

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 2,
  }).format(value);
}

const DEBOUNCE_MS = 300;

export default function BuscadorHistoricoPage() {
  const [query, setQuery] = React.useState("");
  const [resultados, setResultados] = React.useState<SearchResult[]>([]);
  const [loading, setLoading] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (!query.trim()) {
      setResultados([]);
      setError(null);
      setLoading(false);
      return;
    }
    let cancelled = false;
    setLoading(true);
    setError(null);
    const t = setTimeout(() => {
      SearchService.search(query.trim())
        .then((data) => {
          if (!cancelled) setResultados(data);
        })
        .catch((e) => {
          if (!cancelled) {
            setError(e instanceof Error ? e.message : "Error en la búsqueda");
            setResultados([]);
          }
        })
        .finally(() => {
          if (!cancelled) setLoading(false);
        });
    }, DEBOUNCE_MS);
    return () => {
      cancelled = true;
      clearTimeout(t);
    };
  }, [query]);

  return (
    <div className="flex flex-1 flex-col gap-6">
      <header className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
            Buscador Histórico de Precios
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Consulta precios ofertados anteriormente por producto (datos de la base de datos).
          </p>
        </div>
        <Link href="/">
          <Button variant="outline" className="gap-2">
            <ArrowLeft className="h-4 w-4" />
            Volver al Dashboard
          </Button>
        </Link>
      </header>

      <section className="mt-2">
        <div className="mx-auto flex max-w-3xl items-center">
          <div className="relative w-full">
            <SearchIcon className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <input
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter" && query.trim()) {
                  e.currentTarget.blur();
                }
              }}
              placeholder="Ej: Planta, Tierra, Tubería..."
              className="h-11 w-full rounded-full border border-slate-200 bg-white pl-9 pr-4 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
              aria-label="Buscar productos por nombre"
            />
          </div>
        </div>
      </section>

      <section>
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm font-medium text-slate-800">
              Resultados
            </CardTitle>
          </CardHeader>
          <CardContent className="pt-0">
            {query.trim().length === 0 ? (
              <p className="py-6 text-sm text-slate-500">
                Introduce un término en la barra de búsqueda para consultar
                precios históricos de productos.
              </p>
            ) : error ? (
              <p className="py-6 text-sm text-red-600">{error}</p>
            ) : loading ? (
              <p className="py-6 text-sm text-slate-500">Buscando…</p>
            ) : resultados.length === 0 ? (
              <p className="py-6 text-sm text-slate-500">
                No se han encontrado resultados para{" "}
                <span className="font-medium text-slate-900">&quot;{query}&quot;</span>.
              </p>
            ) : (
              <>
                <p className="mb-3 text-xs text-slate-500">
                  Mostrando{" "}
                  <span className="font-semibold text-slate-800">
                    {resultados.length}
                  </span>{" "}
                  coincidencias.
                </p>
                <div className="overflow-x-auto">
                  <table className="min-w-full text-left text-sm">
                    <thead>
                      <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <th className="py-2 pr-3">Producto</th>
                        <th className="py-2 pr-3">Licitación</th>
                        <th className="py-2 pr-3">Proveedor</th>
                        <th className="py-2 pr-3 text-right">Unidades</th>
                        <th className="py-2 pr-3 text-right">PCU (Coste)</th>
                        <th className="py-2 pr-3 text-right text-emerald-700">
                          PVU (Venta)
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {resultados.map((item, index) => (
                        <tr
                          key={`${item.producto}-${item.licitacion_nombre ?? ""}-${index}`}
                          className="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                        >
                          <td className="max-w-xs py-2 pr-3 text-sm font-medium text-slate-900">
                            {item.producto}
                          </td>
                          <td className="py-2 pr-3 text-sm text-slate-700">
                            {item.licitacion_nombre ?? "—"}
                          </td>
                          <td className="py-2 pr-3 text-sm text-slate-700">
                            {item.proveedor ?? "—"}
                          </td>
                          <td className="py-2 pr-3 text-right text-sm text-slate-900">
                            {item.unidades != null
                              ? item.unidades.toLocaleString("es-ES")
                              : "—"}
                          </td>
                          <td className="py-2 pr-3 text-right text-sm text-slate-900">
                            {item.pcu != null ? formatEuro(item.pcu) : "—"}
                          </td>
                          <td className="py-2 pr-3 text-right text-sm font-semibold text-emerald-700">
                            {item.pvu != null ? formatEuro(item.pvu) : "—"}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </CardContent>
        </Card>
      </section>
    </div>
  );
}
