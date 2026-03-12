"use client";

import { useQuery } from "@tanstack/react-query";
import { AnalyticsService } from "@/services/api";

export function useProductAnalytics(productId: number) {
  return useQuery({
    queryKey: ["product-analytics", productId],
    queryFn: () => AnalyticsService.getProductAnalytics(productId),
    enabled: productId > 0,
  });
}
