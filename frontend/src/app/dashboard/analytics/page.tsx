"use client";

import * as React from "react";
import Link from "next/link";
import { ArrowLeft, TrendingUp } from "lucide-react";

import { MaterialTrendChart } from "@/components/analytics/MaterialTrendChart";
import { RiskPipelineChart } from "@/components/analytics/RiskPipelineChart";
import { SweetSpotScatter } from "@/components/analytics/SweetSpotScatter";
import { PriceDeviationAlert } from "@/components/analytics/PriceDeviationAlert";
import { ProductAutocompleteInput } from "@/components/producto-autocomplete-input";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  useMaterialTrends,
  usePriceDeviation,
  useRiskPipeline,
  useSweetSpots,
} from "@/hooks/useAnalytics";

export default function DashboardAnalyticsPage() {
  const [selectedProductTrend, setSelectedProductTrend] = React.useState<{
    id: number;
    nombre: string;
  } | null>(null);
  const [selectedProductDeviation, setSelectedProductDeviation] = React.useState<{
    id: number;
    nombre: string;
  } | null>(null);
  const [deviationPrice, setDeviationPrice] = React.useState<string>("");

  const materialName = selectedProductTrend?.nombre ?? "";
  const deviationMaterial = selectedProductDeviation?.nombre ?? "";

  const materialTrends = useMaterialTrends(materialName);
  const riskPipeline = useRiskPipeline();
  const sweetSpots = useSweetSpots();
  const priceDeviation = usePriceDeviation(
    deviationMaterial,
    parseFloat(deviationPrice) || 0
  );

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <Link href="/">
            <Button variant="outline" size="sm" className="gap-2">
              <ArrowLeft className="h-4 w-4" />
              Dashboard
            </Button>
          </Link>
          <div className="flex items-center gap-2">
            <TrendingUp className="h-6 w-6 text-emerald-600" />
            <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-100">
              Analítica avanzada
            </h1>
          </div>
        </div>
      </header>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="overflow-hidden border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-slate-800 dark:text-slate-200">
              Tendencia de precios (material)
            </CardTitle>
          </CardHeader>
          <CardContent className="pt-0">
            <div className="mb-3 max-w-xs">
              <ProductAutocompleteInput
                value={selectedProductTrend}
                onSelect={(id, nombre) => setSelectedProductTrend({ id, nombre })}
                placeholder="Escribe para buscar producto…"
              />
            </div>
            <MaterialTrendChart
              data={materialTrends.data ?? []}
              materialName={materialName}
              isLoading={materialTrends.isLoading}
              error={materialTrends.error}
            />
          </CardContent>
        </Card>

        <Card className="overflow-hidden border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-slate-800 dark:text-slate-200">
              Pipeline ajustado por riesgo
            </CardTitle>
          </CardHeader>
          <CardContent>
            <RiskPipelineChart
              data={riskPipeline.data ?? []}
              isLoading={riskPipeline.isLoading}
              error={riskPipeline.error}
            />
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="overflow-hidden border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-slate-800 dark:text-slate-200">
              Sweet spots (Adjudicadas vs Perdidas)
            </CardTitle>
          </CardHeader>
          <CardContent>
            <SweetSpotScatter
              data={sweetSpots.data ?? []}
              isLoading={sweetSpots.isLoading}
              error={sweetSpots.error}
            />
          </CardContent>
        </Card>

        <Card className="overflow-hidden border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-slate-800 dark:text-slate-200">
              Comprobación de desviación de precio
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex flex-wrap items-end gap-2">
              <div className="min-w-[200px]">
                <ProductAutocompleteInput
                  value={selectedProductDeviation}
                  onSelect={(id, nombre) => setSelectedProductDeviation({ id, nombre })}
                  placeholder="Escribe para buscar producto…"
                />
              </div>
              <Input
                type="number"
                min={0}
                step={0.01}
                placeholder="Precio actual (€)"
                value={deviationPrice}
                onChange={(e) => setDeviationPrice(e.target.value)}
                className="max-w-[140px] bg-white dark:bg-slate-800"
              />
            </div>
            <PriceDeviationAlert query={priceDeviation} />
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
