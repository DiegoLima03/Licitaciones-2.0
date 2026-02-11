"use client";

import * as React from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { cn } from "@/lib/utils";

type CalendarProps = {
  selected?: Date;
  onSelect?: (date: Date | undefined) => void;
  mode?: "single";
  initialFocus?: boolean;
};

const WEEKDAYS = ["Lu", "Ma", "Mi", "Ju", "Vi", "Sá", "Do"];

function isSameDay(a: Date, b: Date): boolean {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}

function getMonthYearLabel(date: Date): string {
  return date.toLocaleDateString("es-ES", { month: "long", year: "numeric" });
}

/**
 * Calendario tipo rejilla (estilo Shadcn/UI).
 * Sin input type="date" para evitar doble calendario y bugs de escritura.
 * Solo selección por clic en día.
 */
export function Calendar({
  selected,
  onSelect,
}: CalendarProps) {
  const [viewMonth, setViewMonth] = React.useState<Date>(() => {
    const d = selected ?? new Date();
    return new Date(d.getFullYear(), d.getMonth(), 1);
  });

  const year = viewMonth.getFullYear();
  const month = viewMonth.getMonth();
  const firstDay = new Date(year, month, 1);
  const lastDay = new Date(year, month + 1, 0);
  const daysInMonth = lastDay.getDate();
  const startWeekday = firstDay.getDay();
  const startOffset = startWeekday === 0 ? 6 : startWeekday - 1;

  const days: (number | null)[] = [];
  for (let i = 0; i < startOffset; i++) days.push(null);
  for (let d = 1; d <= daysInMonth; d++) days.push(d);
  const remainder = days.length % 7;
  if (remainder !== 0) {
    for (let i = 0; i < 7 - remainder; i++) days.push(null);
  }

  const prevMonth = () => {
    setViewMonth((m) => new Date(m.getFullYear(), m.getMonth() - 1, 1));
  };

  const nextMonth = () => {
    setViewMonth((m) => new Date(m.getFullYear(), m.getMonth() + 1, 1));
  };

  const handleDayClick = (day: number) => {
    const date = new Date(year, month, day);
    onSelect?.(date);
  };

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  return (
    <div
      className="p-3"
      role="application"
      aria-label="Calendario"
      onClick={(e) => e.stopPropagation()}
      onKeyDown={(e) => e.stopPropagation()}
    >
      <div className="flex items-center justify-between gap-2 border-b border-slate-200 pb-2">
        <button
          type="button"
          onClick={prevMonth}
          className="rounded p-1.5 text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
          aria-label="Mes anterior"
        >
          <ChevronLeft className="h-4 w-4" />
        </button>
        <span className="text-sm font-medium capitalize text-slate-900">
          {getMonthYearLabel(viewMonth)}
        </span>
        <button
          type="button"
          onClick={nextMonth}
          className="rounded p-1.5 text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
          aria-label="Mes siguiente"
        >
          <ChevronRight className="h-4 w-4" />
        </button>
      </div>

      <div className="mt-2 grid grid-cols-7 gap-1 text-center text-xs">
        {WEEKDAYS.map((w) => (
          <div key={w} className="py-1 font-medium text-slate-500">
            {w}
          </div>
        ))}
        {days.map((day, idx) => {
          if (day === null) {
            return <div key={`empty-${idx}`} className="py-1.5" />;
          }
          const date = new Date(year, month, day);
          date.setHours(0, 0, 0, 0);
          const isSelected = selected != null && isSameDay(date, selected);
          const isToday = isSameDay(date, today);
          return (
            <button
              key={`${year}-${month}-${day}`}
              type="button"
              onClick={() => handleDayClick(day)}
              className={cn(
                "rounded py-1.5 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-1",
                isSelected &&
                  "bg-emerald-600 text-white hover:bg-emerald-700",
                !isSelected &&
                  "text-slate-900 hover:bg-slate-100",
                isToday && !isSelected && "font-semibold text-emerald-600"
              )}
              aria-label={date.toLocaleDateString("es-ES", {
                day: "numeric",
                month: "long",
                year: "numeric",
              })}
            >
              {day}
            </button>
          );
        })}
      </div>
    </div>
  );
}
