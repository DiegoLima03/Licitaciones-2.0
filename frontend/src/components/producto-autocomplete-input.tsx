"use client";

import * as React from "react";
import { Loader2 } from "lucide-react";
import { Input } from "@/components/ui/input";
import { ProductosService } from "@/services/api";
import type { ProductoSearchResult } from "@/types/api";
import { cn } from "@/lib/utils";

const DEBOUNCE_MS = 280;

export interface ProductAutocompleteInputProps {
  value: { id: number; nombre: string } | null;
  onSelect: (id: number, nombre: string) => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  inputClassName?: string;
}

/**
 * Campo único: el input es el buscador. Al escribir se muestran resultados en un dropdown debajo.
 * Sin popover/desplegable secundario.
 */
export function ProductAutocompleteInput({
  value,
  onSelect,
  placeholder = "Buscar o seleccionar producto…",
  disabled = false,
  className,
  inputClassName,
}: ProductAutocompleteInputProps) {
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
  };

  React.useEffect(() => {
    if (open && listRef.current && options.length) {
      const el = listRef.current.querySelector(`[data-index="${highlight}"]`);
      el?.scrollIntoView({ block: "nearest" });
    }
  }, [highlight, open, options.length]);

  return (
    <div ref={wrapperRef} className={cn("relative w-full", className)}>
      <Input
        type="text"
        autoComplete="off"
        role="combobox"
        aria-expanded={open}
        aria-autocomplete="list"
        disabled={disabled}
        className={cn("bg-white dark:bg-slate-800", inputClassName)}
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
          className="absolute left-0 right-0 top-full z-50 mt-0.5 max-h-[220px] overflow-auto rounded-md border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-900"
          role="listbox"
        >
          {loading ? (
            <div className="flex items-center justify-center gap-2 py-4 text-sm text-slate-500">
              <Loader2 className="h-4 w-4 animate-spin" />
              Buscando…
            </div>
          ) : options.length === 0 ? (
            <div className="py-4 text-center text-sm text-slate-500">
              {query.trim() ? "Sin resultados" : "Escribe para buscar productos"}
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
                    className={cn(
                      "w-full rounded px-2 py-1.5 text-left text-sm hover:bg-slate-100 focus:bg-slate-100 focus:outline-none dark:hover:bg-slate-800 dark:focus:bg-slate-800",
                      i === highlight && "bg-slate-100 dark:bg-slate-800"
                    )}
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
