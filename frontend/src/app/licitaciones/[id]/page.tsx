"use client";

import * as React from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { z } from "zod";
import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowLeft, Edit3 } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
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
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs";
import { Switch } from "@/components/ui/switch";
import { ProductCombobox } from "@/components/producto-combobox";
import { EditableBudgetTable } from "@/components/licitaciones/editable-budget-table";
import { DeliveriesService, EstadosService, TendersService, TiposService } from "@/services/api";
import type {
  Estado,
  EntregaWithLines,
  TenderDetail,
  TenderPartida,
  Tipo,
} from "@/types/api";

type PresupuestoItem = {
  id: number;
  lote: string;
  descripcion: string;
  unidades: number;
  pcu: number;
  pvu: number;
  activo: boolean;
};

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 0,
  }).format(value);
}

function formatDate(date?: string | null) {
  if (!date) return "-";
  return new Date(date + "T00:00:00").toLocaleDateString("es-ES");
}

function mapPartidas(partidas: TenderPartida[]): PresupuestoItem[] {
  return partidas.map((p) => ({
    id: p.id_detalle,
    lote: p.lote ?? "General",
    descripcion: p.product_nombre ?? "",
    unidades: Number(p.unidades) || 0,
    pcu: Number(p.pcu) || 0,
    pvu: Number(p.pvu) || 0,
    activo: p.activo ?? true,
  }));
}

/** Agrupa partidas por (lote, descripcion), sumando unidades. Una sola fila por concepto. */
function agregarPartidas(items: PresupuestoItem[]): PresupuestoItem[] {
  const map = new Map<string, PresupuestoItem>();
  for (const i of items) {
    const key = `${i.lote}|${i.descripcion}`;
    const exist = map.get(key);
    if (exist) {
      exist.unidades += i.unidades;
    } else {
      map.set(key, { ...i, unidades: i.unidades });
    }
  }
  return Array.from(map.values());
}

/** Schema para el formulario de edición de cabecera (fechas y descripción). Coincide con TenderUpdate. */
const cabeceraFormSchema = z.object({
  fecha_presentacion: z.string().optional(),
  fecha_adjudicacion: z.string().optional(),
  fecha_finalizacion: z.string().optional(),
  descripcion: z.string().optional(),
});
type CabeceraFormValues = z.infer<typeof cabeceraFormSchema>;

