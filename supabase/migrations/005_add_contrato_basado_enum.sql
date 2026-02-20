-- =============================================================================
-- Añade CONTRATO_BASADO al enum tipo_procedimiento_enum (si existe)
-- Necesario cuando la BD tiene el enum de 003 pero el backend usa CONTRATO_BASADO
-- =============================================================================

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'tipo_procedimiento_enum') THEN
    IF NOT EXISTS (
      SELECT 1 FROM pg_enum e
      JOIN pg_type t ON e.enumtypid = t.oid
      WHERE t.typname = 'tipo_procedimiento_enum' AND e.enumlabel = 'CONTRATO_BASADO'
    ) THEN
      ALTER TYPE public.tipo_procedimiento_enum ADD VALUE 'CONTRATO_BASADO';
    END IF;
  END IF;
END
$$;
