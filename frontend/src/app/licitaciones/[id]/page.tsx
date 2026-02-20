"use client";

import * as React from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { z } from "zod";
import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowLeft, ChevronRight, Edit3, ExternalLink, Trash2 } from "lucide-react";

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
import { getEstadoNombre } from "@/lib/estados";
import { ProductAutocompleteInput } from "@/components/producto-autocomplete-input";
import { CostDeviationKPI } from "@/components/licitaciones/CostDeviationKPI";
import { CreateTenderDialog } from "@/components/licitaciones/create-tender-dialog";
import { EditableBudgetTable } from "@/components/licitaciones/editable-budget-table";
import { ScheduledDeliveriesAccordion } from "@/components/licitaciones/ScheduledDeliveriesAccordion";
import { DeliveriesService, EstadosService, TendersService, TiposGastoService, TiposService } from "@/services/api";
import type {
  EntregaLinea,
  Estado,
  EntregaWithLines,
  LoteConfigItem,
  TenderDetail,
  TenderPartida,
  TenderStatusChange,
  Tipo,
} from "@/types/api";

const ID_ESTADO_ADJUDICADA = 5;
const ID_ESTADO_DESCARTADA = 2;
const ID_ESTADO_NO_ADJUDICADA = 6;
const ID_ESTADO_PRESENTADA = 4;
const ESTADOS_PRESUPUESTO_BLOQUEADO = [2, 4, 5, 6, 7, 8];

const ESTADOS_LINEA_ENTREGA = ["EN ESPERA", "ENTREGADO", "FACTURADO"] as const;

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

