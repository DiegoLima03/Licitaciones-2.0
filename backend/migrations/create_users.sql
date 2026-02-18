-- =============================================================================
-- Crear usuarios en Supabase Auth
-- Ejecutar en Supabase SQL Editor
-- Requiere: saas_migration.sql ya ejecutado (organizations, profiles, trigger)
--
-- Guía paso a paso: ver COMO-CREAR-USUARIOS.md
-- =============================================================================

-- Activar extensión para hash de contraseñas (bcrypt)
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- -----------------------------------------------------------------------------
-- Función: Crear usuario con email y contraseña
-- El trigger handle_new_user creará automáticamente el perfil en public.profiles
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.create_auth_user(
    p_email TEXT,
    p_password TEXT,
    p_full_name TEXT DEFAULT NULL
)
RETURNS UUID
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = auth, public
AS $$
DECLARE
    v_user_id UUID;
BEGIN
    v_user_id := gen_random_uuid();

    INSERT INTO auth.users (
        instance_id,
        id,
        aud,
        role,
        email,
        encrypted_password,
        email_confirmed_at,
        raw_app_meta_data,
        raw_user_meta_data,
        created_at,
        updated_at
    ) VALUES (
        '00000000-0000-0000-0000-000000000000',
        v_user_id,
        'authenticated',
        'authenticated',
        p_email,
        crypt(p_password, gen_salt('bf')),
        now(),
        '{"provider":"email","providers":["email"]}'::jsonb,
        jsonb_build_object('full_name', COALESCE(p_full_name, '')),
        now(),
        now()
    );

    INSERT INTO auth.identities (
        id,
        user_id,
        provider_id,
        identity_data,
        provider,
        email,
        created_at,
        updated_at
    ) VALUES (
        gen_random_uuid(),
        v_user_id,
        v_user_id::text,
        jsonb_build_object('sub', v_user_id::text, 'email', p_email),
        'email',
        p_email,
        now(),
        now()
    );

    RETURN v_user_id;
END;
$$;

-- -----------------------------------------------------------------------------
-- Ejemplos de uso (descomenta y modifica)
-- -----------------------------------------------------------------------------

-- SELECT public.create_auth_user('admin@tudominio.com', 'TuPasswordSeguro123', 'Administrador');
-- SELECT public.create_auth_user('usuario@tudominio.com', 'OtraPassword456');


-- -----------------------------------------------------------------------------
-- ALTERNATIVA: tbl_usuarios (legacy, solo si usas el fallback sin migración SaaS)
-- Contraseña en texto plano (inseguro, solo para desarrollo)
-- -----------------------------------------------------------------------------

-- INSERT INTO public.tbl_usuarios (id_usuario, email, password, rol, nombre)
-- VALUES (nextval('tbl_usuarios_id_usuario_seq'), 'admin@ejemplo.com', 'miPassword123', 'admin', 'Administrador');
