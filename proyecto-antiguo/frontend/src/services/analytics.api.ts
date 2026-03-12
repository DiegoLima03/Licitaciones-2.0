/**
 * Servicios de API para endpoints de analítica avanzada.
 * GET /api/analytics/...
 */

import { apiClient } from "@/lib/axios";
import type {
  MaterialTrendPoint,
  MaterialTrendResponse,
  PriceDeviationResult,
  RiskPipelineItem,
  SweetSpotItem,
} from "@/types/api";

const BASE = "/analytics";

export const AnalyticsService = {
  async getMaterialTrends(materialName: string): Promise<MaterialTrendResponse> {
    const { data } = await apiClient.get<MaterialTrendResponse>(
      `${BASE}/material-trends/${encodeURIComponent(materialName)}`
    );
    return data ?? { pvu: [], pcu: [] };
  },

  async getRiskAdjustedPipeline(): Promise<RiskPipelineItem[]> {
    const { data } = await apiClient.get<RiskPipelineItem[]>(`${BASE}/risk-adjusted-pipeline`);
    return data ?? [];
  },

  async getSweetSpots(): Promise<SweetSpotItem[]> {
    const { data } = await apiClient.get<SweetSpotItem[]>(`${BASE}/sweet-spots`);
    return data ?? [];
  },

  async getPriceDeviationCheck(
    materialName: string,
    currentPrice: number
  ): Promise<PriceDeviationResult> {
    const { data } = await apiClient.get<PriceDeviationResult>(`${BASE}/price-deviation-check`, {
      params: { material_name: materialName, current_price: currentPrice },
    });
    if (!data) throw new Error("Sin respuesta de price-deviation-check");
    return data;
  },
};
