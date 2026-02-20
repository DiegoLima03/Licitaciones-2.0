-- =============================================================================
-- Acuerdos Marco y Sistemas Dinámicos de Adquisición (relación padre-hijo)
-- Ejecutar en Supabase SQL Editor o con: supabase db push
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) Tipo ENUM para tipo_procedimiento (solo si no existe)
-- -----------------------------------------------------------------------------
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'tipo_procedimiento_enum') THEN
    CREATE TYPE public.tipo_procedimiento_enum AS ENUM (
      'ORDINARIO',
      'ACUERDO_MARCO',
      'SDA',
      'BASADO_AM',
      'ESPECIFICO_SDA'
    );
  END IF;
END
$$;

-- -----------------------------------------------------------------------------
-- 2) Columna tipo_procedimiento
-- -----------------------------------------------------------------------------
ALTER TABLE public.tbl_licitaciones
  ADD COLUMN IF NOT EXISTS tipo_procedimiento public.tipo_procedimiento_enum
  DEFAULT 'ORDINARIO';

-- Asegurar valor por defecto en columnas ya existentes
ALTER TABLE public.tbl_licitaciones
  ALTER COLUMN tipo_procedimiento SET DEFAULT 'ORDINARIO'::public.tipo_procedimiento_enum;

-- -----------------------------------------------------------------------------
-- 3) Columna parent_id (FK a la propia tabla, ON DELETE SET NULL)
-- -----------------------------------------------------------------------------
ALTER TABLE public.tbl_licitaciones
  ADD COLUMN IF NOT EXISTS parent_id integer;

ALTER TABLE public.tbl_licitaciones
  DROP CONSTRAINT IF EXISTS tbl_licitaciones_parent_id_fkey;

ALTER TABLE public.tbl_licitaciones
  ADD CONSTRAINT tbl_licitaciones_parent_id_fkey
  FOREIGN KEY (parent_id)
  REFERENCES public.tbl_licitaciones(id_licitacion)
  ON DELETE SET NULL;

COMMENT ON COLUMN public.tbl_licitaciones.parent_id IS 'Licitación padre (AM o SDA) cuando tipo es BASADO_AM o ESPECIFICO_SDA.';

-- -----------------------------------------------------------------------------
-- 4) Columna parent_lote (nombre del lote del padre)
-- -----------------------------------------------------------------------------
ALTER TABLE public.tbl_licitaciones
  ADD COLUMN IF NOT EXISTS parent_lote character varying;

COMMENT ON COLUMN public.tbl_licitaciones.parent_lote IS 'Nombre del lote del acuerdo marco/SDA del que cuelga este contrato hijo.';

-- -----------------------------------------------------------------------------
-- 5) Vistas: si tienes vistas que hacen SELECT desde tbl_licitaciones,
--    recréalas para incluir tipo_procedimiento, parent_id y parent_lote.
--    Ejemplo si existiera una vista "v_licitaciones":
--
--    DROP VIEW IF EXISTS public.v_licitaciones CASCADE;
--    CREATE VIEW public.v_licitaciones AS
--    SELECT *, tipo_procedimiento, parent_id, parent_lote
--    FROM public.tbl_licitaciones;
--
--    En esta base no se detectaron vistas sobre tbl_licitaciones;
--    si las creas después, incluye estas columnas donde las necesites.
-- -----------------------------------------------------------------------------
