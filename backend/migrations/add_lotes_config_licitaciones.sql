-- Configuración de lotes: lista de lotes y cuáles se adjudicaron (ganados).
-- Permite generar N lotes de forma explícita y marcar ganado/no ganado por lote.
-- Formato: [{"nombre": "Lote 1", "ganado": false}, {"nombre": "Lote 2", "ganado": true}, ...]
ALTER TABLE public.tbl_licitaciones
ADD COLUMN IF NOT EXISTS lotes_config JSONB DEFAULT '[]'::jsonb;

COMMENT ON COLUMN public.tbl_licitaciones.lotes_config IS 'Lista de lotes con estado ganado: [{"nombre":"Lote 1","ganado":false}, ...]';
