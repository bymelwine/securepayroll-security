-- Après migrations 002 et 003. Ne crée aucun rôle ni compte.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
START TRANSACTION;
INSERT INTO permissions (code, description)
SELECT incoming.code,incoming.description FROM (
    SELECT 'departments.read' AS code,'Consulter les départements' AS description
    UNION ALL SELECT 'positions.read','Consulter les postes'
    UNION ALL SELECT 'assignments.read','Consulter les affectations'
    UNION ALL SELECT 'assignments.create','Enregistrer une affectation'
    UNION ALL SELECT 'assignments.close','Clôturer une affectation sans supprimer son historique'
    UNION ALL SELECT 'contracts.read','Consulter les contrats administratifs sans rémunération'
    UNION ALL SELECT 'contracts.create','Créer un contrat administratif sans rémunération'
    UNION ALL SELECT 'contracts.close','Clôturer un contrat administratif'
) incoming LEFT JOIN permissions existing ON existing.code=incoming.code
WHERE existing.id IS NULL;
INSERT INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p
    ON p.code IN ('departments.read','positions.read','assignments.read','assignments.create',
        'assignments.close','contracts.read','contracts.create','contracts.close')
LEFT JOIN role_permissions existing ON existing.role_id=r.id AND existing.permission_id=p.id
WHERE r.code='hr_manager' AND existing.role_id IS NULL;
COMMIT;
