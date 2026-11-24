-- Phase 7 — Permission de supervision de l'audit, réservée à l'auditeur de sécurité. Idempotent.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
START TRANSACTION;
INSERT INTO permissions(code,description)
SELECT incoming.code,incoming.description FROM (
 SELECT 'audit.read' AS code,'Consulter la piste d''audit et les signaux de supervision' AS description
) incoming LEFT JOIN permissions existing ON existing.code=incoming.code WHERE existing.id IS NULL;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON
 (r.code='security_auditor' AND p.code='audit.read')
LEFT JOIN role_permissions existing ON existing.role_id=r.id AND existing.permission_id=p.id
WHERE existing.role_id IS NULL;
COMMIT;
