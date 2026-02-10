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

def guardar_datos_importados(df, lic_id: int, client):
    """
    PASO 2: Recibe el DataFrame ya validado e inserta en Supabase.
    """
    try:
        count = 0
        total_rows = len(df)
        
        progress_text = "Guardando en base de datos..."
        my_bar = st.progress(0, text=progress_text)

        # Convertimos a lista de diccionarios para iterar
        records = df.to_dict('records')
        
        for index, row in enumerate(records):
            # Insertamos fila a fila (o podrías hacer upsert masivo si prefieres)
            client.table("tbl_licitaciones_detalle").insert({
                "id_licitacion": lic_id,
                "lote": row['lote'],
                "producto": row['producto'],
                "unidades": row['unidades'],
                "pvu": row['pvu'],
                "pcu": row['pcu'],
                "pmaxu": row['pmaxu'],
                "activo": row['activo']
            }).execute()
            
            count += 1
            if index % 5 == 0:
                my_bar.progress(min((index + 1) / total_rows, 1.0))
        
        my_bar.empty()
        return True, f"✅ Se han importado correctamente {count} partidas."
        
    except Exception as e:
        return False, f"Error guardando en BD: {str(e)}"