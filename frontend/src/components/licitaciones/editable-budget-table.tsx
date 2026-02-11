"use client";

import * as React from "react";
import { useForm, useFieldArray } from "react-hook-form";
import { Loader2 } from "lucide-react";
import { Input } from "@/components/ui/input";
import { ProductosService, TendersService } from "@/services/api";
import type { TenderDetail, TenderPartida } from "@/types/api";
import type { ProductoSearchResult } from "@/types/api";

const DEBOUNCE_MS = 280;

const inputCellClass =
  "w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-sm text-slate-900 focus:bg-slate-50 focus:outline-none focus:ring-1 focus:ring-emerald-500 rounded";

/** Autocomplete inline en celda: input como buscador, dropdown anclado debajo. */
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
  const listRef = React.useRef<HTMLDivElement>(null);

  const displayValue = query.trim() !== "" ? query : (value ? value.nombre : "");

  React.useEffect(() => {
    if (!query.trim()) {
      setOptions([]);
      setLoading(false);
      setOpen(false);
      return;
    }
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

  const select = React.useCallback(
    (opt: ProductoSearchResult) => {
      onSelect(opt.id, opt.nombre);
      setQuery("");
      setOpen(false);
      setOptions([]);
    },
    [onSelect]
  );

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
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
      if (e.key === "Escape") {
        setOpen(false);
        return;
      }
    }
    if (e.key === "Tab") {
      setOpen(false);
    }
    onKeyDown?.(e);
  };

  React.useEffect(() => {
    if (open && listRef.current && options.length) {
      const el = listRef.current.querySelector(`[data-index="${highlight}"]`);
      el?.scrollIntoView({ block: "nearest" });
    }
  }, [highlight, open, options.length]);

  return (
    <div ref={wrapperRef} className="relative w-full">
      <Input
        type="text"
        autoComplete="off"
        role="combobox"
        aria-expanded={open}
        aria-autocomplete="list"
        disabled={disabled}
        className={inputCellClass}
        value={displayValue}
        placeholder={placeholder}
        onChange={(e) => {
          setQuery(e.target.value);
          if (!e.target.value) setOptions([]);
        }}
        onFocus={() => {
          if (query.trim()) setOpen(true);
          if (value) setQuery("");
        }}
        onKeyDown={handleKeyDown}
      />
      {open && (query.trim() || loading) && (
        <div
          ref={listRef}
          className="absolute left-0 right-0 top-full z-50 mt-0.5 max-h-[220px] overflow-auto rounded-md border border-slate-200 bg-white py-1 shadow-lg"
          role="listbox"
        >
          {loading ? (
            <div className="flex items-center justify-center gap-2 py-4 text-sm text-slate-500">
              <Loader2 className="h-4 w-4 animate-spin" />
              Buscando…
            </div>
          ) : options.length === 0 ? (
            <div className="py-4 text-center text-sm text-slate-500">
              {query.trim() ? "Sin resultados" : "Escribe para buscar"}
            </div>
          ) : (
            <ul className="space-y-0.5">
              {options.map((opt, i) => (
                <li key={opt.id}>
                  <button
                    type="button"
                    data-index={i}
                    role="option"
                    aria-selected={i === highlight}
                    className={`w-full rounded px-2 py-1.5 text-left text-sm hover:bg-slate-100 focus:bg-slate-100 focus:outline-none ${
                      i === highlight ? "bg-slate-100" : ""
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

export type PartidaRow = {
  id_detalle: number | null;
  id_producto: number | null;
  product_nombre: string;
  lote: string;
  unidades: number;
  pvu: number;
  pcu: number;
  pmaxu: number;
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
};

function partidaToRow(p: TenderPartida): PartidaRow {
  return {
    id_detalle: p.id_detalle,
    id_producto: p.id_producto,
    product_nombre: p.product_nombre ?? "",
    lote: p.lote ?? "General",
    unidades: Number(p.unidades) || 0,
    pvu: Number(p.pvu) || 0,
    pcu: Number(p.pcu) || 0,
    pmaxu: Number(p.pmaxu) || 0,
  };
}

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 2,
  }).format(value);
}

export interface EditableBudgetTableProps {
  lic: TenderDetail;
  onPartidaAdded: () => void;
  /** Llamado cuando cambian los lotes únicos de las filas (para renderizado condicional del panel de lotes). */
  onUniqueLotesChange?: (lotes: string[]) => void;
}

export function EditableBudgetTable({
  lic,
  onPartidaAdded,
  onUniqueLotesChange,
}: EditableBudgetTableProps) {
  const tenderId = lic.id_licitacion;
  const initialPartidas = React.useMemo(
    () => [...(lic.partidas ?? []).map(partidaToRow), { ...ghostRow }],
    [lic.partidas]
  );

  const form = useForm<FormValues>({
    defaultValues: { partidas: initialPartidas },
  });

  const { fields, append, replace } = useFieldArray({
    control: form.control,
    name: "partidas",
  });

  React.useEffect(() => {
    const rows = [...(lic.partidas ?? []).map(partidaToRow), { ...ghostRow }];
    replace(rows);
  }, [lic.partidas, replace]);

  const partidas = form.watch("partidas");
  const uniqueLotes = React.useMemo(() => {
    const lotes = (partidas ?? [])
      .map((p) => (p.lote ?? "").trim())
      .filter(Boolean);
    return Array.from(new Set(lotes));
  }, [partidas]);

  React.useEffect(() => {
    onUniqueLotesChange?.(uniqueLotes);
  }, [uniqueLotes, onUniqueLotesChange]);

  const commitGhostAndAppend = React.useCallback(
    (rowIndex: number) => {
      const partidas = form.getValues("partidas");
      const row = partidas[rowIndex];
      if (!row?.id_producto) return;
      const newRow: PartidaRow = { ...ghostRow };
      append(newRow);
      TendersService.addPartida(tenderId, {
        lote: row.lote || "General",
        id_producto: row.id_producto,
        unidades: row.unidades || 0,
        pvu: row.pvu || 0,
        pcu: row.pcu || 0,
        pmaxu: row.pmaxu || 0,
      })
        .then(() => onPartidaAdded())
        .catch((err) => {
          console.error("Error añadiendo partida", err);
        });
    },
    [tenderId, append, form, onPartidaAdded]
  );

  const isGhost = (index: number) => index === fields.length - 1;

  const handleProductSelect = (index: number, id: number, nombre: string) => {
    form.setValue(`partidas.${index}.id_producto`, id);
    form.setValue(`partidas.${index}.product_nombre`, nombre);
    if (isGhost(index)) {
      commitGhostAndAppend(index);
    }
  };

  const handleNumericBlur = (index: number) => {
    if (!isGhost(index)) return;
    const partidas = form.getValues("partidas");
    const row = partidas[index];
    if (row?.id_producto && (row.unidades > 0 || row.pvu > 0 || row.pcu > 0)) {
      commitGhostAndAppend(index);
    }
  };

  const handleKeyDown = (
    e: React.KeyboardEvent,
    rowIndex: number,
    cellIndex: number,
    totalCells: number
  ) => {
    if (e.key !== "Tab") return;
    const focusables = e.currentTarget
      .closest("table")
      ?.querySelectorAll<HTMLElement>(
        "input:not([disabled]), button[type='button']"
      );
    if (!focusables?.length) return;
    const list = Array.from(focusables);
    const current = list.indexOf(e.currentTarget as HTMLElement);
    if (e.shiftKey) {
      if (current > 0) {
        e.preventDefault();
        list[current - 1].focus();
      }
    } else {
      if (current < list.length - 1) {
        e.preventDefault();
        list[current + 1].focus();
      }
    }
  };

  return (
    <div className="flex min-h-[60vh] w-full flex-1 flex-col overflow-hidden rounded-md border border-slate-200">
      <div className="min-h-0 flex-1 overflow-auto">
        <table className="min-w-full text-left text-sm">
          <thead className="sticky top-0 z-10 bg-slate-50 shadow-[0_1px_0_0_rgba(0,0,0,0.05)]">
            <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
            <th className="py-2 pr-2 font-medium">Producto</th>
            <th className="w-24 py-2 pr-2 font-medium">Lote</th>
            <th className="w-20 py-2 pr-2 text-right font-medium">Unidades</th>
            <th className="w-24 py-2 pr-2 text-right font-medium">PVU (€)</th>
            <th className="w-24 py-2 pr-2 text-right font-medium">PCU (€)</th>
            <th className="w-24 py-2 pr-2 text-right font-medium">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          {fields.map((field, index) => {
            const isGhostRow = isGhost(index);
            const unidades = form.watch(`partidas.${index}.unidades`) ?? 0;
            const pvu = form.watch(`partidas.${index}.pvu`) ?? 0;
            const subtotal = Number(unidades) * Number(pvu);

            return (
              <tr
                key={field.id}
                className={`border-b border-slate-100 last:border-0 ${
                  isGhostRow ? "bg-slate-50/50" : ""
                }`}
              >
                <td className="min-w-[200px] py-0 pr-2 align-middle">
                  <ProductCellAutocomplete
                    value={
                      form.watch(`partidas.${index}.id_producto`) &&
                      form.watch(`partidas.${index}.product_nombre`)
                        ? {
                            id: form.watch(`partidas.${index}.id_producto`)!,
                            nombre: form.watch(`partidas.${index}.product_nombre`)!,
                          }
                        : null
                    }
                    onSelect={(id, nombre) => handleProductSelect(index, id, nombre)}
                    placeholder={isGhostRow ? "Añadir producto…" : "Escribe para buscar"}
                    onKeyDown={(e) => handleKeyDown(e, index, 0, 6)}
                  />
                </td>
                <td className="py-0 pr-2 align-middle">
                  <Input
                    className={inputCellClass}
                    value={form.watch(`partidas.${index}.lote`) ?? ""}
                    onChange={(e) =>
                      form.setValue(`partidas.${index}.lote`, e.target.value)
                    }
                    onKeyDown={(e) =>
                      handleKeyDown(e, index, 1, 6)
                    }
                  />
                </td>
                <td className="py-0 pr-2 align-middle text-right">
                  <Input
                    type="number"
                    min={0}
                    step={0.01}
                    className={`${inputCellClass} text-right`}
                    value={
                      form.watch(`partidas.${index}.unidades`) === 0
                        ? ""
                        : form.watch(`partidas.${index}.unidades`)
                    }
                    onChange={(e) =>
                      form.setValue(
                        `partidas.${index}.unidades`,
                        parseFloat(e.target.value) || 0
                      )
                    }
                    onBlur={() => handleNumericBlur(index)}
                    onKeyDown={(e) => handleKeyDown(e, index, 2, 6)}
                  />
                </td>
                <td className="py-0 pr-2 align-middle text-right">
                  <Input
                    type="number"
                    min={0}
                    step={0.01}
                    className={`${inputCellClass} text-right`}
                    value={
                      form.watch(`partidas.${index}.pvu`) === 0
                        ? ""
                        : form.watch(`partidas.${index}.pvu`)
                    }
                    onChange={(e) =>
                      form.setValue(
                        `partidas.${index}.pvu`,
                        parseFloat(e.target.value) || 0
                      )
                    }
                    onBlur={() => handleNumericBlur(index)}
                    onKeyDown={(e) => handleKeyDown(e, index, 3, 6)}
                  />
                </td>
                <td className="py-0 pr-2 align-middle text-right">
                  <Input
                    type="number"
                    min={0}
                    step={0.01}
                    className={`${inputCellClass} text-right`}
                    value={
                      form.watch(`partidas.${index}.pcu`) === 0
                        ? ""
                        : form.watch(`partidas.${index}.pcu`)
                    }
                    onChange={(e) =>
                      form.setValue(
                        `partidas.${index}.pcu`,
                        parseFloat(e.target.value) || 0
                      )
                    }
                    onBlur={() => handleNumericBlur(index)}
                    onKeyDown={(e) => handleKeyDown(e, index, 4, 6)}
                  />
                </td>
                <td className="py-1.5 pr-2 text-right text-slate-600 tabular-nums">
                  {subtotal > 0 ? formatEuro(subtotal) : "—"}
                </td>
              </tr>
            );
          })}
        </tbody>
        </table>
      </div>
    </div>
  );
}
