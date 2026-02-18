# Setup Multi-tenant SaaS (Fase 1)

## 1. Variables de entorno

Añade a tu `.env`:

```env
# Existentes
SUPABASE_URL=https://TU_PROJECT.supabase.co
SUPABASE_KEY=eyJ...  # service_role key (backend)

# Nueva (obligatoria para auth)
SUPABASE_JWT_SECRET=tu-jwt-secret
```

El **JWT Secret** está en: Supabase Dashboard → Project Settings → API → JWT Secret.

## 2. Ejecutar migración SQL

1. Abre Supabase → SQL Editor.
2. Copia el contenido de `saas_migration.sql`.
3. Ejecuta el script completo.

## 3. Crear nuevos usuarios

📄 **Guía detallada:** ver `COMO-CREAR-USUARIOS.md`

### Opción A: Panel de Supabase (recomendado para empezar)

1. Entra en **Supabase Dashboard** → tu proyecto.
2. Ve a **Authentication** → **Users**.
3. Pulsa **Add user** → **Create new user**.
4. Rellena email y contraseña (o marca "Auto generate password" y copia la contraseña).
5. Al guardar, el trigger crea el perfil en `public.profiles` con la organización por defecto.

### Opción B: SQL en Supabase

1. Ejecuta primero `create_users.sql` en el SQL Editor (define la función).
2. Crea usuarios con:

```sql
SELECT public.create_auth_user('nuevo@ejemplo.com', 'PasswordSegura123', 'Nombre del usuario');
```

### Opción C: API desde la app (solo admin)

Si un usuario tiene `role=admin` en su perfil, puede crear usuarios vía API:

```bash
curl -X POST http://localhost:8000/api/auth/users \
  -H "Authorization: Bearer <token_admin>" \
  -H "Content-Type: application/json" \
  -d '{"email":"nuevo@ejemplo.com","password":"Password123","full_name":"Nombre"}'
```

El nuevo usuario se asigna a la organización del admin.

### Opción D: Registro en el frontend

Configura una página de registro que llame a `supabase.auth.signUp({ email, password })`. El trigger creará el perfil al registrarse.

## 4. Flujo de autenticación (frontend)

1. Login: `supabase.auth.signInWithPassword({ email, password })`
2. Obtener sesión: `supabase.auth.getSession()` → `session.access_token`
3. Llamar al backend con: `Authorization: Bearer <access_token>`
4. Para obtener perfil/org: `GET /api/auth/me` o `POST /api/auth/login` (con Bearer)

## 5. Endpoints protegidos

Todos los endpoints de `tenders` requieren `Authorization: Bearer <jwt>`.
Los datos se filtran automáticamente por `organization_id` del usuario.
