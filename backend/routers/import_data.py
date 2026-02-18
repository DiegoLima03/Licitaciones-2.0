"""
Importación de Excel a tbl_licitaciones_detalle y tbl_precios_referencia.
Basado estrictamente en src/logic/excel_import.py.

- Recibe UploadFile, usa pandas (+ openpyxl para .xlsx) para leer el Excel.
- Aplica la misma lógica de limpieza que analizar_excel_licitacion
  (normalización de columnas, limpieza de NaN, get_clean_number para precios).
- Inserta los datos limpios en tbl_licitaciones_detalle o tbl_precios_referencia vía Supabase.
"""

import io
from datetime import datetime
from typing import Any, Dict, List, Optional, Tuple

import pandas as pd
from fastapi import APIRouter, File, HTTPException, UploadFile, status

from backend.config import supabase_client
from backend.utils import get_clean_number, normalize_excel_columns

# Para .xlsx pandas usa openpyxl; asegurar que esté instalado: pip install openpyxl


router = APIRouter(prefix="/import", tags=["import"])


def _find_column(cols: List[str], alternatives: List[str]) -> Optional[str]:
    """Devuelve la primera columna que coincida (case-insensitive) con alguna alternativa."""
    cols_lower = {c.strip().lower(): c for c in cols}
    for alt in alternatives:
        key = alt.strip().lower()
        if key in cols_lower:
            return cols_lower[key]
    return None


def _find_column_fuzzy(
    cols: List[str], substrings: List[str], exclude_substr: Optional[str] = None
) -> Optional[str]:
    """
    Busca una columna que contenga todos los substrings dados (útil con encoding roto).
    Si exclude_substr, se ignora la columna que lo contenga (ej. excluir "ref" para no coger "Ref. Artículo").
    """
    for c in cols:
        c_low = c.lower()
        if exclude_substr and exclude_substr.lower() in c_low:
            continue
        if all(s.lower() in c_low for s in substrings if s):
            return c
    return None


def _analizar_excel_albaranes(file_content: bytes) -> Tuple[bool, Any]:
    """
    Lee Excel de albaranes de compra (formato típico: Fecha, Nº Albarán, Ref. Artículo, Artículo, Cantidad, Precio).
    Retorna (True, DataFrame con columnas normalizadas) o (False, str error).
    """
    try:
        buf = io.BytesIO(file_content)
        try:
            df = pd.read_excel(buf, engine="openpyxl")
        except ValueError:
            df = pd.read_excel(io.BytesIO(file_content))
        df.columns = normalize_excel_columns(df.columns)
        cols = list(df.columns)

        col_articulo = _find_column(cols, ["Artículo", "Articulo", "Artculo", "Producto", "Planta"])
        if not col_articulo:
            # Artículo = nombre producto; excluir "ref" para no coger "Ref. Artículo"
            col_articulo = _find_column_fuzzy(cols, ["art", "culo"], exclude_substr="ref")
        col_ref = _find_column(cols, ["Ref. Artículo", "Ref. Articulo", "Ref Articulo", "Referencia", "Ref"])
        if not col_ref:
            col_ref = _find_column_fuzzy(cols, ["ref", "art"])  # Ref. Artículo
        col_cantidad = _find_column(cols, ["Cantidad", "Unidades", "N.º Unidades", "N Unidades"])
        col_precio = _find_column(cols, ["Precio", "PCU", "Precio coste unitario", "Precio Coste"])
        col_fecha = _find_column(cols, ["Fecha", "Fecha Albarán", "Fecha presupuesto"])
        col_albaran = _find_column(cols, ["Nº Albarán", "N Albarán", "N. Albarán", "Albarán", "N Albaran"])
        if not col_albaran:
            col_albaran = _find_column_fuzzy(cols, ["albar"])

        if not col_articulo and not col_ref:
            return False, "Se requiere la columna 'Artículo' o 'Ref. Artículo' (o 'Producto'/'Referencia')."

        if not col_precio:
            return False, "Se requiere la columna 'Precio'."

        data_clean: List[dict[str, Any]] = []

        for _idx, row in df.iterrows():
            art = str(row.get(col_articulo or "", "")).strip() if col_articulo else ""
            ref = str(row.get(col_ref or "", "")).strip() if col_ref else ""
            if (not art or art.lower() == "nan") and (not ref or ref.lower() == "nan"):
                continue

            precio_val = get_clean_number(row, col_precio, cols) if col_precio else 0.0
            if precio_val <= 0:
                continue

            cantidad_val = get_clean_number(row, col_cantidad, cols) if col_cantidad else None
            if cantidad_val == 0.0:
                cantidad_val = None

            fecha_val = None
            if col_fecha:
                v = row.get(col_fecha)
                if pd.notna(v) and v:
                    try:
                        if isinstance(v, datetime):
                            fecha_val = v.strftime("%Y-%m-%d")
                        elif isinstance(v, str) and len(v) >= 10:
                            fecha_val = v[:10]
                        else:
                            fecha_val = str(v)[:10]
                    except Exception:
                        pass

            albaran_val = str(row.get(col_albaran or "", "")).strip() if col_albaran else None
            if albaran_val and albaran_val.lower() == "nan":
                albaran_val = None

            data_clean.append({
                "articulo": art or None,
                "ref_articulo": ref or None,
                "cantidad": cantidad_val,
                "precio": precio_val,
                "fecha": fecha_val,
                "albaran": albaran_val,
            })

        if not data_clean:
            return False, "El Excel no contiene líneas válidas (producto + precio > 0)."
        return True, pd.DataFrame(data_clean)
    except Exception as e:
        return False, f"Error leyendo archivo: {e!s}"


