-- Columnas que usa el popup "Nueva licitación" y el listado en tbl_licitaciones.
-- Ejecuta en MySQL (phpMyAdmin) sobre tu base de datos. Si alguna columna ya existe,
-- comenta o borra el ALTER correspondiente para evitar error "Duplicate column".
--
-- Resumen de columnas que debe tener tbl_licitaciones:
--   id_licitacion (PK, AUTO_INCREMENT)
--   nombre, pais, numero_expediente, pres_maximo
--   descripcion, enlace_gober, enlace_sharepoint
--   id_tipolicitacion (FK a tbl_tipolicitacion), id_estado (FK a tbl_estados)
--   fecha_presentacion, fecha_adjudicacion, fecha_finalizacion
--   tipo_procedimiento, id_licitacion_padre
--
-- A continuación solo las que suelen faltar al migrar:

-- Notas / descripción de la licitación
ALTER TABLE tbl_licitaciones
  ADD COLUMN descripcion TEXT NULL;

-- Enlaces (Gober y SharePoint)
ALTER TABLE tbl_licitaciones
  ADD COLUMN enlace_gober VARCHAR(500) NULL,
  ADD COLUMN enlace_sharepoint VARCHAR(500) NULL;

-- Tipo de licitación (FK tbl_tipolicitacion). Si la tabla tbl_tipolicitacion no existe, créala antes.
ALTER TABLE tbl_licitaciones
  ADD COLUMN id_tipolicitacion INT NULL DEFAULT NULL;
