-- =============================================================================
-- Estructura jerárquica AM/SDA: tipo_procedimiento (VARCHAR) + id_licitacion_padre (FK CASCADE)
-- Ejecutar en Supabase SQL Editor o con: supabase db push
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) Columna tipo_procedimiento VARCHAR (solo si no existe)
-- Valores: ORDINARIO, ACUERDO_MARCO, SDA, CONTRATO_BASADO
-- -----------------------------------------------------------------------------
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = 'tbl_licitaciones'
      AND column_name = 'tipo_procedimiento'
  ) THEN
    ALTER TABLE public.tbl_licitaciones
      ADD COLUMN tipo_procedimiento character varying(32) NOT NULL DEFAULT 'ORDINARIO';

    ALTER TABLE public.tbl_licitaciones
      ADD CONSTRAINT chk_tbl_licitaciones_tipo_procedimiento
      CHECK (tipo_procedimiento IN ('ORDINARIO', 'ACUERDO_MARCO', 'SDA', 'CONTRATO_BASADO'));

    COMMENT ON COLUMN public.tbl_licitaciones.tipo_procedimiento IS
      'Tipo de procedimiento: ORDINARIO, ACUERDO_MARCO, SDA, CONTRATO_BASADO.';
  END IF;
END
$$;

-- Si la columna ya existe como ENUM (p. ej. desde 003), opcional: convertir a VARCHAR
-- (descomenta y adapta si quieres unificar en VARCHAR)
-- ALTER TABLE public.tbl_licitaciones ALTER COLUMN tipo_procedimiento TYPE character varying(32) USING tipo_procedimiento::text;
-- ALTER TABLE public.tbl_licitaciones ADD CONSTRAINT chk_tbl_licitaciones_tipo_procedimiento CHECK (tipo_procedimiento IN ('ORDINARIO', 'ACUERDO_MARCO', 'SDA', 'CONTRATO_BASADO'));

-- -----------------------------------------------------------------------------
-- 2) Columna id_licitacion_padre (FK nullable, ON DELETE CASCADE)
-- -----------------------------------------------------------------------------
ALTER TABLE public.tbl_licitaciones
  ADD COLUMN IF NOT EXISTS id_licitacion_padre integer;

ALTER TABLE public.tbl_licitaciones
  DROP CONSTRAINT IF EXISTS tbl_licitaciones_id_licitacion_padre_fkey;

ALTER TABLE public.tbl_licitaciones
  ADD CONSTRAINT tbl_licitaciones_id_licitacion_padre_fkey
  FOREIGN KEY (id_licitacion_padre)
  REFERENCES public.tbl_licitaciones(id_licitacion)
  ON DELETE CASCADE;

COMMENT ON COLUMN public.tbl_licitaciones.id_licitacion_padre IS
  'Licitación padre (AM o SDA). Contratos derivados (CONTRATO_BASADO) referencian aquí. CASCADE al borrar padre.';
