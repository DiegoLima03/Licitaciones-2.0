"""
Importación de Excel a tbl_licitaciones_detalle.
Migrado desde src/logic/excel_import.py.
Rutas bajo prefijo /import (p. ej. POST /import/excel/{licitacion_id}).
"""

import io
from typing import Any, List, Tuple

import pandas as pd
from fastapi import APIRouter, File, HTTPException, UploadFile, status

from backend.config import supabase_client
from backend.utils import get_clean_number


router = APIRouter(prefix="/import", tags=["import"])


def _analizar_excel_licitacion(
    file_content: bytes,
    tipo_id: int,
) -> Tuple[bool, Any]:
    """
    Lee el Excel, normaliza columnas y prepara datos en memoria.
    Retorna (True, DataFrame) o (False, mensaje_error).
    """
    try:
        df = pd.read_excel(io.BytesIO(file_content))
        df.columns = [str(c).strip() for c in df.columns]

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
    tipo_id: int = 1,  # 1 = desglose con unidades, 2 = alzado (unidades omitidas)
) -> dict:
    """
    Sube un Excel, lo limpia con la lógica de analizar_excel_licitacion
    e inserta las filas en tbl_licitaciones_detalle.

    POST /import/excel/{licitacion_id}
    tipo_id: 1 = desglose completo, 2 = unidades omitidas (alzado).
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
