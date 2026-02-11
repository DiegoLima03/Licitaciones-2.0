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
import { DeliveriesService, ImportService, TendersService } from "@/services/api";
import type {
  EntregaWithLines,
  TenderDetail,
  TenderPartida,
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
    descripcion: p.producto,
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

export default function LicitacionDetallePage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const [lic, setLic] = React.useState<TenderDetail | null>(null);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [lotesActivos, setLotesActivos] = React.useState<string[]>([]);
  const [openPartidaManual, setOpenPartidaManual] = React.useState(false);
  const [partidaForm, setPartidaForm] = React.useState({
    lote: "General",
    producto: "",
    unidades: "1",
    pvu: "",
    pcu: "",
    pmaxu: "",
  });
  const [submittingPartida, setSubmittingPartida] = React.useState(false);
  const [partidaError, setPartidaError] = React.useState<string | null>(null);
  const [entregas, setEntregas] = React.useState<EntregaWithLines[]>([]);
  const [openAlbaran, setOpenAlbaran] = React.useState(false);
  const [albaranForm, setAlbaranForm] = React.useState({
    fecha: new Date().toISOString().slice(0, 10),
    codigo_albaran: "",
    observaciones: "",
    lineas: [{ concepto_partida: "", proveedor: "", cantidad: "", coste_unit: "" }],
  });
  const [submittingAlbaran, setSubmittingAlbaran] = React.useState(false);
  const [albaranError, setAlbaranError] = React.useState<string | null>(null);
  const [openImportExcel, setOpenImportExcel] = React.useState(false);
  const [importFile, setImportFile] = React.useState<File | null>(null);
  const [importTipoId, setImportTipoId] = React.useState<1 | 2>(1);
  const [importingExcel, setImportingExcel] = React.useState(false);
  const [importError, setImportError] = React.useState<string | null>(null);

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

  const opcionesPartidas = React.useMemo(() => {
    const items = mapPartidas(lic?.partidas ?? []);
    const agregados = agregarPartidas(items);
    const labels = agregados.map((i) => `${i.lote} - ${i.descripcion}`);
    return ["➕ Gasto NO Presupuestado / Extra", ...labels];
  }, [lic?.partidas]);

  /** Unidades reales ejecutadas por partida (clave: "lote|descripcion"). */
  const ejecutadoPorPartida = React.useMemo(() => {
    const map: Record<string, number> = {};
    const partidas = lic?.partidas ?? [];
    const idToPartida = new Map<number, { lote: string; descripcion: string }>();
    for (const p of partidas) {
      const lote = p.lote ?? "General";
      const desc = p.producto ?? "";
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

  if (!Number.isFinite(id) || loading) {
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

  if (error || !lic) {
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

  const itemsPresupuesto = mapPartidas(lic.partidas ?? []);
  const itemsPresupuestoAgregado = agregarPartidas(itemsPresupuesto);
  const activos = itemsPresupuestoAgregado.filter((i) => i.activo);
  const presupuestoBase = Number(lic.pres_maximo) || 0;
  const ofertado = activos.reduce((acc, i) => acc + i.unidades * i.pvu, 0);
  const costePrevisto = activos.reduce((acc, i) => acc + i.unidades * i.pcu, 0);
  const beneficioPrevisto = ofertado - costePrevisto;

  const lotesUnicos = Array.from(new Set(itemsPresupuestoAgregado.map((i) => i.lote)));

  const itemsPorLote = lotesUnicos.reduce<Record<string, PresupuestoItem[]>>(
    (acc, lote) => {
      acc[lote] = itemsPresupuestoAgregado.filter((i) => i.lote === lote);
      return acc;
    },
    {}
  );

  const handleSubmitAlbaran = async () => {
    if (!lic) return;
    setAlbaranError(null);
    setSubmittingAlbaran(true);
    try {
      const lineas = albaranForm.lineas
        .filter((l) => l.concepto_partida.trim() || l.cantidad !== "" || l.coste_unit !== "")
        .map((l) => ({
          concepto_partida: l.concepto_partida.trim() || opcionesPartidas[0],
          proveedor: l.proveedor.trim() || undefined,
          cantidad: parseFloat(String(l.cantidad)) || 0,
          coste_unit: parseFloat(String(l.coste_unit)) || 0,
        }));
      if (lineas.length === 0) {
        setAlbaranError("Añade al menos una línea.");
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
        lineas: [{ concepto_partida: "", proveedor: "", cantidad: "", coste_unit: "" }],
      });
    } catch (e) {
      setAlbaranError(e instanceof Error ? e.message : "Error al registrar albarán.");
    } finally {
      setSubmittingAlbaran(false);
    }
  };

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
              {lic.tipo_de_licitacion != null ? `Tipo ${lic.tipo_de_licitacion}` : "—"}
            </span>
            <Badge variant="info">Estado {lic.id_estado}</Badge>
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
                    Edición de fechas, estado y notas (conectar con PUT /tenders/{id}).
                  </DialogDescription>
                </DialogHeader>
                <div className="mt-2 space-y-3">
                  <div className="grid gap-3 md:grid-cols-3">
                    <div>
                      <p className="text-xs font-medium text-slate-500">F. Presentación</p>
                      <Input defaultValue={lic.fecha_presentacion ?? ""} />
                    </div>
                    <div>
                      <p className="text-xs font-medium text-slate-500">F. Adjudicación</p>
                      <Input defaultValue={lic.fecha_adjudicacion ?? ""} />
                    </div>
                    <div>
                      <p className="text-xs font-medium text-slate-500">F. Finalización</p>
                      <Input defaultValue={lic.fecha_finalizacion ?? ""} />
                    </div>
                  </div>
                  <div>
                    <p className="text-xs font-medium text-slate-500">Notas globales</p>
                    <Textarea rows={3} defaultValue={lic.descripcion ?? ""} />
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

          <TabsContent value="presupuesto">
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
                <div className="mt-2 flex gap-2">
                  <Dialog open={openImportExcel} onOpenChange={setOpenImportExcel}>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setImportError(null);
                        setImportFile(null);
                        setImportTipoId(1);
                        setOpenImportExcel(true);
                      }}
                    >
                      Importar Excel
                    </Button>
                    <DialogContent className="max-w-md">
                      <DialogHeader>
                        <DialogTitle>Importar partidas desde Excel</DialogTitle>
                        <DialogDescription>
                          Sube un archivo .xlsx o .xls con columnas Producto/Planta, opcionalmente Lote/Zona, y precios (PVU, PCU, Precio Máximo, N.º Unidades previstas).
                        </DialogDescription>
                      </DialogHeader>
                      <div className="mt-3 space-y-4">
                        <div className="grid gap-2">
                          <label className="text-xs font-medium text-slate-600">
                            Archivo Excel
                          </label>
                          <Input
                            type="file"
                            accept=".xlsx,.xls"
                            onChange={(e) => {
                              const f = e.target.files?.[0];
                              setImportFile(f ?? null);
                              setImportError(null);
                            }}
                          />
                          {importFile && (
                            <p className="text-xs text-slate-500">{importFile.name}</p>
                          )}
                        </div>
                        <div className="grid gap-2">
                          <label className="text-xs font-medium text-slate-600">
                            Tipo de presupuesto
                          </label>
                          <select
                            className="h-9 w-full rounded border border-slate-300 bg-white px-2 text-sm"
                            value={importTipoId}
                            onChange={(e) =>
                              setImportTipoId(e.target.value === "2" ? 2 : 1)
                            }
                          >
                            <option value={1}>Desglose (con unidades previstas)</option>
                            <option value={2}>Alzado (sin unidades)</option>
                          </select>
                        </div>
                        {importError && (
                          <p className="text-sm text-red-600">{importError}</p>
                        )}
                        <div className="flex justify-end gap-2 pt-2">
                          <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpenImportExcel(false)}
                            disabled={importingExcel}
                          >
                            Cancelar
                          </Button>
                          <Button
                            onClick={async () => {
                              if (!lic || !importFile) {
                                setImportError("Selecciona un archivo Excel.");
                                return;
                              }
                              setImportError(null);
                              setImportingExcel(true);
                              try {
                                const res = await ImportService.uploadExcel(
                                  lic.id_licitacion,
                                  importFile,
                                  importTipoId
                                );
                                refetchLicitacion();
                                setOpenImportExcel(false);
                                setImportFile(null);
                              } catch (e) {
                                setImportError(
                                  e instanceof Error ? e.message : "Error al importar."
                                );
                              } finally {
                                setImportingExcel(false);
                              }
                            }}
                            disabled={importingExcel || !importFile}
                          >
                            {importingExcel ? "Importando…" : "Importar"}
                          </Button>
                        </div>
                      </div>
                    </DialogContent>
                  </Dialog>
                  <Dialog open={openPartidaManual} onOpenChange={setOpenPartidaManual}>
                    <Button
                      size="sm"
                      onClick={() => {
                        setPartidaError(null);
                        setPartidaForm({
                          lote: "General",
                          producto: "",
                          unidades: "1",
                          pvu: "",
                          pcu: "",
                          pmaxu: "",
                        });
                        setOpenPartidaManual(true);
                      }}
                    >
                      Añadir Partida Manual
                    </Button>
                    <DialogContent className="max-w-md">
                      <DialogHeader>
                        <DialogTitle>Añadir partida manual</DialogTitle>
                        <DialogDescription>
                          Introduce los datos de la nueva partida del presupuesto.
                        </DialogDescription>
                      </DialogHeader>
                      <form
                        className="mt-3 space-y-3"
                        onSubmit={async (e) => {
                          e.preventDefault();
                          if (!partidaForm.producto.trim()) {
                            setPartidaError("El producto/descripción es obligatorio.");
                            return;
                          }
                          setSubmittingPartida(true);
                          setPartidaError(null);
                          try {
                            await TendersService.addPartida(lic.id_licitacion, {
                              lote: partidaForm.lote || "General",
                              producto: partidaForm.producto.trim(),
                              unidades: parseFloat(partidaForm.unidades) || 0,
                              pvu: parseFloat(partidaForm.pvu) || 0,
                              pcu: parseFloat(partidaForm.pcu) || 0,
                              pmaxu: parseFloat(partidaForm.pmaxu) || 0,
                            });
                            refetchLicitacion();
                            setOpenPartidaManual(false);
                          } catch (err) {
                            setPartidaError(
                              err instanceof Error ? err.message : "Error al guardar la partida"
                            );
                          } finally {
                            setSubmittingPartida(false);
                          }
                        }}
                      >
                        <div className="grid gap-2">
                          <label className="text-xs font-medium text-slate-600">
                            Lote / Zona
                          </label>
                          <Input
                            value={partidaForm.lote}
                            onChange={(e) =>
                              setPartidaForm((p) => ({ ...p, lote: e.target.value }))
                            }
                            placeholder="General"
                          />
                        </div>
                        <div className="grid gap-2">
                          <label className="text-xs font-medium text-slate-600">
                            Producto / Descripción *
                          </label>
                          <Input
                            value={partidaForm.producto}
                            onChange={(e) =>
                              setPartidaForm((p) => ({ ...p, producto: e.target.value }))
                            }
                            placeholder="Ej. Suministro de material"
                            required
                          />
                        </div>
                        <div className="grid grid-cols-3 gap-2">
                          <div className="grid gap-2">
                            <label className="text-xs font-medium text-slate-600">Unidades</label>
                            <Input
                              type="number"
                              min={0}
                              step={0.01}
                              placeholder="0"
                              value={partidaForm.unidades}
                              onChange={(e) =>
                                setPartidaForm((p) => ({ ...p, unidades: e.target.value }))
                              }
                            />
                          </div>
                          <div className="grid gap-2">
                            <label className="text-xs font-medium text-slate-600">PVU (€)</label>
                            <Input
                              type="number"
                              min={0}
                              step={0.01}
                              placeholder="0"
                              value={partidaForm.pvu}
                              onChange={(e) =>
                                setPartidaForm((p) => ({ ...p, pvu: e.target.value }))
                              }
                            />
                          </div>
                          <div className="grid gap-2">
                            <label className="text-xs font-medium text-slate-600">PCU (€)</label>
                            <Input
                              type="number"
                              min={0}
                              step={0.01}
                              placeholder="0"
                              value={partidaForm.pcu}
                              onChange={(e) =>
                                setPartidaForm((p) => ({ ...p, pcu: e.target.value }))
                              }
                            />
                          </div>
                        </div>
                        <div className="grid gap-2">
                          <label className="text-xs font-medium text-slate-600">
                            P. Máximo unit. (€)
                          </label>
                          <Input
                            type="number"
                            min={0}
                            step={0.01}
                            placeholder="0"
                            value={partidaForm.pmaxu}
                            onChange={(e) =>
                              setPartidaForm((p) => ({ ...p, pmaxu: e.target.value }))
                            }
                          />
                        </div>
                        {partidaError && (
                          <p className="text-sm text-red-600">{partidaError}</p>
                        )}
                        <div className="flex justify-end gap-2 pt-2">
                          <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpenPartidaManual(false)}
                            disabled={submittingPartida}
                          >
                            Cancelar
                          </Button>
                          <Button type="submit" disabled={submittingPartida}>
                            {submittingPartida ? "Guardando…" : "Añadir partida"}
                          </Button>
                        </div>
                      </form>
                    </DialogContent>
                  </Dialog>
                </div>
              </div>
            </div>

            <div className="space-y-4">
              {lotesUnicos
                .filter((lote) => lotesActivos.includes(lote))
                .map((lote) => {
                  const items = itemsPorLote[lote] ?? [];
                  const subtotalVenta = items.reduce((acc, i) => acc + i.unidades * i.pvu, 0);
                  const subtotalCoste = items.reduce((acc, i) => acc + i.unidades * i.pcu, 0);
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
                                item.pvu > 0 ? ((item.pvu - item.pcu) / item.pvu) * 100 : 0;
                              return (
                                <tr
                                  key={`${item.lote}-${item.descripcion}`}
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
                                <td className="py-1.5 pr-3 text-slate-900">{lin.articulo ?? "—"}</td>
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
                          className="flex flex-wrap items-end gap-2 rounded border border-slate-200 bg-slate-50/50 p-2"
                        >
                          <div className="min-w-[180px] flex-1">
                            <label className="sr-only">Concepto partida</label>
                            <select
                              className="h-9 w-full rounded border border-slate-300 bg-white px-2 text-sm"
                              value={lin.concepto_partida || opcionesPartidas[0]}
                              onChange={(e) =>
                                setAlbaranForm((f) => ({
                                  ...f,
                                  lineas: f.lineas.map((l, i) =>
                                    i === idx ? { ...l, concepto_partida: e.target.value } : l
                                  ),
                                }))
                              }
                            >
                              {opcionesPartidas.map((opt, optIdx) => (
                                <option key={`partida-${optIdx}`} value={opt}>
                                  {opt}
                                </option>
                              ))}
                            </select>
                          </div>
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
                                concepto_partida: opcionesPartidas[0] ?? "",
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
