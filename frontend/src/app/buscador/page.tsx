"use client";

import * as React from "react";
import Link from "next/link";
import {
  useReactTable,
  getCoreRowModel,
  flexRender,
  type ColumnDef,
} from "@tanstack/react-table";
import { ArrowLeft, Search as SearchIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { Skeleton } from "@/components/ui/skeleton";
import { SearchService } from "@/services/api";
import { useBuscadorStore } from "@/stores/useBuscadorStore";
import { ProductAnalyticsPanel } from "@/components/buscador/ProductAnalyticsPanel";
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
  const { selectedProductId, setSelectedProductId } = useBuscadorStore();

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

  const columns = React.useMemo<ColumnDef<SearchResult>[]>(
    () => [
      {
        accessorKey: "producto",
        header: "Producto",
        cell: ({ getValue }) => (
          <span className="max-w-xs truncate font-medium text-slate-900 dark:text-slate-100">
            {String(getValue() ?? "")}
          </span>
        ),
      },
      {
        accessorKey: "licitacion_nombre",
        header: "Licitación",
        cell: ({ getValue }) => (
          <span className="text-slate-700 dark:text-slate-300">
            {getValue() ?? "—"}
          </span>
        ),
      },
      {
        accessorKey: "proveedor",
        header: "Proveedor",
        cell: ({ getValue }) => (
          <span className="text-slate-700 dark:text-slate-300">
            {getValue() ?? "—"}
          </span>
        ),
      },
      {
        accessorKey: "unidades",
        header: () => <span className="text-right">Unidades</span>,
        cell: ({ getValue }) => (
          <span className="block text-right text-slate-900 dark:text-slate-100">
            {getValue() != null
              ? Number(getValue()).toLocaleString("es-ES")
              : "—"}
          </span>
        ),
      },
      {
        accessorKey: "pcu",
        header: () => <span className="text-right">PCU (Coste)</span>,
        cell: ({ getValue }) => (
          <span className="block text-right text-slate-900 dark:text-slate-100">
            {getValue() != null ? formatEuro(Number(getValue())) : "—"}
          </span>
        ),
      },
      {
        accessorKey: "pvu",
        header: () => (
          <span className="text-right text-emerald-700 dark:text-emerald-400">
            PVU (Venta)
          </span>
        ),
        cell: ({ getValue }) => (
          <span className="block text-right font-semibold text-emerald-700 dark:text-emerald-400">
            {getValue() != null ? formatEuro(Number(getValue())) : "—"}
          </span>
        ),
      },
    ],
    []
  );

  const table = useReactTable({
    data: resultados,
    columns,
    getCoreRowModel: getCoreRowModel(),
    getRowId: (row, index) => `${row.producto}-${row.licitacion_nombre ?? ""}-${index}`,
  });

  const { rows } = table.getRowModel();

  const handleRowClick = React.useCallback(
    (row: SearchResult) => {
      const id = row.id_producto;
      if (id != null && typeof id === "number") {
        setSelectedProductId(id);
      }
    },
    [setSelectedProductId]
  );

  return (
    <div className="flex h-full flex-1 flex-col gap-6 bg-slate-50 dark:bg-slate-950">
      <header className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">
            Buscador Histórico de Precios
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Consulta precios ofertados anteriormente por producto. Haz clic en una
            fila para abrir la ficha de analíticas.
          </p>
        </div>
        <Link href="/">
          <Button variant="outline" className="gap-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
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
                if (e.key === "Enter" && query.trim()) e.currentTarget.blur();
              }}
              placeholder="Ej: Planta, Tierra, Tubería..."
              className="h-11 w-full rounded-full border border-slate-200 bg-white pl-9 pr-4 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              aria-label="Buscar productos por nombre"
            />
          </div>
        </div>
      </section>

      <Card className="flex flex-1 flex-col overflow-hidden dark:border-slate-700 dark:bg-slate-900">
        <CardHeader className="shrink-0 pb-3">
          <CardTitle className="text-sm font-medium text-slate-800 dark:text-slate-200">
            Resultados
          </CardTitle>
        </CardHeader>
        <CardContent className="flex min-h-0 flex-1 flex-col pt-0">
          {query.trim().length === 0 ? (
            <p className="py-6 text-sm text-slate-500 dark:text-slate-400">
              Introduce un término en la barra de búsqueda para consultar precios
              históricos de productos.
            </p>
          ) : error ? (
            <p className="py-6 text-sm text-red-600 dark:text-red-400">{error}</p>
          ) : loading ? (
            <div className="space-y-2 py-4">
              {[1, 2, 3, 4, 5].map((i) => (
                <Skeleton
                  key={i}
                  className="h-12 w-full dark:bg-slate-700"
                />
              ))}
            </div>
          ) : resultados.length === 0 ? (
            <p className="py-6 text-sm text-slate-500 dark:text-slate-400">
              No se han encontrado resultados para &quot;{query}&quot;.
            </p>
          ) : (
            <>
              <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">
                Mostrando{" "}
                <span className="font-semibold text-slate-800 dark:text-slate-200">
                  {resultados.length}
                </span>{" "}
                coincidencias. Clic en fila para analíticas.
              </p>
              <div className="min-h-0 flex-1 overflow-auto">
                <table className="min-w-full text-left text-sm">
                  <thead className="sticky top-0 z-10 bg-slate-100 dark:bg-slate-800">
                    {table.getHeaderGroups().map((hg) => (
                      <tr
                        key={hg.id}
                        className="border-b border-slate-200 dark:border-slate-700"
                      >
                        {hg.headers.map((h) => (
                          <th
                            key={h.id}
                            className="py-2 pr-3 font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"
                          >
                            {flexRender(
                              h.column.columnDef.header,
                              h.getContext()
                            )}
                          </th>
                        ))}
                      </tr>
                    ))}
                  </thead>
                  <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {rows.map((row) => {
                      const original = row.original;
                      const hasProductId =
                        original.id_producto != null &&
                        typeof original.id_producto === "number";
                      return (
                        <tr
                          key={row.id}
                          className={
                            hasProductId
                              ? "cursor-pointer bg-white hover:bg-slate-50 dark:bg-slate-900 dark:hover:bg-slate-800"
                              : "bg-white dark:bg-slate-900"
                          }
                          onClick={() => hasProductId && handleRowClick(original)}
                        >
                          {row.getVisibleCells().map((cell) => (
                            <td
                              key={cell.id}
                              className="py-2 pr-3"
                            >
                              {flexRender(
                                cell.column.columnDef.cell,
                                cell.getContext()
                              )}
                            </td>
                          ))}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </CardContent>
      </Card>

      <Sheet
        open={selectedProductId !== null}
        onOpenChange={(open) => !open && setSelectedProductId(null)}
      >
        <SheetContent side="right" className="w-full max-w-xl p-0">
          {selectedProductId !== null ? (
            <ProductAnalyticsPanel productId={selectedProductId} onClose={() => setSelectedProductId(null)} />
          ) : null}
        </SheetContent>
      </Sheet>
    </div>
  );
}
