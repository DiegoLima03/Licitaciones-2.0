# Backend Veraleza (FastAPI)

## Arrancar el servidor

Desde la **raíz del proyecto** (la carpeta donde están `backend/` y `frontend/`), ejecuta:

```bash
uvicorn backend.main:app --reload
```

El API quedará disponible en **http://localhost:8000**.

- Health check: http://localhost:8000/
- Login: `POST http://localhost:8000/api/auth/login`

Asegúrate de tener el archivo `.env` en la raíz con `SUPABASE_URL` y `SUPABASE_KEY`.
