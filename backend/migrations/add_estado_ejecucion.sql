-- Añadir estado EJECUCIÓN (entre ADJUDICADA y TERMINADA) para flujo de máquina de estados.
-- Ejecutar en Supabase SQL Editor.

INSERT INTO public.tbl_estados (id_estado, nombre_estado)
SELECT 8, 'EJECUCIÓN'
WHERE NOT EXISTS (SELECT 1 FROM public.tbl_estados WHERE id_estado = 8);
