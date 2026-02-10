import * as React from "react";

type CalendarProps = {
  selected?: Date;
  onSelect?: (date: Date | undefined) => void;
  /**
   * Se mantiene la prop `mode` para compatibilidad con la API de shadcn,
   * aunque aquí no la usamos realmente.
   */
  mode?: "single";
  initialFocus?: boolean;
};

/**
 * Implementación mínima de un selector de fecha para evitar dependencias
 * adicionales. Si más adelante añades shadcn UI completo, puedes reemplazar
 * este componente por el `Calendar` oficial basado en `react-day-picker`.
 */
export function Calendar({
  selected,
  onSelect,
}: CalendarProps) {
  const [internal, setInternal] = React.useState<Date | undefined>(selected);

  React.useEffect(() => {
    setInternal(selected);
  }, [selected]);

  const value =
    internal != null
      ? internal.toISOString().split("T")[0]
      : "";

  function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
    const next = e.target.value
      ? new Date(e.target.value + "T00:00:00")
      : undefined;
    setInternal(next);
    onSelect?.(next);
  }

  return (
    <input
      type="date"
      value={value}
      onChange={handleChange}
      className="w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-900 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
    />
  );
}

