-- Unificar id_tipolicitacion y tipo_de_licitacion en tbl_licitaciones.
-- Ambas columnas almacenaban el mismo valor (FK a tbl_tipolicitacion); el código usaba tipo_de_licitacion.
-- Se unifica en id_tipolicitacion (nombre estándar para FK) y se elimina la redundante.
-- Ejecutar en Supabase SQL Editor.

-- 1. Copiar valores de tipo_de_licitacion a id_tipolicitacion donde falte
UPDATE tbl_licitaciones
SET id_tipolicitacion = tipo_de_licitacion::integer
WHERE tipo_de_licitacion IS NOT NULL
  AND (id_tipolicitacion IS NULL OR id_tipolicitacion != tipo_de_licitacion::integer);

-- 2. Eliminar FK de tipo_de_licitacion
ALTER TABLE tbl_licitaciones
DROP CONSTRAINT IF EXISTS tbl_licitaciones_tipo_de_licitacion_fkey;

-- 3. Eliminar columna redundante
ALTER TABLE tbl_licitaciones
DROP COLUMN IF EXISTS tipo_de_licitacion;
