-- Enlace a Gober: startup que scrapea licitaciones públicas.
-- Permite vincular la licitación con su ficha en Gober.
ALTER TABLE public.tbl_licitaciones
ADD COLUMN IF NOT EXISTS enlace_gober TEXT;

COMMENT ON COLUMN public.tbl_licitaciones.enlace_gober IS 'URL de la licitación en Gober (plataforma de scraping de licitaciones públicas)';
