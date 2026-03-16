-- Añadir columna motivo_descarte a tbl_licitaciones
ALTER TABLE `tbl_licitaciones`
    ADD COLUMN `motivo_descarte` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL
    AFTER `motivo_perdida`;
