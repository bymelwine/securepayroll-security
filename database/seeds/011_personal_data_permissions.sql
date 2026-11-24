-- Phase 6 — Permissions du salarié sur SES propres données personnelles. Idempotent, n'élargit aucun autre rôle.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
START TRANSACTION;
INSERT INTO permissions(code,description)
SELECT incoming.code,incoming.description FROM (
 SELECT 'personal_data.view_own' AS code,'Consulter ses propres données personnelles (masquées)' AS description
 UNION ALL SELECT 'personal_data.update_rib','Saisir ou mettre à jour son propre RIB'
) incoming LEFT JOIN permissions existing ON existing.code=incoming.code WHERE existing.id IS NULL;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON
 (r.code='employee' AND p.code IN ('personal_data.view_own','personal_data.update_rib'))
LEFT JOIN role_permissions existing ON existing.role_id=r.id AND existing.permission_id=p.id
WHERE existing.role_id IS NULL;
COMMIT;
