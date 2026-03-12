-- ============================================================
-- Datos del Excel "DISPONIBLE QUE ME PASA ALVARO A DANI"
-- 6 productos visibles en el PDF (pág. 1 + pág. 2)
--
-- Mapeo de columnas de precio (las 5 que aparecen rellenas):
--   precio_x_unid          → columna "PRECIO X UNID"
--   precio_x_unid_diplad_m7→ columna "PRECIO X UNID PARA DIPLAD ENIA M7"
--   precio_x_unid_almeria  → columna "PRECIO X UNID SALIDA DESDE ALMERIA"
--   precio_t5_directo      → columna "T5% CARGA DIRECTA EN PRODUCTOR"
--   precio_t5_almeria      → columna "T5% SALIDA DESDE ALMERIA"
--
-- NOTA: "ultimo_cambio" (DATE) no se rellena porque el PDF muestra
--       valores en formato semana (S08-2026, S07-2026…) que no son
--       fechas válidas. Esos valores se guardan en "fecha_sem_produccion"
--       si corresponden, o se pueden añadir manualmente desde la web.
-- ============================================================

INSERT INTO tbl_disponible (
    codigo,
    codigo_rach,
    descripcion_rach,
    nombre_floriday,
    precio_coste_productor,
    descuento_productor,
    precio_coste_final,
    precio_x_unid,
    precio_x_unid_diplad_m7,
    precio_x_unid_almeria,
    precio_t5_directo,
    precio_t5_almeria,
    formato,
    clasificacion_compra_facil,
    caracteristicas,
    cantidades_minimas,
    unids_x_piso,
    unids_x_cc,
    porcentaje_ocupacion,
    zona,
    disponible,
    nombre_productor,
    unids_disponibles,
    fecha_sem_produccion,
    pedido_x_unid,
    pedido_x_piso,
    pedido_x_cc,
    total_unids_x_linea
)
VALUES

-- ─────────────────────────────────────────────────────────────
-- 1. Abelia other
--    Coste: 5,75 € | Dto: 10% | Final: 5,18 €
--    Precios: 6,25 / 6,60 / 6,90 / 7,20 / 7,80
--    Productor: VIVEIRO COREMA | Ud. disp.: 100 | Sem: S.38/25
-- ─────────────────────────────────────────────────────────────
(
    '9115',
    NULL,
    'ABELIA GRANDIFLORA STERENDENN M-5L',
    'Abelia other',
    5.75, 10.00, 5.18,
    6.25, 6.60, 6.90, 7.20, 7.80,
    'M-5L',
    NULL, NULL,
    21, 21, 105, 100.00,
    'NORTE', 1,
    'VIVEIRO COREMA', 100, 'S.38/25',
    0, 0, 0, 0
),

-- ─────────────────────────────────────────────────────────────
-- 2. Acer palmatum 'Atropurpureum'
--    Coste: 7,07 € | Sin descuento visible
--    Precios: 9,50 / 10,00 / 10,50 / 11,00 / 12,00
--    Productor: VERALEZA | Ud. disp.: 150
-- ─────────────────────────────────────────────────────────────
(
    'P000059033',
    '30092',
    'ACER PALMATUM ATROPURPUREUM 60/80 M-3L',
    'Acer palmatum ''Atropurpureum''',
    7.07, NULL, NULL,
    9.50, 10.00, 10.50, 11.00, 12.00,
    'M-3L',
    NULL, NULL,
    21, 21, 63, 100.00,
    'NORTE', 1,
    'VERALEZA', 150, NULL,
    0, 0, 0, 0
),

-- ─────────────────────────────────────────────────────────────
-- 3. Acer palmatum (con foto)
--    Coste: 9,00 €
--    Precios: 12,00 / 12,50 / 13,25 / 13,75 / 15,00
--    Productor: VIVEROS LAMIGUEIROS | Sem: S.46/25
-- ─────────────────────────────────────────────────────────────
(
    'P000059059',
    '30090',
    'ACER PALMATUM M-10L',
    'Acer palmatum',
    9.00, NULL, NULL,
    12.00, 12.50, 13.25, 13.75, 15.00,
    'M-10L',
    NULL, NULL,
    10, 10, 30, 100.00,
    'NORTE', 1,
    'VIVEROS LAMIGUEIROS', NULL, 'S.46/25',
    0, 0, 0, 0
),

-- ─────────────────────────────────────────────────────────────
-- 4. Achillea millefolium
--    Coste: 2,70 €
--    Precios: 3,15 / 3,30 / 3,45 / 3,60 / 3,95
--    Productor: VIVEIROS DA BARXA | Ud. disp.: 300 | Sem: S.51/25
--    Clasif.: PLANTA DE OBRA | Caract.: VIVACES
-- ─────────────────────────────────────────────────────────────
(
    'P001840001',
    '40054',
    'ACHILLEA "MILLEFOLIUM" M-2L',
    'Achillea millefolium',
    2.70, NULL, NULL,
    3.15, 3.30, 3.45, 3.60, 3.95,
    'M-2L',
    'PLANTA DE OBRA', 'VIVACES',
    36, 36, 216, 100.00,
    'NORTE', 1,
    'VIVEIROS DA BARXA', 300, 'S.51/25',
    0, 0, 0, 0
),

-- ─────────────────────────────────────────────────────────────
-- 5. Achillea 'Moonshine'
--    Coste: 2,70 €
--    Precios: 3,15 / 3,30 / 3,45 / 3,60 / 3,95
--    Productor: VIVEIROS DA BARXA | Ud. disp.: 300 | Sem: S.51/25
-- ─────────────────────────────────────────────────────────────
(
    'P003655001',
    '40091',
    'ACHILLEA "MOONSHINE" M-2L',
    'Achillea ''Moonshine''',
    2.70, NULL, NULL,
    3.15, 3.30, 3.45, 3.60, 3.95,
    'M-2L',
    'PLANTA DE OBRA', 'VIVACES',
    36, 36, 216, 100.00,
    'NORTE', 1,
    'VIVEIROS DA BARXA', 300, 'S.51/25',
    0, 0, 0, 0
),

-- ─────────────────────────────────────────────────────────────
-- 6. Achillea millefolium Summer Pastels Mix
--    Coste: 2,70 €
--    Precios: 3,15 / 3,30 / 3,45 / 3,60 / 3,95
--    Productor: VIVEIROS DA BARXA | Ud. disp.: 200 | Sem: S.51/25
-- ─────────────────────────────────────────────────────────────
(
    'P002868001',
    '51614',
    'ACHILLEA "SUMMER PASTELS" M-2L',
    'Achillea millefolium Summer Pastels Mix',
    2.70, NULL, NULL,
    3.15, 3.30, 3.45, 3.60, 3.95,
    'M-2L',
    'PLANTA DE OBRA', 'VIVACES',
    36, 36, 216, 100.00,
    'NORTE', 1,
    'VIVEIROS DA BARXA', 200, 'S.51/25',
    0, 0, 0, 0
);
