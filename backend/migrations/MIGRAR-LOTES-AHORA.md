# Migraciones pendientes (enlace_gober, lotes_config)

Si al **crear licitaciones** o **generar lotes** te sale:
```
Could not find the 'enlace_gober' column...
Could not find the 'lotes_config' column...
```

Ejecuta la migración en Supabase.

## Pasos

1. Entra en **https://supabase.com** e inicia sesión.
2. Abre tu proyecto.
3. En el menú lateral, haz clic en **SQL Editor**.
4. Crea una nueva query y pega el contenido del archivo **`ejecutar_todas_migraciones_pendientes.sql`** (o el SQL de abajo).
5. Pulsa **Run** (o Ctrl+Enter).
6. Recarga la app.

```sql
-- Enlace Gober
ALTER TABLE public.tbl_licitaciones
ADD COLUMN IF NOT EXISTS enlace_gober TEXT;

-- Config lotes
ALTER TABLE public.tbl_licitaciones
ADD COLUMN IF NOT EXISTS lotes_config JSONB DEFAULT '[]'::jsonb;
```
