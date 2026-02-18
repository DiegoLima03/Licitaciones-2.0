-- =============================================================================
-- SaaS Migration: Multi-tenancy y Seguridad (Fase 1)
-- Ejecutar en Supabase SQL Editor (orden secuencial)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. CREAR TABLA ORGANIZATIONS
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.organizations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- -----------------------------------------------------------------------------
-- 2. CREAR TABLA PROFILES (vinculada a auth.users)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.profiles (
    id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
    organization_id UUID NOT NULL REFERENCES public.organizations(id) ON DELETE RESTRICT,
    role TEXT NOT NULL DEFAULT 'member',
    full_name TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_profiles_organization_id ON public.profiles(organization_id);

-- -----------------------------------------------------------------------------
-- 3. ORGANIZACIÓN POR DEFECTO (debe existir antes del trigger)
-- -----------------------------------------------------------------------------
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM public.organizations LIMIT 1) THEN
        INSERT INTO public.organizations (name) VALUES ('Organización por defecto');
    END IF;
END $$;

-- -----------------------------------------------------------------------------
-- 4. TRIGGER: Crear perfil cuando se registra un usuario en auth.users
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.handle_new_user()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
    default_org_id UUID;
BEGIN
    SELECT id INTO default_org_id FROM public.organizations LIMIT 1;
    
    IF default_org_id IS NULL THEN
        RAISE EXCEPTION 'No existe ninguna organización. Ejecute la migración completa.';
    END IF;
    
    INSERT INTO public.profiles (id, organization_id, role, full_name)
    VALUES (
        NEW.id,
        default_org_id,
        COALESCE(NEW.raw_user_meta_data->>'role', 'member'),
        COALESCE(NEW.raw_user_meta_data->>'full_name', NEW.raw_user_meta_data->>'name')
    );
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS on_auth_user_created ON auth.users;
CREATE TRIGGER on_auth_user_created
    AFTER INSERT ON auth.users
    FOR EACH ROW EXECUTE FUNCTION public.handle_new_user();

-- -----------------------------------------------------------------------------
-- 5. AÑADIR organization_id A TABLAS CRÍTICAS
-- Primero añadimos la columna nullable, migramos datos, luego NOT NULL
-- -----------------------------------------------------------------------------
ALTER TABLE public.tbl_licitaciones
    ADD COLUMN IF NOT EXISTS organization_id UUID REFERENCES public.organizations(id) ON DELETE RESTRICT;

ALTER TABLE public.tbl_productos
    ADD COLUMN IF NOT EXISTS organization_id UUID REFERENCES public.organizations(id) ON DELETE RESTRICT;

ALTER TABLE public.tbl_entregas
    ADD COLUMN IF NOT EXISTS organization_id UUID REFERENCES public.organizations(id) ON DELETE RESTRICT;

ALTER TABLE public.tbl_licitaciones_detalle
    ADD COLUMN IF NOT EXISTS organization_id UUID REFERENCES public.organizations(id) ON DELETE RESTRICT;

ALTER TABLE public.tbl_licitaciones_real
    ADD COLUMN IF NOT EXISTS organization_id UUID REFERENCES public.organizations(id) ON DELETE RESTRICT;

ALTER TABLE public.tbl_precios_referencia
    ADD COLUMN IF NOT EXISTS organization_id UUID REFERENCES public.organizations(id) ON DELETE RESTRICT;

-- Asignar organización por defecto a filas existentes
DO $$
DECLARE
    default_org_id UUID;
BEGIN
    SELECT id INTO default_org_id FROM public.organizations LIMIT 1;
    
    UPDATE public.tbl_licitaciones SET organization_id = default_org_id WHERE organization_id IS NULL;
    UPDATE public.tbl_productos SET organization_id = default_org_id WHERE organization_id IS NULL;
    UPDATE public.tbl_entregas SET organization_id = default_org_id WHERE organization_id IS NULL;
    
    -- tbl_licitaciones_detalle: heredar de tbl_licitaciones por id_licitacion
    UPDATE public.tbl_licitaciones_detalle d
    SET organization_id = l.organization_id
    FROM public.tbl_licitaciones l
    WHERE d.id_licitacion = l.id_licitacion AND d.organization_id IS NULL;
    UPDATE public.tbl_licitaciones_detalle SET organization_id = default_org_id WHERE organization_id IS NULL;
    
    -- tbl_licitaciones_real: heredar de tbl_licitaciones
    UPDATE public.tbl_licitaciones_real r
    SET organization_id = l.organization_id
    FROM public.tbl_licitaciones l
    WHERE r.id_licitacion = l.id_licitacion AND r.organization_id IS NULL;
    UPDATE public.tbl_licitaciones_real SET organization_id = default_org_id WHERE organization_id IS NULL;
    
    -- tbl_precios_referencia: heredar de tbl_productos por id_producto, o default
    UPDATE public.tbl_precios_referencia pr
    SET organization_id = p.organization_id
    FROM public.tbl_productos p
    WHERE pr.id_producto = p.id AND pr.organization_id IS NULL;
    UPDATE public.tbl_precios_referencia SET organization_id = default_org_id WHERE organization_id IS NULL;
