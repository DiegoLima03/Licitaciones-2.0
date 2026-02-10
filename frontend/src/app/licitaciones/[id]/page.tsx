 "use client";

import * as React from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
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
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs";
import { Switch } from "@/components/ui/switch";

type EstadoNombre =
  | "En Estudio"
  | "Presentada"
  | "Pendiente de Fallo"
  | "Pendiente"
  | "Adjudicada"
  | "Desierta";

type PresupuestoItem = {
  id: number;
  lote: string;
  descripcion: string;
  unidades: number;
  pcu: number;
  pvu: number;
  activo: boolean;
};

type EntregaLinea = {
  id: number;
  articulo: string;
  proveedor: string;
  cantidad: number;
  pcu: number;
  estado: "EN ESPERA" | "ENTREGADO" | "FACTURADO";
  cobrado: boolean;
};

type Entrega = {
  id: number;
  fecha: string;
  codigo: string;
  notas?: string;
  lineas: EntregaLinea[];
};

type LicitacionDetalle = {
  id: number;
  nombre: string;
  expediente: string;
  estado: EstadoNombre;
  tipo: string;
  fechas: {
    presentacion: string;
    adjudicacion?: string;
    fin?: string;
  };
  presupuestoMax: number;
  descuentoGlobal: number;
  itemsPresupuesto: PresupuestoItem[];
  entregas: Entrega[];
};

const MOCK_LICITACIONES: LicitacionDetalle[] = [
  {
    id: 1,
    nombre: "Suministro de material hospitalario",
    expediente: "EXP-24-001",
    estado: "En Estudio",
    tipo: "Suministro",
    fechas: {
      presentacion: "2024-03-15",
      adjudicacion: "2024-05-10",
      fin: "2026-03-31",
    },
    presupuestoMax: 850_000,
    descuentoGlobal: 8.5,
    itemsPresupuesto: [
      {
        id: 11,
        lote: "Lote 1: Obra Civil",
        descripcion: "Demoliciones y movimientos de tierra",
        unidades: 120_000,
        pcu: 0.18,
        pvu: 0.32,
        activo: true,
      },
      {
        id: 12,
        lote: "Lote 1: Obra Civil",
        descripcion: "Estructura metálica y cerramientos",
        unidades: 80_000,
        pcu: 0.45,
        pvu: 0.78,
        activo: true,
      },
      {
        id: 13,
        lote: "Lote 2: Iluminación",
        descripcion: "Suministro de luminarias LED interiores",
        unidades: 40_000,
        pcu: 1.2,
        pvu: 2.1,
        activo: true,
      },
      {
        id: 14,
        lote: "Lote 3: Jardinería",
        descripcion: "Mantenimiento de zonas verdes y riego",
        unidades: 5_000,
        pcu: 6.5,
        pvu: 8.9,
        activo: false,
      },
    ],
    entregas: [
      {
        id: 101,
        fecha: "2024-06-05",
        codigo: "ALB-24-001",
        notas: "Primera entrega lote 1",
        lineas: [
          {
            id: 1,
            articulo: "Lote 1 - Guantes quirúrgicos estériles",
            proveedor: "Proveedor Salud S.A.",
            cantidad: 30_000,
            pcu: 0.18,
            estado: "FACTURADO",
            cobrado: true,
          },
          {
            id: 2,
            articulo: "Lote 1 - Mascarillas FFP2",
            proveedor: "Proveedor Salud S.A.",
            cantidad: 10_000,
            pcu: 0.45,
            estado: "ENTREGADO",
            cobrado: false,
          },
        ],
      },
      {
        id: 102,
        fecha: "2024-07-20",
        codigo: "ALB-24-019",
        notas: "Reposición mascarillas",
        lineas: [
          {
            id: 3,
            articulo: "Lote 1 - Mascarillas FFP2",
            proveedor: "Proveedor Salud S.A.",
            cantidad: 15_000,
            pcu: 0.46,
            estado: "FACTURADO",
            cobrado: false,
          },
        ],
      },
    ],
  },
  {
    id: 2,
    nombre: "Mantenimiento integral edificio corporativo",
    expediente: "EXP-24-014",
    estado: "Presentada",
    tipo: "Servicio",
    fechas: {
      presentacion: "2024-02-10",
      adjudicacion: undefined,
      fin: undefined,
    },
    presupuestoMax: 430_000,
    descuentoGlobal: 5,
    itemsPresupuesto: [],
    entregas: [],
  },
];

