-- Elimina organization_id para modo organización única.
-- MySQL 8.0+ (compatible con 8.4.x).

SET @old_foreign_key_checks = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- profiles
SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'profiles'
      AND COLUMN_NAME = 'organization_id'
  ),
  'ALTER TABLE profiles DROP COLUMN organization_id',
  'SELECT ''profiles.organization_id no existe'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- role_permissions
SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'role_permissions'
      AND COLUMN_NAME = 'organization_id'
  ),
  'ALTER TABLE role_permissions DROP COLUMN organization_id',
  'SELECT ''role_permissions.organization_id no existe'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tbl_entregas
SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_entregas'
      AND COLUMN_NAME = 'organization_id'
  ),
  'ALTER TABLE tbl_entregas DROP COLUMN organization_id',
  'SELECT ''tbl_entregas.organization_id no existe'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tbl_gastos_proyecto
SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_gastos_proyecto'
      AND COLUMN_NAME = 'organization_id'
  ),
  'ALTER TABLE tbl_gastos_proyecto DROP COLUMN organization_id',
  'SELECT ''tbl_gastos_proyecto.organization_id no existe'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tbl_licitaciones
SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_licitaciones'
      AND COLUMN_NAME = 'organization_id'
  ),
  'ALTER TABLE tbl_licitaciones DROP COLUMN organization_id',
  'SELECT ''tbl_licitaciones.organization_id no existe'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tbl_licitaciones_detalle
SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_licitaciones_detalle'
      AND COLUMN_NAME = 'organization_id'
  ),
  'ALTER TABLE tbl_licitaciones_detalle DROP COLUMN organization_id',
  'SELECT ''tbl_licitaciones_detalle.organization_id no existe'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tbl_licitaciones_real
SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_licitaciones_real'
      AND COLUMN_NAME = 'organization_id'
  ),
  'ALTER TABLE tbl_licitaciones_real DROP COLUMN organization_id',
  'SELECT ''tbl_licitaciones_real.organization_id no existe'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tbl_precios_referencia
SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_precios_referencia'
      AND COLUMN_NAME = 'organization_id'
  ),
  'ALTER TABLE tbl_precios_referencia DROP COLUMN organization_id',
  'SELECT ''tbl_precios_referencia.organization_id no existe'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- tbl_productos_old
SET @sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_productos_old'
      AND COLUMN_NAME = 'organization_id'
  ),
  'ALTER TABLE tbl_productos_old DROP COLUMN organization_id',
  'SELECT ''tbl_productos_old.organization_id no existe'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = @old_foreign_key_checks;
