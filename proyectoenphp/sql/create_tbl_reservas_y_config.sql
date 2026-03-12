-- ============================================================
-- Tablas para el sistema de reservas de disponible por cliente
-- ============================================================

-- Configuración por cliente: zona(s) y tarifa asignadas
CREATE TABLE IF NOT EXISTS tbl_cliente_config (
    user_id        VARCHAR(36)  NOT NULL PRIMARY KEY,
    zonas          VARCHAR(500) NOT NULL DEFAULT 'TODAS',
    -- Valores válidos de columna_precio:
    --   precio_x_unid | precio_x_unid_diplad_m7 | precio_x_unid_almeria
    --   precio_t5_directo | precio_t5_almeria
    columna_precio VARCHAR(50)  NOT NULL DEFAULT 'precio_x_unid',
    puede_reservar TINYINT(1)   NOT NULL DEFAULT 1,
    notas          TEXT,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reservas: qué unidades reserva cada cliente de cada producto disponible
CREATE TABLE IF NOT EXISTS tbl_reservas_disponible (
    id            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    disponible_id INT          NOT NULL,
    user_id       VARCHAR(36)  NOT NULL,
    unids         INT          NOT NULL DEFAULT 0,
    notas         TEXT,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_disp_user (disponible_id, user_id),
    KEY idx_user_id       (user_id),
    KEY idx_disponible_id (disponible_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
