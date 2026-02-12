-- Precios de referencia: quitar fecha_creacion y creado_por; añadir fecha_presupuesto.
-- Útil para importación masiva (asignar fecha de presupuesto por lote).
-- Ejecutar en Supabase SQL Editor.

-- 1. Añadir columna fecha_presupuesto (fecha del presupuesto / vigencia del precio)
ALTER TABLE tbl_precios_referencia
ADD COLUMN IF NOT EXISTS fecha_presupuesto DATE;

-- 2. Opcional: rellenar con la fecha de creación antigua si existía esa columna
-- (descomenta si tienes datos en fecha_creacion y quieres conservarlos como fecha presupuesto)
-- UPDATE tbl_precios_referencia
-- SET fecha_presupuesto = (fecha_creacion::date)
-- WHERE fecha_presupuesto IS NULL AND fecha_creacion IS NOT NULL;

-- 3. Eliminar columnas antiguas (Supabase/PostgreSQL)
ALTER TABLE tbl_precios_referencia
DROP COLUMN IF EXISTS creado_por;

ALTER TABLE tbl_precios_referencia
DROP COLUMN IF EXISTS fecha_creacion;
