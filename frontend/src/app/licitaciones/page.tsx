import Link from "next/link";
import { Plus, Search } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { CreateTenderDialog } from "@/components/licitaciones/create-tender-dialog";

type LicitacionEstado =
  | "En Estudio"
  | "Presentada"
  | "Pendiente de Fallo"
  | "Pendiente"
  | "Adjudicada"
  | "Desierta";

type Licitacion = {
  id: number;
  expediente: string;
  nombre: string;
  estado: LicitacionEstado;
  presupuesto: number;
};

function getEstadoVariant(estado: LicitacionEstado) {
  switch (estado) {
    case "Adjudicada":
      return "success";
    case "En Estudio":
    case "Pendiente":
      return "info";
    case "Presentada":
    case "Pendiente de Fallo":
      return "warning";
    case "Desierta":
      return "destructive";
    default:
      return "default";
  }
}

function getMockLicitaciones(): Licitacion[] {
  // Mock basado en las columnas usadas en tenders_list.py
  return [
    {
      id: 1,
      expediente: "EXP-24-001",
      nombre: "Suministro de material hospitalario",
      estado: "En Estudio",
      presupuesto: 850_000,
    },
    {
      id: 2,
      expediente: "EXP-24-014",
      nombre: "Mantenimiento integral edificio corporativo",
      estado: "Presentada",
      presupuesto: 430_000,
    },
    {
      id: 3,
      expediente: "EXP-24-021",
      nombre: "Servicio de limpieza centros educativos",
      estado: "Pendiente de Fallo",
      presupuesto: 1_250_000,
    },
    {
      id: 4,
      expediente: "EXP-23-087",
      nombre: "Gestión de residuos sanitarios",
      estado: "Adjudicada",
      presupuesto: 620_000,
    },
    {
      id: 5,
      expediente: "EXP-23-033",
      nombre: "Renovación parque de vehículos",
      estado: "Desierta",
      presupuesto: 295_000,
    },
  ];
}

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 0,
  }).format(value);
}

export default function LicitacionesPage() {
  const data = getMockLicitaciones();

  return (
    <div className="flex flex-1 flex-col gap-6">
      <header className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
            Mis licitaciones
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Gestión rápida del pipeline: estados, presupuesto y detalle.
          </p>
        </div>
        <CreateTenderDialog triggerLabel="Nueva Licitación" />
      </header>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between gap-4">
          <CardTitle className="text-sm font-medium text-slate-800">
            Listado de licitaciones
          </CardTitle>
          <div className="relative w-full max-w-xs">
            <Search className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
            <input
              type="text"
              placeholder="Buscar por nombre o expediente…"
              className="h-9 w-full rounded-lg border border-slate-200 bg-white pl-8 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
            />
          </div>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          <table className="min-w-full text-left text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <th className="py-2 pr-4">Expediente</th>
                <th className="py-2 pr-4">Nombre proyecto</th>
                <th className="py-2 pr-4">Estado</th>
                <th className="py-2 pr-4 text-right">Presupuesto (€)</th>
              </tr>
            </thead>
            <tbody>
              {data.map((lic) => (
                <tr
                  key={lic.id}
                  className="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                >
                  <td className="py-2 pr-4 text-xs font-medium text-slate-500">
                    <Link
                      href={`/licitaciones/${lic.id}`}
                      className="hover:underline"
                    >
                      {lic.expediente}
                    </Link>
                  </td>
                  <td className="max-w-xs py-2 pr-4 text-sm font-medium text-slate-900">
                    <Link
                      href={`/licitaciones/${lic.id}`}
                      className="hover:underline"
                    >
                      {lic.nombre}
                    </Link>
                  </td>
                  <td className="py-2 pr-4">
                    <Badge variant={getEstadoVariant(lic.estado)}>
                      {lic.estado}
                    </Badge>
                  </td>
                  <td className="py-2 pr-4 text-right text-sm font-semibold text-slate-900">
                    {formatEuro(lic.presupuesto)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>

      <p className="text-xs text-slate-400">
        Más adelante, este listado podrá conectarse a{" "}
        <code className="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-700">
          GET http://localhost:8000/licitaciones
        </code>{" "}
        o al endpoint que definas en FastAPI.
      </p>
    </div>
  );
}

