/**
 * Tipos de la API (equivalentes a backend/models.py y backend/schemas.py).
 * Mantener alineados con los modelos Pydantic del backend.
 *
 * Tipos generados: Ejecutar `npm run generate-types` (backend en 127.0.0.1:8000)
 * para generar src/generated/api.d.ts desde OpenAPI. Los tipos que coincidan
 * con el esquema pueden sustituirse progresivamente por los generados para evitar duplicados.
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

export type PaisLicitacion = "España" | "Portugal";

/** Tipo de procedimiento (Acuerdos Marco / SDA / jerarquía padre-hijo). */
export type TipoProcedimiento =
  | "ORDINARIO"
  | "ACUERDO_MARCO"
  | "SDA"
  | "CONTRATO_BASADO";

export interface Tender {
  id_licitacion: number;
  nombre: string;
  pais?: PaisLicitacion | null;
  numero_expediente?: string | null;
  pres_maximo?: number | null;
  descripcion?: string | null;
  id_estado: number;
  id_tipolicitacion?: number | null;
  fecha_presentacion?: string | null;
  fecha_adjudicacion?: string | null;
  fecha_finalizacion?: string | null;
  descuento_global?: number | null;
  enlace_gober?: string | null;
  lotes_config?: LoteConfigItem[] | null;
  tipo_procedimiento?: TipoProcedimiento | null;
  id_licitacion_padre?: number | null;
  [key: string]: unknown;
}

export interface LoteConfigItem {
  nombre: string;
  ganado: boolean;
}

export interface TenderCreate {
  nombre: string;
  pais: PaisLicitacion;
  numero_expediente?: string | null;
  pres_maximo?: number | null;
  descripcion?: string | null;
  enlace_gober?: string | null;
  id_tipolicitacion?: number | null;
  fecha_presentacion?: string | null;
  fecha_adjudicacion?: string | null;
  fecha_finalizacion?: string | null;
  tipo_procedimiento?: TipoProcedimiento | null;
  id_licitacion_padre?: number | null;
}

export interface TenderUpdate {
  nombre?: string | null;
  pais?: PaisLicitacion | null;
  numero_expediente?: string | null;
  pres_maximo?: number | null;
  descripcion?: string | null;
  enlace_gober?: string | null;
  id_estado?: number | null;
  id_tipolicitacion?: number | null;
  fecha_presentacion?: string | null;
  fecha_adjudicacion?: string | null;
  fecha_finalizacion?: string | null;
  descuento_global?: number | null;
  lotes_config?: LoteConfigItem[] | null;
  tipo_procedimiento?: TipoProcedimiento | null;
  id_licitacion_padre?: number | null;
}

/** Payload para POST /tenders/{id}/change-status (máquina de estados) */
export interface TenderStatusChange {
  nuevo_estado_id: number;
  motivo_descarte?: string | null;
  motivo_perdida?: string | null;
  competidor_ganador?: string | null;
  importe_adjudicacion?: number | null;
  fecha_adjudicacion?: string | null; // YYYY-MM-DD
}

// ----- Productos (tbl_productos) -----

export interface ProductoSearchResult {
  id: number;
  nombre: string;
  nombre_proveedor?: string | null;
}

// Partida de presupuesto (tbl_licitaciones_detalle)
export interface TenderPartida {
  id_detalle: number;
  id_licitacion: number;
  id_producto: number;
  product_nombre?: string | null;
  nombre_proveedor?: string | null;
  lote?: string | null;
  unidades?: number | null;
  pvu?: number | null;
  pcu?: number | null;
  pmaxu?: number | null;
  activo?: boolean | null;
  [key: string]: unknown;
}

/** Datos mínimos del expediente padre (solo en detalle de contrato derivado). */
export interface LicitacionPadre {
  id_licitacion: number;
  nombre?: string | null;
  numero_expediente?: string | null;
}

/** Detalle de licitación con partidas (GET /tenders/{id}). Para AM/SDA incluye contratos_derivados. */
export interface TenderDetail extends Tender {
  partidas: TenderPartida[];
  /** Licitaciones hijo (CONTRATO_BASADO) cuando esta licitación es AM o SDA. */
  contratos_derivados?: Tender[];
  /** Padre AM/SDA cuando esta licitación es contrato derivado (acceso desde el padre). */
  licitacion_padre?: LicitacionPadre | null;
}

