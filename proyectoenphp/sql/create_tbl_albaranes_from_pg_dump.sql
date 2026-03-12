-- Crea tbl_albaranes desde cero e importa datos desde un dump estilo Postgres.
-- Este script permite pegar el INSERT original:
--   INSERT INTO "public"."tbl_precios_referencia" (...)
-- sin tocar columnas/valores, usando una tabla puente compatible.
--
-- Uso recomendado (en este orden, misma sesión):
-- 1) Ejecuta TODO este archivo.
-- 2) Pega tu INSERT de Postgres tal cual (el que empieza por "public"."tbl_precios_referencia").
-- 3) Ejecuta la sección "CARGA A TABLA FINAL".

-- ---------------------------------------------------------------------------
-- 0) Compatibilidad con identificadores en comillas dobles ("public"."tabla")
-- ---------------------------------------------------------------------------
SET SESSION sql_mode = CONCAT_WS(',', REPLACE(@@SESSION.sql_mode, 'ANSI_QUOTES', ''), 'ANSI_QUOTES');

-- ---------------------------------------------------------------------------
-- 1) Tabla final en tu BD actual: tbl_albaranes
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS tbl_albaranes;

CREATE TABLE tbl_albaranes (
  id_albaran BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_origen_uuid CHAR(36) NULL,
  id_producto INT NOT NULL,
  producto VARCHAR(255) NULL,
  proveedor VARCHAR(255) NULL,
  unidades DECIMAL(15,4) NULL,
  pvu DECIMAL(15,4) NULL,
  pcu DECIMAL(15,4) NULL,
  tipo_albaran ENUM('VENTA','COMPRA','MIXTO','SIN_PRECIO') NOT NULL DEFAULT 'SIN_PRECIO',
  fecha_albaran DATE NULL,
  notas TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_albaran),
  UNIQUE KEY uk_albaranes_origen_uuid (id_origen_uuid),
  KEY idx_albaranes_id_producto (id_producto),
  KEY idx_albaranes_fecha (fecha_albaran),
  KEY idx_albaranes_tipo (tipo_albaran),
  CONSTRAINT fk_albaranes_producto
    FOREIGN KEY (id_producto) REFERENCES tbl_productos_old (id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2) Tabla puente para pegar el INSERT de Postgres SIN CAMBIOS
-- ---------------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS "public"
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

DROP TABLE IF EXISTS "public"."tbl_precios_referencia";

CREATE TABLE "public"."tbl_precios_referencia" (
  "id" CHAR(36) NOT NULL,
  "pvu" DECIMAL(15,4) NULL,
  "pcu" DECIMAL(15,4) NULL,
  "unidades" DECIMAL(15,4) NULL,
  "id_producto" INT NOT NULL,
  "fecha_presupuesto" DATE NULL,
  "organization_id" CHAR(36) NULL,
  "producto" VARCHAR(255) NULL,
  "proveedor" VARCHAR(255) NULL,
  "notas" TEXT NULL,
  PRIMARY KEY ("id"),
  KEY idx_pg_pr_id_producto ("id_producto"),
  KEY idx_pg_pr_fecha ("fecha_presupuesto")
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3) CARGA A TABLA FINAL (ejecutar DESPUÉS de pegar el INSERT de Postgres)
-- ---------------------------------------------------------------------------
INSERT INTO tbl_albaranes
  (id_origen_uuid, id_producto, producto, proveedor, unidades, pvu, pcu, tipo_albaran, fecha_albaran, notas)
SELECT
  s."id" AS id_origen_uuid,
  s."id_producto",
  NULLIF(TRIM(COALESCE(s."producto", '')), '') AS producto,
  NULLIF(TRIM(COALESCE(s."proveedor", '')), '') AS proveedor,
  s."unidades",
  s."pvu",
  s."pcu",
  CASE
    WHEN s."pvu" IS NOT NULL AND s."pcu" IS NULL THEN 'VENTA'
    WHEN s."pcu" IS NOT NULL AND s."pvu" IS NULL THEN 'COMPRA'
    WHEN s."pvu" IS NOT NULL AND s."pcu" IS NOT NULL THEN 'MIXTO'
    ELSE 'SIN_PRECIO'
  END AS tipo_albaran,
  s."fecha_presupuesto" AS fecha_albaran,
  NULLIF(TRIM(COALESCE(s."notas", '')), '') AS notas
FROM "public"."tbl_precios_referencia" s
INNER JOIN tbl_productos_old p
  ON p.id = s."id_producto"
ON DUPLICATE KEY UPDATE
  producto = VALUES(producto),
  proveedor = VALUES(proveedor),
  unidades = VALUES(unidades),
  pvu = VALUES(pvu),
  pcu = VALUES(pcu),
  tipo_albaran = VALUES(tipo_albaran),
  fecha_albaran = VALUES(fecha_albaran),
  notas = VALUES(notas);

-- ---------------------------------------------------------------------------
-- 4) Verificación rápida
-- ---------------------------------------------------------------------------
SELECT
  (SELECT COUNT(*) FROM "public"."tbl_precios_referencia") AS rows_pg_raw,
  (SELECT COUNT(*) FROM tbl_albaranes) AS rows_tbl_albaranes,
  (
    SELECT COUNT(*)
    FROM "public"."tbl_precios_referencia" s
    LEFT JOIN tbl_productos_old p ON p.id = s."id_producto"
    WHERE p.id IS NULL
  ) AS rows_descartadas_sin_producto;

-- Limpieza opcional:
-- DROP TABLE "public"."tbl_precios_referencia";
-- DROP DATABASE "public";
