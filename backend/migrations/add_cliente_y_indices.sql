-- Mejoras de esquema: columna cliente en entregas e índices de rendimiento.
-- Ejecutar en Supabase SQL Editor después de unificar_tipo_licitacion.sql.

-- 1. Columna cliente en tbl_entregas (el API ya la acepta pero no existía)
ALTER TABLE tbl_entregas
ADD COLUMN IF NOT EXISTS cliente TEXT;

-- 2. Índices para consultas frecuentes por id_licitacion y id_producto
CREATE INDEX IF NOT EXISTS idx_entregas_id_licitacion
ON tbl_entregas(id_licitacion);

CREATE INDEX IF NOT EXISTS idx_licitaciones_detalle_id_licitacion
ON tbl_licitaciones_detalle(id_licitacion);

CREATE INDEX IF NOT EXISTS idx_licitaciones_real_id_licitacion
ON tbl_licitaciones_real(id_licitacion);

CREATE INDEX IF NOT EXISTS idx_precios_referencia_id_producto
ON tbl_precios_referencia(id_producto);
