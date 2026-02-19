from pathlib import Path

from dotenv import load_dotenv

# Cargar .env antes que nada (por si uvicorn arranca desde otra ruta)
load_dotenv(Path(__file__).resolve().parent.parent / ".env")

from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse

from backend.config import DEBUG, SKIP_AUTH
from backend.routers import auth, analytics, tenders, import_data, deliveries, search, estados, tipos, tipos_gasto, precios_referencia, productos, expenses


app = FastAPI(
    title="Veraleza API",
    version="1.0.0",
    description="API REST para la aplicación de licitaciones, migrada desde Streamlit.",
)


# CORS: permitir frontend en localhost y en IP de red (p. ej. 192.168.x.x)
origins = [
    "http://localhost:3000",
    "http://localhost:3001",
    "http://127.0.0.1:3000",
    "http://127.0.0.1:3001",
    "http://192.168.1.14:3000",
    "http://192.168.1.14:3001",
]

# Manejador global: en producción no exponer detail del 500; solo si DEBUG=true
@app.exception_handler(Exception)
async def global_exception_handler(_request: Request, exc: Exception) -> JSONResponse:
    detail = str(exc) if DEBUG else "Internal Server Error"
    return JSONResponse(status_code=500, content={"detail": detail})


app.add_middleware(
    CORSMiddleware,
    allow_origins=origins,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# Registro de routers bajo /api para que el frontend llame a /api/auth/login, etc.
app.include_router(auth.router, prefix="/api")
app.include_router(analytics.router, prefix="/api")
app.include_router(tenders.router, prefix="/api")
app.include_router(import_data.router, prefix="/api")
app.include_router(deliveries.router, prefix="/api")
app.include_router(search.router, prefix="/api")
app.include_router(estados.router, prefix="/api")
app.include_router(tipos.router, prefix="/api")
app.include_router(tipos_gasto.router, prefix="/api")
app.include_router(precios_referencia.router, prefix="/api")
app.include_router(productos.router, prefix="/api")
app.include_router(expenses.router, prefix="/api")


@app.get("/")
def root() -> dict:
    """Health check sencillo para verificar que el backend está levantado."""
    return {"status": "ok"}


@app.on_event("startup")
def startup():
    """Log de modo desarrollo al arrancar."""
    if SKIP_AUTH:
        print(">>> Modo desarrollo: SKIP_AUTH=true (API acepta peticiones sin token)")


__all__ = ["app"]

