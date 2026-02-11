 "use client";

import * as React from "react";
import { z } from "zod";
import { zodResolver } from "@hookform/resolvers/zod";
import { CalendarIcon } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
  useForm,
} from "@/components/ui/form";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Calendar } from "@/components/ui/calendar";
import { EstadosService, TiposService, TendersService } from "@/services/api";
import type { Estado, Tipo } from "@/types/api";

type CreateTenderDialogProps = {
  triggerLabel?: string;
  onSuccess?: () => void;
};

const formSchema = z.object({
  nombre: z.string().min(1, "El nombre del proyecto es obligatorio"),
  expediente: z.string().min(1, "El nº de expediente es obligatorio"),
  f_presentacion: z.date({
    required_error: "La fecha de presentación es obligatoria",
  }),
  f_adjudicacion: z.date({
    required_error: "La fecha de adjudicación es obligatoria",
  }),
  f_finalizacion: z.date({
    required_error: "La fecha de finalización es obligatoria",
  }),
  presupuesto: z
    .union([z.string(), z.number()])
    .transform((val) => (typeof val === "string" ? parseFloat(val || "0") : val))
    .refine((val) => !Number.isNaN(val) && val >= 0, "Introduce un importe válido"),
  estado_id: z.string().min(1, "Selecciona un estado inicial"),
  tipo_id: z.string().min(1, "Selecciona un tipo de licitación"),
  notas: z.string().min(1, "Las notas/descripción son obligatorias"),
});

type FormValues = z.infer<typeof formSchema>;