function findLicitacion(id: number): LicitacionDetalle | undefined {
  return MOCK_LICITACIONES.find((l) => l.id === id);
}

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 0,
  }).format(value);
}

function formatDate(date?: string) {
  if (!date) return "-";
  return new Date(date + "T00:00:00").toLocaleDateString("es-ES");
}

export default function LicitacionDetallePage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const lic = Number.isFinite(id) ? findLicitacion(id) : undefined;

  if (!lic) {
    return (
      <div className="flex flex-1 flex-col items-start gap-4">
        <Link href="/licitaciones">
          <Button variant="outline" className="mt-4 gap-2">
            <ArrowLeft className="h-4 w-4" />
            Volver al listado
          </Button>
        </Link>
        <p className="mt-6 text-sm text-slate-600">
          No se ha encontrado la licitación solicitada (mock).
        </p>
      </div>
    );
  }

  const activos = lic.itemsPresupuesto.filter((i) => i.activo);
  const presupuestoBase = lic.presupuestoMax;
  const ofertado = activos.reduce(
    (acc, i) => acc + i.unidades * i.pvu,
    0
  );
  const costePrevisto = activos.reduce(
    (acc, i) => acc + i.unidades * i.pcu,
    0
  );
  const beneficioPrevisto = ofertado - costePrevisto;

  // Maestro-detalle: agrupar por lote y controlar visibilidad
  const lotesUnicos = Array.from(
    new Set(lic.itemsPresupuesto.map((i) => i.lote))
  );
  const [lotesActivos, setLotesActivos] = React.useState<string[]>(lotesUnicos);

  const itemsPorLote = lotesUnicos.reduce<Record<string, PresupuestoItem[]>>(
    (acc, lote) => {
      acc[lote] = lic.itemsPresupuesto.filter((i) => i.lote === lote);
      return acc;
    },
    {}
  );

  // Remaining: sumas ejecutadas a partir de entregas mock
  const ejecutadoPorArticulo = new Map<string, number>();
  lic.entregas.forEach((e) =>
    e.lineas.forEach((l) => {
      ejecutadoPorArticulo.set(
        l.articulo,
        (ejecutadoPorArticulo.get(l.articulo) ?? 0) + l.cantidad
      );
    })
  );

  return (
    <div className="flex flex-1 flex-col gap-6">
      {/* Cabecera */}
      <header className="flex items-start justify-between gap-4">
        <div className="space-y-1">
          <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
            Licitación #{lic.id}
          </p>
          <h1 className="text-2xl font-semibold leading-tight text-slate-900">
            {lic.nombre}
          </h1>
          <div className="flex flex-wrap items-center gap-3 text-sm text-slate-600">
            <span>
              <span className="font-medium text-slate-700">Expediente:</span>{" "}
              {lic.expediente}
            </span>
            <span>
              <span className="font-medium text-slate-700">Tipo:</span>{" "}
              {lic.tipo}
            </span>
            <Badge variant="info">{lic.estado}</Badge>
          </div>
        </div>

        <div className="flex flex-col items-end gap-2">
          <div className="flex gap-2">
            <Dialog>
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
                    Simulación de edición de fechas, estado y notas generales.
                  </DialogDescription>
                </DialogHeader>
                <div className="mt-2 space-y-3">
                  <div className="grid gap-3 md:grid-cols-3">
                    <div>
                      <p className="text-xs font-medium text-slate-500">
                        F. Presentación
                      </p>
                      <Input defaultValue={lic.fechas.presentacion} />
                    </div>
                    <div>
                      <p className="text-xs font-medium text-slate-500">
                        F. Adjudicación
                      </p>
                      <Input defaultValue={lic.fechas.adjudicacion ?? ""} />
                    </div>
                    <div>
                      <p className="text-xs font-medium text-slate-500">
                        F. Finalización
                      </p>
                      <Input defaultValue={lic.fechas.fin ?? ""} />
                    </div>
                  </div>
                  <div>
                    <p className="text-xs font-medium text-slate-500">
                      Notas globales
                    </p>
                    <Textarea
                      rows={3}
                      defaultValue="Aquí irían las notas globales de la licitación."
                    />
                  </div>
                  <div className="flex justify-end gap-2 pt-1">
                    <Button variant="outline">Cancelar</Button>
                    <Button>Guardar cambios</Button>
                  </div>
                </div>
              </DialogContent>
            </Dialog>

            <Link href="/licitaciones">
              <Button variant="outline" className="gap-2">
                <ArrowLeft className="h-4 w-4" />
                Volver al listado
              </Button>
            </Link>
          </div>
          <p className="text-xs text-slate-400">
            Mock de detalle inspirado en la vista de Streamlit.
          </p>
        </div>
      </header>

      {/* KPIs */}
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

      {/* Tabs principales */}
      <section className="mt-2">
        <Tabs defaultValue="presupuesto">
          <TabsList>
            <TabsTrigger value="presupuesto">Presupuesto (Oferta)</TabsTrigger>
            <TabsTrigger value="ejecucion">Ejecución (Real / Albaranes)</TabsTrigger>
            <TabsTrigger value="remaining">Remaining</TabsTrigger>
          </TabsList>

          {/* Tab Presupuesto */}
          <TabsContent value="presupuesto">
            {/* Panel maestro: configuración de lotes */}
            <div className="mb-4 grid gap-3 lg:grid-cols-[2fr,3fr]">
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
                      {lotesUnicos.map((lote) => (
                        <tr
                          key={lote}
                          className="border-b border-slate-100 last:border-0"
                        >
                          <td className="py-2 pr-3 text-xs text-slate-900">
                            {lote}
                          </td>
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
                  Activa o desactiva los lotes que quieres incluir en el
                  análisis. Los lotes inactivos no se muestran en el detalle
                  inferior.
                </p>
                <div className="mt-2 flex gap-2">
                  <Button variant="outline" size="sm">
                    Importar Excel
                  </Button>
                  <Button size="sm">Añadir Partida Manual</Button>
                </div>
              </div>
            </div>

            {/* Detalle por lote (tarjetas individuales) */}
            <div className="space-y-4">
              {lotesUnicos
                .filter((lote) => lotesActivos.includes(lote))
                .map((lote) => {
                  const items = itemsPorLote[lote] ?? [];
                  const subtotalVenta = items.reduce(
                    (acc, i) => acc + i.unidades * i.pvu,
                    0
                  );
                  const subtotalCoste = items.reduce(
                    (acc, i) => acc + i.unidades * i.pcu,
                    0
                  );
                  return (
                    <Card key={lote}>
                      <CardHeader className="flex flex-row items-center justify-between gap-3 pb-3">
                        <div>
                          <CardTitle className="text-sm font-semibold text-slate-800">
                            {lote}
                          </CardTitle>
                          <p className="text-xs text-slate-500">
                            {items.length} partidas presupuestadas.
                          </p>
                        </div>
                      </CardHeader>
                      <CardContent className="pt-0">
                        <table className="min-w-full text-left text-sm">
                          <thead>
                            <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                              <th className="py-2 pr-3">Descripción</th>
                              <th className="py-2 pr-3 text-right">Uds</th>
                              <th className="py-2 pr-3 text-right">PCU</th>
                              <th className="py-2 pr-3 text-right">PVU</th>
                              <th className="py-2 pr-3 text-right">Margen %</th>
                            </tr>
                          </thead>
                          <tbody>
                            {items.map((item) => {
                              const margenPct =
                                item.pvu > 0
                                  ? ((item.pvu - item.pcu) / item.pvu) * 100
                                  : 0;
                              return (
                                <tr
                                  key={item.id}
                                  className="border-b border-slate-100 last:border-0"
                                >
                                  <td className="max-w-xs py-2 pr-3 text-sm text-slate-900">
                                    {item.descripcion}
                                  </td>
                                  <td className="py-2 pr-3 text-right text-sm text-slate-900">
                                    {item.unidades.toLocaleString("es-ES")}
                                  </td>
                                  <td className="py-2 pr-3 text-right text-sm text-slate-900">
                                    {item.pcu.toFixed(2)} €
                                  </td>
                                  <td className="py-2 pr-3 text-right text-sm text-slate-900">
                                    {item.pvu.toFixed(2)} €
                                  </td>
                                  <td className="py-2 pr-3 text-right text-sm text-slate-900">
                                    {margenPct.toFixed(1)} %
                                  </td>
                                </tr>
                              );
                            })}
                          </tbody>
                          <tfoot>
                            <tr className="border-t border-slate-200 text-xs font-medium text-slate-700">
                              <td className="py-2 pr-3 text-right" colSpan={3}>
                                Subtotal lote
                              </td>
                              <td className="py-2 pr-3 text-right">
                                {formatEuro(subtotalVenta)}
                              </td>
                              <td className="py-2 pr-3 text-right">
                                {formatEuro(subtotalVenta - subtotalCoste)}
                              </td>
                            </tr>
                          </tfoot>
                        </table>
                      </CardContent>
                    </Card>
                  );
                })}
            </div>
          </TabsContent>

          {/* Tab Ejecución */}
          <TabsContent value="ejecucion">
            <div className="mb-3 flex items-center justify-between gap-2">
              <p className="text-sm text-slate-600">
                Resumen de entregas y albaranes vinculados a esta licitación.
              </p>
              <Button size="sm">➕ Registrar Nuevo Albarán</Button>
            </div>

            {lic.entregas.length === 0 ? (
              <p className="text-sm text-slate-500">
                Aún no hay entregas registradas (datos mock).
              </p>
            ) : (
              <div className="space-y-3">
                {lic.entregas.map((entrega) => (
                  <Card key={entrega.id}>
                    <CardHeader className="flex flex-row items-center justify-between gap-3">
                      <div>
                        <CardTitle className="text-sm font-semibold text-slate-800">
                          {entrega.codigo}
                        </CardTitle>
                        <p className="text-xs text-slate-500">
                          Fecha: {formatDate(entrega.fecha)}
                        </p>
                      </div>
                      <p className="text-xs text-slate-400">
                        {entrega.notas ?? "Documento simulado para la demo."}
                      </p>
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
                          {entrega.lineas.map((linea) => (
                            <tr
                              key={linea.id}
                              className="border-b border-slate-100 last:border-0"
                            >
                              <td className="py-1.5 pr-3 text-xs text-slate-900">
                                {linea.articulo}
                              </td>
                              <td className="py-1.5 pr-3 text-xs text-slate-600">
                                {linea.proveedor}
                              </td>
                              <td className="py-1.5 pr-3 text-right text-xs text-slate-900">
                                {linea.cantidad.toLocaleString("es-ES")}
                              </td>
                              <td className="py-1.5 pr-3 text-right text-xs text-slate-900">
                                {linea.pcu.toFixed(2)} €
                              </td>
                              <td className="py-1.5 pr-3 text-center text-xs text-slate-700">
                                {linea.estado}
                              </td>
                              <td className="py-1.5 pr-3 text-center text-xs">
                                {linea.cobrado ? "✔" : "—"}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </CardContent>
                  </Card>
                ))}
              </div>
            )}
          </TabsContent>

          {/* Tab Remaining */}
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
                    {lic.itemsPresupuesto.map((item) => {
                      const key = `${item.lote} - ${item.descripcion}`;
                      const ejecutado = ejecutadoPorArticulo.get(key) ?? 0;
                      const pendiente = item.unidades - ejecutado;
                      const progreso =
                        item.unidades > 0
                          ? Math.min(
                              100,
                              Math.max(0, (ejecutado / item.unidades) * 100)
                            )
                          : 0;

                      return (
                        <tr
                          key={item.id}
                          className="border-b border-slate-100 last:border-0"
                        >
                          <td className="py-2 pr-3 text-xs text-slate-500">
                            {item.lote}
                          </td>
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

