-- Enlaces a Gober y SharePoint en tbl_licitaciones.
-- Ejecutar en MySQL si al cargar licitaciones sale "Unknown column 'enlace_gober'" o similar.

ALTER TABLE tbl_licitaciones
  ADD COLUMN enlace_gober VARCHAR(500) NULL,
  ADD COLUMN enlace_sharepoint VARCHAR(500) NULL;
