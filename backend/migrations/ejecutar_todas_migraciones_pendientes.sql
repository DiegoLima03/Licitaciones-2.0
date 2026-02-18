-- Ejecuta este SQL en Supabase → SQL Editor si tienes errores al crear licitaciones o generar lotes.
-- Añade las columnas: enlace_gober, lotes_config

-- 1. Enlace Gober (URL de la licitación en gober.es)
ALTER TABLE public.tbl_licitaciones
ADD COLUMN IF NOT EXISTS enlace_gober TEXT;

COMMENT ON COLUMN public.tbl_licitaciones.enlace_gober IS 'URL de la licitación en Gober (plataforma de scraping)';

-- 2. Configuración de lotes (lista de lotes y cuáles se adjudicaron)
ALTER TABLE public.tbl_licitaciones
ADD COLUMN IF NOT EXISTS lotes_config JSONB DEFAULT '[]'::jsonb;

COMMENT ON COLUMN public.tbl_licitaciones.lotes_config IS 'Lista de lotes con estado ganado: [{"nombre":"Lote 1","ganado":false}, ...]';
