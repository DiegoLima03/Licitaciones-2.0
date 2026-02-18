-- =============================================================================
-- RPC: Listar usuarios de una organización (id, email, full_name, role)
-- Requiere: saas_migration.sql ejecutado
-- Ejecutar en Supabase SQL Editor
-- =============================================================================

CREATE OR REPLACE FUNCTION public.get_org_users(p_org_id UUID)
RETURNS TABLE (id UUID, email TEXT, full_name TEXT, role TEXT)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, auth
AS $$
BEGIN
  RETURN QUERY
  SELECT p.id, u.email::TEXT, p.full_name, p.role
  FROM public.profiles p
  JOIN auth.users u ON u.id = p.id
  WHERE p.organization_id = p_org_id
  ORDER BY p.full_name NULLS LAST, u.email;
END;
$$;

COMMENT ON FUNCTION public.get_org_users(UUID) IS
  'Devuelve los usuarios de una organización con email desde auth.users. Solo para uso backend (service_role).';
