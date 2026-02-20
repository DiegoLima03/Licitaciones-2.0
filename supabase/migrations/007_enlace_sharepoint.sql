-- =============================================================================
-- Migración 007: Enlace SharePoint en licitaciones
-- =============================================================================

ALTER TABLE public.tbl_licitaciones
  ADD COLUMN IF NOT EXISTS enlace_sharepoint text;

COMMENT ON COLUMN public.tbl_licitaciones.enlace_sharepoint IS 'URL del sitio/carpeta de SharePoint con la documentación e información de la licitación.';
