-- Tipos de licitación del proyecto anterior.
-- Ejecutar en MySQL (phpMyAdmin) sobre tu base de datos.
-- Si la tabla ya existe con otros datos, usa INSERT IGNORE para no duplicar por id.

CREATE TABLE IF NOT EXISTS tbl_tipolicitacion (
  id_tipolicitacion INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tbl_tipolicitacion (id_tipolicitacion, tipo, descripcion) VALUES
(1, 'Unidades y Precio Máximo', NULL),
(2, 'Precio Unitario Máx No Unidades(descuentos)', NULL),
(3, 'Unidades y no Precio Unitario(raro)', NULL),
(4, 'Precio Unitario Máx No Unidades', NULL),
(5, 'Unidades y Precio Máximo (Descuentos)', NULL);