/** Payload para añadir una partida manual (POST /tenders/{id}/partidas). */
export interface PartidaCreate {
  lote?: string | null;
  id_producto: number;
  unidades?: number | null;
  pvu?: number | null;
  pcu?: number | null;
  pmaxu?: number | null;
  activo?: boolean | null;
}

/** Payload para actualizar una partida (PUT /tenders/{id}/partidas/{detalle_id}). */
export interface PartidaUpdate {
  lote?: string | null;
  id_producto?: number | null;
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

export interface TipoGasto {
  id: number;
  codigo: string;
  nombre: string;
}

export interface DeliveryItem {
  /** Null si la línea es gasto extraordinario (id_tipo_gasto en su lugar). */
  id_producto?: number | null;
  /** Si es null = gasto extraordinario (no presupuestado). */
  id_detalle?: number | null;
  /** Si no null, línea es gasto extraordinario (concepto desde tbl_tipos_gasto). */
  id_tipo_gasto?: number | null;
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

/** Payload para actualizar estado/cobrado de una línea de entrega. */
export interface DeliveryLineUpdate {
  estado?: string | null;
  cobrado?: boolean | null;
}

/** Línea de una entrega (tbl_licitaciones_real). */
export interface EntregaLinea {
  id_real?: number;
  id_detalle?: number | null;
  id_producto?: number | null;
  product_nombre?: string | null;
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

export interface PreciosReferenciaImportResponse {
  message: string;
  rows_imported: number;
  rows_skipped: number;
  skipped_details?: { articulo: string; precio?: number }[];
}

// ----- Precios de referencia (tbl_precios_referencia) -----

export interface PrecioReferencia {
  id: string;
  id_producto: number;
  product_nombre?: string | null;
  pvu?: number | null;
  pcu?: number | null;
  unidades?: number | null;
  proveedor?: string | null;
  notas?: string | null;
  fecha_presupuesto?: string | null;
}

export interface PrecioReferenciaCreate {
  id_producto: number;
  pvu?: number | null;
  pcu?: number | null;
  unidades?: number | null;
  proveedor?: string | null;
  notas?: string | null;
  fecha_presupuesto?: string | null;
}

// ----- Buscador -----

export interface SearchResult {
  id_producto?: number | null;
  producto: string;
  pvu?: number | null;
  pcu?: number | null;
  unidades?: number | null;
  licitacion_nombre?: string | null;
  numero_expediente?: string | null;
  proveedor?: string | null;
}

// ----- Product Analytics (GET /analytics/product/{id}) -----

export interface PriceHistoryPoint {
  time: string;
  value: number;
  /** Unidades (suma ese día) para tooltip en gráfico de evolución. */
  unidades?: number | null;
}

export interface VolumeMetrics {
  total_licitado: number;
  cantidad_oferentes_promedio: number;
}

export interface CompetitorItem {
  empresa: string;
  precio_medio: number;
  cantidad_adjudicaciones: number;
}

export interface ProductAnalytics {
  product_id: number;
  product_name: string;
  price_history: PriceHistoryPoint[];
  price_history_pcu?: PriceHistoryPoint[];
  volume_metrics: VolumeMetrics;
  competitor_analysis: CompetitorItem[];
  forecast: number | null;
  precio_referencia_medio: number | null;
}

// ----- Filtros (query params) -----

export interface TenderListFilters {
  estado_id?: number;
  nombre?: string;
  pais?: PaisLicitacion;
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

// ----- Analytics avanzada -----

export interface MaterialTrendPoint {
  time: string;
  value: number;
  unidades?: number | null;
}

export interface MaterialTrendResponse {
  pvu: MaterialTrendPoint[];
  pcu: MaterialTrendPoint[];
}

export interface RiskPipelineItem {
  category: string;
  pipeline_bruto: number;
  pipeline_ajustado: number;
}

export interface SweetSpotItem {
  id: string;
  presupuesto: number;
  estado: string;
  cliente: string;
}

export interface PriceDeviationResult {
  is_deviated: boolean;
  deviation_percentage: number;
  historical_avg: number;
  recommendation: string;
}
