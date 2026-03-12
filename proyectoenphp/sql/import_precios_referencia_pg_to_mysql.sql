-- Importa registros de albaranes/precios (compra y venta) desde un dump tipo Postgres
-- a tbl_precios_referencia de MySQL.
--
-- Origen (Postgres): usa id UUID + organization_id.
-- Destino (MySQL): id INT AUTO_INCREMENT, sin organization_id.
--
-- Tu tabla destino actual:
--   tbl_precios_referencia(
--     id INT AUTO_INCREMENT,
--     id_producto INT,
--     producto VARCHAR(255),
--     pvu DECIMAL(15,4),
--     pcu DECIMAL(15,4),
--     unidades DECIMAL(15,4),
--     proveedor VARCHAR(255),
--     notas TEXT,
--     fecha_presupuesto DATE
--   )
--
-- PASOS:
-- 1) Ejecuta este script.
-- 2) Copia tu INSERT de Postgres y cambia SOLO el encabezado por:
--      INSERT INTO stg_precios_referencia_pg
--      (id_uuid, pvu, pcu, unidades, id_producto, fecha_presupuesto, organization_id, producto, proveedor, notas)
--      VALUES (...)
-- 3) Ejecuta la parte "Cargar staging -> tabla final".
--
-- Ejemplo de reemplazo de cabecera:
--   de: INSERT INTO "public"."tbl_precios_referencia" (...)
--   a : INSERT INTO stg_precios_referencia_pg (...)

-- 0) Staging (sí conserva UUID y organization_id solo para importar)
CREATE TABLE IF NOT EXISTS stg_precios_referencia_pg (
  id_uuid CHAR(36) NULL,
  pvu DECIMAL(15,4) NULL,
  pcu DECIMAL(15,4) NULL,
  unidades DECIMAL(15,4) NULL,
  id_producto INT NOT NULL,
  fecha_presupuesto DATE NULL,
  organization_id CHAR(36) NULL,
  producto VARCHAR(255) NULL,
  proveedor VARCHAR(255) NULL,
  notas TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_stg_pr_id_producto (id_producto),
  KEY idx_stg_pr_fecha (fecha_presupuesto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1) Cargar staging -> tabla final (ignorando UUID y organization_id)
--    y validando FK de producto contra tbl_productos_old.
INSERT INTO tbl_precios_referencia
  (id_producto, producto, pvu, pcu, unidades, proveedor, notas, fecha_presupuesto)
SELECT
  s.id_producto,
  NULLIF(TRIM(COALESCE(s.producto, '')), ''),
  s.pvu,
  s.pcu,
  s.unidades,
  NULLIF(TRIM(COALESCE(s.proveedor, '')), ''),
  NULLIF(TRIM(COALESCE(s.notas, '')), ''),
  s.fecha_presupuesto
FROM stg_precios_referencia_pg s
INNER JOIN tbl_productos_old p
  ON p.id = s.id_producto;

-- 2) Revisión rápida
SELECT
  COUNT(*) AS rows_staging,
  (
    SELECT COUNT(*)
    FROM stg_precios_referencia_pg s
    INNER JOIN tbl_productos_old p ON p.id = s.id_producto
  ) AS rows_validas_fk_producto,
  (
    SELECT COUNT(*)
    FROM stg_precios_referencia_pg s
    LEFT JOIN tbl_productos_old p ON p.id = s.id_producto
    WHERE p.id IS NULL
  ) AS rows_descartadas_sin_producto;

-- 3) Si todo está bien y quieres limpiar staging:
-- TRUNCATE TABLE stg_precios_referencia_pg;