/** Schema para el formulario de edición de cabecera (fechas, descripción y enlace Gober). Coincide con TenderUpdate. */
const cabeceraFormSchema = z
  .object({
    fecha_presentacion: z.string().optional(),
    fecha_adjudicacion: z.string().optional(),
    fecha_finalizacion: z.string().optional(),
    descripcion: z.string().optional(),
    enlace_gober: z.string().optional(),
    enlace_sharepoint: z.string().optional(),
  })
  .superRefine((data, ctx) => {
    const raw = (data.fecha_presentacion ?? "").trim().split("T")[0];
    if (!raw) return;
    const [y, m, d] = raw.split("-").map(Number);
    if (!y || !m || !d) return;
    const fPresentacion = new Date(y, m - 1, d);
    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    fPresentacion.setHours(0, 0, 0, 0);
    if (fPresentacion > hoy) {
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
  });
type CabeceraFormValues = z.infer<typeof cabeceraFormSchema>;

function EstadoLineaCell({
  lin,
  updatingLineId,
  onUpdate,
  options,
}: {
  lin: EntregaLinea;
  updatingLineId: number | null;
  onUpdate: (idReal: number, payload: { estado?: string; cobrado?: boolean }) => void;
  options: readonly string[];
}) {
  const idReal = lin.id_real;
  const valor = lin.estado ?? "";
  if (idReal == null) {
    return <span className="text-slate-500">{valor || "—"}</span>;
  }
  const isUpdating = updatingLineId === idReal;
  return (
    <select
      value={valor || options[0]}
      onChange={(e) => onUpdate(idReal, { estado: e.target.value })}
      disabled={isUpdating}
      className="rounded border border-slate-200 bg-white px-2 py-1 text-sm text-slate-900 disabled:opacity-60"
    >
      {options.map((opt) => (
        <option key={opt} value={opt}>
          {opt}
        </option>
      ))}
    </select>
  );
}

function CobradoLineaCell({
  lin,
  updatingLineId,
  onUpdate,
}: {
  lin: EntregaLinea;
  updatingLineId: number | null;
  onUpdate: (idReal: number, payload: { estado?: string; cobrado?: boolean }) => void;
}) {
  const idReal = lin.id_real;
  const checked = lin.cobrado ?? false;
  if (idReal == null) {
    return <span className="text-slate-500">{checked ? "Sí" : "No"}</span>;
  }
  const isUpdating = updatingLineId === idReal;
  return (
    <div className="flex justify-center">
      <Switch
        checked={checked}
        onCheckedChange={(c) => onUpdate(idReal, { cobrado: c })}
        disabled={isUpdating}
      />
    </div>
  );
}

export default function LicitacionDetallePage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const [lic, setLic] = React.useState<TenderDetail | null>(null);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [uniqueLotesFromTable, setUniqueLotesFromTable] = React.useState<string[]>([]);
  const [showLotesConfigPanel, setShowLotesConfigPanel] = React.useState(false);
  const [numLotesInput, setNumLotesInput] = React.useState("2");
  const [submittingLotes, setSubmittingLotes] = React.useState(false);
  const [errorLotes, setErrorLotes] = React.useState<string | null>(null);
  const [entregas, setEntregas] = React.useState<EntregaWithLines[]>([]);
  const [estados, setEstados] = React.useState<Estado[]>([]);
  const [tipos, setTipos] = React.useState<Tipo[]>([]);
  const [tiposGasto, setTiposGasto] = React.useState<{ id: number; codigo: string; nombre: string }[]>([]);
  const [openAlbaran, setOpenAlbaran] = React.useState(false);
  type LineaTipo = "presupuestada" | "extraordinario";

  const nuevaLineaVacia = () => ({
    id_producto: null as number | null,
    id_detalle: null as number | null,
    id_tipo_gasto: null as number | null,
    productNombre: "",
    tipoGastoNombre: "",
    proveedor: "",
    cantidad: "",
    coste_unit: "",
  });

  const [albaranForm, setAlbaranForm] = React.useState({
    fecha: new Date().toISOString().slice(0, 10),
    codigo_albaran: "",
    observaciones: "",
    tipoLinea: "presupuestada" as LineaTipo,
    lineas: [nuevaLineaVacia(), nuevaLineaVacia(), nuevaLineaVacia()],
  });

  const tipoLinea = albaranForm.tipoLinea;
  const [submittingAlbaran, setSubmittingAlbaran] = React.useState(false);
  const [albaranError, setAlbaranError] = React.useState<string | null>(null);
  const [openEditarCabecera, setOpenEditarCabecera] = React.useState(false);
  const [submittingCabecera, setSubmittingCabecera] = React.useState(false);
  const [updatingLineId, setUpdatingLineId] = React.useState<number | null>(null);
  const [openCambiarEstado, setOpenCambiarEstado] = React.useState(false);
  const [submittingEstado, setSubmittingEstado] = React.useState(false);
  const [errorEstado, setErrorEstado] = React.useState<string | null>(null);
  const [nuevoEstadoId, setNuevoEstadoId] = React.useState<number | null>(null);
  const [motivoDescarte, setMotivoDescarte] = React.useState("");
  const [motivoPerdida, setMotivoPerdida] = React.useState("");
  const [competidorGanador, setCompetidorGanador] = React.useState("");
  const [openCreateContratoBasado, setOpenCreateContratoBasado] = React.useState(false);

  const cabeceraForm = useForm<CabeceraFormValues>({
    resolver: zodResolver(cabeceraFormSchema),
    defaultValues: {
      fecha_presentacion: "",
      fecha_adjudicacion: "",
      fecha_finalizacion: "",
      descripcion: "",
      enlace_gober: "",
      enlace_sharepoint: "",
    },
  });

  React.useEffect(() => {
    if (openEditarCabecera && lic) {
      cabeceraForm.reset({
        fecha_presentacion: lic.fecha_presentacion ?? "",
        fecha_adjudicacion: lic.fecha_adjudicacion ?? "",
        fecha_finalizacion: lic.fecha_finalizacion ?? "",
        descripcion: lic.descripcion ?? "",
        enlace_gober: lic.enlace_gober ?? "",
        enlace_sharepoint: lic.enlace_sharepoint ?? "",
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

  const refetchEntregas = React.useCallback(() => {
    if (!Number.isFinite(id)) return;
    DeliveriesService.getByLicitacion(id).then(setEntregas).catch(() => setEntregas([]));
  }, [id]);

  const handleUpdateLineaEntrega = React.useCallback(
    (idReal: number, payload: { estado?: string; cobrado?: boolean }) => {
      setUpdatingLineId(idReal);
      DeliveriesService.updateLine(idReal, payload)
        .then(() => refetchEntregas())
        .catch(() => {})
        .finally(() => setUpdatingLineId(null));
    },
    [refetchEntregas]
  );

  React.useEffect(() => {
    if (!Number.isFinite(id) || !lic) return;
    refetchEntregas();
  }, [id, lic?.id_licitacion, refetchEntregas]);

  React.useEffect(() => {
    Promise.allSettled([
      EstadosService.getAll(),
      TiposService.getAll(),
      TiposGastoService.getTipos(),
    ]).then(([e, t, tg]) => {
      setEstados( e.status === "fulfilled" ? (e.value ?? []) : [] );
      setTipos( t.status === "fulfilled" ? (t.value ?? []) : [] );
      setTiposGasto( tg.status === "fulfilled" ? (tg.value ?? []) : [] );
    });
  }, []);

  /** Unidades ya entregadas por id_detalle (solo entregas guardadas, sin el formulario en curso). */
  const unidadesEntregadasPorDetalle = React.useMemo(() => {
    const map = new Map<number, number>();
    for (const ent of entregas) {
      for (const lin of ent.lineas) {
        const idDet = lin.id_detalle;
        if (idDet != null) {
          map.set(idDet, (map.get(idDet) ?? 0) + Number(lin.cantidad));
        }
      }
    }
    return map;
  }, [entregas]);

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

  const isLocked = showContent && lic ? ESTADOS_PRESUPUESTO_BLOQUEADO.includes(lic.id_estado) : false;
  const showEjecucionRemainingTabs = showContent && lic ? lic.id_estado >= ID_ESTADO_ADJUDICADA : false;
  const isAmSda = showContent && lic && (lic.tipo_procedimiento === "ACUERDO_MARCO" || lic.tipo_procedimiento === "SDA");
  /** Contratos basados y específicos: no tienen lotes; solo una tabla de presupuesto. */
  const isContratoDerivado = showContent && lic && (lic.id_licitacion_padre != null || lic.tipo_procedimiento === "CONTRATO_BASADO" || lic.tipo_procedimiento === "ESPECIFICO_SDA");
  const puedeMarcarLotesGanados = showContent && lic ? lic.id_estado >= ID_ESTADO_ADJUDICADA : false;
  const isRechazada = showContent && lic && (lic.id_estado === ID_ESTADO_DESCARTADA || lic.id_estado === ID_ESTADO_NO_ADJUDICADA);
  const motivoRechazo = React.useMemo(() => {
    if (!lic?.descripcion || !isRechazada) return null;
    const d = lic.descripcion;
    const matchDescarte = d.match(/\[MOTIVO DESCARTE\]:\s*(.+?)(?:\n|$)/i);
    if (matchDescarte) return { tipo: "Descartada" as const, texto: matchDescarte[1].trim() };
    const matchPerdida = d.match(/\[PERDIDA\]:\s*(.+?)(?:\n|$)/i);
    if (matchPerdida) return { tipo: "Perdida" as const, texto: matchPerdida[1].trim() };
    return null;
  }, [lic?.descripcion, isRechazada]);

  const handleCambiarEstado = async () => {
    if (!lic || nuevoEstadoId == null) return;
    setErrorEstado(null);
    setSubmittingEstado(true);
    try {
      const payload: TenderStatusChange = { nuevo_estado_id: nuevoEstadoId };
      if (nuevoEstadoId === ID_ESTADO_DESCARTADA) {
        if (!motivoDescarte.trim()) {
          setErrorEstado("El motivo del descarte es obligatorio.");
          return;
        }
        payload.motivo_descarte = motivoDescarte.trim();
      }
      if (nuevoEstadoId === ID_ESTADO_NO_ADJUDICADA) {
        if (!motivoPerdida.trim()) {
          setErrorEstado("El motivo de la pérdida es obligatorio.");
          return;
        }
        if (!competidorGanador.trim()) {
          setErrorEstado("El ganador es obligatorio.");
          return;
        }
        payload.motivo_perdida = motivoPerdida.trim();
        payload.competidor_ganador = competidorGanador.trim();
      }
      if (nuevoEstadoId === ID_ESTADO_ADJUDICADA) {
        // AM/SDA: no tienen partidas; usamos pres_maximo (o 1) como importe simbólico
        const isAmOrSda = lic.tipo_procedimiento === "ACUERDO_MARCO" || lic.tipo_procedimiento === "SDA";
        const importe = isAmOrSda
          ? Math.max(1, Math.round((Number(lic.pres_maximo) || 0) * 100) / 100)
          : Math.round(ofertado * 100) / 100;
        if (importe <= 0) {
          setErrorEstado("El presupuesto presentado debe ser > 0 para marcar como adjudicada.");
          return;
        }
        payload.importe_adjudicacion = importe;
        const fAdj = (lic.fecha_adjudicacion ?? "").toString().trim().slice(0, 10);
        payload.fecha_adjudicacion = fAdj || new Date().toISOString().slice(0, 10);
      }
      await TendersService.changeStatus(lic.id_licitacion, payload);
      refetchLicitacion();
      setOpenCambiarEstado(false);
      setNuevoEstadoId(null);
      setMotivoDescarte("");
      setMotivoPerdida("");
      setCompetidorGanador("");
    } catch (e) {
      setErrorEstado(e instanceof Error ? e.message : "Error al cambiar estado.");
    } finally {
      setSubmittingEstado(false);
    }
  };

  const transicionesDisponibles = React.useMemo(() => {
    if (!lic) return [];
    const id = lic.id_estado;
    const trans: { id: number; label: string }[] = [];
    if (id === 1 || id === 3) {
      trans.push({ id: ID_ESTADO_PRESENTADA, label: "Presentada" });
      trans.push({ id: ID_ESTADO_DESCARTADA, label: "Descartar" });
    }
    if (id === ID_ESTADO_PRESENTADA) {
      trans.push({ id: ID_ESTADO_ADJUDICADA, label: "Adjudicada" });
      trans.push({ id: ID_ESTADO_NO_ADJUDICADA, label: "Marcar como Perdida" });
    }
    if (id === ID_ESTADO_ADJUDICADA) {
      trans.push({ id: 7, label: "Finalizada" });
    }
    return trans;
  }, [lic?.id_estado]);

  const itemsPresupuesto = showContent && lic ? mapPartidas(lic.partidas ?? []) : [];
  const itemsPresupuestoAgregado = agregarPartidas(itemsPresupuesto);
  const lotesConfig = (lic?.lotes_config ?? []) as LoteConfigItem[];
  const lotesGanados = new Set(lotesConfig.filter((l) => l.ganado).map((l) => l.nombre));
  const activos = itemsPresupuestoAgregado.filter((i) => {
    if (lotesConfig.length > 0) {
      return lotesGanados.has(i.lote);
    }
    return i.activo;
  });
  const presupuestoBase = showContent && lic ? Number(lic.pres_maximo) || 0 : 0;
  // Ofertado / coste previsto según tipo de licitación
  const isTipo2 = lic?.id_tipolicitacion === 2;
  const partidasActivasTipo2 =
    isTipo2 && lic
      ? (lic.partidas ?? []).filter((p) => {
          const lote = (p.lote ?? "General") as string;
          const activo = p.activo ?? true;
          if (lotesConfig.length > 0) {
            return activo && lotesGanados.has(lote);
          }
          return activo;
        })
      : [];

  const ofertado = isTipo2
    ? (() => {
        const totalPmaxu = partidasActivasTipo2.reduce(
          (acc, p) => acc + (Number(p.pmaxu) || 0),
          0
        );
        const totalPvu = partidasActivasTipo2.reduce(
          (acc, p) => acc + (Number(p.pvu) || 0),
          0
        );
        if (!presupuestoBase || presupuestoBase <= 0) return totalPvu;
        const factor =
          totalPmaxu > 0 ? Math.max(0, Math.min(1, totalPvu / totalPmaxu)) : 1;
        return presupuestoBase * factor;
      })()
    : activos.reduce((acc, i) => {
        const pvu = Number(i.pvu) || 0;
        const uds = Number(i.unidades) || 0;
        return acc + uds * pvu;
      }, 0);

  const costePrevisto = isTipo2
    ? (() => {
        const n = partidasActivasTipo2.length;
        if (n === 0) return 0;
        const totalPvu = partidasActivasTipo2.reduce(
          (acc, p) => acc + (Number(p.pvu) || 0),
          0
        );
        const totalPcu = partidasActivasTipo2.reduce(
          (acc, p) => acc + (Number(p.pcu) || 0),
          0
        );
        const mediaPvu = totalPvu / n || 0;
        const udsTeoricas =
          mediaPvu > 0 ? ofertado / mediaPvu : 0;
        const mediaCoste =
          n > 0 ? totalPcu / n : 0;
        return udsTeoricas * mediaCoste;
      })()
    : activos.reduce((acc, i) => {
        const pcu = Number(i.pcu) || 0;
        const uds = Number(i.unidades) || 0;
        return acc + uds * pcu;
      }, 0);
  const beneficioPrevisto = ofertado - costePrevisto;

  const handleGenerarLotes = async () => {
    if (!lic) return;
    setErrorLotes(null);
    const n = parseInt(numLotesInput, 10);
    if (Number.isNaN(n) || n < 1 || n > 20) {
      setErrorLotes("Introduce un número entre 1 y 20.");
      return;
    }
    setSubmittingLotes(true);
    try {
      const cfg: LoteConfigItem[] = Array.from({ length: n }, (_, i) => ({
        nombre: `Lote ${i + 1}`,
        ganado: false,
      }));
      const actualizado = await TendersService.update(lic.id_licitacion, { lotes_config: cfg });
      setLic((prev) => (prev ? { ...prev, ...actualizado, lotes_config: cfg } : null));
    } catch (e) {
      setErrorLotes(e instanceof Error ? e.message : "Error al generar lotes. ¿Has ejecutado la migración lotes_config en Supabase?");
    } finally {
      setSubmittingLotes(false);
    }
  };

  const handleToggleGanado = async (nombreLote: string, ganado: boolean) => {
    if (!lic?.lotes_config) return;
    const cfg = (lic.lotes_config as LoteConfigItem[]).map((l) =>
      l.nombre === nombreLote ? { ...l, ganado } : l
    );
    await TendersService.update(lic.id_licitacion, { lotes_config: cfg });
    refetchLicitacion();
  };

  const handleSubmitAlbaran = async () => {
    if (!showContent || !lic) return;
    setAlbaranError(null);
    setSubmittingAlbaran(true);
    try {
      const partidasMap = new Map<number, number>();
      for (const p of lic.partidas ?? []) {
        partidasMap.set(p.id_detalle, Number(p.unidades) || 0);
      }
      const allocPorDetalle = new Map<number, number>();

      const lineas = albaranForm.lineas
        .filter((l) =>
          tipoLinea === "extraordinario"
            ? l.id_tipo_gasto != null
            : l.id_producto != null
        )
        .map((l) => {
          let cantidad = tipoLinea === "extraordinario" ? 0 : parseFloat(String(l.cantidad)) || 0;
          if (tipoLinea === "presupuestada" && l.id_detalle != null) {
            const presu = partidasMap.get(l.id_detalle) ?? 0;
            const entregadas = unidadesEntregadasPorDetalle.get(l.id_detalle) ?? 0;
            const yaAlloc = allocPorDetalle.get(l.id_detalle) ?? 0;
            const maxPermitido = Math.max(0, presu - entregadas - yaAlloc);
            cantidad = Math.min(cantidad, maxPermitido);
            allocPorDetalle.set(l.id_detalle, yaAlloc + cantidad);
          }
          const coste = parseFloat(String(l.coste_unit)) || 0;
          if (tipoLinea === "extraordinario") {
            return {
              id_producto: null as number | null,
              id_detalle: null,
              id_tipo_gasto: l.id_tipo_gasto ?? null,
              proveedor: undefined,
              cantidad: 0,
              coste_unit: coste,
            };
          }
          return {
            id_producto: l.id_producto as number,
            id_detalle: l.id_detalle ?? null,
            id_tipo_gasto: null as number | null,
            proveedor: l.proveedor?.trim() || undefined,
            cantidad,
            coste_unit: coste,
          };
        })
        .filter((l) => l.cantidad > 0 || l.coste_unit > 0);
      if (lineas.length === 0) {
        setAlbaranError(
          tipoLinea === "extraordinario"
            ? "Añade al menos una línea con tipo de gasto y coste."
            : "Añade al menos una línea con producto seleccionado."
        );
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
        tipoLinea: "presupuestada",
        lineas: [nuevaLineaVacia(), nuevaLineaVacia(), nuevaLineaVacia()],
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
            {lic.enlace_gober && (
              <a
                href={lic.enlace_gober}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1 font-medium text-emerald-600 hover:text-emerald-700 hover:underline"
              >
                <ExternalLink className="h-3.5 w-3.5" />
                Ver en Gober
              </a>
            )}
            {lic.enlace_sharepoint && (
              <a
                href={lic.enlace_sharepoint}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1 font-medium text-sky-600 hover:text-sky-700 hover:underline"
              >
                <ExternalLink className="h-3.5 w-3.5" />
                SharePoint
              </a>
            )}
            <span>
              <span className="font-medium text-slate-700">Tipo:</span>{" "}
              {lic.id_tipolicitacion != null
                ? (tipos.find((t) => t.id_tipolicitacion === lic.id_tipolicitacion)?.tipo ?? `Tipo ${lic.id_tipolicitacion}`)
                : "—"}
            </span>
            <Badge variant="info" className="min-w-[130px] justify-center">
              {getEstadoNombre(lic.id_estado, estados)}
            </Badge>
            {transicionesDisponibles.length > 0 && (
              <Dialog
                open={openCambiarEstado}
                onOpenChange={(open) => {
                  setOpenCambiarEstado(open);
                  if (!open) {
                    setNuevoEstadoId(null);
                    setErrorEstado(null);
                  }
                }}
              >
                <DialogTrigger asChild>
                  <Button className="gap-2" size="sm">
                    <ChevronRight className="h-4 w-4" />
                    Cambiar Estado
                  </Button>
                </DialogTrigger>
                <DialogContent className="max-w-md">
                  <DialogHeader>
                    <DialogTitle>Avanzar estado</DialogTitle>
                    <DialogDescription>Selecciona el nuevo estado y completa los datos requeridos.</DialogDescription>
                  </DialogHeader>
                  <div className="space-y-4 pt-2">
                    <div>
                      <label className="mb-1 block text-xs font-medium text-slate-600">Nuevo estado</label>
                      <div className="flex flex-wrap gap-2">
                        {transicionesDisponibles.map((t) => (
                          <Button
                            key={t.id}
                            type="button"
                            variant={nuevoEstadoId === t.id ? "default" : "outline"}
                            size="sm"
                            onClick={() => {
                              setNuevoEstadoId(t.id);
                              setErrorEstado(null);
                            }}
                          >
                            {t.label}
                          </Button>
                        ))}
                      </div>
                    </div>
                    {nuevoEstadoId === ID_ESTADO_DESCARTADA && (
                      <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Motivo del descarte *</label>
                        <Textarea
                          value={motivoDescarte}
                          onChange={(e) => setMotivoDescarte(e.target.value)}
                          placeholder="Explica por qué se descarta esta licitación..."
                          rows={3}
                          className="w-full"
                        />
                      </div>
                    )}
                    {nuevoEstadoId === ID_ESTADO_NO_ADJUDICADA && (
                      <div className="space-y-2">
                        <div>
                          <label className="mb-1 block text-xs font-medium text-slate-600">Motivo de la pérdida *</label>
                          <Textarea
                            value={motivoPerdida}
                            onChange={(e) => setMotivoPerdida(e.target.value)}
                            placeholder="¿Por qué no se adjudicó?"
                            rows={2}
                            className="w-full"
                          />
                        </div>
                        <div>
                          <label className="mb-1 block text-xs font-medium text-slate-600">Ganador / Competidor *</label>
                          <Input
                            value={competidorGanador}
                            onChange={(e) => setCompetidorGanador(e.target.value)}
                            placeholder="Empresa adjudicataria"
                          />
                        </div>
                      </div>
                    )}
                    {errorEstado && (
                      <p className="text-sm text-red-600">{errorEstado}</p>
                    )}
                    <div className="flex justify-end gap-2 pt-2">
                      <Button variant="outline" onClick={() => setOpenCambiarEstado(false)} disabled={submittingEstado}>
                        Cancelar
                      </Button>
                      <Button onClick={handleCambiarEstado} disabled={submittingEstado || nuevoEstadoId == null}>
                        {submittingEstado ? "Guardando…" : "Confirmar cambio"}
                      </Button>
                    </div>
                  </div>
                </DialogContent>
              </Dialog>
            )}
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
                          enlace_gober: values.enlace_gober?.trim() || null,
                          enlace_sharepoint: values.enlace_sharepoint?.trim() || null,
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
                      name="enlace_gober"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel className="text-xs font-medium text-slate-500">
                            Enlace Gober (obligatorio si F. Presentación es futura)
                          </FormLabel>
                          <FormControl>
                            <Input
                              type="url"
                              placeholder="https://gober.es/..."
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
                      name="enlace_sharepoint"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel className="text-xs font-medium text-slate-500">
                            Enlace SharePoint
                          </FormLabel>
                          <FormControl>
                            <Input
                              type="url"
                              placeholder="https://... (carpeta o sitio con documentación)"
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

            {lic.licitacion_padre ? (
              <Link href={`/licitaciones/${lic.licitacion_padre.id_licitacion}`}>
                <Button variant="outline" className="gap-2">
                  <ArrowLeft className="h-4 w-4" />
                  Volver al AM/SDA
                </Button>
              </Link>
            ) : (
              <Link href="/licitaciones">
                <Button variant="outline" className="gap-2">
                  <ArrowLeft className="h-4 w-4" />
                  Volver al listado
                </Button>
              </Link>
            )}
          </div>
        </div>
      </header>

      {lic.licitacion_padre && (
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
          <p className="text-sm text-slate-700">
            Contrato derivado de:{" "}
            <Link
              href={`/licitaciones/${lic.licitacion_padre.id_licitacion}`}
              className="font-semibold text-slate-900 hover:underline"
            >
              {lic.licitacion_padre.nombre ?? `#${lic.licitacion_padre.id_licitacion}`}
              {lic.licitacion_padre.numero_expediente ? ` (${lic.licitacion_padre.numero_expediente})` : ""}
            </Link>
          </p>
          <Link href={`/licitaciones/${lic.licitacion_padre.id_licitacion}`}>
            <Button variant="outline" size="sm" className="mt-2 gap-2">
              <ArrowLeft className="h-3.5 w-3.5" />
              Volver al AM/SDA
            </Button>
          </Link>
        </div>
      )}

      {motivoRechazo && (
        <div className="rounded-lg border border-red-200 bg-red-50 p-4">
          <p className="text-sm font-semibold text-red-800">
            {motivoRechazo.tipo === "Descartada" ? "Licitación descartada" : "Licitación perdida"}
          </p>
          <p className="mt-1 text-sm text-red-700">{motivoRechazo.texto}</p>
        </div>
      )}

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

      {isAmSda && (
        <div className="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
          Este es un Acuerdo Marco / SDA. La gestión de presupuesto, entregas y remaining se hace en cada contrato derivado.
        </div>
      )}

      {showContent && lic && (lic.coste_presupuestado != null || lic.coste_real != null) && (
        <CostDeviationKPI
          costePresupuestado={lic.coste_presupuestado}
          costeReal={lic.coste_real}
          gastosExtraordinarios={lic.gastos_extraordinarios}
          className="mt-4"
        />
      )}

      <section className="mt-2">
        <Tabs defaultValue={isAmSda ? "contratos-derivados" : "presupuesto"}>
          <TabsList>
            {!isAmSda && (
              <TabsTrigger value="presupuesto">Presupuesto (Oferta)</TabsTrigger>
            )}
            {isAmSda && (
              <TabsTrigger value="contratos-derivados">Contratos Derivados</TabsTrigger>
            )}
            {showEjecucionRemainingTabs && !isAmSda && (
              <>
                <TabsTrigger value="ejecucion">Entregas (Real / Albaranes)</TabsTrigger>
                <TabsTrigger value="remaining">Remaining</TabsTrigger>
              </>
            )}
          </TabsList>

          {!isAmSda && (
          <TabsContent value="presupuesto" className="flex min-h-[60vh] flex-col">
            {lic ? (
              <>
                {isContratoDerivado ? (
                  <div className="min-h-0 flex-1">
                    <p className="mb-3 text-sm text-slate-600">
                      En contratos basados y específicos no se usan lotes; todas las partidas en una sola tabla.
                    </p>
                    <EditableBudgetTable
                      lic={lic}
                      onPartidaAdded={refetchLicitacion}
                      isLocked={isLocked}
                    />
                  </div>
                ) : (!lotesConfig || lotesConfig.length === 0) ? (
                  <div className="flex flex-col gap-4">
                    {!showLotesConfigPanel ? (
                      <div className="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <span className="text-sm text-slate-600">¿Quieres añadir lotes a esta licitación?</span>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          onClick={() => setShowLotesConfigPanel(true)}
                        >
                          Sí, configurar lotes
                        </Button>
                      </div>
                    ) : (
                      <Card>
                        <CardHeader>
                          <CardTitle className="text-sm font-semibold text-slate-800">
                            Configurar lotes
                          </CardTitle>
                          <p className="text-sm text-slate-600">
                            Define cuántos lotes tiene esta licitación. Cada lote se mostrará como una tabla separada con su propio presupuesto.
                          </p>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                          {errorLotes && (
                            <p className="text-sm text-red-600">{errorLotes}</p>
                          )}
                          <div className="flex flex-row items-end gap-3">
                            <div className="flex flex-col gap-1">
                              <label className="text-xs font-medium text-slate-600">¿Cuántos lotes?</label>
                              <Input
                                type="number"
                                min={1}
                                max={20}
                                value={numLotesInput}
                                onChange={(e) => {
                                  setNumLotesInput(e.target.value);
                                  setErrorLotes(null);
                                }}
                                className="w-24"
                              />
                            </div>
                            <Button
                              type="button"
                              onClick={handleGenerarLotes}
                              disabled={submittingLotes || !numLotesInput || parseInt(numLotesInput, 10) < 1}
                            >
                              {submittingLotes ? "Generando…" : "Generar lotes"}
                            </Button>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              onClick={() => setShowLotesConfigPanel(false)}
                            >
                              Cancelar
                            </Button>
                          </div>
                        </CardContent>
                      </Card>
                    )}
                    <div className="min-h-0 flex-1">
                      <EditableBudgetTable
                        lic={lic}
                        onPartidaAdded={refetchLicitacion}
                        onUniqueLotesChange={setUniqueLotesFromTable}
                        isLocked={isLocked}
                      />
                    </div>
                  </div>
                ) : (
                  <div className="space-y-6">
                    {lotesConfig.map((loteItem) => (
                      <Card key={loteItem.nombre}>
                        <CardHeader className="flex flex-row items-center justify-between gap-4 pb-3">
                          <CardTitle className="text-base font-semibold text-slate-800">
                            {loteItem.nombre}
                          </CardTitle>
                          <div className="flex items-center gap-2">
                            <span className="text-xs text-slate-500">Ganado</span>
                            <Switch
                              checked={loteItem.ganado}
                              disabled={!puedeMarcarLotesGanados}
                              title={puedeMarcarLotesGanados ? "Marcar si este lote se adjudicó" : "Disponible tras la adjudicación"}
                              onCheckedChange={(checked) => handleToggleGanado(loteItem.nombre, checked)}
                            />
                          </div>
                        </CardHeader>
                        <CardContent className="pt-0">
                          <div className="min-h-0 flex-1">
                            <EditableBudgetTable
                              key={`lote-${loteItem.nombre}`}
                              lic={lic}
                              onPartidaAdded={refetchLicitacion}
                              loteFilter={loteItem.nombre}
                              isLocked={isLocked}
                            />
                          </div>
                        </CardContent>
                      </Card>
                    ))}
                    {(() => {
                      const lotesDefinidos = new Set(lotesConfig.map((l) => l.nombre));
                      const partidasOtros = (lic.partidas ?? []).filter(
                        (p) => !lotesDefinidos.has(p.lote ?? "General")
                      );
                      if (partidasOtros.length === 0) return null;
                      return (
                        <Card>
                          <CardHeader>
                            <CardTitle className="text-base font-semibold text-slate-800">
                              Otros (sin lote definido)
                            </CardTitle>
                          </CardHeader>
                          <CardContent className="pt-0">
                            <div className="min-h-0 flex-1">
                              <EditableBudgetTable
                                key="lote-otros"
                                lic={lic}
                                onPartidaAdded={refetchLicitacion}
                                lotesExcluidos={lotesConfig.map((l) => l.nombre)}
                                isLocked={isLocked}
                              />
                            </div>
                          </CardContent>
                        </Card>
                      );
                    })()}
                  </div>
                )}
              </>
            ) : null}
          </TabsContent>
          )}

          {isAmSda && (
            <TabsContent value="contratos-derivados" className="flex min-h-[40vh] flex-col">
              <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-slate-600">
                  Contratos basados derivados de este Acuerdo Marco / SDA.
                </p>
                <Button
                  size="sm"
                  onClick={() => setOpenCreateContratoBasado(true)}
                >
                  Generar Contrato Basado
                </Button>
              </div>
              {(lic.contratos_derivados ?? []).length === 0 ? (
                <p className="text-sm text-slate-500">
                  Aún no hay contratos derivados. Usa &quot;Generar Contrato Basado&quot; para crear uno.
                </p>
              ) : (
                <div className="overflow-x-auto rounded-md border border-slate-200">
                  <table className="min-w-full text-left text-sm">
                    <thead>
                      <tr className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <th className="py-2 pr-4">ID</th>
                        <th className="py-2 pr-4">Expediente</th>
                        <th className="py-2 pr-4">Nombre</th>
                        <th className="py-2 pr-4">Estado</th>
                        <th className="py-2 pr-4 text-right">Presupuesto (€)</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(lic.contratos_derivados ?? []).map((c) => (
                        <tr
                          key={c.id_licitacion}
                          className="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                        >
                          <td className="py-2 pr-4 font-medium text-slate-700">
                            <Link href={`/licitaciones/${c.id_licitacion}`} className="hover:underline">
                              #{c.id_licitacion}
                            </Link>
                          </td>
                          <td className="py-2 pr-4 text-slate-600">{c.numero_expediente ?? "—"}</td>
                          <td className="py-2 pr-4">
                            <Link href={`/licitaciones/${c.id_licitacion}`} className="font-medium text-slate-900 hover:underline">
                              {c.nombre}
                            </Link>
                          </td>
                          <td className="py-2 pr-4">
                            {getEstadoNombre(c.id_estado, estados)}
                          </td>
                          <td className="py-2 pr-4 text-right font-medium text-slate-900">
                            {formatEuro(Number(c.pres_maximo) || 0)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </TabsContent>
          )}

          {showEjecucionRemainingTabs && !isAmSda && (
          <TabsContent value="ejecucion">
            {lic?.scheduled_deliveries && lic.scheduled_deliveries.length > 0 && (
              <div className="mb-6">
                <ScheduledDeliveriesAccordion deliveries={lic.scheduled_deliveries} />
              </div>
            )}
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
                                <td className="py-1.5 pr-3 text-center">
                                  <EstadoLineaCell
                                    lin={lin}
                                    updatingLineId={updatingLineId}
                                    onUpdate={handleUpdateLineaEntrega}
                                    options={ESTADOS_LINEA_ENTREGA}
                                  />
                                </td>
                                <td className="py-1.5 pr-3 text-center">
                                  <CobradoLineaCell
                                    lin={lin}
                                    updatingLineId={updatingLineId}
                                    onUpdate={handleUpdateLineaEntrega}
                                  />
                                </td>
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
                    <div className="mb-3 flex items-center gap-2">
                      <span className="text-xs text-slate-500">Tipo de línea:</span>
                      <div className="flex rounded-md border border-slate-200 bg-slate-100 p-0.5" role="group">
                        <button
                          type="button"
                          onClick={() =>
                            setAlbaranForm((f) => ({
                              ...f,
                              tipoLinea: "presupuestada",
                              lineas: f.lineas.map(() => nuevaLineaVacia()),
                            }))
                          }
                          className={
                            "rounded px-3 py-1.5 text-xs font-medium transition-colors " +
                            (tipoLinea === "presupuestada"
                              ? "bg-white text-slate-900 shadow-sm"
                              : "text-slate-600 hover:text-slate-900")
                          }
                        >
                          Presupuestada
                        </button>
                        <button
                          type="button"
                          onClick={() =>
                            setAlbaranForm((f) => ({
                              ...f,
                              tipoLinea: "extraordinario",
                              lineas: f.lineas.map(() => nuevaLineaVacia()),
                            }))
                          }
                          className={
                            "rounded px-3 py-1.5 text-xs font-medium transition-colors " +
                            (tipoLinea === "extraordinario"
                              ? "bg-white text-slate-900 shadow-sm"
                              : "text-slate-600 hover:text-slate-900")
                          }
                        >
                          Gasto extraordinario
                        </button>
                      </div>
                    </div>
                    <div className="overflow-x-auto rounded border border-slate-200">
                      <table className="min-w-full text-sm">
                        <thead>
                          <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <th className="min-w-[180px] py-2 pl-3 pr-2">Concepto</th>
                            {tipoLinea === "presupuestada" && (
                              <th className="w-16 py-2 pr-2 text-right">Cant.</th>
                            )}
                            <th className="w-20 py-2 pr-2 text-right">Coste €</th>
                            <th className="w-10 py-2 pr-3"></th>
                          </tr>
                        </thead>
                        <tbody>
                          {albaranForm.lineas.map((lin, idx) => (
                            <tr key={idx} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/50">
                              <td className="py-1.5 pl-3 pr-2 align-top">
                                {tipoLinea === "presupuestada" ? (
                                  <select
                                    className="h-8 w-full min-w-[160px] rounded border border-slate-200 bg-white px-2 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                    value={lin.id_detalle != null ? String(lin.id_detalle) : ""}
                                    onChange={(e) => {
                                      const idDet = e.target.value ? Number(e.target.value) : null;
                                      const partida = idDet != null ? lic?.partidas?.find((p) => p.id_detalle === idDet) : undefined;
                                      setAlbaranForm((f) => ({
                                        ...f,
                                        lineas: f.lineas.map((l, i) =>
                                          i === idx
                                            ? {
                                                ...l,
                                                id_detalle: idDet ?? null,
                                                id_producto: partida?.id_producto ?? null,
                                                productNombre: partida?.product_nombre ?? "",
                                                proveedor: partida?.nombre_proveedor ?? "",
                                              }
                                            : l
                                        ),
                                      }));
                                    }}
                                  >
                                    <option value="">Selecciona partida…</option>
                                    {(lic?.partidas ?? []).map((p) => (
                                      <option key={p.id_detalle} value={p.id_detalle}>
                                        {isContratoDerivado ? (p.product_nombre ?? "—") : [p.lote ?? "General", p.product_nombre].filter(Boolean).join(" – ")}
                                      </option>
                                    ))}
                                  </select>
                                ) : (
                                  <select
                                    className="h-8 w-full min-w-[160px] rounded border border-slate-200 bg-white px-2 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                    value={lin.id_tipo_gasto != null ? String(lin.id_tipo_gasto) : ""}
                                    onChange={(e) => {
                                      const idTipo = e.target.value ? Number(e.target.value) : null;
                                      const tipo = idTipo != null ? tiposGasto.find((t) => t.id === idTipo) : undefined;
                                      setAlbaranForm((f) => ({
                                        ...f,
                                        lineas: f.lineas.map((l, i) =>
                                          i === idx
                                            ? {
                                                ...l,
                                                id_tipo_gasto: idTipo,
                                                tipoGastoNombre: tipo?.nombre ?? "",
                                                id_producto: null,
                                                productNombre: "",
                                              }
                                            : l
                                        ),
                                      }));
                                    }}
                                  >
                                    <option value="">Tipo de gasto…</option>
                                    {tiposGasto.map((t, idx) => (
                                      <option key={t.id ?? idx} value={t.id ?? ""}>
                                        {t.nombre ?? t.codigo ?? ""}
                                      </option>
                                    ))}
                                  </select>
                                )}
                              </td>
                              {tipoLinea === "presupuestada" && (
                                <td className="py-1.5 pr-2 text-right align-top">
                                    {(() => {
                                      const partida =
                                        lin.id_detalle != null
                                          ? lic?.partidas?.find((p) => p.id_detalle === lin.id_detalle)
                                          : undefined;
                                      const unidadesPresu = partida?.unidades != null ? Number(partida.unidades) : 0;
                                      const yaEntregadas = lin.id_detalle != null ? unidadesEntregadasPorDetalle.get(lin.id_detalle) ?? 0 : 0;
                                      const enOtrasLineas =
                                        lin.id_detalle != null
                                          ? albaranForm.lineas.reduce(
                                              (sum, l, i) =>
                                                i !== idx && l.id_detalle === lin.id_detalle
                                                  ? sum + (parseFloat(String(l.cantidad)) || 0)
                                                  : sum,
                                              0
                                            )
                                          : 0;
                                      const restantes = Math.max(0, unidadesPresu - yaEntregadas - enOtrasLineas);
                                      const showGhost = partida && !String(lin.cantidad).trim() && restantes >= 0;
                                      return (
                                        <div className="relative w-20">
                                          <Input
                                            type="number"
                                            min={0}
                                            max={restantes}
                                            step={0.01}
                                            className="h-8 w-20 text-right text-xs"
                                            value={lin.cantidad}
                                            placeholder={partida && restantes >= 0 ? " " : "Cant."}
                                          onChange={(e) =>
                                            setAlbaranForm((f) => ({
                                              ...f,
                                              lineas: f.lineas.map((l, i) =>
                                                i === idx ? { ...l, cantidad: e.target.value } : l
                                              ),
                                            }))
                                          }
                                          onBlur={(e) => {
                                            const v = parseFloat(String(lin.cantidad));
                                            if (Number.isFinite(v) && v > restantes) {
                                              setAlbaranForm((f) => ({
                                                ...f,
                                                lineas: f.lineas.map((l, i) =>
                                                  i === idx ? { ...l, cantidad: restantes > 0 ? String(restantes) : "" } : l
                                                ),
                                              }));
                                            }
                                          }}
                                          />
                                          {showGhost && (
                                            <span
                                              className="pointer-events-none absolute inset-0 flex items-center justify-end pr-2 text-xs text-slate-400"
                                              aria-hidden
                                            >
                                              {restantes}
                                            </span>
                                          )}
                                        </div>
                                      );
                                    })()}
                                </td>
                              )}
                              <td className="py-1.5 pr-2 text-right align-top">
                                <Input
                                  type="number"
                                  min={0}
                                  step={0.01}
                                  className="h-8 w-16 text-right text-xs"
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
                              </td>
                              <td className="py-1.5 pr-3 align-top">
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  className="h-8 w-8 p-0 text-red-500 hover:bg-red-50 hover:text-red-700"
                                  onClick={() =>
                                    setAlbaranForm((f) => ({
                                      ...f,
                                      lineas: f.lineas.filter((_, i) => i !== idx),
                                    }))
                                  }
                                  aria-label="Quitar línea"
                                >
                                  <Trash2 className="h-4 w-4" />
                                </Button>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="mt-2"
                      onClick={() =>
                        setAlbaranForm((f) => ({
                          ...f,
                          lineas: [...f.lineas, nuevaLineaVacia()],
                        }))
                      }
                    >
                      + Añadir fila
                    </Button>
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
          )}

          {showEjecucionRemainingTabs && !isAmSda && (
          <TabsContent value="remaining">
            <p className="mb-3 text-sm text-slate-600">
              Comparativa entre unidades presupuestadas y ejecutadas por partida.
            </p>
            <Card>
              <CardContent className="pt-4">
                <table className="min-w-full text-left text-sm">
                  <thead>
                    <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                      {!isContratoDerivado && <th className="py-2 pr-3">Lote</th>}
                      <th className="py-2 pr-3">Partida</th>
                      <th className="py-2 pr-3 text-right">Ud. Presu.</th>
                      <th className="py-2 pr-3 text-right">Ud. Real</th>
                      <th className="py-2 pr-3 text-right">Pendiente</th>
                      <th className="py-2 pr-3">Progreso</th>
                    </tr>
                  </thead>
                  <tbody>
                    {itemsPresupuestoAgregado
                      .filter((item) => isContratoDerivado || lotesConfig.length === 0 || lotesGanados.has(item.lote))
                      .map((item) => {
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
                          {!isContratoDerivado && (
                            <td className="py-2 pr-3 text-xs text-slate-500">{item.lote}</td>
                          )}
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
          )}
        </Tabs>
      </section>

      {isAmSda && (
        <CreateTenderDialog
          open={openCreateContratoBasado}
          onOpenChange={setOpenCreateContratoBasado}
          defaultIdLicitacionPadre={id}
          onSuccess={() => {
            refetchLicitacion();
            setOpenCreateContratoBasado(false);
          }}
        />
      )}
    </div>
  );
}
