-- À RELIRE ET VALIDER avant exécution. Aucun utilisateur, compte ou mot de passe.
-- Permissions RH de la première version web, uniquement pour HR Manager.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
START TRANSACTION;
INSERT INTO permissions (code, description)
SELECT incoming.code, incoming.description FROM (
    SELECT 'employees.read' AS code, 'Consulter les fiches des employés' AS description
    UNION ALL SELECT 'employees.create', 'Créer une fiche employé'
    UNION ALL SELECT 'employees.update', 'Modifier le prénom et le nom d’un employé'
) AS incoming
LEFT JOIN permissions existing ON existing.code = incoming.code
WHERE existing.id IS NULL;
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.code IN ('employees.read', 'employees.create', 'employees.update')
LEFT JOIN role_permissions existing ON existing.role_id = r.id AND existing.permission_id = p.id
WHERE r.code = 'hr_manager' AND existing.role_id IS NULL;
COMMIT;
