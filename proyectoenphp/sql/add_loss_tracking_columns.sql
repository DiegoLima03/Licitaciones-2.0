-- Guarda de forma estructurada la informacion de perdida/adjudicacion parcial
-- que hoy se esta dejando solo como texto en tbl_licitaciones.descripcion.
--
-- Compatible con entornos donde "ADD COLUMN IF NOT EXISTS" falla en ALTER TABLE.
-- Ejecuta cada bloque; si la columna ya existe, no hace cambios.

-- motivo_perdida
SET @sql := IF(
  EXISTS(
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_licitaciones'
      AND COLUMN_NAME = 'motivo_perdida'
  ),
  'SELECT ''motivo_perdida ya existe''',
  'ALTER TABLE tbl_licitaciones ADD COLUMN motivo_perdida TEXT NULL AFTER descripcion'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- competidor_ganador
SET @sql := IF(
  EXISTS(
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_licitaciones'
      AND COLUMN_NAME = 'competidor_ganador'
  ),
  'SELECT ''competidor_ganador ya existe''',
  'ALTER TABLE tbl_licitaciones ADD COLUMN competidor_ganador VARCHAR(255) NULL AFTER motivo_perdida'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- importe_perdida
SET @sql := IF(
  EXISTS(
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_licitaciones'
      AND COLUMN_NAME = 'importe_perdida'
  ),
  'SELECT ''importe_perdida ya existe''',
  'ALTER TABLE tbl_licitaciones ADD COLUMN importe_perdida DECIMAL(14,2) NULL AFTER competidor_ganador'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
