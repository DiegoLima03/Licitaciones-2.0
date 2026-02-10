"use client";

import * as React from "react";
import Link from "next/link";
import { ArrowLeft, Search as SearchIcon } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

type ResultadoHistorico = {
  id: number;
  producto: string;
  licitacion: string;
  expediente: string;
  unidades: number;
  pcu: number;
  pvu: number;
  fecha: string;
};

const MOCK_RESULTS: ResultadoHistorico[] = [
  {
    id: 1,
    producto: "Planta arbustiva ornamental",
    licitacion: "Mantenimiento Zonas Verdes Distrito Norte",
    expediente: "EXP-23-041",
    unidades: 2_500,
    pcu: 3.2,
    pvu: 5.6,
    fecha: "2023-03-10",
  },
  {
    id: 2,
    producto: "Tierra vegetal cribada",
    licitacion: "Regeneración Parque Fluvial",
    expediente: "EXP-22-118",
    unidades: 1_200,
    pcu: 14.5,
    pvu: 21.9,
    fecha: "2022-11-25",
  },
  {
    id: 3,
    producto: "Tubería PVC presión DN80",
    licitacion: "Renovación Red de Riego Campos Deportivos",
    expediente: "EXP-24-007",
    unidades: 3_400,
    pcu: 4.1,
    pvu: 6.9,
    fecha: "2024-02-15",
  },
  {
    id: 4,
    producto: "Césped en tepe de alta resistencia",
    licitacion: "Mejora Zonas Deportivas Municipales",
    expediente: "EXP-23-089",
    unidades: 5_800,
    pcu: 6.7,
    pvu: 10.5,
    fecha: "2023-09-02",
  },
  {
    id: 5,
    producto: "Farola LED vial 60W",
    licitacion: "Sustitución Alumbrado Barrio Centro",
    expediente: "EXP-22-063",
    unidades: 420,
    pcu: 145.0,
    pvu: 198.0,
    fecha: "2022-06-18",
  },
  {
    id: 6,
    producto: "Hormigón HA-25 bombeado",
    licitacion: "Reurbanización Calle Mayor",
    expediente: "EXP-24-012",
    unidades: 780,
    pcu: 64.0,
    pvu: 92.0,
    fecha: "2024-01-30",
  },
  {
    id: 7,
    producto: "Borde jardinera prefabricado",
    licitacion: "Remodelación Plaza Central",
    expediente: "EXP-23-022",
    unidades: 1_100,
    pcu: 11.5,
    pvu: 17.9,
    fecha: "2023-02-11",
  },
  {
    id: 8,
    producto: "Planta tapizante aromática",
    licitacion: "Ajardinamiento Rotondas Acceso Ciudad",
    expediente: "EXP-21-097",
    unidades: 3_200,
    pcu: 2.3,
    pvu: 4.1,
    fecha: "2021-10-05",
  },
  {
    id: 9,
    producto: "Tubería PEAD drenante Ø160",
    licitacion: "Obra Civil Colector Pluvial",
    expediente: "EXP-22-201",
    unidades: 1_050,
    pcu: 19.8,
    pvu: 28.4,
    fecha: "2022-12-01",
  },
  {
    id: 10,
    producto: "Programador riego 12 estaciones",
    licitacion: "Modernización Sistema de Riego Parques",
    expediente: "EXP-23-064",
    unidades: 65,
    pcu: 135.0,
    pvu: 189.0,
    fecha: "2023-05-20",
  },
  {
    id: 11,
    producto: "Planta de porte medio",
    licitacion: "Jardinería Accesos Parque Tecnológico",
    expediente: "EXP-24-031",
    unidades: 1_800,
    pcu: 4.4,
    pvu: 7.2,
    fecha: "2024-03-01",
  },
];

function formatEuro(value: number) {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 2,
  }).format(value);
}

function formatDate(date: string) {
  return new Date(date + "T00:00:00").toLocaleDateString("es-ES");
}

export default function BuscadorHistoricoPage() {
  const [query, setQuery] = React.useState("");

  const resultados =
    query.trim().length === 0
      ? []
      : MOCK_RESULTS.filter((item) =>
          item.producto.toLowerCase().includes(query.toLowerCase())
        );

  return (
    <div className="flex flex-1 flex-col gap-6">
      {/* Cabecera */}
      <header className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
            Buscador Histórico de Precios
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Consulta precios ofertados anteriormente por producto.
          </p>
        </div>
        <Link href="/">
          <Button variant="outline" className="gap-2">
            <ArrowLeft className="h-4 w-4" />
            Volver al Dashboard
          </Button>
        </Link>
      </header>

      {/* Barra de búsqueda */}
      <section className="mt-2">
        <div className="mx-auto flex max-w-3xl items-center">
          <div className="relative w-full">
            <SearchIcon className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <input
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Ej: Planta, Tierra, Tubería..."
              className="h-11 w-full rounded-full border border-slate-200 bg-white pl-9 pr-4 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
            />
          </div>
        </div>
      </section>

      {/* Resultados */}
      <section>
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm font-medium text-slate-800">
              Resultados
            </CardTitle>
          </CardHeader>
          <CardContent className="pt-0">
            {query.trim().length === 0 ? (
              <p className="py-6 text-sm text-slate-500">
                Introduce un término en la barra de búsqueda para consultar
                precios históricos de productos.
              </p>
            ) : resultados.length === 0 ? (
              <p className="py-6 text-sm text-slate-500">
                No se han encontrado resultados para{" "}
                <span className="font-medium text-slate-900">
                  &quot;{query}&quot;
                </span>
                .
              </p>
            ) : (
              <>
                <p className="mb-3 text-xs text-slate-500">
                  Mostrando{" "}
                  <span className="font-semibold text-slate-800">
                    {resultados.length}
                  </span>{" "}
                  coincidencias.
                </p>
                <div className="overflow-x-auto">
                  <table className="min-w-full text-left text-sm">
                    <thead>
                      <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <th className="py-2 pr-3">Producto</th>
                        <th className="py-2 pr-3">Licitación</th>
                        <th className="py-2 pr-3">Expediente</th>
                        <th className="py-2 pr-3 text-right">Unidades</th>
                        <th className="py-2 pr-3 text-right">PCU (Coste)</th>
                        <th className="py-2 pr-3 text-right text-emerald-700">
                          PVU (Venta)
                        </th>
                        <th className="py-2 pr-3">Fecha</th>
                      </tr>
                    </thead>
                    <tbody>
                      {resultados.map((item) => (
                        <tr
                          key={item.id}
                          className="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                        >
                          <td className="max-w-xs py-2 pr-3 text-sm font-medium text-slate-900">
                            {item.producto}
                          </td>
                          <td className="py-2 pr-3 text-sm text-slate-700">
                            {item.licitacion}
                          </td>
                          <td className="py-2 pr-3 text-xs">
                            <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-medium text-slate-600">
                              {item.expediente}
                            </span>
                          </td>
                          <td className="py-2 pr-3 text-right text-sm text-slate-900">
                            {item.unidades.toLocaleString("es-ES")}
                          </td>
                          <td className="py-2 pr-3 text-right text-sm text-slate-900">
                            {formatEuro(item.pcu)}
                          </td>
                          <td className="py-2 pr-3 text-right text-sm font-semibold text-emerald-700">
                            {formatEuro(item.pvu)}
                          </td>
                          <td className="py-2 pr-3 text-xs text-slate-600">
                            {formatDate(item.fecha)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </CardContent>
        </Card>
      </section>
    </div>
  );
}

