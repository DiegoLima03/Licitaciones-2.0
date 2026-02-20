"use client";

import * as React from "react";
import { ChevronDown, Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { ProductosService } from "@/services/api";
import type { ProductoSearchResult } from "@/types/api";

const DEBOUNCE_MS = 300;

export interface ProductComboboxProps {
  value: { id: number; nombre: string } | null;
  onSelect: (id: number, nombre: string) => void;
  /** Si se proporciona, permite deseleccionar el producto (p. ej. para usar nombre libre). */
  onClear?: () => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
}

export function ProductCombobox({
  value,
  onSelect,
  onClear,
  placeholder = "Buscar producto…",
  disabled = false,
  className,
}: ProductComboboxProps) {
  const [open, setOpen] = React.useState(false);
  const [query, setQuery] = React.useState("");
  const [options, setOptions] = React.useState<ProductoSearchResult[]>([]);
  const [loading, setLoading] = React.useState(false);
  const debounceRef = React.useRef<ReturnType<typeof setTimeout> | null>(null);

  React.useEffect(() => {
    if (!query.trim()) {
      setOptions([]);
      setLoading(false);
      return;
    }
    if (debounceRef.current) clearTimeout(debounceRef.current);
    setLoading(true);
    debounceRef.current = setTimeout(() => {
      ProductosService.search(query.trim())
        .then((data) => setOptions(data))
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

  const displayValue = value ? value.nombre : "";

  return (
    <Popover open={open} onOpenChange={(o) => setOpen(o)}>
      <div className={className}>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            role="combobox"
            aria-expanded={open}
            disabled={disabled}
            className="h-9 w-full justify-between font-normal"
          >
            <span className={value ? "text-slate-900" : "text-slate-500"}>
              {displayValue || placeholder}
            </span>
            <ChevronDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent align="start" className="min-w-[var(--radix-popover-trigger-width)] p-0">
          <div className="border-b border-slate-200 p-2">
            <Input
              placeholder="Escribe para buscar…"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => e.stopPropagation()}
              className="h-8"
              autoFocus
            />
          </div>
          <div className="max-h-[240px] overflow-auto p-1">
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
                {value && onClear && (
                  <li>
                    <button
                      type="button"
                      className="w-full rounded px-2 py-1.5 text-left text-sm text-slate-500 hover:bg-slate-100 focus:bg-slate-100 focus:outline-none"
                      onClick={() => {
                        onClear();
                        setOpen(false);
                        setQuery("");
                      }}
                    >
                      — Quitar selección
                    </button>
                  </li>
                )}
                {options.map((opt) => (
                  <li key={opt.id}>
                    <button
                      type="button"
                      className="w-full rounded px-2 py-1.5 text-left text-sm hover:bg-slate-100 focus:bg-slate-100 focus:outline-none"
                      onClick={() => {
                        onSelect(opt.id, opt.nombre);
                        setOpen(false);
                        setQuery("");
                      }}
                    >
                      {opt.nombre}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </PopoverContent>
      </div>
    </Popover>
  );
}
