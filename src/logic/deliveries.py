### src/logic/deliveries.py
import pandas as pd
import streamlit as st

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