export function CreateTenderDialog({
  triggerLabel = "Nueva Licitación",
  onSuccess,
}: CreateTenderDialogProps) {
  const [open, setOpen] = React.useState(false);
  const [submitting, setSubmitting] = React.useState(false);
  const [estados, setEstados] = React.useState<Estado[]>([]);
  const [tipos, setTipos] = React.useState<Tipo[]>([]);
  const [loadingMaestros, setLoadingMaestros] = React.useState(false);
  const [openDatePopover, setOpenDatePopover] = React.useState<
    "f_presentacion" | "f_adjudicacion" | "f_finalizacion" | null
  >(null);
  const [dateInputValues, setDateInputValues] = React.useState<
    Record<"f_presentacion" | "f_adjudicacion" | "f_finalizacion", string>
  >({ f_presentacion: "", f_adjudicacion: "", f_finalizacion: "" });

  React.useEffect(() => {
    if (!open) {
      setOpenDatePopover(null);
      return;
    }
    const v = form.getValues();
    setDateInputValues({
      f_presentacion: v.f_presentacion ? formatDateForInput(v.f_presentacion) : "",
      f_adjudicacion: v.f_adjudicacion ? formatDateForInput(v.f_adjudicacion) : "",
      f_finalizacion: v.f_finalizacion ? formatDateForInput(v.f_finalizacion) : "",
    });
  }, [open]);

  function formatDateForInput(date: Date): string {
    const d = date.getDate();
    const m = date.getMonth() + 1;
    const y = date.getFullYear();
    return `${d.toString().padStart(2, "0")}/${m.toString().padStart(2, "0")}/${y}`;
  }

  function parseDateInput(str: string): Date | null {
    const t = str.trim().replace(/\s+/g, "");
    if (!t) return null;
    const parts = t.split(/[/.-]/);
    if (parts.length !== 3) return null;
    const d = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10) - 1;
    let y = parseInt(parts[2], 10);
    if (y >= 0 && y < 100) y += 2000;
    if (Number.isNaN(d) || Number.isNaN(m) || Number.isNaN(y)) return null;
    const date = new Date(y, m, d);
    if (date.getFullYear() !== y || date.getMonth() !== m || date.getDate() !== d)
      return null;
    return date;
  }

  React.useEffect(() => {
    if (!open) return;
    setLoadingMaestros(true);
    Promise.all([EstadosService.getAll(), TiposService.getAll()])
      .then(([e, t]) => {
        setEstados(e);
        setTipos(t);
      })
      .catch((err) => {
        console.error("Error cargando estados/tipos", err);
      })
      .finally(() => setLoadingMaestros(false));
  }, [open]);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      nombre: "",
      expediente: "",
      presupuesto: 0,
      notas: "",
      estado_id: "",
      tipo_id: "",
    },
  });

  async function onSubmit(values: FormValues) {
    setSubmitting(true);
    try {
      const id_estado = Number(values.estado_id);
      const tipo_de_licitacion = Number(values.tipo_id);

      await TendersService.create({
        nombre: values.nombre,
        numero_expediente: values.expediente,
        pres_maximo: values.presupuesto ?? 0,
        descripcion: values.notas,
        id_estado,
        tipo_de_licitacion,
        fecha_presentacion: values.f_presentacion.toISOString().split("T")[0],
        fecha_adjudicacion: values.f_adjudicacion.toISOString().split("T")[0],
        fecha_finalizacion: values.f_finalizacion.toISOString().split("T")[0],
      });

      // eslint-disable-next-line no-alert
      alert("Licitación creada correctamente");
      form.reset();
      setDateInputValues({ f_presentacion: "", f_adjudicacion: "", f_finalizacion: "" });
      setOpen(false);
      onSuccess?.();
    } catch (error) {
      console.error("Error creando licitación", error);
      // eslint-disable-next-line no-alert
      alert(error instanceof Error ? error.message : "Ha ocurrido un error al crear la licitación");
    } finally {
      setSubmitting(false);
    }
  }

  function renderDateField(
    field: {
      value?: Date;
      onChange: (date?: Date) => void;
    },
    placeholder: string,
    popoverKey: "f_presentacion" | "f_adjudicacion" | "f_finalizacion"
  ) {
    const isOpen = openDatePopover === popoverKey;
    const inputValue = dateInputValues[popoverKey];

    return (
      <div className="flex gap-2">
        <Input
          type="text"
          placeholder="dd/mm/aaaa"
          value={inputValue}
          onChange={(e) =>
            setDateInputValues((prev) => ({ ...prev, [popoverKey]: e.target.value }))
          }
          onBlur={() => {
            const parsed = parseDateInput(inputValue);
            if (parsed) {
              field.onChange(parsed);
              setDateInputValues((prev) => ({
                ...prev,
                [popoverKey]: formatDateForInput(parsed),
              }));
            } else if (inputValue.trim() !== "" && !field.value) {
              setDateInputValues((prev) => ({
                ...prev,
                [popoverKey]: field.value ? formatDateForInput(field.value) : "",
              }));
            }
          }}
          className="flex-1 font-mono text-sm"
          aria-label={placeholder}
        />
        <Popover
          open={isOpen}
          onOpenChange={(open) => setOpenDatePopover(open ? popoverKey : null)}
        >
          <PopoverTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="icon"
              className="h-9 w-9 shrink-0"
              aria-label="Abrir calendario"
            >
              <CalendarIcon className="h-4 w-4" />
            </Button>
          </PopoverTrigger>
          <PopoverContent className="w-auto p-0" align="end">
            <Calendar
              mode="single"
              selected={field.value}
              onSelect={(date) => {
                field.onChange(date);
                setDateInputValues((prev) => ({
                  ...prev,
                  [popoverKey]: date ? formatDateForInput(date) : "",
                }));
                setOpenDatePopover(null);
              }}
            />
          </PopoverContent>
        </Popover>
      </div>
    );
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button className="gap-2">
          {triggerLabel}
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle>Nueva Licitación</DialogTitle>
          <DialogDescription>
            Completa los datos principales de la licitación. Podrás editar el
            detalle más adelante.
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form
            className="mt-2 space-y-4"
            onSubmit={form.handleSubmit(onSubmit)}
          >
            <div className="grid gap-4 md:grid-cols-2">
              <FormField
                control={form.control}
                name="nombre"
                render={({ field }) => (
                  <FormItem className="md:col-span-2">
                    <FormLabel>Nombre del Proyecto</FormLabel>
                    <FormControl>
                      <Input
                        placeholder="Ej. Servicio de limpieza centros educativos"
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="expediente"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Nº Expediente</FormLabel>
                    <FormControl>
                      <Input placeholder="EXP-24-001" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="presupuesto"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Presupuesto Max (€)</FormLabel>
                    <FormControl>
                      <Input
                        type="number"
                        step="100"
                        min="0"
                        placeholder="0"
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="f_presentacion"
                render={({ field }) => (
                  <FormItem className="flex flex-col">
                    <FormLabel>F. Presentación</FormLabel>
                    <FormControl>
                      {renderDateField(field, "Selecciona la fecha", "f_presentacion")}
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="f_adjudicacion"
                render={({ field }) => (
                  <FormItem className="flex flex-col">
                    <FormLabel>F. Adjudicación</FormLabel>
                    <FormControl>
                      {renderDateField(field, "Selecciona la fecha", "f_adjudicacion")}
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="f_finalizacion"
                render={({ field }) => (
                  <FormItem className="flex flex-col">
                    <FormLabel>F. Finalización</FormLabel>
                    <FormControl>
                      {renderDateField(field, "Selecciona la fecha", "f_finalizacion")}
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="estado_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Estado Inicial</FormLabel>
                    <FormControl>
                      <select
                        className="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:opacity-50"
                        value={field.value ?? ""}
                        onChange={(e) => field.onChange(e.target.value)}
                        disabled={loadingMaestros}
                      >
                        <option value="" disabled>
                          {loadingMaestros ? "Cargando..." : "Selecciona un estado"}
                        </option>
                        {estados.map((est) => (
                          <option key={est.id_estado} value={est.id_estado}>
                            {est.nombre_estado}
                          </option>
                        ))}
                      </select>
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="tipo_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Tipo</FormLabel>
                    <FormControl>
                      <select
                        className="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:opacity-50"
                        value={field.value ?? ""}
                        onChange={(e) => field.onChange(e.target.value)}
                        disabled={loadingMaestros}
                      >
                        <option value="" disabled>
                          {loadingMaestros ? "Cargando..." : "Selecciona un tipo"}
                        </option>
                        {tipos.map((t) => (
                          <option key={t.id_tipolicitacion} value={t.id_tipolicitacion}>
                            {t.tipo}
                          </option>
                        ))}
                      </select>
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <FormField
              control={form.control}
              name="notas"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Notas / Descripción</FormLabel>
                  <FormControl>
                    <Textarea
                      rows={3}
                      placeholder="Notas internas, matices del pliego, alcance, etc."
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <div className="flex justify-end gap-2 pt-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(false)}
                disabled={submitting}
              >
                Cancelar
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? "Guardando..." : "Guardar Licitación"}
              </Button>
            </div>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}

