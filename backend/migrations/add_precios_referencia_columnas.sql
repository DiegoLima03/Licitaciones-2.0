-- Añadir columnas producto, proveedor y notas a tbl_precios_referencia.
-- El código las usa para listar y crear líneas de referencia.
-- Ejecutar en Supabase SQL Editor.

ALTER TABLE tbl_precios_referencia
ADD COLUMN IF NOT EXISTS producto TEXT;

ALTER TABLE tbl_precios_referencia
ADD COLUMN IF NOT EXISTS proveedor TEXT;

ALTER TABLE tbl_precios_referencia
ADD COLUMN IF NOT EXISTS notas TEXT;