def _analizar_excel_licitacion(
    file_content: bytes,
    tipo_id: int,
) -> Tuple[bool, Any]:
    """
    Lógica migrada de src/logic/excel_import.py::analizar_excel_licitacion.
    Lee el Excel, normaliza columnas, aplica get_clean_number a precios/unidades.
    Retorna (True, DataFrame) o (False, str error).
    """
    try:
        buf = io.BytesIO(file_content)
        try:
            df = pd.read_excel(buf, engine="openpyxl")
        except ValueError:
            df = pd.read_excel(io.BytesIO(file_content))
        df.columns = normalize_excel_columns(df.columns)

        col_prod = "Planta"
        if col_prod not in df.columns and "Producto" in df.columns:
            col_prod = "Producto"
        if col_prod not in df.columns:
            return False, "No se encuentra la columna 'Producto' o 'Planta' en el Excel."

        col_lote = None
        for cand in ["Lote", "lote", "Zona", "zona", "Grupo"]:
            if cand in df.columns:
                col_lote = cand
                break

        data_clean: List[dict[str, Any]] = []
        columns_list = list(df.columns)

        for _index, row in df.iterrows():
            prod = str(row[col_prod]).strip()
            if not prod or prod.lower() == "nan":
                continue
            val_lote = "General"
            if col_lote:
                raw_lote = str(row[col_lote]).strip()
                if raw_lote and raw_lote.lower() != "nan":
                    val_lote = raw_lote

            uds = (
                None
                if tipo_id == 2
                else get_clean_number(row, "N.º Unidades previstas", columns_list)
            )
            p_max = get_clean_number(row, "Precio Máximo", columns_list)
            pvu = get_clean_number(row, "Precio Venta Unitario", columns_list)
            pcu = get_clean_number(row, "Precio coste unitario", columns_list)

            data_clean.append({
                "lote": val_lote,
                "producto": prod,
                "unidades": uds,
                "pvu": pvu,
                "pcu": pcu,
                "pmaxu": p_max,
                "activo": True,
            })

        if not data_clean:
            return False, "El Excel parece estar vacío o no tiene líneas válidas."
        return True, pd.DataFrame(data_clean)
    except Exception as e:
        return False, f"Error leyendo archivo: {e!s}"


@router.post("/excel/{licitacion_id}", status_code=status.HTTP_201_CREATED)
def import_excel(
    licitacion_id: int,
    file: UploadFile = File(...),
    tipo_id: int = 1,
) -> dict:
    """
    POST /import/excel/{licitacion_id}

    - Recibe un archivo Excel (UploadFile).
    - Usa Pandas para leerlo y aplica la misma limpieza que analizar_excel_licitacion.
    - Inserta las partidas en tbl_licitaciones_detalle.

    tipo_id: 1 = desglose con unidades, 2 = alzado (unidades omitidas).
    """
    if not file.filename or not file.filename.lower().endswith((".xlsx", ".xls")):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Se requiere un archivo Excel (.xlsx o .xls).",
        )

    content = file.file.read()
    ok, result = _analizar_excel_licitacion(content, tipo_id)
    if not ok:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=str(result),
        )

    df: pd.DataFrame = result
    records = df.to_dict("records")
    count = 0
    try:
        for row in records:
            supabase_client.table("tbl_licitaciones_detalle").insert({
                "id_licitacion": licitacion_id,
                "lote": row["lote"],
                "producto": row["producto"],
                "unidades": row["unidades"],
                "pvu": row["pvu"],
                "pcu": row["pcu"],
                "pmaxu": row["pmaxu"],
                "activo": row["activo"],
            }).execute()
            count += 1
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error guardando en BD: {e!s}",
        ) from e

    return {
        "message": f"Se han importado correctamente {count} partidas.",
        "licitacion_id": licitacion_id,
        "rows_imported": count,
    }


