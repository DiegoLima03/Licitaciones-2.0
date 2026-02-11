/**
 * Tipos de la API (equivalentes a backend/models.py y backend/schemas.py).
 * Mantener alineados con los modelos Pydantic del backend.
 */

// ----- Auth -----

export interface User {
  id?: number | null;
  email: string;
  rol?: string | null;
  nombre?: string | null;
}

/** Respuesta de POST /auth/login (backend devuelve User; token opcional si en el futuro se usa JWT). */
export type LoginResponse = User;

// ----- Estados (tbl_estados) -----

export interface Estado {
  id_estado: number;
  nombre_estado: string;
}

// ----- Tipos de licitación (tbl_tipolicitacion) -----

export interface Tipo {
  id_tipolicitacion: number;
  tipo: string;
}

// ----- Licitaciones (tbl_licitaciones) -----

export interface Tender {
  id_licitacion: number;
  nombre: string;
  numero_expediente?: string | null;
  pres_maximo?: number | null;
  descripcion?: string | null;
  id_estado: number;
  tipo_de_licitacion?: number | null;
  fecha_presentacion?: string | null;
  fecha_adjudicacion?: string | null;
  fecha_finalizacion?: string | null;
  descuento_global?: number | null;
  [key: string]: unknown;
}

export interface TenderCreate {
  nombre: string;
  numero_expediente?: string | null;
  pres_maximo?: number | null;
  descripcion?: string | null;
  id_estado: number;
  tipo_de_licitacion?: number | null;
  fecha_presentacion?: string | null;
  fecha_adjudicacion?: string | null;
  fecha_finalizacion?: string | null;
}

export interface TenderUpdate {
  nombre?: string | null;
  numero_expediente?: string | null;
  pres_maximo?: number | null;
  descripcion?: string | null;
  id_estado?: number | null;
  tipo_de_licitacion?: number | null;
  fecha_presentacion?: string | null;
  fecha_adjudicacion?: string | null;
  fecha_finalizacion?: string | null;
  descuento_global?: number | null;
}

// Partida de presupuesto (tbl_licitaciones_detalle)
export interface TenderPartida {
  id_detalle: number;
  id_licitacion: number;
  lote?: string | null;
  producto: string;
  unidades?: number | null;
  pvu?: number | null;
  pcu?: number | null;
  pmaxu?: number | null;
  activo?: boolean | null;
  [key: string]: unknown;
}

/** Detalle de licitación con partidas (GET /tenders/{id}). */
export interface TenderDetail extends Tender {
  partidas: TenderPartida[];
}

/** Payload para añadir una partida manual (POST /tenders/{id}/partidas). */
export interface PartidaCreate {
  lote?: string | null;
  producto: string;
  unidades?: number | null;
  pvu?: number | null;
  pcu?: number | null;
  pmaxu?: number | null;
  activo?: boolean | null;
}

// ----- Entregas (tbl_entregas + tbl_licitaciones_real) -----

export interface DeliveryHeaderCreate {
  fecha: string;
  codigo_albaran: string;
  observaciones?: string | null;
  cliente?: string | null;
}

export interface DeliveryItem {
  concepto_partida: string;
  proveedor?: string | null;
  cantidad: number;
  coste_unit: number;
}

export interface DeliveryCreate {
  id_licitacion: number;
  cabecera: DeliveryHeaderCreate;
  lineas: DeliveryItem[];
}

export interface DeliveryCreateResponse {
  id_entrega: number;
  message: string;
  lines_count: number;
}

/** Línea de una entrega (tbl_licitaciones_real). */
export interface EntregaLinea {
  id_real?: number;
  id_detalle?: number | null;
  articulo?: string;
  proveedor?: string | null;
  cantidad: number;
  pcu: number;
  estado?: string | null;
  cobrado?: boolean | null;
  [key: string]: unknown;
}

/** Entrega con sus líneas (GET /deliveries?licitacion_id=X). */
export interface EntregaWithLines {
  id_entrega: number;
  id_licitacion: number;
  fecha_entrega: string;
  codigo_albaran: string;
  observaciones?: string | null;
  lineas: EntregaLinea[];
  [key: string]: unknown;
}

// ----- Importación Excel -----

export interface ExcelImportResponse {
  message: string;
  licitacion_id: number;
  rows_imported: number;
}

// ----- Precios de referencia (tbl_precios_referencia) -----

export interface PrecioReferencia {
  id: string;
  producto: string;
  pvu?: number | null;
  pcu?: number | null;
  unidades?: number | null;
  proveedor?: string | null;
  notas?: string | null;
  fecha_creacion?: string | null;
  creado_por?: string | null;
}

export interface PrecioReferenciaCreate {
  producto: string;
  pvu?: number | null;
  pcu?: number | null;
  unidades?: number | null;
  proveedor?: string | null;
  notas?: string | null;
}

// ----- Buscador -----

export interface SearchResult {
  producto: string;
  pvu?: number | null;
  pcu?: number | null;
  unidades?: number | null;
  licitacion_nombre?: string | null;
  numero_expediente?: string | null;
  proveedor?: string | null;
}

// ----- Filtros (query params) -----

export interface TenderListFilters {
  estado_id?: number;
  nombre?: string;
}

// ----- Dashboard / Analytics -----

export interface TimelineItem {
  id_licitacion: number;
  nombre: string;
  fecha_adjudicacion?: string | null;
  fecha_finalizacion?: string | null;
  estado_nombre?: string | null;
  pres_maximo?: number | null;
}

export interface DashboardKPIs {
  timeline: TimelineItem[];
  total_oportunidades_uds: number;
  total_oportunidades_euros: number;
  total_ofertado_uds: number;
  total_ofertado_euros: number;
  ratio_ofertado_oportunidades_uds: number;
  ratio_ofertado_oportunidades_euros: number;
  ratio_adjudicadas_terminadas_ofertado: number;
  margen_medio_ponderado_presupuestado?: number | null;
  margen_medio_ponderado_real?: number | null;
  pct_descartadas_uds?: number | null;
  pct_descartadas_euros?: number | null;
  ratio_adjudicacion: number;
}
