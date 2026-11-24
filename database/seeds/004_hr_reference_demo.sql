-- OPTIONNEL : petits catalogues de démonstration, à valider pour votre organisation.
-- Sans compte, employé, contrat ou affectation ; conserve les libellés déjà présents.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
START TRANSACTION;
INSERT INTO departments (code,name_fr,name_en)
SELECT incoming.code,incoming.name_fr,incoming.name_en FROM (
    SELECT 'HR' AS code,'Ressources humaines' AS name_fr,'Human resources' AS name_en
    UNION ALL SELECT 'FIN','Finance','Finance'
    UNION ALL SELECT 'IT','Systèmes d’information','Information technology'
) incoming LEFT JOIN departments existing ON existing.code=incoming.code WHERE existing.id IS NULL;
INSERT INTO positions (code,name_fr,name_en)
SELECT incoming.code,incoming.name_fr,incoming.name_en FROM (
    SELECT 'HR_MANAGER' AS code,'Responsable RH' AS name_fr,'HR Manager' AS name_en
    UNION ALL SELECT 'HR_OFFICER','Gestionnaire RH','HR Officer'
    UNION ALL SELECT 'FINANCE_OFFICER','Gestionnaire financier','Finance Officer'
    UNION ALL SELECT 'SOFTWARE_ENGINEER','Ingénieur logiciel','Software Engineer'
) incoming LEFT JOIN positions existing ON existing.code=incoming.code WHERE existing.id IS NULL;
COMMIT;
