-- Ne crée aucun compte ni rôle ; idempotent, sans élargir les autres rôles.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
START TRANSACTION;
INSERT INTO permissions(code,description)
SELECT incoming.code,incoming.description FROM (
 SELECT 'compensation.read' AS code,'Consulter les demandes et historiques salariaux' AS description
 UNION ALL SELECT 'compensation.submit','Soumettre une demande salariale RH'
 UNION ALL SELECT 'compensation.decide','Approuver ou rejeter une demande salariale Finance'
) incoming LEFT JOIN permissions existing ON existing.code=incoming.code WHERE existing.id IS NULL;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON
 (r.code='hr_manager' AND p.code IN ('compensation.read','compensation.submit')) OR
 (r.code='finance_manager' AND p.code IN ('compensation.read','compensation.decide'))
LEFT JOIN role_permissions existing ON existing.role_id=r.id AND existing.permission_id=p.id
WHERE existing.role_id IS NULL;
COMMIT;
