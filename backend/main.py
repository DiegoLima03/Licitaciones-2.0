from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from backend.routers import auth, analytics, tenders, import_data, deliveries, search


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


@app.get("/")
def root() -> dict:
    """Health check sencillo para verificar que el backend está levantado."""
    return {"status": "ok"}


__all__ = ["app"]

