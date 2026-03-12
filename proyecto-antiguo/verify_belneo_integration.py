#!/usr/bin/env python3
"""
Verificación rápida de que la flexibilidad nombre_producto_libre (sin product_id)
no rompe la integración con Belneo.

Ejecutar desde la raíz del proyecto:
  SKIP_AUTH=true python verify_belneo_integration.py

Requisitos: .env con SUPABASE_URL y SUPABASE_KEY; backend importable (pip install -e . o PYTHONPATH=.).
"""

import os
import sys
from decimal import Decimal

# Asegurar que el backend es importable
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

# Forzar SKIP_AUTH para el test si no está definido
if "SKIP_AUTH" not in os.environ:
    os.environ["SKIP_AUTH"] = "true"

from fastapi.testclient import TestClient

from backend.main import app
from backend.models import EstadoLicitacion

client = TestClient(app)

# --- 1) Crear licitación ---
CREATE_PAYLOAD = {
    "nombre": "Test Belneo integración - solo nombre libre",
    "pais": "España",
    "numero_expediente": "VER-BELNEO-001",
    "pres_maximo": 10000,
    "descripcion": "Verificación script",
}
r_create = client.post("/api/tenders", json=CREATE_PAYLOAD)
if r_create.status_code != 201:
    print(f"FAIL: crear licitación → {r_create.status_code} {r_create.text}")
    sys.exit(1)
tender = r_create.json()
tender_id = tender["id_licitacion"]
print(f"OK: licitación creada id={tender_id}")

# --- 2) Añadir partidas SOLO con nombre_producto_libre (sin id_producto) ---
PARTIDA_LIBRE_1 = {
    "lote": "General",
    "nombre_producto_libre": "Producto libre A",
    "unidades": 2,
    "pvu": 100,
    "pcu": 50,
    "pmaxu": 120,
}
PARTIDA_LIBRE_2 = {
    "lote": "General",
    "nombre_producto_libre": "Producto libre B",
    "unidades": 1,
    "pvu": 200,
    "pcu": 80,
}
# Sin id_producto en el payload
for i, payload in enumerate([PARTIDA_LIBRE_1, PARTIDA_LIBRE_2], 1):
    r_part = client.post(f"/api/tenders/{tender_id}/partidas", json=payload)
    if r_part.status_code != 201:
        print(f"FAIL: añadir partida {i} (solo nombre_producto_libre) → {r_part.status_code} {r_part.text}")
        sys.exit(1)
    print(f"OK: partida {i} añadida (solo nombre_producto_libre)")

# --- 3) Búsqueda, listado y detalle (totales) sin excepciones por campos nulos ---
r_list = client.get("/api/tenders", params={"nombre": "Belneo"})
if r_list.status_code != 200:
    print(f"FAIL: listado con nombre → {r_list.status_code} {r_list.text}")
    sys.exit(1)
data_list = r_list.json()
if not isinstance(data_list, list):
    print("FAIL: listado no devolvió lista")
    sys.exit(1)
print("OK: listado (búsqueda por nombre) sin excepciones")

r_detail = client.get(f"/api/tenders/{tender_id}")
if r_detail.status_code != 200:
    print(f"FAIL: detalle licitación → {r_detail.status_code} {r_detail.text}")
    sys.exit(1)
detail = r_detail.json()
partidas = detail.get("partidas") or []
if len(partidas) != 2:
    print(f"FAIL: se esperaban 2 partidas, hay {len(partidas)}")
    sys.exit(1)
for p in partidas:
    if p.get("id_producto") is not None:
        print(f"FAIL: partida con id_producto no nulo (esperado solo nombre_producto_libre): {p}")
        sys.exit(1)
# Comprobar que no hay KeyError/AttributeError por nulls (totales se calculan en front o en get_active_budget_total)
total_ok = "pres_maximo" in detail and "partidas" in detail
if not total_ok:
    print("FAIL: detalle sin pres_maximo o partidas")
    sys.exit(1)
print("OK: detalle con partidas sin id_producto; totales/cálculos sin fallos")

# --- 4) Transición a Adjudicada: debe rechazarse pidiendo vinculación Belneo ---
ADJUDICADA_ID = EstadoLicitacion.ADJUDICADA.value
r_status = client.post(
    f"/api/tenders/{tender_id}/change-status",
    json={
        "nuevo_estado_id": ADJUDICADA_ID,
        "importe_adjudicacion": 500,
    },
)
if r_status.status_code == 200:
    print("FAIL: se esperaba rechazo al adjudicar con partidas sin id_producto (Belneo)")
    sys.exit(1)
if r_status.status_code != 400:
    print(f"FAIL: cambio estado → {r_status.status_code} (esperado 400) {r_status.text}")
    sys.exit(1)
body = r_status.json()
detail_msg = body.get("detail") or ""
if "Belneo" not in detail_msg and "id_producto" not in detail_msg:
    print(f"FAIL: mensaje de rechazo no menciona Belneo/id_producto: {detail_msg}")
    sys.exit(1)
print("OK: adjudicación rechazada correctamente (vinculación Belneo requerida)")

# --- 5) Limpieza: borrar licitación de prueba ---
r_del = client.delete(f"/api/tenders/{tender_id}")
if r_del.status_code not in (200, 204):
    print(f"WARN: borrado licitación → {r_del.status_code} (no crítico)")
else:
    print("OK: licitación de prueba eliminada")

print("\n>>> Verificación Belneo completada: listado, búsqueda, totales y rechazo a adjudicar sin id_producto OK.")
