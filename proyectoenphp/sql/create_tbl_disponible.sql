-- Tabla de productos disponibles (sustituye el Excel "Disponible que me pasa Alvaro a Dani")
-- Ejecutar en MySQL: mysql -u root -p licitaciones < create_tbl_disponible.sql

CREATE TABLE IF NOT EXISTS tbl_disponible (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Identificación / Códigos
    foto                        VARCHAR(500)    NULL COMMENT 'URL o ruta de la imagen principal',
    foto1                       VARCHAR(500)    NULL COMMENT 'URL o ruta de imagen secundaria',
    campanya_precios_espec      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Campaña y precios especiales (0/1)',
    producto_precio_espec       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Producto precio especial (0/1)',
    codigo                      VARCHAR(100)    NULL COMMENT 'Código interno',
    codigo_rach                 VARCHAR(100)    NULL COMMENT 'Código RACH',
    descripcion_rach            VARCHAR(500)    NULL COMMENT 'Descripción RACH (nombre botánico + formato)',
    ean                         VARCHAR(50)     NULL COMMENT 'Código EAN',
    id_articulo_agricultor      VARCHAR(100)    NULL COMMENT 'ID artículo agricultor',
    passaporte_fito             VARCHAR(100)    NULL COMMENT 'Passaporte fitosanitario',
    floricode                   VARCHAR(100)    NULL COMMENT 'Código Floricode',
    nombre_floriday             VARCHAR(255)    NULL COMMENT 'Nombre Floriday',
    clasificacion               VARCHAR(100)    NULL COMMENT 'Clasificación: GARDEN, HOUSE PLANT',
    calidad                     VARCHAR(100)    NULL COMMENT 'Calidad del producto',
    descripcion                 VARCHAR(500)    NULL COMMENT 'Descripción comercial',

    -- Precios
    precio_coste_productor      DECIMAL(10,2)   NULL COMMENT 'Precio coste productor (€)',
    descuento_productor         DECIMAL(5,2)    NULL COMMENT 'Descuento productor (%)',
    precio_coste_final          DECIMAL(10,2)   NULL COMMENT 'Precio coste productor final (€)',
    tarifa_mayorista            DECIMAL(10,2)   NULL COMMENT 'Tarifa mayorista – artículos carga en origen +3 (€)',
    precio_x_unid               DECIMAL(10,2)   NULL COMMENT 'Precio por unidad (€)',
    precio_x_unid_diplad_m7     DECIMAL(10,2)   NULL COMMENT 'Precio por unidad para Diplad M7 (€)',
    precio_x_unid_almeria       DECIMAL(10,2)   NULL COMMENT 'Precio por unidad salida desde Almería (€)',
    precio_t5_directo           DECIMAL(10,2)   NULL COMMENT 'T5% carga directa en productor (€)',
    precio_t5_almeria           DECIMAL(10,2)   NULL COMMENT 'T5% salida desde Almería (€)',
    precio_t10                  DECIMAL(10,2)   NULL COMMENT 'Precio por unidad T10% (€)',
    precio_t15                  DECIMAL(10,2)   NULL COMMENT 'Precio por unidad T15% (€)',
    precio_dipladen_t25         DECIMAL(10,2)   NULL COMMENT 'Precio por unidad para Dipladen T25% (€)',
    precio_t25                  DECIMAL(10,2)   NULL COMMENT 'Precio por unidad T25% (€)',

    -- Formato y presentación
    formato                     VARCHAR(100)    NULL COMMENT 'Formato (M-5L, M-3L, M-10L…)',
    tamanyo_aprox               VARCHAR(100)    NULL COMMENT 'Tamaño aproximado',

    -- Disponibilidad y logística
    observaciones               TEXT            NULL,
    clasificacion_compra_facil  VARCHAR(100)    NULL COMMENT 'Clasificación compra fácil',
    color                       VARCHAR(100)    NULL,
    caracteristicas             TEXT            NULL,
    cantidades_minimas          INT             NULL COMMENT 'Cantidades mínimas de pedido (unidades)',
    unids_x_piso                INT             NULL COMMENT 'Unidades por piso',
    unids_x_cc                  INT             NULL COMMENT 'Unidades por CC (camión completo)',
    porcentaje_ocupacion        DECIMAL(5,2)    NULL COMMENT 'Porcentaje de ocupación (%)',
    zona                        VARCHAR(100)    NULL COMMENT 'Zona (NORTE, SUR…)',
    disponible                  TINYINT(1)      NOT NULL DEFAULT 1 COMMENT 'Disponible para pedido (0/1)',

    -- Pedido actual
    pedido_x_unid               INT             NULL DEFAULT 0 COMMENT 'Pedido por unidad',
    pedido_x_piso               INT             NULL DEFAULT 0 COMMENT 'Pedido por piso',
    pedido_x_cc                 INT             NULL DEFAULT 0 COMMENT 'Pedido por CC',

    -- Productor
    cod_productor               VARCHAR(100)    NULL,
    cod_productor_opc2          VARCHAR(100)    NULL,
    cod_productor_opc3          VARCHAR(100)    NULL,
    nombre_productor            VARCHAR(255)    NULL,
    unids_disponibles           INT             NULL COMMENT 'Unidades disponibles en el productor',
    fecha_sem_produccion        VARCHAR(50)     NULL COMMENT 'Fecha aprox o semana de producción (ej: S.38/25)',

    -- Control
    ultimo_cambio               DATE            NULL,
    pasado_a_freshportal        TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Pasado a Freshportal (0/1)',
    total_unids_x_linea         INT             NULL DEFAULT 0 COMMENT 'Total unidades a pedir por línea',
    incremento_precio_x_unid    DECIMAL(10,2)   NULL COMMENT 'Incremento de precio por unidad (€)',

    -- Auditoría
    created_at                  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