export default function LicitacionDetallePage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const [lic, setLic] = React.useState<TenderDetail | null>(null);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [lotesActivos, setLotesActivos] = React.useState<string[]>([]);
  const [uniqueLotesFromTable, setUniqueLotesFromTable] = React.useState<string[]>([]);
  const [entregas, setEntregas] = React.useState<EntregaWithLines[]>([]);
  const [estados, setEstados] = React.useState<Estado[]>([]);
  const [tipos, setTipos] = React.useState<Tipo[]>([]);
  const [openAlbaran, setOpenAlbaran] = React.useState(false);
  type LineaTipo = "presupuestada" | "extraordinario";

  const [albaranForm, setAlbaranForm] = React.useState({
    fecha: new Date().toISOString().slice(0, 10),
    codigo_albaran: "",
    observaciones: "",
    lineas: [
      {
        tipo: "presupuestada" as LineaTipo,
        id_producto: null as number | null,
        id_detalle: null as number | null,
        productNombre: "",
        proveedor: "",
        cantidad: "",
        coste_unit: "",
      },
    ],
  });
  const [submittingAlbaran, setSubmittingAlbaran] = React.useState(false);
  const [albaranError, setAlbaranError] = React.useState<string | null>(null);
  const [openEditarCabecera, setOpenEditarCabecera] = React.useState(false);
  const [submittingCabecera, setSubmittingCabecera] = React.useState(false);

  const cabeceraForm = useForm<CabeceraFormValues>({
    resolver: zodResolver(cabeceraFormSchema),
    defaultValues: {
      fecha_presentacion: "",
      fecha_adjudicacion: "",
      fecha_finalizacion: "",
      descripcion: "",
    },
  });

  React.useEffect(() => {
    if (openEditarCabecera && lic) {
      cabeceraForm.reset({
        fecha_presentacion: lic.fecha_presentacion ?? "",
        fecha_adjudicacion: lic.fecha_adjudicacion ?? "",
        fecha_finalizacion: lic.fecha_finalizacion ?? "",
        descripcion: lic.descripcion ?? "",
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reset when dialog opens with lic
  }, [openEditarCabecera, lic]);

  const refetchLicitacion = React.useCallback(() => {
    if (!Number.isFinite(id)) return;
    TendersService.getById(id).then(setLic).catch(() => {});
  }, [id]);

  React.useEffect(() => {
    if (!Number.isFinite(id)) {
      setLoading(false);
      return;
    }
    let cancelled = false;
    setLoading(true);
    setError(null);
    TendersService.getById(id)
      .then((data) => {
        if (!cancelled) setLic(data);
      })
      .catch((e) => {
        if (!cancelled) setError(e instanceof Error ? e.message : "Error al cargar");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [id]);

  React.useEffect(() => {
    if (!lic?.partidas?.length) {
      setLotesActivos([]);
      return;
    }
    const items = mapPartidas(lic.partidas);
    const lotes = Array.from(new Set(items.map((i) => i.lote)));
    setLotesActivos(lotes);
  }, [lic?.id_licitacion, lic?.partidas?.length]);

  const refetchEntregas = React.useCallback(() => {
    if (!Number.isFinite(id)) return;
    DeliveriesService.getByLicitacion(id).then(setEntregas).catch(() => setEntregas([]));
  }, [id]);

  React.useEffect(() => {
    if (!Number.isFinite(id) || !lic) return;
    refetchEntregas();
  }, [id, lic?.id_licitacion, refetchEntregas]);

  React.useEffect(() => {
    Promise.all([EstadosService.getAll(), TiposService.getAll()])
      .then(([e, t]) => {
        setEstados(e ?? []);
        setTipos(t ?? []);
      })
      .catch(() => {
        setEstados([]);
        setTipos([]);
      });
  }, []);

  React.useEffect(() => {
    if (uniqueLotesFromTable.length <= 1) {
      setLotesActivos((prev) => prev.filter((l) => uniqueLotesFromTable.includes(l)));
    }
  }, [uniqueLotesFromTable]);

  /** Unidades reales ejecutadas por partida (clave: "lote|descripcion"). */
  const ejecutadoPorPartida = React.useMemo(() => {
    const map: Record<string, number> = {};
    const partidas = lic?.partidas ?? [];
    const idToPartida = new Map<number, { lote: string; descripcion: string }>();
    for (const p of partidas) {
      const lote = p.lote ?? "General";
      const desc = p.product_nombre ?? "";
      idToPartida.set(p.id_detalle, { lote, descripcion: desc });
    }
    for (const ent of entregas) {
      for (const lin of ent.lineas) {
        const idDet = lin.id_detalle;
        if (idDet == null) continue;
        const partida = idToPartida.get(idDet);
        if (!partida) continue;
        const key = `${partida.lote}|${partida.descripcion}`;
        map[key] = (map[key] ?? 0) + Number(lin.cantidad);
      }
    }
    return map;
  }, [entregas, lic?.partidas]);

  const showLoading = !Number.isFinite(id) || loading;
  const showError = !showLoading && (!!error || !lic);
  const showContent = !showLoading && !showError;

  const itemsPresupuesto = showContent && lic ? mapPartidas(lic.partidas ?? []) : [];
  const itemsPresupuestoAgregado = agregarPartidas(itemsPresupuesto);
  const activos = itemsPresupuestoAgregado.filter((i) => i.activo);
  const presupuestoBase = showContent && lic ? Number(lic.pres_maximo) || 0 : 0;
  const ofertado = activos.reduce((acc, i) => acc + i.unidades * i.pvu, 0);
  const costePrevisto = activos.reduce((acc, i) => acc + i.unidades * i.pcu, 0);
  const beneficioPrevisto = ofertado - costePrevisto;

  const handleSubmitAlbaran = async () => {
    if (!showContent || !lic) return;
    setAlbaranError(null);
    setSubmittingAlbaran(true);
    try {
      const lineas = albaranForm.lineas
        .filter((l) => l.id_producto != null)
        .map((l) => ({
          id_producto: l.id_producto as number,
          id_detalle: l.tipo === "extraordinario" ? null : (l.id_detalle ?? null),
          proveedor: l.proveedor.trim() || undefined,
          cantidad: parseFloat(String(l.cantidad)) || 0,
          coste_unit: parseFloat(String(l.coste_unit)) || 0,
        }))
        .filter((l) => l.cantidad > 0 || l.coste_unit > 0);
      if (lineas.length === 0) {
        setAlbaranError("Añade al menos una línea con producto seleccionado.");
        setSubmittingAlbaran(false);
        return;
      }
      await DeliveriesService.create({
        id_licitacion: lic.id_licitacion,
        cabecera: {
          fecha: albaranForm.fecha,
          codigo_albaran: albaranForm.codigo_albaran.trim() || "Sin código",
          observaciones: albaranForm.observaciones.trim() || undefined,
        },
        lineas,
      });
      refetchEntregas();
      setOpenAlbaran(false);
      setAlbaranForm({
        fecha: new Date().toISOString().slice(0, 10),
        codigo_albaran: "",
        observaciones: "",
        lineas: [
          {
            tipo: "presupuestada",
            id_producto: null,
            id_detalle: null,
            productNombre: "",
            proveedor: "",
            cantidad: "",
            coste_unit: "",
          },
        ],
      });
    } catch (e) {
      setAlbaranError(e instanceof Error ? e.message : "Error al registrar albarán.");
    } finally {
      setSubmittingAlbaran(false);
    }
  };

  if (showLoading) {
    return (
      <div className="flex flex-1 flex-col items-start gap-4">
        <Link href="/licitaciones">
          <Button variant="outline" className="mt-4 gap-2">
            <ArrowLeft className="h-4 w-4" />
            Volver al listado
          </Button>
        </Link>
        <p className="mt-6 text-sm text-slate-600">
          {loading ? "Cargando…" : "ID de licitación no válido."}
        </p>
      </div>
    );
  }

  if (showError) {
    return (
      <div className="flex flex-1 flex-col items-start gap-4">
        <Link href="/licitaciones">
          <Button variant="outline" className="mt-4 gap-2">
            <ArrowLeft className="h-4 w-4" />
            Volver al listado
          </Button>
        </Link>
        <p className="mt-6 text-sm text-slate-600">
          {error ?? "No se ha encontrado la licitación solicitada."}
        </p>
      </div>
    );
  }

  return (
    <div className="flex flex-1 flex-col gap-6">
      <header className="flex items-start justify-between gap-4">
        <div className="space-y-1">
          <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
            Licitación #{lic.id_licitacion}
          </p>
          <h1 className="text-2xl font-semibold leading-tight text-slate-900">
            {lic.nombre}
          </h1>
          <div className="flex flex-wrap items-center gap-3 text-sm text-slate-600">
            <span>
              <span className="font-medium text-slate-700">Expediente:</span>{" "}
              {lic.numero_expediente ?? "—"}
            </span>
            <span>
              <span className="font-medium text-slate-700">Tipo:</span>{" "}
              {lic.tipo_de_licitacion != null
                ? (tipos.find((t) => t.id_tipolicitacion === lic.tipo_de_licitacion)?.tipo ?? `Tipo ${lic.tipo_de_licitacion}`)
                : "—"}
            </span>
            <Badge variant="info">
              {estados.find((e) => e.id_estado === lic.id_estado)?.nombre_estado ?? `Estado ${lic.id_estado}`}
            </Badge>
          </div>
        </div>

        <div className="flex flex-col items-end gap-2">
          <div className="flex gap-2">
            <Dialog open={openEditarCabecera} onOpenChange={setOpenEditarCabecera}>
              <DialogTrigger asChild>
                <Button variant="outline" className="gap-2">
                  <Edit3 className="h-4 w-4" />
                  Editar cabecera
                </Button>
              </DialogTrigger>
              <DialogContent className="max-w-xl">
                <DialogHeader>
                  <DialogTitle>Editar cabecera</DialogTitle>
                  <DialogDescription>
                    Edición de fechas y notas. Los cambios se guardan en el servidor (PUT /tenders/{id}).
                  </DialogDescription>
                </DialogHeader>
                <Form {...cabeceraForm}>
                  <form
                    className="mt-2 space-y-3"
                    onSubmit={cabeceraForm.handleSubmit(async (values) => {
                      if (!lic) return;
                      setSubmittingCabecera(true);
                      try {
                        await TendersService.update(lic.id_licitacion, {
                          fecha_presentacion: values.fecha_presentacion?.trim() || null,
                          fecha_adjudicacion: values.fecha_adjudicacion?.trim() || null,
                          fecha_finalizacion: values.fecha_finalizacion?.trim() || null,
                          descripcion: values.descripcion?.trim() || null,
                        });
                        refetchLicitacion();
                        setOpenEditarCabecera(false);
                      } finally {
                        setSubmittingCabecera(false);
                      }
                    })}
                  >
                    <div className="grid gap-3 md:grid-cols-3">
                      <FormField
                        control={cabeceraForm.control}
                        name="fecha_presentacion"
                        render={({ field }) => (
                          <FormItem>
                            <FormLabel className="text-xs font-medium text-slate-500">
                              F. Presentación
                            </FormLabel>
                            <FormControl>
                              <Input
                                type="date"
                                {...field}
                                value={field.value ?? ""}
                              />
                            </FormControl>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                      <FormField
                        control={cabeceraForm.control}
                        name="fecha_adjudicacion"
                        render={({ field }) => (
                          <FormItem>
                            <FormLabel className="text-xs font-medium text-slate-500">
                              F. Adjudicación
                            </FormLabel>
                            <FormControl>
                              <Input
                                type="date"
                                {...field}
                                value={field.value ?? ""}
                              />
                            </FormControl>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                      <FormField
                        control={cabeceraForm.control}
                        name="fecha_finalizacion"
                        render={({ field }) => (
                          <FormItem>
                            <FormLabel className="text-xs font-medium text-slate-500">
                              F. Finalización
                            </FormLabel>
                            <FormControl>
                              <Input
                                type="date"
                                {...field}
                                value={field.value ?? ""}
                              />
                            </FormControl>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </div>
                    <FormField
                      control={cabeceraForm.control}
                      name="descripcion"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel className="text-xs font-medium text-slate-500">
                            Notas globales
                          </FormLabel>
                          <FormControl>
                            <Textarea rows={3} {...field} value={field.value ?? ""} />
                          </FormControl>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                    <div className="flex justify-end gap-2 pt-1">
                      <Button
                        type="button"
                        variant="outline"
                        onClick={() => setOpenEditarCabecera(false)}
                        disabled={submittingCabecera}
                      >
                        Cancelar
                      </Button>
                      <Button type="submit" disabled={submittingCabecera}>
                        {submittingCabecera ? "Guardando…" : "Guardar cambios"}
                      </Button>
                    </div>
                  </form>
                </Form>
              </DialogContent>
            </Dialog>

            <Link href="/licitaciones">
              <Button variant="outline" className="gap-2">
                <ArrowLeft className="h-4 w-4" />
                Volver al listado
              </Button>
            </Link>
          </div>
        </div>
      </header>

      <section className="grid gap-3 md:grid-cols-4">
        <Card>
          <CardHeader>
            <CardTitle className="text-xs font-semibold text-slate-500">
              Presupuesto Base
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-lg font-semibold text-slate-900">
              {formatEuro(presupuestoBase)}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-xs font-semibold text-slate-500">
              Ofertado (Partidas activas)
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-lg font-semibold text-emerald-900">
              {formatEuro(ofertado)}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-xs font-semibold text-slate-500">
              Coste Estimado
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-lg font-semibold text-amber-900">
              {formatEuro(costePrevisto)}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-xs font-semibold text-slate-500">
              Beneficio Previsto
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p
              className={`text-lg font-semibold ${
                beneficioPrevisto >= 0 ? "text-emerald-900" : "text-rose-700"
              }`}
            >
              {formatEuro(beneficioPrevisto)}
            </p>
          </CardContent>
        </Card>
      </section>

      <section className="mt-2">
        <Tabs defaultValue="presupuesto">
          <TabsList>
            <TabsTrigger value="presupuesto">Presupuesto (Oferta)</TabsTrigger>
            <TabsTrigger value="ejecucion">Ejecución (Real / Albaranes)</TabsTrigger>
            <TabsTrigger value="remaining">Remaining</TabsTrigger>
          </TabsList>

          <TabsContent value="presupuesto" className="flex min-h-[60vh] flex-col">
            {uniqueLotesFromTable.length > 1 && (
              <div className="mb-4 grid shrink-0 gap-3 lg:grid-cols-[2fr,3fr]">
                <Card>
                  <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-semibold text-slate-800">
                      Configuración de Lotes
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="pt-0">
                    <table className="min-w-full text-left text-sm">
                      <thead>
                        <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                          <th className="py-2 pr-3">Lote</th>
                          <th className="py-2 pr-3 text-right">Activo</th>
                        </tr>
                      </thead>
                      <tbody>
                        {uniqueLotesFromTable.map((lote) => (
                          <tr
                            key={lote}
                            className="border-b border-slate-100 last:border-0"
                          >
                            <td className="py-2 pr-3 text-xs text-slate-900">{lote}</td>
                            <td className="py-2 pr-3 text-right">
                              <Switch
                                checked={lotesActivos.includes(lote)}
                                onCheckedChange={(checked) =>
                                  setLotesActivos((prev) =>
                                    checked
                                      ? Array.from(new Set([...prev, lote]))
                                      : prev.filter((l) => l !== lote)
                                  )
                                }
                              />
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </CardContent>
                </Card>
                <div className="flex flex-col justify-between gap-2">
                  <p className="text-sm text-slate-600">
                    Activa o desactiva los lotes que quieres incluir en el análisis.
                  </p>
                </div>
              </div>
            )}

            <div className="min-h-0 flex-1">
              {lic && (
                <EditableBudgetTable
                  lic={lic}
                  onPartidaAdded={refetchLicitacion}
                  onUniqueLotesChange={setUniqueLotesFromTable}
                />
              )}
            </div>
          </TabsContent>

          <TabsContent value="ejecucion">
            <div className="mb-3 flex items-center justify-between gap-2">
              <p className="text-sm text-slate-600">
                Resumen de entregas y albaranes vinculados a esta licitación.
              </p>
              <Button size="sm" onClick={() => setOpenAlbaran(true)}>
                ➕ Registrar Nuevo Albarán
              </Button>
            </div>
            {entregas.length === 0 ? (
              <p className="text-sm text-slate-500">
                No hay entregas registradas para esta licitación.
              </p>
            ) : (
              <div className="space-y-3">
                {entregas.map((entrega) => (
                  <Card key={entrega.id_entrega}>
                    <CardHeader className="flex flex-row items-center justify-between gap-3">
                      <div>
                        <CardTitle className="text-sm font-semibold text-slate-800">
                          {entrega.codigo_albaran}
                        </CardTitle>
                        <p className="text-xs text-slate-500">
                          Fecha: {formatDate(entrega.fecha_entrega)}
                        </p>
                      </div>
                      <p className="text-xs text-slate-400">{entrega.observaciones ?? ""}</p>
                    </CardHeader>
                    <CardContent>
                      <table className="min-w-full text-left text-sm">
                        <thead>
                          <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                            <th className="py-1.5 pr-3">Concepto</th>
                            <th className="py-1.5 pr-3">Proveedor</th>
                            <th className="py-1.5 pr-3 text-right">Cantidad</th>
                            <th className="py-1.5 pr-3 text-right">Coste</th>
                            <th className="py-1.5 pr-3 text-center">Estado</th>
                            <th className="py-1.5 pr-3 text-center">Cobrado</th>
                          </tr>
                        </thead>
                        <tbody>
                          {entrega.lineas.length === 0 ? (
                            <tr>
                              <td colSpan={6} className="py-4 text-center text-xs text-slate-500">
                                Sin líneas
                              </td>
                            </tr>
                          ) : (
                            entrega.lineas.map((lin, idx) => (
                              <tr key={lin.id_real ?? idx} className="border-b border-slate-100 last:border-0">
                                <td className="py-1.5 pr-3 text-slate-900">{lin.product_nombre ?? "—"}</td>
                                <td className="py-1.5 pr-3 text-slate-600">{lin.proveedor ?? "—"}</td>
                                <td className="py-1.5 pr-3 text-right text-slate-900">{lin.cantidad}</td>
                                <td className="py-1.5 pr-3 text-right text-slate-900">{lin.pcu}</td>
                                <td className="py-1.5 pr-3 text-center text-slate-500">{lin.estado ?? "—"}</td>
                                <td className="py-1.5 pr-3 text-center text-slate-500">{lin.cobrado ? "Sí" : "No"}</td>
                              </tr>
                            ))
                          )}
                        </tbody>
                      </table>
                    </CardContent>
                  </Card>
                ))}
              </div>
            )}

            <Dialog open={openAlbaran} onOpenChange={setOpenAlbaran}>
              <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                  <DialogTitle>Registrar nuevo albarán</DialogTitle>
                  <DialogDescription>
                    Cabecera del albarán y líneas de entrega (concepto, proveedor, cantidad, coste).
                  </DialogDescription>
                </DialogHeader>
                <div className="space-y-4 pt-2">
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div className="grid gap-2">
                      <label className="text-xs font-medium text-slate-600">Fecha</label>
                      <Input
                        type="date"
                        value={albaranForm.fecha}
                        onChange={(e) =>
                          setAlbaranForm((f) => ({ ...f, fecha: e.target.value }))
                        }
                      />
                    </div>
                    <div className="grid gap-2">
                      <label className="text-xs font-medium text-slate-600">Código albarán</label>
                      <Input
                        value={albaranForm.codigo_albaran}
                        onChange={(e) =>
                          setAlbaranForm((f) => ({ ...f, codigo_albaran: e.target.value }))
                        }
                        placeholder="Ej. ALB-001"
                      />
                    </div>
                  </div>
                  <div className="grid gap-2">
                    <label className="text-xs font-medium text-slate-600">Observaciones</label>
                    <Textarea
                      value={albaranForm.observaciones}
                      onChange={(e) =>
                        setAlbaranForm((f) => ({ ...f, observaciones: e.target.value }))
                      }
                      placeholder="Opcional"
                      rows={2}
                    />
                  </div>
                  <div>
                    <p className="mb-2 text-xs font-medium text-slate-600">Líneas</p>
                    <div className="space-y-2">
                      {albaranForm.lineas.map((lin, idx) => (
                        <div
                          key={idx}
                          className="flex flex-col gap-2 rounded border border-slate-200 bg-slate-50/50 p-2"
                        >
                          <Tabs
                            value={lin.tipo}
                            onValueChange={(v) => {
                              const nextTipo = v as LineaTipo;
                              setAlbaranForm((f) => ({
                                ...f,
                                lineas: f.lineas.map((l, i) =>
                                  i === idx
                                    ? {
                                        ...l,
                                        tipo: nextTipo,
                                        id_producto: null,
                                        id_detalle: null,
                                        productNombre: "",
                                      }
                                    : l
                                ),
                              }));
                            }}
                            defaultValue="presupuestada"
                          >
                            <TabsList className="h-8 w-full max-w-[320px]">
                              <TabsTrigger value="presupuestada" className="flex-1 text-xs">
                                Línea presupuestada
                              </TabsTrigger>
                              <TabsTrigger value="extraordinario" className="flex-1 text-xs">
                                Gasto extraordinario
                              </TabsTrigger>
                            </TabsList>
                            <TabsContent value="presupuestada" className="mt-2">
                              <label className="mb-1 block text-xs font-medium text-slate-600">
                                Producto del presupuesto
                              </label>
                              <select
                                className="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                value={
                                  lin.id_detalle != null
                                    ? String(lin.id_detalle)
                                    : ""
                                }
                                onChange={(e) => {
                                  const idDet = e.target.value ? Number(e.target.value) : null;
                                  const partida = lic?.partidas?.find(
                                    (p) => p.id_detalle === idDet
                                  );
                                  if (!partida || idDet == null) return;
                                  setAlbaranForm((f) => ({
                                    ...f,
                                    lineas: f.lineas.map((l, i) =>
                                      i === idx
                                        ? {
                                            ...l,
                                            id_detalle: idDet,
                                            id_producto: partida.id_producto,
                                            productNombre: partida.product_nombre ?? "",
                                          }
                                        : l
                                    ),
                                  }));
                                }}
                              >
                                <option value="">Selecciona partida…</option>
                                {(lic?.partidas ?? []).map((p) => (
                                  <option key={p.id_detalle} value={p.id_detalle}>
                                    {[p.lote ?? "General", p.product_nombre].filter(Boolean).join(" – ")}
                                  </option>
                                ))}
                              </select>
                            </TabsContent>
                            <TabsContent value="extraordinario" className="mt-2">
                              <label className="mb-1 block text-xs font-medium text-slate-600">
                                Producto (catálogo global)
                              </label>
                              <ProductCombobox
                                value={
                                  lin.id_producto != null && lin.productNombre
                                    ? { id: lin.id_producto, nombre: lin.productNombre }
                                    : null
                                }
                                onSelect={(id, nombre) =>
                                  setAlbaranForm((f) => ({
                                    ...f,
                                    lineas: f.lineas.map((l, i) =>
                                      i === idx
                                        ? {
                                            ...l,
                                            id_producto: id,
                                            id_detalle: null,
                                            productNombre: nombre,
                                          }
                                        : l
                                    ),
                                  }))
                                }
                                placeholder="Ej. Hotel, Gasolina, Dietas…"
                              />
                            </TabsContent>
                          </Tabs>
                          <div className="flex flex-wrap items-end gap-2">
                          <Input
                            placeholder="Proveedor"
                            className="max-w-[140px]"
                            value={lin.proveedor}
                            onChange={(e) =>
                              setAlbaranForm((f) => ({
                                ...f,
                                lineas: f.lineas.map((l, i) =>
                                  i === idx ? { ...l, proveedor: e.target.value } : l
                                ),
                              }))
                            }
                          />
                          <Input
                            type="number"
                            min={0}
                            step={0.01}
                            placeholder="Cant."
                            className="w-20"
                            value={lin.cantidad}
                            onChange={(e) =>
                              setAlbaranForm((f) => ({
                                ...f,
                                lineas: f.lineas.map((l, i) =>
                                  i === idx ? { ...l, cantidad: e.target.value } : l
                                ),
                              }))
                            }
                          />
                          <Input
                            type="number"
                            min={0}
                            step={0.01}
                            placeholder="Coste €"
                            className="w-24"
                            value={lin.coste_unit}
                            onChange={(e) =>
                              setAlbaranForm((f) => ({
                                ...f,
                                lineas: f.lineas.map((l, i) =>
                                  i === idx ? { ...l, coste_unit: e.target.value } : l
                                ),
                              }))
                            }
                          />
                          {albaranForm.lineas.length > 1 && (
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="text-red-600"
                              onClick={() =>
                                setAlbaranForm((f) => ({
                                  ...f,
                                  lineas: f.lineas.filter((_, i) => i !== idx),
                                }))
                              }
                            >
                              Quitar
                            </Button>
                          )}
                          </div>
                        </div>
                      ))}
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                          setAlbaranForm((f) => ({
                            ...f,
                            lineas: [
                              ...f.lineas,
                              {
                                tipo: "presupuestada",
                                id_producto: null,
                                id_detalle: null,
                                productNombre: "",
                                proveedor: "",
                                cantidad: "",
                                coste_unit: "",
                              },
                            ],
                          }))
                        }
                      >
                        Añadir línea
                      </Button>
                    </div>
                  </div>
                  {albaranError && (
                    <p className="text-sm text-red-600">{albaranError}</p>
                  )}
                  <div className="flex justify-end gap-2 pt-2">
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() => setOpenAlbaran(false)}
                      disabled={submittingAlbaran}
                    >
                      Cancelar
                    </Button>
                    <Button onClick={handleSubmitAlbaran} disabled={submittingAlbaran}>
                      {submittingAlbaran ? "Guardando…" : "Registrar albarán"}
                    </Button>
                  </div>
                </div>
              </DialogContent>
            </Dialog>
          </TabsContent>

          <TabsContent value="remaining">
            <p className="mb-3 text-sm text-slate-600">
              Comparativa entre unidades presupuestadas y ejecutadas por partida.
            </p>
            <Card>
              <CardContent className="pt-4">
                <table className="min-w-full text-left text-sm">
                  <thead>
                    <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                      <th className="py-2 pr-3">Lote</th>
                      <th className="py-2 pr-3">Partida</th>
                      <th className="py-2 pr-3 text-right">Ud. Presu.</th>
                      <th className="py-2 pr-3 text-right">Ud. Real</th>
                      <th className="py-2 pr-3 text-right">Pendiente</th>
                      <th className="py-2 pr-3">Progreso</th>
                    </tr>
                  </thead>
                  <tbody>
                    {itemsPresupuestoAgregado.map((item) => {
                      const keyPartida = `${item.lote}|${item.descripcion}`;
                      const ejecutado = ejecutadoPorPartida[keyPartida] ?? 0;
                      const pendiente = Math.max(0, item.unidades - ejecutado);
                      const progreso =
                        item.unidades > 0
                          ? Math.min(100, Math.max(0, (ejecutado / item.unidades) * 100))
                          : 0;
                      return (
                        <tr
                          key={`${item.lote}-${item.descripcion}`}
                          className="border-b border-slate-100 last:border-0"
                        >
                          <td className="py-2 pr-3 text-xs text-slate-500">{item.lote}</td>
                          <td className="max-w-xs py-2 pr-3 text-sm text-slate-900">
                            {item.descripcion}
                          </td>
                          <td className="py-2 pr-3 text-right text-xs text-slate-900">
                            {item.unidades.toLocaleString("es-ES")}
                          </td>
                          <td className="py-2 pr-3 text-right text-xs text-slate-900">
                            {ejecutado.toLocaleString("es-ES")}
                          </td>
                          <td className="py-2 pr-3 text-right text-xs text-slate-900">
                            {pendiente.toLocaleString("es-ES")}
                          </td>
                          <td className="py-2 pr-3">
                            <div className="h-2 w-full rounded-full bg-slate-100">
                              <div
                                className="h-2 rounded-full bg-emerald-500"
                                style={{ width: `${progreso}%` }}
                              />
                            </div>
                            <p className="mt-1 text-[11px] text-slate-500">
                              {progreso.toFixed(0)} %
                            </p>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </section>
    </div>
  );
}
