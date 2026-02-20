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
import Image from "next/image";
import { ChevronDown } from "lucide-react";
import { PAIS_FLAG_SRC, PAIS_LABEL, PAISES_OPCIONES } from "@/lib/paises";
import { TiposService, TendersService } from "@/services/api";
import type { PaisLicitacion, Tender, Tipo, TipoProcedimiento } from "@/types/api";

type CreateTenderDialogProps = {
  triggerLabel?: string;
  onSuccess?: () => void;
  /** Modo controlado: abre/cierra desde fuera (ej. botón "Generar Contrato Basado"). */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  /** Pre-rellena padre y tipo CONTRATO_BASADO; oculta selector de tipo y expediente padre. */
  defaultIdLicitacionPadre?: number;
};

const TIPO_PROCEDIMIENTO_VALUES: TipoProcedimiento[] = [
  "ORDINARIO",
  "ACUERDO_MARCO",
  "SDA",
  "CONTRATO_BASADO",
];

const formSchema = z.object({
  nombre: z.string().min(1, "El nombre del proyecto es obligatorio"),
  pais: z.enum(["España", "Portugal"], { required_error: "Selecciona el país de la licitación" }),
  expediente: z.string().min(1, "El nº de expediente es obligatorio"),
  enlace_gober: z
    .string()
    .optional()
    .refine((s) => !s || s.trim() === "" || /^https?:\/\/.+/.test(s.trim()), "Introduce una URL válida"),
  enlace_sharepoint: z
    .string()
    .optional()
    .refine((s) => !s || s.trim() === "" || /^https?:\/\/.+/.test(s.trim()), "Introduce una URL válida"),
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
  tipo_id: z.string().min(1, "Selecciona un tipo de licitación"),
  tipo_procedimiento: z.enum([
    "ORDINARIO",
    "ACUERDO_MARCO",
    "SDA",
    "CONTRATO_BASADO",
  ] as const),
  id_licitacion_padre: z.string().optional(),
  notas: z.string().optional().default(""),
}).superRefine((data, ctx) => {
  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);
  const fPresentacion = data.f_presentacion;
  if (fPresentacion && fPresentacion > hoy) {
    const enlace = (data.enlace_gober ?? "").trim();
    if (!enlace) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: "El enlace a Gober es obligatorio cuando la fecha de presentación es futura",
        path: ["enlace_gober"],
      });
    } else if (!/^https?:\/\/.+/.test(enlace)) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: "Introduce una URL válida",
        path: ["enlace_gober"],
      });
    }
  }
  if (
    data.tipo_procedimiento === "CONTRATO_BASADO" &&
    (!data.id_licitacion_padre || String(data.id_licitacion_padre).trim() === "")
  ) {
    ctx.addIssue({
      code: z.ZodIssueCode.custom,
      message: "Selecciona el expediente padre (AM/SDA adjudicado)",
      path: ["id_licitacion_padre"],
    });
  }
});

type FormValues = z.infer<typeof formSchema>;

