-- =============================================================================
-- ON DELETE CASCADE para tbl_licitaciones
-- Requerido por DELETE /tenders/{id} que asume eliminación en cascada.
-- NOTA: En muchos esquemas estas FKs ya tienen CASCADE. Verificar antes de ejecutar.
-- =============================================================================

-- Si las constraints ya existen con CASCADE, no es necesario ejecutar este script.
-- Comprobar con:
--   SELECT constraint_name, delete_rule FROM information_schema.referential_constraints
--   WHERE constraint_name LIKE '%id_licitacion%';