END $$;

-- Hacer NOT NULL
ALTER TABLE public.tbl_licitaciones ALTER COLUMN organization_id SET NOT NULL;
ALTER TABLE public.tbl_productos ALTER COLUMN organization_id SET NOT NULL;
ALTER TABLE public.tbl_entregas ALTER COLUMN organization_id SET NOT NULL;
ALTER TABLE public.tbl_licitaciones_detalle ALTER COLUMN organization_id SET NOT NULL;
ALTER TABLE public.tbl_licitaciones_real ALTER COLUMN organization_id SET NOT NULL;
ALTER TABLE public.tbl_precios_referencia ALTER COLUMN organization_id SET NOT NULL;

-- Índices para rendimiento
CREATE INDEX IF NOT EXISTS idx_tbl_licitaciones_organization_id ON public.tbl_licitaciones(organization_id);
CREATE INDEX IF NOT EXISTS idx_tbl_productos_organization_id ON public.tbl_productos(organization_id);
CREATE INDEX IF NOT EXISTS idx_tbl_entregas_organization_id ON public.tbl_entregas(organization_id);
CREATE INDEX IF NOT EXISTS idx_tbl_licitaciones_detalle_organization_id ON public.tbl_licitaciones_detalle(organization_id);
CREATE INDEX IF NOT EXISTS idx_tbl_licitaciones_real_organization_id ON public.tbl_licitaciones_real(organization_id);
CREATE INDEX IF NOT EXISTS idx_tbl_precios_referencia_organization_id ON public.tbl_precios_referencia(organization_id);

-- -----------------------------------------------------------------------------
-- 6. HABILITAR RLS EN TODAS LAS TABLAS
-- -----------------------------------------------------------------------------
ALTER TABLE public.organizations ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tbl_licitaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tbl_productos ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tbl_entregas ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tbl_licitaciones_detalle ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tbl_licitaciones_real ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tbl_precios_referencia ENABLE ROW LEVEL SECURITY;

-- -----------------------------------------------------------------------------
-- 7. FUNCIÓN HELPER: Obtener organization_id del usuario actual
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.current_user_org_id()
RETURNS UUID
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
    SELECT organization_id FROM public.profiles WHERE id = auth.uid();
$$;

-- -----------------------------------------------------------------------------
-- 8. POLÍTICAS RLS: organizations (usuarios ven solo su org)
-- -----------------------------------------------------------------------------
DROP POLICY IF EXISTS "Users can view own organization" ON public.organizations;
CREATE POLICY "Users can view own organization"
    ON public.organizations FOR SELECT
    USING (id = public.current_user_org_id());

DROP POLICY IF EXISTS "Service role full access organizations" ON public.organizations;
-- Permitir al service_role (backend) acceso total para operaciones administrativas
CREATE POLICY "Service role full access organizations"
    ON public.organizations FOR ALL
    USING (auth.jwt() ->> 'role' = 'service_role');

-- -----------------------------------------------------------------------------
-- 9. POLÍTICAS RLS: profiles (usuarios ven solo perfiles de su org)
-- -----------------------------------------------------------------------------
DROP POLICY IF EXISTS "Users can view own profile" ON public.profiles;
CREATE POLICY "Users can view own profile"
    ON public.profiles FOR SELECT
    USING (id = auth.uid());

DROP POLICY IF EXISTS "Users can view profiles in same org" ON public.profiles;
CREATE POLICY "Users can view profiles in same org"
    ON public.profiles FOR SELECT
    USING (organization_id = public.current_user_org_id());

DROP POLICY IF EXISTS "Users can update own profile" ON public.profiles;
CREATE POLICY "Users can update own profile"
    ON public.profiles FOR UPDATE
    USING (id = auth.uid());

DROP POLICY IF EXISTS "Service role full access profiles" ON public.profiles;
CREATE POLICY "Service role full access profiles"
    ON public.profiles FOR ALL
    USING (auth.jwt() ->> 'role' = 'service_role');

-- -----------------------------------------------------------------------------
-- 10. POLÍTICAS RLS: tbl_licitaciones
-- -----------------------------------------------------------------------------
DROP POLICY IF EXISTS "Org isolation tbl_licitaciones" ON public.tbl_licitaciones;
CREATE POLICY "Org isolation tbl_licitaciones"
    ON public.tbl_licitaciones FOR ALL
    USING (organization_id = public.current_user_org_id())
    WITH CHECK (organization_id = public.current_user_org_id());

