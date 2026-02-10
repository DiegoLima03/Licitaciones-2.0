"use client";

import * as React from "react";
import { createPortal } from "react-dom";
import { cn } from "@/lib/utils";

type DialogContextValue = {
  open: boolean;
  setOpen: (open: boolean) => void;
};

const DialogContext = React.createContext<DialogContextValue | null>(null);

export type DialogProps = {
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  children: React.ReactNode;
};

export function Dialog({ open, onOpenChange, children }: DialogProps) {
  const [internalOpen, setInternalOpen] = React.useState(false);

  const realOpen = open ?? internalOpen;

  const setOpen = (next: boolean) => {
    if (open === undefined) {
      setInternalOpen(next);
    }
    onOpenChange?.(next);
  };

  return (
    <DialogContext.Provider value={{ open: realOpen, setOpen }}>
      {children}
    </DialogContext.Provider>
  );
}

function useDialogContext(component: string) {
  const ctx = React.useContext(DialogContext);
  if (!ctx) {
    throw new Error(`${component} must be used within <Dialog>`);
  }
  return ctx;
}

export function DialogTrigger({
  asChild,
  children,
}: {
  asChild?: boolean;
  children: React.ReactElement;
}) {
  const { setOpen } = useDialogContext("DialogTrigger");

  if (asChild) {
    return React.cloneElement(children, {
      onClick: (event: React.MouseEvent) => {
        children.props.onClick?.(event);
        setOpen(true);
      },
    });
  }

  return (
    <button
      type="button"
      onClick={() => setOpen(true)}
    >
      {children}
    </button>
  );
}

export function DialogContent({
  className,
  children,
}: React.HTMLAttributes<HTMLDivElement>) {
  const { open, setOpen } = useDialogContext("DialogContent");
  const [mounted, setMounted] = React.useState(false);

  React.useEffect(() => {
    setMounted(true);
  }, []);

  if (!mounted) return null;
  if (!open) return null;

  return createPortal(
    <>
      <div
        className="fixed inset-0 z-40 bg-black/40"
        onClick={() => setOpen(false)}
      />
      <div className="fixed inset-0 z-50 flex items-center justify-center px-4">
        <div
          className={cn(
            "w-full max-w-lg rounded-xl bg-white p-4 shadow-xl",
            className
          )}
        >
          {children}
        </div>
      </div>
    </>,
    document.body
  );
}

export function DialogHeader({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn("space-y-1 border-b border-slate-100 pb-3", className)}
      {...props}
    />
  );
}

export function DialogTitle({
  className,
  ...props
}: React.HTMLAttributes<HTMLHeadingElement>) {
  return (
    <h2
      className={cn("text-base font-semibold text-slate-900", className)}
      {...props}
    />
  );
}

export function DialogDescription({
  className,
  ...props
}: React.HTMLAttributes<HTMLParagraphElement>) {
  return (
    <p
      className={cn("text-sm text-slate-500", className)}
      {...props}
    />
  );
}

