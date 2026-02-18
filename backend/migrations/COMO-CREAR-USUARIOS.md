# Cómo crear usuarios – Guía paso a paso

Tienes **2 formas** de crear usuarios. Elige la que prefieras.

---

## Método 1: Desde el panel de Supabase (más sencillo)

### Paso 1: Abre Supabase

1. Entra en [https://supabase.com](https://supabase.com) e inicia sesión.
2. Abre tu proyecto.

### Paso 2: Ir a usuarios

1. En el menú de la izquierda, haz clic en **Authentication**.
2. Luego en **Users**.

### Paso 3: Crear el usuario

1. Pulsa el botón **Add user** (arriba a la derecha).
2. Elige **Create new user**.
3. Completa:
   - **Email:** por ejemplo `admin@miempresa.com`
   - **Password:** escribe una contraseña (o marca "Auto generate" y copia la que te dé)
4. Pulsa **Create user**.

### Paso 4: Comprobar

- El usuario aparece en la lista.
- Ya puede iniciar sesión en tu app con ese email y contraseña.

---

## Método 2: Con SQL (para varios usuarios a la vez)

### Paso 1: Ejecutar la función (solo una vez)

1. En Supabase, ve a **SQL Editor**.
2. Crea una nueva query.
3. Copia y pega el contenido completo del archivo `create_users.sql`.
4. Pulsa **Run** para ejecutar.

### Paso 2: Crear cada usuario

En el SQL Editor, ejecuta una línea como esta por cada usuario (cambia el email, contraseña y nombre):

```sql
SELECT public.create_auth_user(
    'nuevo@ejemplo.com',      -- email del usuario
    'MiPassword123',          -- contraseña
    'Nombre del usuario'      -- nombre (opcional, puede ser NULL)
);
```

**Ejemplos:**

```sql
-- Usuario administrador
SELECT public.create_auth_user('admin@veraleza.com', 'Admin123!', 'Administrador');

-- Otro usuario
SELECT public.create_auth_user('maria@veraleza.com', 'Maria456!', 'María García');
```

---

## ¿Qué método usar?

| Situación                         | Método recomendado     |
|-----------------------------------|------------------------|
| Crear 1 o 2 usuarios              | Método 1 (Panel)       |
| Crear muchos usuarios de una vez  | Método 2 (SQL)         |
| No tienes claro qué hacer         | Método 1 (Panel)       |

---

## Requisito previo

Si **no** has ejecutado antes la migración `saas_migration.sql`, hazlo primero.  
Crea las tablas `organizations`, `profiles` y el trigger que asocia cada usuario nuevo a una organización.

**Opcional:** Si quieres que la página "Usuarios" muestre el email de cada usuario, ejecuta también `org_users_rpc.sql`.

---

## Usuarios antiguos (tbl_usuarios)

Si todavía usas la tabla `tbl_usuarios` (sin migración SaaS), puedes crear usuarios así:

```sql
INSERT INTO public.tbl_usuarios (id_usuario, email, password, rol, nombre)
VALUES (nextval('tbl_usuarios_id_usuario_seq'), 'admin@ejemplo.com', 'miPassword123', 'admin', 'Administrador');
```

*La contraseña se guarda en texto plano; solo para desarrollo.*
