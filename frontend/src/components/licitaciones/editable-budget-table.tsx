"use client";

import * as React from "react";
import { useForm, useFieldArray } from "react-hook-form";
import { Loader2, Check, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ProductosService, TendersService } from "@/services/api";
import { AnalyticsService } from "@/services/analytics.api";
import type { TenderDetail, ProductoSearchResult } from "@/types/api";

const DEBOUNCE_MS = 280; // Para el buscador
const AUTO_SAVE_INTERVAL_MS = 30_000; // 30 segundos: guardar todas las líneas pendientes
const DEVIATION_DEBOUNCE_MS = 500; // Debounce para comprobación de desviación de PVU
const inputCellClass =
  "w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded";

// --- COMPONENTE DE AUTOCOMPLETADO (Sin cambios) ---
function ProductCellAutocomplete({
  value,
  onSelect,
  placeholder,
  onKeyDown,
  disabled,
}: {
  value: { id: number; nombre: string } | null;
  onSelect: (id: number, nombre: string) => void;
  placeholder?: string;
  onKeyDown?: (e: React.KeyboardEvent<HTMLInputElement>) => void;
  disabled?: boolean;
}) {
  const [query, setQuery] = React.useState("");
  const [options, setOptions] = React.useState<ProductoSearchResult[]>([]);
  const [loading, setLoading] = React.useState(false);
  const [open, setOpen] = React.useState(false);
  const [highlight, setHighlight] = React.useState(0);
  const debounceRef = React.useRef<ReturnType<typeof setTimeout> | null>(null);
  const wrapperRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    if (value && !open) {
       // Sincronización silenciosa
    }
  }, [value, open]);

  const displayValue = open ? query : (value ? value.nombre : query);

  React.useEffect(() => {
    if (!query.trim()) {
      setOptions([]);
      setLoading(false);
      if (open) setOpen(false);
      return;
    }
    if (value && query === value.nombre) return;

    if (debounceRef.current) clearTimeout(debounceRef.current);
    setLoading(true);
    setOpen(true);
    setHighlight(0);
    debounceRef.current = setTimeout(() => {
      ProductosService.search(query.trim())
        .then((data) => {
          setOptions(data);
          setHighlight(0);
        })
        .catch(() => setOptions([]))
        .finally(() => {
          setLoading(false);
          debounceRef.current = null;
        });
    }, DEBOUNCE_MS);
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [query]);

  React.useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  const select = (opt: ProductoSearchResult) => {
    onSelect(opt.id, opt.nombre);
    setQuery(opt.nombre); 
    setOpen(false);
    setOptions([]);
  };

  return (
    <div ref={wrapperRef} className="relative w-full">
      <Input
        type="text"
        autoComplete="off"
        className={inputCellClass}
        value={displayValue}
        placeholder={placeholder}
        disabled={disabled}
        onChange={(e) => {
          setQuery(e.target.value);
          if (!open) setOpen(true);
        }}
        onFocus={() => {
            if (value) setQuery(value.nombre);
            setOpen(true);
        }}
        onKeyDown={(e) => {
          if (open && options.length > 0) {
            if (e.key === "ArrowDown") {
              e.preventDefault();
              setHighlight((h) => (h + 1) % options.length);
              return;
            }
            if (e.key === "ArrowUp") {
              e.preventDefault();
              setHighlight((h) => (h - 1 + options.length) % options.length);
              return;
            }
            if (e.key === "Enter") {
              e.preventDefault();
              select(options[highlight]);
              return;
            }
          }
          onKeyDown?.(e);
        }}
      />
      {open && (query.trim() || loading) && (
        <div className="absolute left-0 right-0 top-full z-50 mt-0.5 max-h-[200px] overflow-auto rounded-md border border-slate-200 bg-white py-1 shadow-lg">
          {loading ? (
            <div className="p-2 text-center text-xs text-slate-500">Cargando...</div>
          ) : options.length === 0 ? (
            <div className="p-2 text-center text-xs text-slate-500">Sin resultados</div>
          ) : (
            <ul>
              {options.map((opt, i) => (
                <li key={opt.id}>
                  <button
                    type="button"
                    className={`w-full px-2 py-1.5 text-left text-sm ${
                      i === highlight ? "bg-slate-100" : "hover:bg-slate-50"
                    }`}
                    onMouseDown={(e) => {
                      e.preventDefault();
                      select(opt);
                    }}
                  >
                    {opt.nombre}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}

// --- TIPOS ---
export type PartidaRow = {
  id_detalle: number | null; 
  id_producto: number | null;
  product_nombre: string;
  lote: string;
  unidades: number;
  pvu: number;
  pcu: number;
  pmaxu: number;
  isSaving?: boolean;
  isDirty?: boolean; // Nuevo: Para saber si hay cambios pendientes
};

type FormValues = {
  partidas: PartidaRow[];
};

const ghostRow: PartidaRow = {
  id_detalle: null,
  id_producto: null,
  product_nombre: "",
  lote: "General",
  unidades: 0,
  pvu: 0,
  pcu: 0,
  pmaxu: 0,
  isSaving: false,
  isDirty: false,
};

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 2,
  }).format(value);
}

/** Parsea un importe aceptando tanto coma como punto decimal (1,4 y 1.4). */
function parseDecimalInput(value: string): number {
  const normalized = String(value).trim().replace(",", ".");
  const num = parseFloat(normalized);
  return Number.isNaN(num) ? 0 : num;
}

// --- COMPONENTE PRINCIPAL ---
export interface EditableBudgetTableProps {
  lic: TenderDetail;
  onPartidaAdded: () => void;
  onUniqueLotesChange?: (lotes: string[]) => void;
}

export function EditableBudgetTable({
  lic,
  onPartidaAdded,
  onUniqueLotesChange,
}: EditableBudgetTableProps) {
  const tenderId = lic.id_licitacion;
  /** Tipo 1 = Unidades y Precio Máximo: mostrar columna PMAXU */
  const showPmaxu = lic.tipo_de_licitacion === 1;

  // Restaurar foco después de añadir línea para no saltar a la nueva fila
  const focusRestoreRef = React.useRef<HTMLElement | null>(null);
  const shouldRestoreFocusRef = React.useRef(false);
  const focusUnidadesAfterSelectRef = React.useRef<number | null>(null);
  const unidadesInputRefs = React.useRef<(HTMLInputElement | null)[]>([]);
  const autoSaveIntervalRef = React.useRef<ReturnType<typeof setInterval> | null>(null);
  const saveAllPendingRef = React.useRef<() => Promise<void>>(() => Promise.resolve());
  const [isSavingAll, setIsSavingAll] = React.useState(false);
  // Texto en edición para PVU/PCU (permite escribir "1," o "1." sin que se borre al parsear)
  const [editingDecimal, setEditingDecimal] = React.useState<{
    index: number;
    field: "pvu" | "pcu";
    value: string;
  } | null>(null);
  /** Por cada índice de fila: si el PVU tiene desviación aceptable (verde) o no (rojo). null = sin datos o cargando. */
  const [deviationByIndex, setDeviationByIndex] = React.useState<Record<number, boolean | null>>({});

  const initialRows = React.useMemo(() => {
    const serverRows = (lic.partidas ?? []).map((p) => ({
      id_detalle: p.id_detalle,
      id_producto: p.id_producto,
      product_nombre: p.product_nombre || "",
      lote: p.lote || "General",
      unidades: Number(p.unidades) || 0,
      pvu: Number(p.pvu) || 0,
      pcu: Number(p.pcu) || 0,
      pmaxu: Number(p.pmaxu) || 0,
      isSaving: false,
      isDirty: false,
    }));
    return [...serverRows, { ...ghostRow }];
  }, [lic.partidas]);

  const form = useForm<FormValues>({
    defaultValues: { partidas: initialRows },
    mode: "onChange", 
  });

  const { fields, append, update, remove } = useFieldArray({
    control: form.control,
    name: "partidas",
  });

  // Intervalo de 30 s: guardar todas las líneas pendientes
  React.useEffect(() => {
    autoSaveIntervalRef.current = setInterval(() => {
      saveAllPendingRef.current();
    }, AUTO_SAVE_INTERVAL_MS);
    return () => {
      if (autoSaveIntervalRef.current) clearInterval(autoSaveIntervalRef.current);
    };
  }, []);

  // Tras añadir línea o seleccionar producto: ir a Unidades de la fila o restaurar foco
  React.useEffect(() => {
    if (focusUnidadesAfterSelectRef.current !== null) {
      const index = focusUnidadesAfterSelectRef.current;
      focusUnidadesAfterSelectRef.current = null;
      shouldRestoreFocusRef.current = false;
      focusRestoreRef.current = null;
      const input = unidadesInputRefs.current[index];
      if (input && typeof input.focus === "function") input.focus();
      return;
    }
    if (!shouldRestoreFocusRef.current || !focusRestoreRef.current) return;
    const el = focusRestoreRef.current;
    if (typeof el.focus === "function" && document.contains(el)) el.focus();
    shouldRestoreFocusRef.current = false;
    focusRestoreRef.current = null;
  }, [fields.length]);

  const watchedPartidas = form.watch("partidas");
  React.useEffect(() => {
    const lotes = (watchedPartidas ?? [])
      .map((p) => (p.lote ?? "").trim())
      .filter(Boolean);
    const unique = Array.from(new Set(lotes));
    onUniqueLotesChange?.(unique);
  }, [watchedPartidas, onUniqueLotesChange]);

  // Comprobación de desviación de precio por fila (PVU vs histórico): verde = aceptable, rojo = desviado
  const deviationDebounceRef = React.useRef<ReturnType<typeof setTimeout> | null>(null);
  React.useEffect(() => {
    const partidas = form.watch("partidas") ?? [];
    if (deviationDebounceRef.current) clearTimeout(deviationDebounceRef.current);
    deviationDebounceRef.current = setTimeout(() => {
      deviationDebounceRef.current = null;
      const toClear: number[] = [];
      const toFetch: { index: number; nombre: string; pvu: number }[] = [];
      partidas.forEach((row, index) => {
        const nombre = (row.product_nombre ?? "").trim();
        const pvuVal = Number(row.pvu);
        if (!nombre || pvuVal <= 0) toClear.push(index);
        else toFetch.push({ index, nombre, pvu: pvuVal });
      });
      setDeviationByIndex((prev) => {
        const next = { ...prev };
        toClear.forEach((i) => (next[i] = null));
        return next;
      });
      toFetch.forEach(({ index, nombre, pvu }) => {
        AnalyticsService.getPriceDeviationCheck(nombre, pvu)
          .then((res) => {
            setDeviationByIndex((prev) => ({ ...prev, [index]: res.is_deviated }));
          })
          .catch(() => {
            setDeviationByIndex((prev) => ({ ...prev, [index]: null }));
          });
      });
    }, DEVIATION_DEBOUNCE_MS);
    return () => {
      if (deviationDebounceRef.current) clearTimeout(deviationDebounceRef.current);
    };
  }, [watchedPartidas]);

  // --- GUARDAR TODAS LAS LÍNEAS PENDIENTES ---
  const saveAllPending = React.useCallback(async () => {
    const partidas = form.getValues("partidas");
    const toUpdate: number[] = [];
    const toAdd: number[] = [];
    partidas.forEach((row, index) => {
      if (row.id_detalle && row.isDirty) toUpdate.push(index);
      else if (!row.id_detalle && row.id_producto && (Number(row.unidades) > 0 || Number(row.pvu) > 0 || Number(row.pcu) > 0))
        toAdd.push(index);
    });
    if (toUpdate.length === 0 && toAdd.length === 0) return;

    const lastRowIndex = partidas.length - 1;
    const lastRowWasSaved = toAdd.includes(lastRowIndex);

    setIsSavingAll(true);
    let needsRefetch = false;
    try {
      for (const index of toUpdate) {
        const row = form.getValues(`partidas.${index}`);
        update(index, { ...row, isSaving: true });
        await TendersService.updatePartida(tenderId, row.id_detalle!, {
          lote: row.lote || "General",
          id_producto: row.id_producto!,
          unidades: row.unidades,
          pvu: row.pvu,
          pcu: row.pcu,
          pmaxu: row.pmaxu ?? 0,
        });
        const current = form.getValues(`partidas.${index}`);
        update(index, { ...current, isSaving: false, isDirty: false });
      }
      for (const index of toAdd) {
        const row = form.getValues(`partidas.${index}`);
        update(index, { ...row, isSaving: true });
        const created = await TendersService.addPartida(tenderId, {
          lote: row.lote || "General",
          id_producto: row.id_producto!,
          unidades: row.unidades,
          pvu: row.pvu,
          pcu: row.pcu,
          pmaxu: row.pmaxu ?? 0,
        });
        focusRestoreRef.current = document.activeElement as HTMLElement | null;
        shouldRestoreFocusRef.current = true;
        const current = form.getValues(`partidas.${index}`);
        update(index, { ...current, id_detalle: created.id_detalle, isSaving: false, isDirty: false });
        needsRefetch = true;
      }
      // Solo añadir nueva fila "Añadir..." si la última fila era una que acabamos de guardar (ya no queda fantasma al final)
      if (needsRefetch) {
        onPartidaAdded();
        if (lastRowWasSaved) {
          const lastLote = form.getValues(`partidas.${lastRowIndex}`).lote || "General";
          append({ ...ghostRow, lote: lastLote });
        }
      }
    } catch (err) {
      console.error("Error guardando partidas", err);
      for (const index of [...toUpdate, ...toAdd]) {
        const row = form.getValues(`partidas.${index}`);
        update(index, { ...row, isSaving: false, isDirty: true });
      }
    } finally {
      setIsSavingAll(false);
    }
  }, [tenderId, form, update, append, onPartidaAdded]);

  saveAllPendingRef.current = saveAllPending;

  const markDirty = (index: number) => {
    const row = form.getValues(`partidas.${index}`);
    if (!row.isDirty) update(index, { ...row, isDirty: true });
  };

  // Solo al seleccionar un producto en la última fila: añadir una nueva fila vacía y mantener el foco donde estaba
  const ensureNextGhostRow = (index: number) => {
    if (index === fields.length - 1) {
      focusRestoreRef.current = document.activeElement as HTMLElement | null;
      shouldRestoreFocusRef.current = true;
      const row = form.getValues(`partidas.${index}`);
      append({ ...ghostRow, lote: row.lote || "General" });
    }
  };

  const handleRemoveRow = async (index: number) => {
    const partidas = form.getValues("partidas");
    const row = partidas[index];
    const wasLast = index === fields.length - 1;
    if (row?.id_detalle) {
      try {
        await TendersService.deletePartida(tenderId, row.id_detalle);
        onPartidaAdded();
      } catch (err) {
        console.error("Error eliminando partida", err);
        return;
      }
    }
    remove(index);
    if (wasLast) append({ ...ghostRow, lote: row?.lote || "General" });
  };

  // --- HANDLERS ---
  const handleProductSelect = (index: number, id: number, nombre: string) => {
    form.setValue(`partidas.${index}.id_producto`, id);
    form.setValue(`partidas.${index}.product_nombre`, nombre);
    markDirty(index);
    focusUnidadesAfterSelectRef.current = index;
    ensureNextGhostRow(index);
    // Si no se añadió fila, el efecto no se dispara; enfocamos Unidades en el siguiente tick
    setTimeout(() => {
      if (focusUnidadesAfterSelectRef.current !== null) {
        const i = focusUnidadesAfterSelectRef.current;
        focusUnidadesAfterSelectRef.current = null;
        unidadesInputRefs.current[i]?.focus();
      }
    }, 0);
  };

  return (
    <div className="flex min-h-[500px] w-full flex-1 flex-col overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
      <div className="flex-1 overflow-auto">
        <table className="min-w-full text-left text-sm">
          <thead className="sticky top-0 z-10 bg-slate-50 shadow-sm">
            <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
              <th className="py-3 pl-4 pr-2 font-medium">Producto</th>
              <th className="w-24 py-3 pr-2 font-medium">Lote</th>
              <th className="w-24 py-3 pr-2 text-right font-medium">Uds.</th>
              {showPmaxu && (
                <th className="w-28 py-3 pr-2 text-right font-medium">PMAXU (€)</th>
              )}
              <th className="w-28 py-3 pr-2 text-right font-medium">PVU (€)</th>
              <th className="w-28 py-3 pr-2 text-right font-medium">PCU (€)</th>
              <th className="w-24 py-3 pr-2 text-right font-medium">Beneficio</th>
              <th className="w-16 py-3 pr-4 text-right font-medium">Estado</th>
              <th className="w-12 py-3 pr-4 text-center font-medium">Eliminar</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {fields.map((field, index) => {
              const isGhost = !field.id_detalle;
              const isSaving = form.watch(`partidas.${index}.isSaving`);
              const pvu = form.watch(`partidas.${index}.pvu`);
              const pcu = form.watch(`partidas.${index}.pcu`);
              const pmaxu = form.watch(`partidas.${index}.pmaxu`);
              const beneficio = Number(pvu) - Number(pcu);

              // Observadores para renderizado
              const idProd = form.watch(`partidas.${index}.id_producto`);
              const prodName = form.watch(`partidas.${index}.product_nombre`);

              return (
                <tr 
                    key={field.id} 
                    className={`hover:bg-slate-50 ${isGhost ? "bg-emerald-50/20" : ""}`}
                >
                  <td className="py-2 pl-4 pr-2 align-middle">
                    <ProductCellAutocomplete
                      value={idProd ? { id: idProd, nombre: prodName } : null}
                      onSelect={(id, nombre) => handleProductSelect(index, id, nombre)}
                      placeholder={isGhost ? "Añadir..." : ""}
                    />
                  </td>

                  <td className="py-2 pr-2 align-middle">
                    <Input
                      className={inputCellClass}
                      {...form.register(`partidas.${index}.lote`)}
                      onChange={(e) => {
                        form.setValue(`partidas.${index}.lote`, e.target.value);
                        markDirty(index);
                      }}
                    />
                  </td>

                  <td className="py-2 pr-2 align-middle">
                    {(() => {
                      const { ref: regRef, ...unidadesRest } = form.register(
                        `partidas.${index}.unidades`,
                        { valueAsNumber: true }
                      );
                      return (
                        <Input
                          type="number"
                          min={0}
                          step={1}
                          className={`${inputCellClass} text-right font-medium`}
                          {...unidadesRest}
                          ref={(el) => {
                            unidadesInputRefs.current[index] = el;
                            regRef(el);
                          }}
                          onChange={(e) => {
                            unidadesRest.onChange(e);
                            form.setValue(`partidas.${index}.unidades`, parseFloat(e.target.value) || 0);
                            markDirty(index);
                          }}
                        />
                      );
                    })()}
                  </td>

                  {showPmaxu && (
                    <td className="py-2 pr-2 align-middle">
                      <Input
                        type="number"
                        min={0}
                        step={0.01}
                        className={`${inputCellClass} text-right`}
                        value={pmaxu != null && !Number.isNaN(Number(pmaxu)) ? Number(pmaxu) : 0}
                        onChange={(e) => {
                          const num = parseFloat(e.target.value) || 0;
                          form.setValue(`partidas.${index}.pmaxu`, num);
                          markDirty(index);
                        }}
                      />
                    </td>
                  )}

                  <td
                    className={`py-2 pr-2 align-middle ${
                      deviationByIndex[index] === false
                        ? "bg-emerald-50 dark:bg-emerald-950/30"
                        : deviationByIndex[index] === true
                          ? "bg-red-50 dark:bg-red-950/30"
                          : ""
                    }`}
                    title={
                      deviationByIndex[index] === false
                        ? "PVU dentro del rango esperado (histórico)"
                        : deviationByIndex[index] === true
                          ? "PVU desviado respecto al histórico"
                          : undefined
                    }
                  >
                    <Input
                      type="text"
                      inputMode="decimal"
                      className={`${inputCellClass} text-right`}
                      value={
                        editingDecimal?.index === index && editingDecimal?.field === "pvu"
                          ? editingDecimal.value
                          : pvu !== undefined && pvu !== null && !Number.isNaN(Number(pvu))
                            ? (Number(pvu) === 0 ? "" : String(pvu))
                            : ""
                      }
                      onFocus={() =>
                        setEditingDecimal({
                          index,
                          field: "pvu",
                          value: pvu !== undefined && pvu !== null && !Number.isNaN(Number(pvu)) ? String(pvu) : "",
                        })
                      }
                      onChange={(e) => {
                        if (editingDecimal?.index === index && editingDecimal?.field === "pvu") {
                          setEditingDecimal({ ...editingDecimal, value: e.target.value });
                        }
                      }}
                      onBlur={() => {
                        if (editingDecimal?.index === index && editingDecimal?.field === "pvu") {
                          const num = parseDecimalInput(editingDecimal.value);
                          form.setValue(`partidas.${index}.pvu`, num);
                          markDirty(index);
                          setEditingDecimal(null);
                        }
                      }}
                    />
                  </td>

                  <td className="py-2 pr-2 align-middle">
                    <Input
                      type="text"
                      inputMode="decimal"
                      className={`${inputCellClass} text-right text-slate-500`}
                      value={
                        editingDecimal?.index === index && editingDecimal?.field === "pcu"
                          ? editingDecimal.value
                          : pcu !== undefined && pcu !== null && !Number.isNaN(Number(pcu))
                            ? (Number(pcu) === 0 ? "" : String(pcu))
                            : ""
                      }
                      onFocus={() =>
                        setEditingDecimal({
                          index,
                          field: "pcu",
                          value: pcu !== undefined && pcu !== null && !Number.isNaN(Number(pcu)) ? String(pcu) : "",
                        })
                      }
                      onChange={(e) => {
                        if (editingDecimal?.index === index && editingDecimal?.field === "pcu") {
                          setEditingDecimal({ ...editingDecimal, value: e.target.value });
                        }
                      }}
                      onBlur={() => {
                        if (editingDecimal?.index === index && editingDecimal?.field === "pcu") {
                          const num = parseDecimalInput(editingDecimal.value);
                          form.setValue(`partidas.${index}.pcu`, num);
                          markDirty(index);
                          setEditingDecimal(null);
                        }
                      }}
                    />
                  </td>

                  {/* BENEFICIO (PVU - PCU) */}
                  <td className="py-2 pr-2 text-right align-middle">
                    <span
                      className={
                        beneficio > 0
                          ? "font-medium text-emerald-600"
                          : beneficio < 0
                            ? "font-medium text-red-600"
                            : "text-slate-500"
                      }
                    >
                      {formatEuro(beneficio)}
                    </span>
                  </td>

                  {/* ESTADO */}
                  <td className="py-2 pr-4 text-right align-middle">
                     {isSaving ? (
                        <div title="Guardando..." className="flex justify-end">
                             <Loader2 className="h-4 w-4 animate-spin text-emerald-600" />
                        </div>
                     ) : !isGhost ? (
                        <div title="Guardado" className="flex justify-end">
                            <Check className="h-4 w-4 text-slate-300" />
                        </div>
                     ) : (
                        <div title="Sin guardar" className="flex justify-end">
                            <span className="h-1.5 w-1.5 rounded-full bg-slate-200"></span>
                        </div>
                     )}
                  </td>

                  {/* ELIMINAR */}
                  <td className="py-2 pr-4 text-center align-middle">
                    {!isGhost && (
                      <button
                        type="button"
                        onClick={() => handleRemoveRow(index)}
                        className="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600"
                        title="Eliminar línea"
                      >
                        <X className="h-4 w-4" />
                      </button>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      
      <div className="border-t border-slate-100 bg-slate-50 px-4 py-2 flex items-center justify-between gap-3">
        <span className="text-xs text-slate-400">
          Guarda con el botón o se guardará todo automáticamente cada 30 segundos.
        </span>
        <div className="flex items-center gap-2">
          <span className="text-xs text-slate-400">{fields.length - 1} líneas</span>
          <Button
            type="button"
            size="sm"
            onClick={() => saveAllPending()}
            disabled={isSavingAll}
          >
            {isSavingAll ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin mr-1.5" />
                Guardando…
              </>
            ) : (
              "Guardar todo"
            )}
          </Button>
        </div>
      </div>
    </div>
  );
}