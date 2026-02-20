-- =============================================================================
-- ROLLBACK: deshacer la migración 003 (Acuerdos Marco / SDA)
-- Ejecuta este script en Supabase SQL Editor para quitar las columnas
-- tipo_procedimiento, parent_id y parent_lote de tbl_licitaciones.
-- =============================================================================

-- 1) Quitar columna parent_lote
ALTER TABLE public.tbl_licitaciones
  DROP COLUMN IF EXISTS parent_lote;

-- 2) Quitar FK y columna parent_id
ALTER TABLE public.tbl_licitaciones
  DROP CONSTRAINT IF EXISTS tbl_licitaciones_parent_id_fkey;

ALTER TABLE public.tbl_licitaciones
  DROP COLUMN IF EXISTS parent_id;

-- 3) Quitar columna tipo_procedimiento
ALTER TABLE public.tbl_licitaciones
  DROP COLUMN IF EXISTS tipo_procedimiento;

-- 4) Eliminar el tipo ENUM (solo si ya no lo usa ninguna columna)
DROP TYPE IF EXISTS public.tipo_procedimiento_enum;
