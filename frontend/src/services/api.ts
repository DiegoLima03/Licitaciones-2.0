/**
 * Servicios de API (FastAPI backend).
 * Exporta AuthService, TendersService, ImportService, SearchService, DeliveriesService.
 */

import { apiClient } from "@/lib/axios";
import type {
  DashboardKPIs,
  DeliveryCreate,
  DeliveryCreateResponse,
  EntregaWithLines,
  Estado,
  ExcelImportResponse,
  LoginResponse,
  PartidaCreate,
  PrecioReferencia,
  PrecioReferenciaCreate,
  SearchResult,
  Tender,
  TenderCreate,
  TenderDetail,
  TenderListFilters,
  TenderPartida,
  TenderUpdate,
  Tipo,
} from "@/types/api";

function getMessageFromError(error: unknown): string {
  if (error && typeof error === "object" && "response" in error) {
    const res = (error as { response?: { data?: { detail?: string | string[] } } }).response;
    const detail = res?.data?.detail;
    if (typeof detail === "string") return detail;
    if (Array.isArray(detail)) return detail.join(", ");
    if (detail) return String(detail);
  }
  if (error instanceof Error) return error.message;
  return "Error de conexión con el servidor.";
}

// ----- AuthService -----

export const AuthService = {
  async login(email: string, password: string): Promise<LoginResponse> {
    try {
      const { data } = await apiClient.post<LoginResponse>("/auth/login", {
        email,
        password,
      });
      return data;
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },
};

// ----- EstadosService -----

export const EstadosService = {
  async getAll(): Promise<Estado[]> {
    try {
      const { data } = await apiClient.get<Estado[]>("/estados");
      return data ?? [];
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },
};

// ----- TiposService -----

export const TiposService = {
  async getAll(): Promise<Tipo[]> {
    try {
      const { data } = await apiClient.get<Tipo[]>("/tipos");
      return data ?? [];
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },
};

// ----- PreciosReferenciaService -----

export const PreciosReferenciaService = {
  async getAll(): Promise<PrecioReferencia[]> {
    try {
      const { data } = await apiClient.get<PrecioReferencia[]>("/precios-referencia");
      return data ?? [];
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },

  async create(payload: PrecioReferenciaCreate): Promise<PrecioReferencia> {
    try {
      const { data } = await apiClient.post<PrecioReferencia>("/precios-referencia", payload);
      if (!data) throw new Error("No se devolvió la línea creada.");
      return data;
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },
};

// ----- TendersService -----

export const TendersService = {
  async getAll(filters?: TenderListFilters): Promise<Tender[]> {
    try {
      const params = new URLSearchParams();
      if (filters?.estado_id != null) params.set("estado_id", String(filters.estado_id));
      if (filters?.nombre) params.set("nombre", filters.nombre);
      const { data } = await apiClient.get<Tender[]>("/tenders", { params });
      return data ?? [];
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },

  async getById(id: number): Promise<TenderDetail> {
    try {
      const { data } = await apiClient.get<TenderDetail>(`/tenders/${id}`);
      if (!data) throw new Error("Licitación no encontrada.");
      return data;
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },

  async create(payload: TenderCreate): Promise<Tender> {
    try {
      const { data } = await apiClient.post<Tender>("/tenders", payload);
      if (!data) throw new Error("No se devolvió la licitación creada.");
      return data;
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },

  async update(id: number, payload: TenderUpdate): Promise<Tender> {
    try {
      const { data } = await apiClient.put<Tender>(`/tenders/${id}`, payload);
      if (!data) throw new Error("Licitación no encontrada.");
      return data;
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },

  async delete(id: number): Promise<void> {
    try {
      await apiClient.delete(`/tenders/${id}`);
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },

  async addPartida(tenderId: number, payload: PartidaCreate): Promise<TenderPartida> {
    try {
      const { data } = await apiClient.post<TenderPartida>(
        `/tenders/${tenderId}/partidas`,
        payload
      );
      if (!data) throw new Error("No se devolvió la partida creada.");
      return data;
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },
};

// ----- ImportService -----

export const ImportService = {
  async uploadExcel(
    licitacionId: number,
    file: File,
    tipoId: number = 1
  ): Promise<ExcelImportResponse> {
    try {
      const formData = new FormData();
      formData.append("file", file);
      const { data } = await apiClient.post<ExcelImportResponse>(
        `/import/excel/${licitacionId}`,
        formData,
        {
          headers: {
            "Content-Type": "multipart/form-data",
          },
          params: { tipo_id: tipoId },
        }
      );
      if (!data) throw new Error("No se recibió respuesta del servidor.");
      return data;
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },
};

// ----- SearchService -----

export const SearchService = {
  async search(query: string): Promise<SearchResult[]> {
    try {
      if (!query.trim()) return [];
      const { data } = await apiClient.get<SearchResult[]>("/search", {
        params: { q: query.trim() },
      });
      return data ?? [];
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },
};

// ----- DeliveriesService -----

export const DeliveriesService = {
  async getByLicitacion(licitacionId: number): Promise<EntregaWithLines[]> {
    try {
      const { data } = await apiClient.get<EntregaWithLines[]>("/deliveries", {
        params: { licitacion_id: licitacionId },
      });
      return data ?? [];
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },

  async create(payload: DeliveryCreate): Promise<DeliveryCreateResponse> {
    try {
      const { data } = await apiClient.post<DeliveryCreateResponse>("/deliveries", payload);
      if (!data) throw new Error("No se devolvió la entrega creada.");
      return data;
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },

  async delete(id: number): Promise<void> {
    try {
      await apiClient.delete(`/deliveries/${id}`);
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },
};

// ----- AnalyticsService -----

export interface DashboardKpisFilters {
  fecha_adjudicacion_desde?: string;
  fecha_adjudicacion_hasta?: string;
}

export const AnalyticsService = {
  async getKpis(filters?: DashboardKpisFilters): Promise<DashboardKPIs> {
    try {
      const params: Record<string, string> = {};
      if (filters?.fecha_adjudicacion_desde)
        params.fecha_adjudicacion_desde = filters.fecha_adjudicacion_desde;
      if (filters?.fecha_adjudicacion_hasta)
        params.fecha_adjudicacion_hasta = filters.fecha_adjudicacion_hasta;
      const { data } = await apiClient.get<DashboardKPIs>("/analytics/kpis", { params });
      if (!data) throw new Error("No se devolvieron los KPIs.");
      return data;
    } catch (error) {
      throw new Error(getMessageFromError(error));
    }
  },
};
