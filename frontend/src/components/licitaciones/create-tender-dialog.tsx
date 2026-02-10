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

type CreateTenderDialogProps = {
  estados?: string[];
  tipos?: string[];
  triggerLabel?: string;
};

const formSchema = z.object({
  nombre: z.string().min(1, "El nombre del proyecto es obligatorio"),
  expediente: z.string().optional(),
  f_presentacion: z.date({
    required_error: "La fecha de presentación es obligatoria",
  }),
  f_adjudicacion: z.date().optional(),
  presupuesto: z
    .union([z.string(), z.number()])
    .transform((val) => (typeof val === "string" ? parseFloat(val || "0") : val))
    .refine((val) => !Number.isNaN(val) && val >= 0, "Introduce un importe válido"),
  estado: z.string().min(1, "Selecciona un estado inicial"),
  tipo: z.string().min(1, "Selecciona un tipo de licitación"),
  notas: z.string().optional(),
});

type FormValues = z.infer<typeof formSchema>;

export function CreateTenderDialog({
  estados = [
    "En Estudio",
    "Presentada",
    "Pendiente de Fallo",
    "Pendiente",
    "Adjudicada",
    "Desierta",
  ],
  tipos = ["Obra", "Servicio", "Suministro"],
  triggerLabel = "Nueva Licitación",
}: CreateTenderDialogProps) {
  const [open, setOpen] = React.useState(false);
  const [submitting, setSubmitting] = React.useState(false);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      nombre: "",
      expediente: "",
      presupuesto: 0,
      notas: "",
    },
  });

  async function onSubmit(values: FormValues) {
    setSubmitting(true);
    try {
      const payload = {
        nombre: values.nombre,
        numero_expediente: values.expediente || null,
        pres_maximo: values.presupuesto ?? 0,
        descripcion: values.notas || "",
        estado_nombre: values.estado,
        tipo_nombre: values.tipo,
        fecha_presentacion: values.f_presentacion.toISOString().split("T")[0],
        fecha_adjudicacion: values.f_adjudicacion
          ? values.f_adjudicacion.toISOString().split("T")[0]
          : null,
      };

      await fetch("/api/licitaciones", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      // En un proyecto real usarías el sistema de toasts de shadcn (useToast).
      // Aquí dejamos un alert sencillo para visualizar el flujo.
      // eslint-disable-next-line no-alert
      alert("Licitación creada correctamente");

      form.reset();
      setOpen(false);
    } catch (error) {
      console.error("Error creando licitación", error);
      // eslint-disable-next-line no-alert
      alert("Ha ocurrido un error al crear la licitación");
    } finally {
      setSubmitting(false);
    }
  }

  function renderDateField(
    field: {
      value?: Date;
      onChange: (date?: Date) => void;
    },
    placeholder: string
  ) {
    return (
      <Popover>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            className="w-full justify-start text-left font-normal"
          >
            <CalendarIcon className="mr-2 h-4 w-4" />
            {field.value
              ? field.value.toLocaleDateString("es-ES")
              : placeholder}
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-auto p-0" align="start">
          <Calendar
            mode="single"
            selected={field.value}
            onSelect={field.onChange}
            initialFocus
          />
        </PopoverContent>
      </Popover>
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
                      {renderDateField(field, "Selecciona la fecha")}
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
                      {renderDateField(field, "Selecciona la fecha (opcional)")}
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="estado"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Estado Inicial</FormLabel>
                    <FormControl>
                      <select
                        className="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
                        value={field.value ?? ""}
                        onChange={(e) => field.onChange(e.target.value)}
                      >
                        <option value="" disabled>
                          Selecciona un estado
                        </option>
                        {estados.map((estado) => (
                          <option key={estado} value={estado}>
                            {estado}
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
                name="tipo"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Tipo</FormLabel>
                    <FormControl>
                      <select
                        className="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
                        value={field.value ?? ""}
                        onChange={(e) => field.onChange(e.target.value)}
                      >
                        <option value="" disabled>
                          Selecciona un tipo
                        </option>
                        {tipos.map((tipo) => (
                          <option key={tipo} value={tipo}>
                            {tipo}
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

