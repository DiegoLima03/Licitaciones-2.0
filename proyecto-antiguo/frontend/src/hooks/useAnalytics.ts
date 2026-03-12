"use client";

import { useQuery } from "@tanstack/react-query";
import { useEffect, useRef, useState } from "react";
import { AnalyticsService } from "@/services/analytics.api";

const ANALYTICS_KEYS = {
  materialTrends: (name: string) => ["analytics", "material-trends", name] as const,
  riskPipeline: () => ["analytics", "risk-pipeline"] as const,
  sweetSpots: () => ["analytics", "sweet-spots"] as const,
  priceDeviation: (material: string, price: number) =>
    ["analytics", "price-deviation", material, price] as const,
};

export function useMaterialTrends(materialName: string) {
  return useQuery({
    queryKey: ANALYTICS_KEYS.materialTrends(materialName),
    queryFn: () => AnalyticsService.getMaterialTrends(materialName),
    enabled: Boolean(materialName?.trim()),
  });
}

export function useRiskPipeline() {
  return useQuery({
    queryKey: ANALYTICS_KEYS.riskPipeline(),
    queryFn: () => AnalyticsService.getRiskAdjustedPipeline(),
  });
}

export function useSweetSpots() {
  return useQuery({
    queryKey: ANALYTICS_KEYS.sweetSpots(),
    queryFn: () => AnalyticsService.getSweetSpots(),
  });
}

const DEBOUNCE_MS = 400;

export function usePriceDeviation(materialName: string, currentPrice: number) {
  const [debouncedPrice, setDebouncedPrice] = useState(currentPrice);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = setTimeout(() => {
      setDebouncedPrice(currentPrice);
      timerRef.current = null;
    }, DEBOUNCE_MS);
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, [currentPrice]);

  return useQuery({
    queryKey: ANALYTICS_KEYS.priceDeviation(materialName, debouncedPrice),
    queryFn: () => AnalyticsService.getPriceDeviationCheck(materialName, debouncedPrice),
    enabled:
      Boolean(materialName?.trim()) &&
      Number.isFinite(debouncedPrice) &&
      debouncedPrice >= 0,
  });
}
