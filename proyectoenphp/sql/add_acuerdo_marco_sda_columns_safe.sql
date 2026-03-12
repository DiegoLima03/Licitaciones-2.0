-- Soporte AM/SDA como "carpetas" de licitaciones derivadas.
-- Script idempotente compatible con MySQL/MariaDB sin usar "ADD COLUMN IF NOT EXISTS".
--
-- Añade en tbl_licitaciones:
-- 1) id_licitacion_padre (INT NULL)
-- 2) tipo_procedimiento (VARCHAR(50) DEFAULT 'ORDINARIO')
-- 3) índice idx_licitacion_padre
--
-- Uso:
-- - Ejecutar una sola vez.
-- - Si no hay BD seleccionada o estás en information_schema, intenta usar "licitaciones".

SET @db_name := DATABASE();

-- Detecta tabla en BD actual y, si no está, prueba en "licitaciones".
SELECT COUNT(*) INTO @has_table_current
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = @db_name
  AND TABLE_NAME = 'tbl_licitaciones';

SELECT COUNT(*) INTO @has_table_licitaciones
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'licitaciones'
  AND TABLE_NAME = 'tbl_licitaciones';

SET @db_name := IF(
  @has_table_current > 0,
  @db_name,
  IF(@has_table_licitaciones > 0, 'licitaciones', @db_name)
);

SELECT COUNT(*) INTO @has_table_target
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = @db_name
  AND TABLE_NAME = 'tbl_licitaciones';

SET @target_table := CONCAT('`', REPLACE(@db_name, '`', '``'), '`.`tbl_licitaciones`');

-- 1) Columna id_licitacion_padre
SELECT COUNT(*) INTO @has_id_padre
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @db_name
  AND TABLE_NAME = 'tbl_licitaciones'
  AND COLUMN_NAME = 'id_licitacion_padre';

SET @sql_add_id_padre := IF(
  @has_table_target = 0,
  'SELECT ''ERROR: no se encontro tbl_licitaciones en la BD actual ni en licitaciones'' AS info',
  IF(
    @has_id_padre = 0,
    CONCAT('ALTER TABLE ', @target_table, ' ADD COLUMN id_licitacion_padre INT NULL DEFAULT NULL AFTER created_at'),
    'SELECT ''id_licitacion_padre ya existe'' AS info'
  )
);

PREPARE stmt_add_id_padre FROM @sql_add_id_padre;
EXECUTE stmt_add_id_padre;
DEALLOCATE PREPARE stmt_add_id_padre;

-- 2) Columna tipo_procedimiento
SELECT COUNT(*) INTO @has_tipo_proc
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @db_name
  AND TABLE_NAME = 'tbl_licitaciones'
  AND COLUMN_NAME = 'tipo_procedimiento';

SET @sql_add_tipo_proc := IF(
  @has_table_target = 0,
  'SELECT ''ERROR: no se encontro tbl_licitaciones en la BD actual ni en licitaciones'' AS info',
  IF(
    @has_tipo_proc = 0,
    CONCAT('ALTER TABLE ', @target_table, ' ADD COLUMN tipo_procedimiento VARCHAR(50) NULL DEFAULT ''ORDINARIO'' AFTER id_licitacion_padre'),
    'SELECT ''tipo_procedimiento ya existe'' AS info'
  )
);

PREPARE stmt_add_tipo_proc FROM @sql_add_tipo_proc;
EXECUTE stmt_add_tipo_proc;
DEALLOCATE PREPARE stmt_add_tipo_proc;

-- 3) Índice para búsquedas por padre
SELECT COUNT(*) INTO @has_idx_padre
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = @db_name
  AND TABLE_NAME = 'tbl_licitaciones'
  AND INDEX_NAME = 'idx_licitacion_padre';

SET @sql_add_idx_padre := IF(
  @has_table_target = 0,
  'SELECT ''ERROR: no se encontro tbl_licitaciones en la BD actual ni en licitaciones'' AS info',
  IF(
    @has_idx_padre = 0,
    CONCAT('ALTER TABLE ', @target_table, ' ADD INDEX idx_licitacion_padre (id_licitacion_padre)'),
    'SELECT ''idx_licitacion_padre ya existe'' AS info'
  )
);

PREPARE stmt_add_idx_padre FROM @sql_add_idx_padre;
EXECUTE stmt_add_idx_padre;
DEALLOCATE PREPARE stmt_add_idx_padre;

-- 4) Normalización básica de datos
SET @sql_normalize := IF(
  @has_table_target = 0,
  'SELECT ''ERROR: no se pudo ejecutar normalizacion porque tbl_licitaciones no existe'' AS info',
  CONCAT(
    'UPDATE ', @target_table, ' ',
    'SET tipo_procedimiento = ''ORDINARIO'' ',
    'WHERE tipo_procedimiento IS NULL OR TRIM(tipo_procedimiento) = '''''
  )
);

PREPARE stmt_normalize FROM @sql_normalize;
EXECUTE stmt_normalize;
DEALLOCATE PREPARE stmt_normalize;

-- Nota:
-- Valores esperados de tipo_procedimiento en la app:
-- ORDINARIO, ACUERDO_MARCO, SDA, CONTRATO_BASADO, ESPECIFICO_SDA
