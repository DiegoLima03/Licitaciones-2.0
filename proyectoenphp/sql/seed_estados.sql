-- Estados básicos de las licitaciones.
-- Ejecutar en MySQL (phpMyAdmin) sobre tu base de datos 3308.
-- Si la tabla ya existe con otros datos, usa INSERT IGNORE para no duplicar por id.

CREATE TABLE IF NOT EXISTS tbl_estados (
  id_estado INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre_estado VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tbl_estados (id_estado, nombre_estado) VALUES
(2, 'Descartada'),
(3, 'En análisis'),
(4, 'Presentada'),
(5, 'Adjudicada'),
(6, 'No adjudicada'),
(7, 'Terminada');