def _build_producto_maps() -> Tuple[Dict[str, int], Dict[str, int]]:
    """
    Carga productos y devuelve mapas: referencia -> id_producto, nombre -> id_producto.
    Prioridad: referencia (exacto), luego nombre (strip, case-insensitive para búsqueda).
    """
    resp = (
        supabase_client.table("tbl_productos")
        .select("id, nombre, referencia")
        .execute()
    )
    ref_map: Dict[str, int] = {}
    nom_map: Dict[str, int] = {}
    for r in (resp.data or []):
        pid = int(r["id"]) if r.get("id") is not None else None
        if not pid:
            continue
        ref = (r.get("referencia") or "").strip()
        if ref:
            ref_map[ref] = pid
        nom = (r.get("nombre") or "").strip()
        if nom and str(nom).lower() != "null":
            nom_map[nom] = pid
            nom_map[nom.lower()] = pid  # búsqueda case-insensitive
    return ref_map, nom_map


@router.post("/precios-referencia", status_code=status.HTTP_201_CREATED)
def import_precios_referencia(
    file: UploadFile = File(...),
) -> dict:
    """
    POST /import/precios-referencia

    Importa albaranes de compra (Excel) como líneas de precios de referencia.
    Formato esperado: Fecha, Nº Albarán, Ref. Artículo, Artículo, Cantidad, Precio.
    - Se busca id_producto por "Ref. Artículo" (referencia) o "Artículo" (nombre).
    - PCU = Precio; Unidades = Cantidad; fecha_presupuesto = Fecha; proveedor/notas = Nº Albarán.
    - Las filas sin producto coincidente se omiten y se reportan.
    """
    if not file.filename or not file.filename.lower().endswith((".xlsx", ".xls")):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Se requiere un archivo Excel (.xlsx o .xls).",
        )

    content = file.file.read()
    ok, result = _analizar_excel_albaranes(content)
    if not ok:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=str(result),
        )

    df: pd.DataFrame = result
    ref_map, nom_map = _build_producto_maps()
    records = df.to_dict("records")

    count = 0
    skipped: List[dict] = []

    try:
        for row in records:
            id_producto = None
            ref_val = (row.get("ref_articulo") or "").strip()
            art_val = (row.get("articulo") or "").strip()

            if ref_val and ref_val in ref_map:
                id_producto = ref_map[ref_val]
            elif art_val:
                id_producto = nom_map.get(art_val) or nom_map.get(art_val.lower())

            if not id_producto:
                skipped.append({
                    "articulo": art_val or ref_val or "—",
                    "precio": row.get("precio"),
                })
                continue

            prod_resp = (
                supabase_client.table("tbl_productos")
                .select("nombre, organization_id")
                .eq("id", id_producto)
                .limit(1)
                .execute()
            )
            product_nombre = ""
            org_id = None
            if prod_resp.data and len(prod_resp.data) > 0:
                p0 = prod_resp.data[0]
                product_nombre = (p0.get("nombre") or "").strip()
                org_id = p0.get("organization_id")

            if not org_id:
                skipped.append({"articulo": art_val or ref_val or "—", "precio": row.get("precio")})
                continue

            insert_row = {
                "id_producto": id_producto,
                "producto": product_nombre or "",
                "organization_id": org_id,
                "pvu": None,
                "pcu": float(row.get("precio", 0)),
                "unidades": row.get("cantidad"),
                "proveedor": (row.get("albaran") or "").strip() or None,
                "notas": None,
                "fecha_presupuesto": (row.get("fecha") or "").strip() or None,
            }

            supabase_client.table("tbl_precios_referencia").insert(insert_row).execute()
            count += 1

    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Error guardando en BD: {e!s}",
        ) from e

    return {
        "message": f"Se han importado {count} líneas de precios de referencia."
        + (f" Se omitieron {len(skipped)} líneas (producto no encontrado)." if skipped else ""),
        "rows_imported": count,
        "rows_skipped": len(skipped),
        "skipped_details": skipped[:50],  # máx 50 para no saturar la respuesta
    }
