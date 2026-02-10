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

- **Solo backend:** doble clic en `arrancar-backend.bat` en la raíz del proyecto.
- **Frontend:** después abre una terminal, `cd frontend`, `npm run dev` (o `npm run dev -- -p 3001` si quieres usar el puerto 3001).