DROP POLICY IF EXISTS "Service role tbl_licitaciones" ON public.tbl_licitaciones;
CREATE POLICY "Service role tbl_licitaciones"
    ON public.tbl_licitaciones FOR ALL
    USING (auth.jwt() ->> 'role' = 'service_role');

-- -----------------------------------------------------------------------------
-- 11. POLÍTICAS RLS: tbl_productos
-- -----------------------------------------------------------------------------
DROP POLICY IF EXISTS "Org isolation tbl_productos" ON public.tbl_productos;
CREATE POLICY "Org isolation tbl_productos"
    ON public.tbl_productos FOR ALL
    USING (organization_id = public.current_user_org_id())
    WITH CHECK (organization_id = public.current_user_org_id());

DROP POLICY IF EXISTS "Service role tbl_productos" ON public.tbl_productos;
CREATE POLICY "Service role tbl_productos"
    ON public.tbl_productos FOR ALL
    USING (auth.jwt() ->> 'role' = 'service_role');

-- -----------------------------------------------------------------------------
-- 12. POLÍTICAS RLS: tbl_entregas
-- -----------------------------------------------------------------------------
DROP POLICY IF EXISTS "Org isolation tbl_entregas" ON public.tbl_entregas;
CREATE POLICY "Org isolation tbl_entregas"
    ON public.tbl_entregas FOR ALL
    USING (organization_id = public.current_user_org_id())
    WITH CHECK (organization_id = public.current_user_org_id());

DROP POLICY IF EXISTS "Service role tbl_entregas" ON public.tbl_entregas;
CREATE POLICY "Service role tbl_entregas"
    ON public.tbl_entregas FOR ALL
    USING (auth.jwt() ->> 'role' = 'service_role');

-- -----------------------------------------------------------------------------
-- 13. POLÍTICAS RLS: tbl_licitaciones_detalle
-- -----------------------------------------------------------------------------
DROP POLICY IF EXISTS "Org isolation tbl_licitaciones_detalle" ON public.tbl_licitaciones_detalle;
CREATE POLICY "Org isolation tbl_licitaciones_detalle"
    ON public.tbl_licitaciones_detalle FOR ALL
    USING (organization_id = public.current_user_org_id())
    WITH CHECK (organization_id = public.current_user_org_id());

DROP POLICY IF EXISTS "Service role tbl_licitaciones_detalle" ON public.tbl_licitaciones_detalle;
CREATE POLICY "Service role tbl_licitaciones_detalle"
    ON public.tbl_licitaciones_detalle FOR ALL
    USING (auth.jwt() ->> 'role' = 'service_role');

-- -----------------------------------------------------------------------------
-- 14. POLÍTICAS RLS: tbl_licitaciones_real
-- -----------------------------------------------------------------------------
DROP POLICY IF EXISTS "Org isolation tbl_licitaciones_real" ON public.tbl_licitaciones_real;
CREATE POLICY "Org isolation tbl_licitaciones_real"
    ON public.tbl_licitaciones_real FOR ALL
    USING (organization_id = public.current_user_org_id())
    WITH CHECK (organization_id = public.current_user_org_id());

DROP POLICY IF EXISTS "Service role tbl_licitaciones_real" ON public.tbl_licitaciones_real;
CREATE POLICY "Service role tbl_licitaciones_real"
    ON public.tbl_licitaciones_real FOR ALL
    USING (auth.jwt() ->> 'role' = 'service_role');

-- -----------------------------------------------------------------------------
-- 15. POLÍTICAS RLS: tbl_precios_referencia
-- -----------------------------------------------------------------------------
DROP POLICY IF EXISTS "Org isolation tbl_precios_referencia" ON public.tbl_precios_referencia;
CREATE POLICY "Org isolation tbl_precios_referencia"
    ON public.tbl_precios_referencia FOR ALL
    USING (organization_id = public.current_user_org_id())
    WITH CHECK (organization_id = public.current_user_org_id());

DROP POLICY IF EXISTS "Service role tbl_precios_referencia" ON public.tbl_precios_referencia;
CREATE POLICY "Service role tbl_precios_referencia"
    ON public.tbl_precios_referencia FOR ALL
    USING (auth.jwt() ->> 'role' = 'service_role');

-- -----------------------------------------------------------------------------
-- NOTA: tbl_estados y tbl_tipolicitacion son datos maestros compartidos.
-- No se añade organization_id. Si en el futuro deben ser por org, crear migración.
-- -----------------------------------------------------------------------------
