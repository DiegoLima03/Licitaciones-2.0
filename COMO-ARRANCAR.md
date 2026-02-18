# Cómo arrancar Backend y Frontend

Necesitas **dos terminales** (o dos pestañas): una para el backend y otra para el frontend.

---

## Paso 1: Backend (FastAPI)

1. Abre una **terminal** (PowerShell o CMD) en la carpeta del proyecto.
2. Ejecuta:

   ```bash
   uvicorn backend.main:app --reload --host 0.0.0.0
   ```

3. Deja **esta terminal abierta**. Deberías ver algo como:
   ```
   INFO:     Uvicorn running on http://0.0.0.0:8000
   INFO:     Application startup complete.
   ```

4. Comprueba en el navegador: **http://localhost:8000/** → debe salir `{"status":"ok"}`.

---

## Paso 2: Frontend (Next.js)

1. Abre **otra terminal** (segunda pestaña o ventana) en la carpeta del proyecto.
2. Entra en la carpeta del frontend y arranca el servidor:

   ```bash
   cd frontend
   npm run dev
   ```

3. Deja **esta terminal abierta**. Verás algo como:
   ```
   ▲ Next.js 14.x.x
   - Local:        http://localhost:3001
   ```

4. Abre en el navegador: **http://localhost:3001**  
   Te redirigirá al login. Inicia sesión y ya podrás usar la app.

---

## Resumen

| Qué        | Terminal 1                    | Terminal 2              |
|-----------|--------------------------------|-------------------------|
| Comando   | `uvicorn backend.main:app --reload --host 0.0.0.0` | `cd frontend` y `npm run dev` |
| URL       | http://localhost:8000         | http://localhost:3001    |
| No cierres| ✓                             | ✓                       |

---

## Atajos (Windows)

- **Backend + frontend juntos:** doble clic en `arrancar-todo.bat` (abre dos ventanas).
- **Solo backend:** doble clic en `arrancar-backend.bat` en la raíz del proyecto.
- **Solo frontend:** terminal → `cd frontend` → `npm run dev` (o `npm run dev -- -p 3001`).

---

## Modo desarrollo (sin login)

Para desarrollar sin iniciar sesión:

- **Frontend** (`frontend/.env.local`): `NEXT_PUBLIC_SKIP_LOGIN=true`
- **Backend** (`.env` en la raíz): `SKIP_AUTH=true`

Con ambos activados, entras directo al dashboard y la API acepta peticiones sin token.

---

## Problemas frecuentes

| Problema | Solución |
|----------|----------|
| "Python no encontrado" | Instala Python o añádelo al PATH. |
| "uvicorn no está instalado" | En la raíz del proyecto: `pip install -r requirements.txt` |
| "ModuleNotFoundError: backend" | Ejecuta desde la **raíz del proyecto** (donde está la carpeta `backend`). |
| Puerto 8000 en uso | Cierra otras instancias del backend o usa otro puerto: `uvicorn backend.main:app --reload --port 8001` |
