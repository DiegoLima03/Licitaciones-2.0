### src/logic/deliveries.py
import pandas as pd
import streamlit as st
from datetime import datetime

def guardar_entrega_completa(client, lic_id, datos_cabecera, df_lineas, mapa_ids):
    """
    Guarda una entrega (Cabecera simplificada) y sus líneas (Con proveedor individual).
    """
    try:
        # 1. Insertar Cabecera (tbl_entregas) - YA NO LLEVA PROVEEDOR
        res_cab = client.table("tbl_entregas").insert({
            "id_licitacion": lic_id,
            "fecha_entrega": str(datos_cabecera['fecha']),
            "codigo_albaran": datos_cabecera['albaran'],
            "observaciones": datos_cabecera['notas']
        }).execute()
        
        if not res_cab.data:
            return False, "Error creando la cabecera de la entrega."
            
        new_id_entrega = res_cab.data[0]['id_entrega']
        
        # 2. Preparar Líneas (tbl_licitaciones_real)
        lineas_a_insertar = []
        records = df_lineas.to_dict('records')
        
        for r in records:
            nombre_prod = r.get('Concepto / Partida')
            if not nombre_prod: continue
            
            qty = float(r.get('Cantidad', 0) or 0)
            cost = float(r.get('Coste Unit.', 0) or 0)
            id_detalle = mapa_ids.get(nombre_prod)
            
            articulo_final = nombre_prod
            if id_detalle and " - " in nombre_prod:
                articulo_final = nombre_prod.split(" - ", 1)[1]
            
            # --- CAMBIO: LEEMOS EL PROVEEDOR DE LA LÍNEA ---
            prov_linea = str(r.get('Proveedor', '')).strip()
            
            lineas_a_insertar.append({
                "id_licitacion": lic_id,
                "id_entrega": new_id_entrega,
                "id_detalle": id_detalle,
                "fecha_entrega": str(datos_cabecera['fecha']),
                "articulo": articulo_final,
                "cantidad": qty,
                "pcu": cost,
                "proveedor": prov_linea, # <--- Nuevo campo individual
                "estado": "EN ESPERA",
                "cobrado": False
            })
            
        # 3. Insertar Líneas
        if lineas_a_insertar:
            client.table("tbl_licitaciones_real").insert(lineas_a_insertar).execute()
            return True, f"Documento guardado con {len(lineas_a_insertar)} líneas."
        else:
            # Rollback manual si no hay líneas
            client.table("tbl_entregas").delete().eq("id_entrega", new_id_entrega).execute()
            return False, "El documento no tenía líneas válidas."

    except Exception as e:
        return False, f"Error del sistema: {str(e)}"

def eliminar_entrega_completa(client, id_entrega):
    """Borra la entrega y sus líneas (Cascade)."""
    try:
        client.table("tbl_entregas").delete().eq("id_entrega", id_entrega).execute()
        return True
    except Exception as e:
        print(e)
        return False

