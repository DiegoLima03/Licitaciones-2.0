"use client";

import * as React from "react";
import Link from "next/link";
import { ArrowLeft, FileSpreadsheet, Plus } from "lucide-react";

import { ProductAutocompleteInput } from "@/components/producto-autocomplete-input";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { ImportService, PreciosReferenciaService } from "@/services/api";
import type { PrecioReferencia, PrecioReferenciaCreate } from "@/types/api";

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 2,
  }).format(value);
}

const initialForm = {
  pvu: null as number | null,
  pcu: null as number | null,
  unidades: null as number | null,
  proveedor: "",
  notas: "",
  fecha_presupuesto: "" as string,
};

export default function LineasReferenciaPage() {
  const [list, setList] = React.useState<PrecioReferencia[]>([]);
  const [loading, setLoading] = React.useState(true);
  const [submitting, setSubmitting] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [selectedProduct, setSelectedProduct] = React.useState<{
    id: number;
    nombre: string;
  } | null>(null);
  const [form, setForm] = React.useState(initialForm);
  const [importing, setImporting] = React.useState(false);
  const [importResult, setImportResult] = React.useState<{
    message: string;
    rows_imported: number;
    rows_skipped: number;
    skipped_details?: { articulo: string; precio?: number }[];
  } | null>(null);

  const fetchList = React.useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await PreciosReferenciaService.getAll();
      setList(data);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Error al cargar líneas");
      setList([]);
    } finally {
      setLoading(false);
    }
  }, []);

  React.useEffect(() => {
    fetchList();
  }, [fetchList]);

  function handleChange(
    field: keyof typeof initialForm,
    value: string | number | null
  ) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  async function handleImportExcel(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const input = (e.target as HTMLFormElement).querySelector<HTMLInputElement>('input[type="file"]');
    const file = input?.files?.[0];
    if (!file) {
      setError("Selecciona un archivo Excel (.xlsx).");
      return;
    }
    if (!file.name.toLowerCase().endsWith(".xlsx") && !file.name.toLowerCase().endsWith(".xls")) {
      setError("El archivo debe ser Excel (.xlsx o .xls).");
      return;
    }
    setImporting(true);
    setError(null);
    setImportResult(null);
    try {
      const res = await ImportService.uploadPreciosReferencia(file);
      setImportResult(res);
      await fetchList();
      input.value = "";
    } catch (e) {
      setError(e instanceof Error ? e.message : "Error al importar.");
    } finally {
      setImporting(false);
    }
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!selectedProduct) {
      setError("Selecciona un producto.");
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      const payload: PrecioReferenciaCreate = {
        id_producto: selectedProduct.id,
        pvu: form.pvu != null ? Number(form.pvu) : null,
        pcu: form.pcu != null ? Number(form.pcu) : null,
        unidades: form.unidades != null ? Number(form.unidades) : null,
        proveedor: form.proveedor?.trim() || null,
        notas: form.notas?.trim() || null,
        fecha_presupuesto: form.fecha_presupuesto?.trim() || null,
      };
      await PreciosReferenciaService.create(payload);
      setSelectedProduct(null);
      setForm(initialForm);
      await fetchList();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Error al guardar la línea");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="flex flex-1 flex-col gap-6">
      <header className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
            Añadir líneas
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Añade precios de producto sin vincularlos a ninguna licitación. Estas
            líneas aparecerán en el Buscador Histórico.
          </p>
        </div>
        <Link href="/">
          <Button variant="outline" className="gap-2">
            <ArrowLeft className="h-4 w-4" />
            Volver al Dashboard
          </Button>
        </Link>
      </header>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-sm font-medium text-slate-800">
            Importar albaranes de compra
          </CardTitle>
          <p className="text-xs text-slate-500 mt-1">
            Sube un Excel con columnas: Fecha, Nº Albarán, Ref. Artículo, Artículo, Cantidad, Precio.
            Se crearán líneas de precios de referencia (PCU = Precio). Los productos deben existir en la base.
          </p>
        </CardHeader>
        <CardContent className="pt-0">
          <form onSubmit={handleImportExcel} className="flex flex-wrap items-end gap-3">
            <div className="flex-1 min-w-[200px]">
              <label className="block text-xs font-medium text-slate-600 mb-1">Archivo Excel</label>
              <Input
                type="file"
                accept=".xlsx,.xls"
                disabled={importing}
                className="cursor-pointer"
              />
            </div>
            <Button type="submit" variant="secondary" disabled={importing} className="gap-2">
              <FileSpreadsheet className="h-4 w-4" />
              {importing ? "Importando…" : "Importar"}
            </Button>
          </form>
          {importResult && (
            <div className={`mt-4 rounded-md px-3 py-2 text-sm ${
              importResult.rows_skipped > 0 ? "bg-amber-50 text-amber-800" : "bg-emerald-50 text-emerald-800"
            }`}>
              <p>{importResult.message}</p>
              {importResult.skipped_details && importResult.skipped_details.length > 0 && (
                <details className="mt-2">
                  <summary className="cursor-pointer text-xs">Ver líneas omitidas ({importResult.skipped_details.length})</summary>
                  <ul className="mt-1 text-xs list-disc list-inside space-y-0.5">
                    {importResult.skipped_details.slice(0, 15).map((s, i) => (
                      <li key={i}>{s.articulo} {s.precio != null ? `(PCU: ${s.precio} €)` : ""}</li>
                    ))}
                    {importResult.skipped_details.length > 15 && (
                      <li>… y {importResult.skipped_details.length - 15} más</li>
                    )}
                  </ul>
                </details>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-sm font-medium text-slate-800">
            Nueva línea de precio
          </CardTitle>
        </CardHeader>
        <CardContent className="pt-0">
          <form onSubmit={handleSubmit} className="space-y-4">
            {error && (
              <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                {error}
              </p>
            )}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <div className="sm:col-span-2 lg:col-span-2">
                <label className="mb-1 block text-xs font-medium text-slate-600">
                  Producto *
                </label>
                <ProductAutocompleteInput
                  value={selectedProduct}
                  onSelect={(id, nombre) => setSelectedProduct({ id, nombre })}
                  placeholder="Buscar producto…"
                />
              </div>
              <div>
                <label
                  htmlFor="fecha_presupuesto"
                  className="mb-1 block text-xs font-medium text-slate-600"
                >
                  Fecha presupuesto
                </label>
                <Input
                  id="fecha_presupuesto"
                  type="date"
                  value={form.fecha_presupuesto ?? ""}
                  onChange={(e) => handleChange("fecha_presupuesto", e.target.value)}
                />
              </div>
              <div>
                <label
                  htmlFor="pvu"
                  className="mb-1 block text-xs font-medium text-slate-600"
                >
                  PVU (€)
                </label>
                <Input
                  id="pvu"
                  type="number"
                  step="0.01"
                  min="0"
                  value={form.pvu ?? ""}
                  onChange={(e) =>
                    handleChange(
                      "pvu",
                      e.target.value === "" ? null : e.target.value
                    )
                  }
                  placeholder="0,00"
                />
              </div>
              <div>
                <label
                  htmlFor="pcu"
                  className="mb-1 block text-xs font-medium text-slate-600"
                >
                  PCU (€)
                </label>
                <Input
                  id="pcu"
                  type="number"
                  step="0.01"
                  min="0"
                  value={form.pcu ?? ""}
                  onChange={(e) =>
                    handleChange(
                      "pcu",
                      e.target.value === "" ? null : e.target.value
                    )
                  }
                  placeholder="0,00"
                />
              </div>
              <div>
                <label
                  htmlFor="unidades"
                  className="mb-1 block text-xs font-medium text-slate-600"
                >
                  Unidades
                </label>
                <Input
                  id="unidades"
                  type="number"
                  step="0.01"
                  min="0"
                  value={form.unidades ?? ""}
                  onChange={(e) =>
                    handleChange(
                      "unidades",
                      e.target.value === "" ? null : e.target.value
                    )
                  }
                  placeholder="—"
                />
              </div>
              <div className="sm:col-span-2 lg:col-span-3">
                <label
                  htmlFor="proveedor"
                  className="mb-1 block text-xs font-medium text-slate-600"
                >
                  Proveedor
                </label>
                <Input
                  id="proveedor"
                  value={form.proveedor ?? ""}
                  onChange={(e) => handleChange("proveedor", e.target.value)}
                  placeholder="Nombre del proveedor"
                />
              </div>
            </div>
            <div>
              <label
                htmlFor="notas"
                className="mb-1 block text-xs font-medium text-slate-600"
              >
                Notas
              </label>
              <Textarea
                id="notas"
                rows={2}
                value={form.notas ?? ""}
                onChange={(e) => handleChange("notas", e.target.value)}
                placeholder="Observaciones opcionales"
                className="w-full"
              />
            </div>
            <Button type="submit" disabled={submitting} className="gap-2">
              <Plus className="h-4 w-4" />
              {submitting ? "Guardando…" : "Añadir línea"}
            </Button>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-sm font-medium text-slate-800">
            Líneas guardadas
          </CardTitle>
        </CardHeader>
        <CardContent className="pt-0">
          {loading ? (
            <p className="py-6 text-sm text-slate-500">Cargando…</p>
          ) : list.length === 0 ? (
            <p className="py-6 text-sm text-slate-500">
              No hay líneas de referencia. Añade una con el formulario de arriba.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <th className="py-2 pr-3">Producto</th>
                    <th className="py-2 pr-3">Fecha presupuesto</th>
                    <th className="py-2 pr-3">Proveedor</th>
                    <th className="py-2 pr-3 text-right">Unidades</th>
                    <th className="py-2 pr-3 text-right">PCU</th>
                    <th className="py-2 pr-3 text-right text-emerald-700">
                      PVU
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {list.map((item) => (
                    <tr
                      key={item.id}
                      className="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                    >
                      <td className="max-w-xs py-2 pr-3 font-medium text-slate-900">
                        {item.product_nombre ?? "—"}
                      </td>
                      <td className="py-2 pr-3 text-slate-600">
                        {item.fecha_presupuesto
                          ? new Date(item.fecha_presupuesto + "T00:00:00").toLocaleDateString("es-ES")
                          : "—"}
                      </td>
                      <td className="py-2 pr-3 text-slate-700">
                        {item.proveedor ?? "—"}
                      </td>
                      <td className="py-2 pr-3 text-right text-slate-900">
                        {item.unidades != null
                          ? item.unidades.toLocaleString("es-ES")
                          : "—"}
                      </td>
                      <td className="py-2 pr-3 text-right text-slate-900">
                        {item.pcu != null ? formatEuro(item.pcu) : "—"}
                      </td>
                      <td className="py-2 pr-3 text-right font-semibold text-emerald-700">
                        {item.pvu != null ? formatEuro(item.pvu) : "—"}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
