-- Añadir columna tipo_cliente a tbl_cliente_config
ALTER TABLE tbl_cliente_config
    ADD COLUMN tipo_cliente VARCHAR(50) NOT NULL DEFAULT 'minorista'
    AFTER user_id;
