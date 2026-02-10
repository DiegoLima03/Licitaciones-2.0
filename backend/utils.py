"""
Utilidades compartidas para el backend.
Migrado desde src/utils.py (sin dependencias de Streamlit).

Incluye ayudas de limpieza vitales para parsear precios y números desde Excel.
"""

from datetime import date, datetime
from typing import Any, List, Union


def get_clean_number(
    row: Any,
    col_name: str,
    df_columns: Union[List[str], Any],
) -> float:
    """
    Extrae un número de una fila de DataFrame de forma segura.
    Vital para parsear precios y cantidades en importación Excel.

    Soporta formatos europeos/americanos y cadenas con símbolo €.
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


def normalize_excel_columns(columns: Any) -> List[str]:
    """
    Normaliza nombres de columnas de un Excel (strip, string).
    Usado antes de buscar columnas por nombre en importación.
    """
    return [str(c).strip() for c in columns]


def fmt_num(valor: Any) -> str:
    """Convierte un valor numérico a formato español (punto miles, coma decimal)."""
    if valor is None or str(valor).strip() == "":
        return "0,00"
    try:
        val_float = float(valor)
        return f"{val_float:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
    except (ValueError, TypeError):
        return "0,00"


def fmt_date(valor: Union[str, date, datetime, None]) -> str:
    """Convierte fecha (ISO o date/datetime) a formato europeo DD/MM/YYYY."""
    if not valor:
        return ""
    try:
        if isinstance(valor, (date, datetime)):
            return valor.strftime("%d/%m/%Y")
        if isinstance(valor, str):
            valor_clean = valor.split("T")[0]
            dt = datetime.strptime(valor_clean, "%Y-%m-%d")
            return dt.strftime("%d/%m/%Y")
        return str(valor)
    except Exception:
        return str(valor)
