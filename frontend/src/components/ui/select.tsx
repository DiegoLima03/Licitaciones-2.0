"use client";

import * as React from "react";
import { cn } from "@/lib/utils";

type SelectContextValue = {
  value?: string;
  setValue: (value: string) => void;
};

const SelectContext = React.createContext<SelectContextValue | null>(null);

export function Select({
  value,
  defaultValue,
  onValueChange,
  children,
}: {
  value?: string;
  defaultValue?: string;
  onValueChange?: (value: string) => void;
  children: React.ReactNode;
}) {
  const [internal, setInternal] = React.useState(defaultValue);
  const realValue = value ?? internal;

  const setValue = (next: string) => {
    if (value === undefined) {
      setInternal(next);
    }
    onValueChange?.(next);
  };

  return (
    <SelectContext.Provider value={{ value: realValue, setValue }}>
      {children}
    </SelectContext.Provider>
  );
}

function useSelectContext(component: string) {
  const ctx = React.useContext(SelectContext);
  if (!ctx) {
    throw new Error(`${component} must be used within <Select>`);
  }
  return ctx;
}

export function SelectTrigger({
  className,
  children,
  ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  const { value } = useSelectContext("SelectTrigger");
  return (
    <button
      type="button"
      className={cn(
        "flex h-9 w-full items-center justify-between rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2",
        className
      )}
      {...props}
    >
      <span className="truncate">
        {value ? (
          children
        ) : (
          <span className="text-slate-400">
            {/* fallback del placeholder se gestiona en SelectValue */}
            Selecciona una opción
          </span>
        )}
      </span>
    </button>
  );
}

export function SelectValue({
  placeholder,
}: {
  placeholder?: string;
}) {
  const { value } = useSelectContext("SelectValue");
  return (
    <span className={cn(!value && "text-slate-400")}>
      {value || placeholder || "Selecciona una opción"}
    </span>
  );
}

export function SelectContent({
  className,
  children,
}: React.HTMLAttributes<HTMLDivElement>) {
  const [open, setOpen] = React.useState(false);
  const ref = React.useRef<HTMLDivElement | null>(null);

  React.useEffect(() => {
    function onClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    if (open) {
      window.addEventListener("mousedown", onClickOutside);
    }
    return () => window.removeEventListener("mousedown", onClickOutside);
  }, [open]);

  React.useEffect(() => {
    // Abrimos automáticamente al render; en esta versión sencilla,
    // SelectContent se muestra siempre.
    setOpen(true);
  }, []);

  if (!open) return null;

  return (
    <div
      ref={ref}
      className={cn(
        "mt-1 max-h-60 w-full overflow-auto rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg",
        className
      )}
    >
      {children}
    </div>
  );
}

export function SelectItem({
  className,
  value,
  children,
  ...props
}: React.HTMLAttributes<HTMLDivElement> & { value: string }) {
  const { setValue } = useSelectContext("SelectItem");

  return (
    <div
      role="option"
      tabIndex={0}
      className={cn(
        "cursor-pointer px-3 py-1.5 text-sm text-slate-900 hover:bg-slate-100",
        className
      )}
      onClick={() => setValue(value)}
      {...props}
    >
      {children}
    </div>
  );
}