def actualizar_entrega_completa(client, id_entrega, lic_id, datos_cabecera, df_lineas, mapa_ids):
    """
    Actualiza una entrega existente:
    1. Actualiza cabecera.
    2. Borra líneas antiguas.
    3. Inserta líneas nuevas (reemplazo).
    """
    try:
        # 1. Actualizar Cabecera
        client.table("tbl_entregas").update({
            "fecha_entrega": str(datos_cabecera['fecha']),
            "codigo_albaran": datos_cabecera['albaran'],
            "observaciones": datos_cabecera['notas']
        }).eq("id_entrega", id_entrega).execute()
        
        # 2. Borrar líneas antiguas (Limpieza para evitar duplicados/inconsistencias)
        client.table("tbl_licitaciones_real").delete().eq("id_entrega", id_entrega).execute()
        
        # 3. Insertar líneas nuevas (Reutilizando lógica de inserción)
        lineas_a_insertar = []
        records = df_lineas.to_dict('records')
        
        for r in records:
            nombre_prod = r.get('Concepto / Partida')
            if not nombre_prod: continue
            
            qty = float(r.get('Cantidad', 0) or 0)
            cost = float(r.get('Coste Unit.', 0) or 0)
            id_detalle = mapa_ids.get(nombre_prod)
            
            articulo_final = nombre_prod
            if id_detalle and " - " in nombre_prod:
                articulo_final = nombre_prod.split(" - ", 1)[1]
            
            prov_linea = str(r.get('Proveedor', '')).strip()
            
            lineas_a_insertar.append({
                "id_licitacion": lic_id,
                "id_entrega": id_entrega, # ID existente
                "id_detalle": id_detalle,
                "fecha_entrega": str(datos_cabecera['fecha']),
                "articulo": articulo_final,
                "cantidad": qty,
                "pcu": cost,
                "proveedor": prov_linea,
                "estado": "EN ESPERA",
                "cobrado": False
            })
            
        if lineas_a_insertar:
            client.table("tbl_licitaciones_real").insert(lineas_a_insertar).execute()
            return True, f"Entrega actualizada con {len(lineas_a_insertar)} líneas."
        else:
            return True, "Entrega actualizada (sin líneas)."

    except Exception as e:
        return False, f"Error actualizando: {str(e)}"

def sincronizar_lineas_entrega(client, id_licitacion, id_entrega, df_edited):
    """
    Sincroniza las líneas del editor con la BD (Insert/Update/Delete).
    Comparando el estado actual en BD con el DataFrame editado.
    """
    try:
        # 1. Obtener IDs actuales en BD para detectar borrados
        rows_db = client.table("tbl_licitaciones_real").select("id_real").eq("id_entrega", id_entrega).execute().data
        ids_db = {r['id_real'] for r in rows_db}
        
        # 2. Procesar DataFrame
        records = df_edited.to_dict('records')
        to_insert = []
        to_update = []
        ids_en_editor = set()
        
        # Cacheamos la fecha de entrega por si hay que insertar líneas nuevas
        fecha_entrega = None
        
        for r in records:
            # Detectar si es nuevo (ID vacío, NaN o None)
            id_r = r.get('id_real')
            es_nuevo = pd.isna(id_r) or id_r == "" or id_r is None
            
            if not es_nuevo:
                ids_en_editor.add(int(id_r))
            
            # Construir objeto base
            obj = {
                "id_licitacion": id_licitacion,
                "id_entrega": id_entrega,
                "articulo": str(r.get('articulo', '')),
                "proveedor": str(r.get('proveedor', '')),
                "cantidad": float(r.get('cantidad', 0) or 0),
                "pcu": float(r.get('pcu', 0) or 0),
                "estado": r.get('estado', 'EN ESPERA'),
                "cobrado": bool(r.get('cobrado', False))
            }
            
            if es_nuevo:
                if not fecha_entrega:
                    res = client.table("tbl_entregas").select("fecha_entrega").eq("id_entrega", id_entrega).single().execute()
                    fecha_entrega = res.data.get('fecha_entrega') if res.data else datetime.now().strftime("%Y-%m-%d")
                obj['fecha_entrega'] = fecha_entrega
                to_insert.append(obj)
            else:
                obj['id_real'] = int(id_r)
                to_update.append(obj)
        
        # 3. Ejecutar Operaciones
        
        # DELETE: IDs que estaban en BD pero ya no están en el editor
        ids_borrar = list(ids_db - ids_en_editor)
        if ids_borrar:
            client.table("tbl_licitaciones_real").delete().in_("id_real", ids_borrar).execute()
            
        # UPDATE: Actualizar existentes
        if to_update:
            client.table("tbl_licitaciones_real").upsert(to_update).execute()
            
        # INSERT: Crear nuevas
        if to_insert:
            client.table("tbl_licitaciones_real").insert(to_insert).execute()
            
        return True, f"Sincronizado: {len(to_insert)} nuevas, {len(to_update)} editadas, {len(ids_borrar)} borradas."
        
    except Exception as e:
        return False, f"Error al sincronizar: {e}"