export function CreateTenderDialog({
  triggerLabel = "Nueva Licitación",
  onSuccess,
  open: controlledOpen,
  onOpenChange: controlledOnOpenChange,
  defaultIdLicitacionPadre,
}: CreateTenderDialogProps) {
  const [internalOpen, setInternalOpen] = React.useState(false);
  const isControlled = controlledOpen !== undefined && controlledOnOpenChange !== undefined;
  const open = isControlled ? controlledOpen : internalOpen;
  const setOpen = isControlled ? controlledOnOpenChange : setInternalOpen;
  const isContratoBasadoFromParent = defaultIdLicitacionPadre != null;
  const [submitting, setSubmitting] = React.useState(false);
  const [tipos, setTipos] = React.useState<Tipo[]>([]);
  const [parentTenders, setParentTenders] = React.useState<Tender[]>([]);
  const [loadingMaestros, setLoadingMaestros] = React.useState(false);
  const [paisPopoverOpen, setPaisPopoverOpen] = React.useState(false);
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
    Promise.all([TiposService.getAll(), TendersService.getParents()])
      .then(([t, parents]) => {
        setTipos(t);
        setParentTenders(parents);
      })
      .catch((err) => {
        console.error("Error cargando maestros o padres", err);
      })
      .finally(() => setLoadingMaestros(false));
  }, [open]);

  React.useEffect(() => {
    if (open && isContratoBasadoFromParent && defaultIdLicitacionPadre != null) {
      form.reset({
        ...form.getValues(),
        tipo_procedimiento: "CONTRATO_BASADO",
        id_licitacion_padre: String(defaultIdLicitacionPadre),
      });
    }
  }, [open, isContratoBasadoFromParent, defaultIdLicitacionPadre]);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      nombre: "",
      pais: "España" as PaisLicitacion,
      expediente: "",
      enlace_gober: "",
      enlace_sharepoint: "",
      presupuesto: 0,
      notas: "",
      tipo_id: "",
      tipo_procedimiento: "ORDINARIO",
      id_licitacion_padre: "",
    },
  });

  const tipoProcedimiento = form.watch("tipo_procedimiento");
  const showExpedientePadre = tipoProcedimiento === "CONTRATO_BASADO";

  async function onSubmit(values: FormValues) {
    setSubmitting(true);
    try {
      const id_tipolicitacion = Number(values.tipo_id);

      const idLicitacionPadre =
        values.id_licitacion_padre && String(values.id_licitacion_padre).trim() !== ""
          ? Number(values.id_licitacion_padre)
          : undefined;

      await TendersService.create({
        nombre: values.nombre,
        pais: values.pais,
        numero_expediente: values.expediente,
        enlace_gober: values.enlace_gober?.trim() || undefined,
        enlace_sharepoint: values.enlace_sharepoint?.trim() || undefined,
        pres_maximo: values.presupuesto ?? 0,
        descripcion: values.notas ?? "",
        id_tipolicitacion,
        fecha_presentacion: values.f_presentacion.toISOString().split("T")[0],
        fecha_adjudicacion: values.f_adjudicacion.toISOString().split("T")[0],
        fecha_finalizacion: values.f_finalizacion.toISOString().split("T")[0],
        tipo_procedimiento: values.tipo_procedimiento,
        id_licitacion_padre: idLicitacionPadre ?? null,
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
      {!isControlled && (
        <DialogTrigger asChild>
          <Button className="gap-2">
            {triggerLabel}
          </Button>
        </DialogTrigger>
      )}
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle>
            {isContratoBasadoFromParent ? "Nuevo Contrato Basado" : "Nueva Licitación"}
          </DialogTitle>
          <DialogDescription>
            {isContratoBasadoFromParent
              ? "Contrato derivado de este Acuerdo Marco / SDA. Completa los datos del contrato."
              : "Completa los datos principales de la licitación. Podrás editar el detalle más adelante."}
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
                name="pais"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>País</FormLabel>
                    <FormControl>
                      <Popover open={paisPopoverOpen} onOpenChange={setPaisPopoverOpen}>
                        <PopoverTrigger asChild>
                          <button
                            type="button"
                            className="flex h-9 w-full items-center justify-between gap-2 rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
                          >
                            <span className="flex items-center gap-2">
                              {field.value ? (
                                <>
                                  <Image
                                    src={PAIS_FLAG_SRC[field.value]}
                                    alt=""
                                    width={32}
                                    height={20}
                                    unoptimized
                                    className="h-5 w-8 rounded object-cover object-center"
                                  />
                                  <span>{PAIS_LABEL[field.value]}</span>
                                </>
                              ) : (
                                <span className="text-slate-400">Selecciona el país</span>
                              )}
                            </span>
                            <ChevronDown className="h-4 w-4 text-slate-400" />
                          </button>
                        </PopoverTrigger>
                        <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
                          <div className="py-1">
                            {PAISES_OPCIONES.map((p) => (
                              <button
                                key={p}
                                type="button"
                                onClick={() => {
                                  field.onChange(p);
                                  setPaisPopoverOpen(false);
                                }}
                                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-900 hover:bg-slate-100"
                              >
                                <Image
                                  src={PAIS_FLAG_SRC[p]}
                                  alt=""
                                  width={32}
                                  height={20}
                                  unoptimized
                                  className="h-5 w-8 rounded object-cover object-center"
                                />
                                <span>{PAIS_LABEL[p]}</span>
                              </button>
                            ))}
                          </div>
                        </PopoverContent>
                      </Popover>
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
                name="enlace_gober"
                render={({ field }) => (
                  <FormItem className="md:col-span-2">
                    <FormLabel>Enlace Gober (obligatorio si la fecha de presentación es futura)</FormLabel>
                    <FormControl>
                      <Input
                        type="url"
                        placeholder="https://gober.es/... (URL de la licitación en Gober)"
                        {...field}
                        value={field.value ?? ""}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="enlace_sharepoint"
                render={({ field }) => (
                  <FormItem className="md:col-span-2">
                    <FormLabel>Enlace SharePoint</FormLabel>
                    <FormControl>
                      <Input
                        type="url"
                        placeholder="https://... (carpeta o sitio con documentación e información)"
                        {...field}
                        value={field.value ?? ""}
                      />
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
                        step="1"
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

              {!isContratoBasadoFromParent && (
                <FormField
                  control={form.control}
                  name="tipo_procedimiento"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Tipo de procedimiento</FormLabel>
                      <FormControl>
                        <select
                          className="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:opacity-50"
                          value={field.value}
                          onChange={(e) => {
                            const v = e.target.value as FormValues["tipo_procedimiento"];
                            field.onChange(v);
                            if (v !== "CONTRATO_BASADO") form.setValue("id_licitacion_padre", "");
                          }}
                        >
                          <option value="ORDINARIO">Licitación</option>
                          <option value="ACUERDO_MARCO">Acuerdo Marco</option>
                          <option value="SDA">SDA</option>
                          {/* Contrato Basado solo desde el detalle del AM/SDA (Generar Contrato Basado) */}
                        </select>
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              )}

              {showExpedientePadre && !isContratoBasadoFromParent && (
                <FormField
                  control={form.control}
                  name="id_licitacion_padre"
                  render={({ field }) => (
                    <FormItem className="md:col-span-2">
                      <FormLabel>Expediente Padre</FormLabel>
                      <FormControl>
                        <select
                          className="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:opacity-50"
                          value={field.value ?? ""}
                          onChange={(e) => field.onChange(e.target.value)}
                        >
                          <option value="">
                            {parentTenders.length === 0
                              ? "No hay AM/SDA adjudicados"
                              : "Selecciona el expediente padre"}
                          </option>
                          {parentTenders.map((p) => (
                            <option key={p.id_licitacion} value={p.id_licitacion}>
                              {p.numero_expediente ?? `#${p.id_licitacion}`} — {p.nombre}
                            </option>
                          ))}
                        </select>
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              )}
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
                {submitting ? "Guardando..." : isContratoBasadoFromParent ? "Crear Contrato Basado" : "Guardar Licitación"}
              </Button>
            </div>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}

