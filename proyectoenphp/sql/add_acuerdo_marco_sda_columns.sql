-- Añade columnas necesarias para Acuerdos Marco y SDA (licitaciones padre/hijo).
-- Ejecutar en MySQL (phpMyAdmin o línea de comandos) sobre la base de datos del proyecto.
-- Si alguna columna ya existe, comentar o eliminar la línea correspondiente.

-- Licitación padre: AM o SDA del que deriva esta licitación (NULL = licitación raíz).
ALTER TABLE tbl_licitaciones
  ADD COLUMN id_licitacion_padre INT NULL DEFAULT NULL,
  ADD INDEX idx_licitacion_padre (id_licitacion_padre);

-- Tipo de procedimiento: ORDINARIO, ACUERDO_MARCO, SDA, CONTRATO_BASADO
ALTER TABLE tbl_licitaciones
  ADD COLUMN tipo_procedimiento VARCHAR(50) NULL DEFAULT 'ORDINARIO';
