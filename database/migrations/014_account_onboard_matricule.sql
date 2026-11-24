-- Embauche : le matricule n'est plus saisi par la RH mais généré par la base, au format
-- EMP-<année>-<numéro sur 3 chiffres>, la séquence repartant de 001 chaque année.
-- Remplace account_onboard (migration 013). DROP PROCEDURE efface ses droits EXECUTE : on les réaccorde ici.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone = '+00:00';

DROP PROCEDURE IF EXISTS account_onboard;

DELIMITER $$
CREATE DEFINER='iam_owner'@'localhost' PROCEDURE account_onboard(
 IN p_actor BIGINT UNSIGNED,
 IN p_first_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_last_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_login_base VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_personal_email VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_temporary_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_employee BIGINT UNSIGNED;
 DECLARE v_account BIGINT UNSIGNED;
 DECLARE v_role BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_login VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL;
 DECLARE v_number VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
 DECLARE v_year INT UNSIGNED;
 DECLARE v_seq INT UNSIGNED;
 DECLARE v_suffix INT UNSIGNED DEFAULT 1;
 DECLARE v_expires DATETIME(6);
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
 START TRANSACTION;
 CALL payroll_assert(p_actor, 'employees.create', 'hr_manager', v_person);
 IF p_first_name IS NULL OR p_last_name IS NULL OR p_personal_email IS NULL
  OR p_temporary_hash IS NULL OR p_login_base IS NULL
  OR NOT REGEXP_LIKE(p_login_base, '^[a-z0-9]+([.-][a-z0-9]+)*$', 'c') OR CHAR_LENGTH(p_login_base) > 150 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ONBOARD_INVALID';
 END IF;
 SELECT id INTO v_role FROM roles WHERE code = 'employee';
 -- Matricule : EMP-<année>-<numéro>. Le numéro suit le plus grand déjà attribué dans l'année (données
 -- fictives comprises). Deux embauches simultanées peuvent viser le même numéro : l'index unique rejette
 -- alors la seconde (1062), l'embauche est à rejouer. Volume RH faible : pas de table de séquence dédiée.
 SET v_year = YEAR(UTC_DATE());
 SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(employee_number, '-', -1) AS UNSIGNED)), 0) + 1 INTO v_seq
 FROM employees WHERE employee_number REGEXP CONCAT('^EMP-', v_year, '-[0-9]+$');
 SET v_number = CONCAT('EMP-', v_year, '-', LPAD(v_seq, 3, '0'));
 INSERT INTO employees(employee_number, first_name, last_name) VALUES (v_number, p_first_name, p_last_name);
 SET v_employee = LAST_INSERT_ID();
 -- Identifiant professionnel : prenom.nom@securepayroll.ma, puis prenom.nom2@…, prenom.nom3@… en cas d'homonyme.
 WHILE v_login IS NULL DO
  IF v_suffix > 99 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ONBOARD_LOGIN'; END IF;
  SET v_login = CONCAT(p_login_base, IF(v_suffix = 1, '', v_suffix), '@securepayroll.ma');
  IF EXISTS(SELECT 1 FROM accounts WHERE login = v_login) THEN
   SET v_login = NULL;
   SET v_suffix = v_suffix + 1;
  END IF;
 END WHILE;
 SET v_expires = CURRENT_TIMESTAMP(6) + INTERVAL 72 HOUR;
 INSERT INTO accounts(employee_id, login, password_hash, password_change_required, temporary_password_expires_at)
 VALUES (v_employee, v_login, p_temporary_hash, TRUE, v_expires);
 SET v_account = LAST_INSERT_ID();
 INSERT INTO account_invitations(account_id, personal_email, issued_by_account_id, expires_at)
 VALUES (v_account, p_personal_email, p_actor, v_expires);
 INSERT INTO role_assignments(account_id, role_id, granted_by_account_id, grant_reason)
 VALUES (v_account, v_role, p_actor, 'Embauche : rôle de base attribué à la création du compte');
 COMMIT;
 SELECT v_employee AS employee_id, v_account AS account_id, v_number AS employee_number,
        v_login AS login, v_expires AS expires_at;
END$$
DELIMITER ;

-- DROP PROCEDURE a supprimé les droits EXECUTE de l'ancienne procédure : on les rétablit.
GRANT EXECUTE ON PROCEDURE secure_payroll.account_onboard TO 'sp_hr_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.account_onboard TO 'iam_owner'@'localhost';
