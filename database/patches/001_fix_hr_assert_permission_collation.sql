-- Mission 05C — correction ciblée de ERROR 1267 (HY000), MySQL 8.4.11.
-- Exécution MANUELLE uniquement, après revue par l’administrateur.
-- Prérequis : hr_module_owner@localhost existe, reste verrouillé et possède
-- déjà les droits de lecture nécessaires. Aucun compte/table/rôle n’est créé.
-- Le corps d’autorisation est repris à l’identique de la migration 003.
-- Ne pas rejouer cette migration et ne pas changer la collation globale.
--
-- DROP/CREATE ne sont pas transactionnels : prévoir une courte interruption
-- des créations/clôtures RH pendant ce patch. Arrêter à la première erreur,
-- sans --force, et examiner l’état avant toute reprise.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE secure_payroll.hr_assert_permission;

DELIMITER $$
CREATE DEFINER = 'hr_module_owner'@'localhost' PROCEDURE secure_payroll.hr_assert_permission(
    IN p_actor BIGINT UNSIGNED,
    IN p_permission VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs
)
SQL SECURITY DEFINER
READS SQL DATA
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM accounts a JOIN employees e ON e.id=a.employee_id
        JOIN role_assignments ra ON ra.account_id=a.id JOIN roles r ON r.id=ra.role_id
        JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id
        WHERE a.id=p_actor AND a.disabled_at IS NULL AND e.archived_at IS NULL
        AND r.code='hr_manager' AND p.code=p_permission AND ra.valid_from <= UTC_TIMESTAMP(6)
        AND (ra.expires_at IS NULL OR ra.expires_at > UTC_TIMESTAMP(6))
        AND (ra.revoked_at IS NULL OR ra.revoked_at > UTC_TIMESTAMP(6))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_FORBIDDEN';
    END IF;
END$$
DELIMITER ;

-- DROP PROCEDURE peut supprimer les privilèges spécifiques de cette routine.
-- Restaurer exclusivement EXECUTE au DEFINER pour les appels imbriqués.
-- svc_php conserve ses EXECUTE existants sur les quatre procédures publiques ;
-- aucun EXECUTE direct du helper, DML, droit global ou GRANT OPTION ne lui est ajouté.
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_assert_permission TO 'hr_module_owner'@'localhost';

-- Vérification en lecture seule de la définition installée et du paramètre corrigé.
SELECT ROUTINE_NAME, DEFINER, SECURITY_TYPE, SQL_DATA_ACCESS
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'secure_payroll' AND ROUTINE_NAME = 'hr_assert_permission'
    AND ROUTINE_TYPE = 'PROCEDURE';
SELECT PARAMETER_NAME, DTD_IDENTIFIER, CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.PARAMETERS
WHERE SPECIFIC_SCHEMA = 'secure_payroll' AND SPECIFIC_NAME = 'hr_assert_permission'
    AND PARAMETER_NAME = 'p_permission';
