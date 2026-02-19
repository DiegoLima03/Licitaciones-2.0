-- =============================================================================
-- RLS (Row Level Security) y constraints multi-tenant
-- Asegura que cada organización solo accede a sus filas.
-- Ejecutar en Supabase SQL Editor o con supabase db push.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) Función auxiliar: organization_id del usuario actual
-- Opción A: desde public.profiles (id = auth.uid())
-- Opción B: desde JWT app_metadata: auth.jwt() -> 'app_metadata' ->> 'organization_id'
-- Usamos A por defecto; si en el futuro el JWT incluye app_metadata.organization_id
-- se puede cambiar la función para leerlo de ahí.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.current_user_org_id()
RETURNS uuid
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path TO public
AS $$
  SELECT organization_id FROM public.profiles WHERE id = auth.uid();
  -- Alternativa si el JWT tiene app_metadata.organization_id:
  -- (auth.jwt() -> 'app_metadata' ->> 'organization_id')::uuid;
$$;

COMMENT ON FUNCTION public.current_user_org_id() IS 'Devuelve el organization_id del usuario autenticado (desde profiles o JWT app_metadata).';

-- -----------------------------------------------------------------------------
-- 2) Habilitar RLS en tablas multi-tenant
-- -----------------------------------------------------------------------------
ALTER TABLE public.tbl_licitaciones          ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tbl_licitaciones_detalle  ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tbl_entregas              ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tbl_licitaciones_real     ENABLE ROW LEVEL SECURITY;

-- -----------------------------------------------------------------------------
-- 3) Políticas RLS: solo filas donde organization_id = current_user_org_id()
-- Service role puede bypassear RLS; para anon/authenticated aplicamos el filtro.
-- -----------------------------------------------------------------------------
DROP POLICY IF EXISTS "Org isolation tbl_licitaciones" ON public.tbl_licitaciones;
CREATE POLICY "Org isolation tbl_licitaciones"
  ON public.tbl_licitaciones
  FOR ALL
  TO public
  USING (organization_id = current_user_org_id())
  WITH CHECK (organization_id = current_user_org_id());

DROP POLICY IF EXISTS "Org isolation tbl_licitaciones_detalle" ON public.tbl_licitaciones_detalle;
CREATE POLICY "Org isolation tbl_licitaciones_detalle"
  ON public.tbl_licitaciones_detalle
  FOR ALL
  TO public
  USING (organization_id = current_user_org_id())
  WITH CHECK (organization_id = current_user_org_id());

DROP POLICY IF EXISTS "Org isolation tbl_entregas" ON public.tbl_entregas;
CREATE POLICY "Org isolation tbl_entregas"
  ON public.tbl_entregas
  FOR ALL
  TO public
  USING (organization_id = current_user_org_id())
  WITH CHECK (organization_id = current_user_org_id());

DROP POLICY IF EXISTS "Org isolation tbl_licitaciones_real" ON public.tbl_licitaciones_real;
CREATE POLICY "Org isolation tbl_licitaciones_real"
  ON public.tbl_licitaciones_real
  FOR ALL
  TO public
  USING (organization_id = current_user_org_id())
  WITH CHECK (organization_id = current_user_org_id());

-- Permitir acceso total con service_role (backend con key service_role)
DROP POLICY IF EXISTS "Service role tbl_licitaciones" ON public.tbl_licitaciones;
CREATE POLICY "Service role tbl_licitaciones"
  ON public.tbl_licitaciones FOR ALL TO public
  USING ((auth.jwt() ->> 'role') = 'service_role');

DROP POLICY IF EXISTS "Service role tbl_licitaciones_detalle" ON public.tbl_licitaciones_detalle;
CREATE POLICY "Service role tbl_licitaciones_detalle"
  ON public.tbl_licitaciones_detalle FOR ALL TO public
  USING ((auth.jwt() ->> 'role') = 'service_role');

DROP POLICY IF EXISTS "Service role tbl_entregas" ON public.tbl_entregas;
CREATE POLICY "Service role tbl_entregas"
  ON public.tbl_entregas FOR ALL TO public
  USING ((auth.jwt() ->> 'role') = 'service_role');

DROP POLICY IF EXISTS "Service role tbl_licitaciones_real" ON public.tbl_licitaciones_real;
CREATE POLICY "Service role tbl_licitaciones_real"
  ON public.tbl_licitaciones_real FOR ALL TO public
  USING ((auth.jwt() ->> 'role') = 'service_role');

-- -----------------------------------------------------------------------------
-- 4) ON DELETE CASCADE en FKs hacia tbl_licitaciones
-- (Si ya existen con CASCADE, no hace falta alterar.)
-- -----------------------------------------------------------------------------
-- tbl_licitaciones_detalle.id_licitacion -> tbl_licitaciones
ALTER TABLE public.tbl_licitaciones_detalle
  DROP CONSTRAINT IF EXISTS tbl_licitaciones_detalle_id_licitacion_fkey,
  ADD CONSTRAINT tbl_licitaciones_detalle_id_licitacion_fkey
    FOREIGN KEY (id_licitacion) REFERENCES public.tbl_licitaciones(id_licitacion) ON DELETE CASCADE;

-- tbl_entregas.id_licitacion -> tbl_licitaciones
ALTER TABLE public.tbl_entregas
  DROP CONSTRAINT IF EXISTS tbl_entregas_id_licitacion_fkey,
  ADD CONSTRAINT tbl_entregas_id_licitacion_fkey
    FOREIGN KEY (id_licitacion) REFERENCES public.tbl_licitaciones(id_licitacion) ON DELETE CASCADE;

-- tbl_licitaciones_real.id_licitacion -> tbl_licitaciones
ALTER TABLE public.tbl_licitaciones_real
  DROP CONSTRAINT IF EXISTS tbl_licitaciones_real_id_licitacion_fkey,
  ADD CONSTRAINT tbl_licitaciones_real_id_licitacion_fkey
    FOREIGN KEY (id_licitacion) REFERENCES public.tbl_licitaciones(id_licitacion) ON DELETE CASCADE;
