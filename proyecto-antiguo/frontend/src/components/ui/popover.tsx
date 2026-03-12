"use client";

import * as React from "react";
import { cn } from "@/lib/utils";

type PopoverContextValue = {
  open: boolean;
  setOpen: (open: boolean) => void;
};

const PopoverContext = React.createContext<PopoverContextValue | null>(null);

export function Popover({
  children,
  open: controlledOpen,
  onOpenChange,
}: {
  children: React.ReactNode;
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
}) {
  const [internalOpen, setInternalOpen] = React.useState(false);
  const isControlled = controlledOpen !== undefined && onOpenChange !== undefined;
  const open = isControlled ? controlledOpen : internalOpen;
  const setOpen = isControlled ? onOpenChange : setInternalOpen;

  return (
    <PopoverContext.Provider value={{ open, setOpen }}>
      <div className="relative inline-block">{children}</div>
    </PopoverContext.Provider>
  );
}

function usePopoverContext(component: string) {
  const ctx = React.useContext(PopoverContext);
  if (!ctx) {
    throw new Error(`${component} must be used within <Popover>`);
  }
  return ctx;
}

export function PopoverTrigger({
  asChild,
  children,
}: {
  asChild?: boolean;
  children: React.ReactElement;
}) {
  const { open, setOpen } = usePopoverContext("PopoverTrigger");

  if (asChild) {
    return React.cloneElement(children, {
      "aria-expanded": open,
      onClick: (event: React.MouseEvent) => {
        children.props.onClick?.(event);
        setOpen(!open);
      },
    });
  }

  return (
    <button
      type="button"
      aria-expanded={open}
      onClick={() => setOpen(!open)}
    >
      {children}
    </button>
  );
}

export function PopoverContent({
  className,
  align,
  children,
}: React.HTMLAttributes<HTMLDivElement> & { align?: "start" | "center" | "end" }) {
  const { open } = usePopoverContext("PopoverContent");

  if (!open) return null;

  let justify = "left-0";
  if (align === "center") justify = "left-1/2 -translate-x-1/2";
  if (align === "end") justify = "right-0";

  return (
    <div
      className={cn(
        "absolute z-50 mt-1 min-w-[220px] rounded-md border border-slate-200 bg-white p-2 shadow-lg",
        justify,
        className
      )}
    >
      {children}
    </div>
  );
}

