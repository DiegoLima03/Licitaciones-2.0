### src/logic/excel_import.py
import pandas as pd
import streamlit as st
from src.utils import get_clean_number

def analizar_excel_licitacion(uploaded_file, tipo_id: int):
    """
    PASO 1: Lee el Excel, normaliza columnas y prepara los datos en memoria.
    Retorna: (True, DataFrame) o (False, Error)
    """
    try:
        # Leemos el archivo
        df = pd.read_excel(uploaded_file)
        df.columns = [str(c).strip() for c in df.columns]
        
        # 1. Validar columna Producto
        col_prod = 'Planta'
        if col_prod not in df.columns and 'Producto' in df.columns:
            col_prod = 'Producto'

        if col_prod not in df.columns:
            return False, f"Error: No se encuentra la columna 'Producto' o 'Planta' en el Excel."

        # 2. Validar columna Lote (Opcional)
        col_lote = None
        possible_lote_cols = ['Lote', 'lote', 'Zona', 'zona', 'Grupo']
        for cand in possible_lote_cols:
            if cand in df.columns:
                col_lote = cand
                break

        # 3. Construir lista de diccionarios limpia
        data_clean = []
        
        for index, row in df.iterrows():
            prod = str(row[col_prod]).strip()
            if not prod or prod.lower() == 'nan': continue

            # Lógica de Lote
            val_lote = "General"
            if col_lote:
                raw_lote = str(row[col_lote]).strip()
                if raw_lote and raw_lote.lower() != 'nan':
                    val_lote = raw_lote
            
            # Limpieza de números
            uds = None if tipo_id == 2 else get_clean_number(row, 'N.º Unidades previstas', df.columns)
            p_max = get_clean_number(row, 'Precio Máximo', df.columns)
            pvu = get_clean_number(row, 'Precio Venta Unitario', df.columns)
            pcu = get_clean_number(row, 'Precio coste unitario', df.columns)

            data_clean.append({
                "lote": val_lote,
                "producto": prod,
                "unidades": uds,
                "pvu": pvu,
                "pcu": pcu,
                "pmaxu": p_max,
                "activo": True
            })

        if not data_clean:
            return False, "El Excel parece estar vacío o no tiene líneas válidas."

        # Devolvemos un DataFrame limpio listo para revisión
        return True, pd.DataFrame(data_clean)

    except Exception as e:
        return False, f"Error leyendo archivo: {str(e)}"

def guardar_datos_importados(df, lic_id: int, client, tipo_id: int, dto_global: float):
    """
    PASO 2: Recibe el DataFrame ya validado e inserta en Supabase.
    Para licitaciones Tipo 2, recalcula el PVU aplicando el descuento global.
    """
    try:
        count = 0
        total_rows = len(df)
        
        progress_text = f"Procesando y guardando {total_rows} líneas..."
        my_bar = st.progress(0, text=progress_text)

        # Convertimos a lista de diccionarios para iterar
        records = df.to_dict('records')
        to_insert = []
        
        for index, row in enumerate(records):
            val_pmax = row.get('pmaxu')
            val_pvu = row.get('pvu')

            # Para Tipo 2, recalculamos el PVU ignorando el del Excel
            if tipo_id == 2 and val_pmax is not None:
                val_pvu = float(val_pmax) * (1 - (dto_global / 100))

            to_insert.append({
                "id_licitacion": lic_id,
                "lote": row['lote'],
                "producto": row['producto'],
                "unidades": row['unidades'],
                "pvu": val_pvu,
                "pcu": row.get('pcu'),
                "pmaxu": val_pmax,
                "activo": row['activo']
            })
            
            count += 1
            if index % 5 == 0 or index == total_rows - 1:
                my_bar.progress(min((index + 1) / total_rows, 1.0), text=f"Procesando {index+1}/{total_rows}")

        # Inserción masiva para mayor eficiencia
        if to_insert:
            client.table("tbl_licitaciones_detalle").insert(to_insert).execute()
        
        my_bar.empty()
        return True, f"✅ Se han importado correctamente {count} partidas."
        
    except Exception as e:
        return False, f"Error guardando en BD: {str(e)}"