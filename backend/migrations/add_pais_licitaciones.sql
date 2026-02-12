-- Añadir columna pais a tbl_licitaciones (España | Portugal).
-- Ejecutar en Supabase SQL Editor si la tabla no tiene aún la columna.

ALTER TABLE tbl_licitaciones
ADD COLUMN IF NOT EXISTS pais TEXT;

-- Opcional: valor por defecto para filas existentes
UPDATE tbl_licitaciones SET pais = 'España' WHERE pais IS NULL;

-- Opcional: restricción para solo permitir España o Portugal
-- ALTER TABLE tbl_licitaciones ADD CONSTRAINT chk_pais CHECK (pais IN ('España', 'Portugal'));
