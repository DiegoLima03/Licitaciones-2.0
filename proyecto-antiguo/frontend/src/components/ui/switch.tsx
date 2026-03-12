"use client";

import * as React from "react";
import { cn } from "@/lib/utils";

export interface SwitchProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  checked?: boolean;
  onCheckedChange?: (checked: boolean) => void;
}

export const Switch = React.forwardRef<HTMLButtonElement, SwitchProps>(
  ({ checked, onCheckedChange, className, ...props }, ref) => {
    const [internal, setInternal] = React.useState(false);
    const isOn = checked ?? internal;

    function toggle() {
      const next = !isOn;
      if (checked === undefined) {
        setInternal(next);
      }
      onCheckedChange?.(next);
    }

    return (
      <button
        type="button"
        role="switch"
        aria-checked={isOn}
        ref={ref}
        onClick={toggle}
        className={cn(
          "inline-flex h-5 w-9 items-center rounded-full border transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2",
          isOn
            ? "border-emerald-500 bg-emerald-500/80"
            : "border-slate-300 bg-slate-200",
          className
        )}
        {...props}
      >
        <span
          className={cn(
            "ml-0.5 inline-block h-4 w-4 rounded-full bg-white shadow-sm transition-transform",
            isOn && "translate-x-4"
          )}
        />
      </button>
    );
  }
);

Switch.displayName = "Switch";

