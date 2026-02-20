-- =============================================================================
-- Migración 006: Campos libres en presupuesto, adjuntos, desacoplamiento
-- financiero, hitos de entrega y desviación de costes.
-- No destructiva: solo ALTER TABLE y CREATE TABLE.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) Campos libres en líneas de presupuesto (tbl_licitaciones_detalle)
--    id_producto ya es nullable; añadimos nombre_producto_libre para partidas
--    sin producto ERP. Validación estricta ERP solo a nivel lógico si estado
--    pasa a Adjudicada.
-- -----------------------------------------------------------------------------
ALTER TABLE public.tbl_licitaciones_detalle
  ADD COLUMN IF NOT EXISTS nombre_producto_libre text;

COMMENT ON COLUMN public.tbl_licitaciones_detalle.nombre_producto_libre IS 'Nombre libre del producto cuando id_producto es NULL (partida no vinculada al ERP Belneo).';
COMMENT ON COLUMN public.tbl_licitaciones_detalle.id_producto IS 'ID producto en tbl_productos (ERP Belneo). Opcional; si NULL, usar nombre_producto_libre.';

-- -----------------------------------------------------------------------------
-- 2) Sistema de archivos: adjuntos a licitación (pliegos, facturas)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.tender_attachments (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tender_id integer NOT NULL REFERENCES public.tbl_licitaciones(id_licitacion) ON DELETE CASCADE,
  organization_id uuid NOT NULL,
  file_path text NOT NULL,
  file_type text,
  uploaded_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_tender_attachments_tender_id ON public.tender_attachments(tender_id);
CREATE INDEX IF NOT EXISTS idx_tender_attachments_organization_id ON public.tender_attachments(organization_id);

COMMENT ON TABLE public.tender_attachments IS 'Documentos adjuntos a la licitación (pliegos, facturas). file_path ruta local/storage.';

-- RLS
ALTER TABLE public.tender_attachments ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Org isolation tender_attachments" ON public.tender_attachments;
CREATE POLICY "Org isolation tender_attachments"
  ON public.tender_attachments FOR ALL TO public
  USING (organization_id = current_user_org_id())
  WITH CHECK (organization_id = current_user_org_id());

DROP POLICY IF EXISTS "Service role tender_attachments" ON public.tender_attachments;
CREATE POLICY "Service role tender_attachments"
  ON public.tender_attachments FOR ALL TO public
  USING ((auth.jwt() ->> 'role') = 'service_role');

-- -----------------------------------------------------------------------------
-- 3) Desacoplamiento financiero: flags en tbl_licitaciones
-- -----------------------------------------------------------------------------
ALTER TABLE public.tbl_licitaciones
  ADD COLUMN IF NOT EXISTS is_delivered boolean NOT NULL DEFAULT false,
  ADD COLUMN IF NOT EXISTS is_invoiced boolean NOT NULL DEFAULT false,
  ADD COLUMN IF NOT EXISTS is_collected boolean NOT NULL DEFAULT false;

COMMENT ON COLUMN public.tbl_licitaciones.is_delivered IS 'Indica si la licitación está entregada.';
COMMENT ON COLUMN public.tbl_licitaciones.is_invoiced IS 'Indica si está facturada.';
COMMENT ON COLUMN public.tbl_licitaciones.is_collected IS 'Indica si está cobrada.';

-- -----------------------------------------------------------------------------
-- 4) Hitos de entrega programados (1:N con licitación)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.scheduled_deliveries (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tender_id integer NOT NULL REFERENCES public.tbl_licitaciones(id_licitacion) ON DELETE CASCADE,
  organization_id uuid NOT NULL,
  delivery_date date NOT NULL,
  status text,
  description text,
  items_json jsonb
);

CREATE INDEX IF NOT EXISTS idx_scheduled_deliveries_tender_id ON public.scheduled_deliveries(tender_id);
CREATE INDEX IF NOT EXISTS idx_scheduled_deliveries_organization_id ON public.scheduled_deliveries(organization_id);

COMMENT ON TABLE public.scheduled_deliveries IS 'Hitos de entrega programados por licitación. items_json opcional para detalle de partidas.';

-- RLS
ALTER TABLE public.scheduled_deliveries ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Org isolation scheduled_deliveries" ON public.scheduled_deliveries;
CREATE POLICY "Org isolation scheduled_deliveries"
  ON public.scheduled_deliveries FOR ALL TO public
  USING (organization_id = current_user_org_id())
  WITH CHECK (organization_id = current_user_org_id());

DROP POLICY IF EXISTS "Service role scheduled_deliveries" ON public.scheduled_deliveries;
CREATE POLICY "Service role scheduled_deliveries"
  ON public.scheduled_deliveries FOR ALL TO public
  USING ((auth.jwt() ->> 'role') = 'service_role');

-- -----------------------------------------------------------------------------
-- 5) Desviación de costes: coste presupuestado, real y gastos extraordinarios
-- -----------------------------------------------------------------------------
ALTER TABLE public.tbl_licitaciones
  ADD COLUMN IF NOT EXISTS coste_presupuestado numeric,
  ADD COLUMN IF NOT EXISTS coste_real numeric,
  ADD COLUMN IF NOT EXISTS gastos_extraordinarios numeric;

COMMENT ON COLUMN public.tbl_licitaciones.coste_presupuestado IS 'Coste total presupuestado (€).';
COMMENT ON COLUMN public.tbl_licitaciones.coste_real IS 'Coste real imputado (€).';
COMMENT ON COLUMN public.tbl_licitaciones.gastos_extraordinarios IS 'Gastos extraordinarios (grúas, transportes imprevistos, etc.) en €.';
