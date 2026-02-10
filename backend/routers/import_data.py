"""
Importación de Excel a tbl_licitaciones_detalle.
Basado estrictamente en src/logic/excel_import.py.

- Recibe UploadFile, usa pandas (+ openpyxl para .xlsx) para leer el Excel.
- Aplica la misma lógica de limpieza que analizar_excel_licitacion
  (normalización de columnas, limpieza de NaN, get_clean_number para precios).
- Inserta los datos limpios en tbl_licitaciones_detalle vía Supabase.
"""

import io
from typing import Any, List, Tuple

import pandas as pd
from fastapi import APIRouter, File, HTTPException, UploadFile, status

from backend.config import supabase_client
from backend.utils import get_clean_number, normalize_excel_columns

# Para .xlsx pandas usa openpyxl; asegurar que esté instalado: pip install openpyxl


router = APIRouter(prefix="/import", tags=["import"])


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
