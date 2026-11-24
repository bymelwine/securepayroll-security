-- Idempotent : insère les codes absents, conserve les lignes existantes.
-- Seul un couple code/libellé EXACT est considéré comme déjà présent.
-- Tout couple incohérent reste candidat et échoue sur UNIQUE/CHECK, sans écrasement.
-- Précondition : schéma 001, dont ck_roles_canonical limite le catalogue à six rôles.
-- Aucun compte, aucune empreinte, aucune permission ou attribution.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
START TRANSACTION;
INSERT INTO roles (code, name)
SELECT incoming.code, incoming.name
FROM (
    SELECT 'employee' AS code, 'Employee' AS name
    UNION ALL SELECT 'hr_manager', 'HR Manager'
    UNION ALL SELECT 'payroll_manager', 'Payroll Manager'
    UNION ALL SELECT 'finance_manager', 'Finance Manager'
    UNION ALL SELECT 'application_administrator', 'Application Administrator'
    UNION ALL SELECT 'security_auditor', 'Security Auditor'
) AS incoming
LEFT JOIN roles AS existing
    ON CAST(existing.code AS BINARY) = CAST(incoming.code AS BINARY)
    AND CAST(existing.name AS BINARY) = CAST(incoming.name AS BINARY)
WHERE existing.id IS NULL;
COMMIT;
