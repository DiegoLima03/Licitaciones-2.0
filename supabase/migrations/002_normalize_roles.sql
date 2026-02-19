-- Roles: solo los 5 definidos. Migrar 'member' → 'member_licitaciones'.
UPDATE public.profiles
SET role = 'member_licitaciones'
WHERE role IS NULL OR role = 'member';
