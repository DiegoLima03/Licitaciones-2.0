"""
Utilidades compartidas para el backend.
Migrado desde src/utils.py (sin dependencias de Streamlit).
"""

from typing import Any, List, Union


def get_clean_number(
    row: Any,
    col_name: str,
    df_columns: Union[List[str], Any],
) -> float:
    """
    Extrae un número de una fila de DataFrame de forma segura.

    Soporta formatos europeos/americanos y cadenas con símbolo €.
    Usado en la importación de Excel (backend/routers/import.py).
    """
    if col_name not in df_columns:
        return 0.0
    val = row[col_name]
    s_val = str(val).strip()
    if not s_val or s_val.lower() == "nan":
        return 0.0

    if isinstance(val, (int, float)):
        return float(val)
    try:
        s_val = s_val.replace("€", "").strip()
        if "," in s_val and "." in s_val:
            if s_val.find(",") < s_val.find("."):
                s_val = s_val.replace(",", "")
            else:
                s_val = s_val.replace(".", "").replace(",", ".")
        elif "," in s_val:
            s_val = s_val.replace(",", ".")
        return float(s_val)
    except (ValueError, TypeError):
        return 0.0
