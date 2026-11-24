-- New IAM entry points; preserve existing role assignment history and accounts.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
DELIMITER $$
CREATE DEFINER='iam_owner'@'localhost' PROCEDURE iam_overview(IN p_actor BIGINT UNSIGNED, IN p_page INT UNSIGNED)
SQL SECURITY DEFINER READS SQL DATA
BEGIN
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_offset INT UNSIGNED;
 DECLARE v_role VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
 SET v_role=IF(EXISTS(SELECT 1 FROM accounts a JOIN role_assignments ra ON ra.account_id=a.id JOIN roles r ON r.id=ra.role_id
  WHERE a.id=p_actor AND r.code='application_administrator' AND ra.valid_from<=UTC_TIMESTAMP(6)
  AND (ra.expires_at IS NULL OR ra.expires_at>UTC_TIMESTAMP(6)) AND ra.revoked_at IS NULL),'application_administrator','security_auditor');
 CALL payroll_assert(p_actor,'iam.read',v_role,v_person);
 IF p_page IS NULL OR p_page<1 OR p_page>1000000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IAM_INVALID'; END IF;
 SET v_offset=(p_page-1)*50;
 SELECT a.id,a.employee_id,a.login,a.disabled_at,e.employee_number,e.archived_at FROM accounts a JOIN employees e ON e.id=a.employee_id ORDER BY a.id LIMIT 500;
 SELECT id,code,name FROM roles ORDER BY id;
 SELECT ra.*,a.login,r.code FROM role_assignments ra JOIN accounts a ON a.id=ra.account_id JOIN roles r ON r.id=ra.role_id ORDER BY ra.id DESC LIMIT 50 OFFSET v_offset;
END$$
CREATE DEFINER='iam_owner'@'localhost' PROCEDURE iam_grant(
 IN p_account BIGINT UNSIGNED,
 IN p_role VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_reason VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE v_employee BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_parent BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_role BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_active BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_id BIGINT UNSIGNED;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
 START TRANSACTION;
 SELECT employee_id INTO v_employee FROM accounts WHERE id=p_account;
 SELECT id INTO v_parent FROM employees WHERE id=v_employee AND archived_at IS NULL FOR UPDATE;
 CALL payroll_assert(p_actor,'iam.grant','application_administrator',v_person);
 SELECT id INTO v_role FROM roles WHERE code=p_role;
 IF v_employee=v_person THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IAM_FORBIDDEN'; END IF;
 IF v_parent IS NULL OR v_role IS NULL OR NOT EXISTS(SELECT 1 FROM accounts WHERE id=p_account AND disabled_at IS NULL)
  OR p_reason IS NULL OR CHAR_LENGTH(TRIM(p_reason)) NOT BETWEEN 1 AND 255 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IAM_INVALID';
 END IF;
 -- Account state cannot change until this grant commits.
 SELECT employee_id INTO v_active FROM accounts WHERE id=p_account AND disabled_at IS NULL FOR SHARE;
 IF v_active IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IAM_INVALID'; END IF;
 -- A future assignment is also a conflict with a new indefinite grant.
 IF EXISTS(SELECT 1 FROM accounts a JOIN role_assignments ra ON ra.account_id=a.id JOIN roles r ON r.id=ra.role_id
  WHERE a.employee_id=v_employee AND (ra.expires_at IS NULL OR ra.expires_at>UTC_TIMESTAMP(6))
  AND (ra.revoked_at IS NULL OR ra.revoked_at>UTC_TIMESTAMP(6))
  AND (r.code=p_role OR (r.code<>'employee' AND p_role<>'employee'))) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IAM_CONFLICT';
 END IF;
 INSERT INTO role_assignments(account_id,role_id,granted_by_account_id,grant_reason)
 VALUES(p_account,v_role,p_actor,TRIM(p_reason));
 SET v_id=LAST_INSERT_ID();
 COMMIT;
 SELECT v_id AS id;
END$$
CREATE DEFINER='iam_owner'@'localhost' PROCEDURE iam_revoke(
 IN p_assignment BIGINT UNSIGNED,
 IN p_reason VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE v_employee BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_parent BIGINT UNSIGNED;
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
 START TRANSACTION;
 SELECT a.employee_id INTO v_employee FROM accounts a JOIN role_assignments ra ON ra.account_id=a.id WHERE ra.id=p_assignment;
 SELECT id INTO v_parent FROM employees WHERE id=v_employee FOR UPDATE;
 CALL payroll_assert(p_actor,'iam.revoke','application_administrator',v_person);
 IF v_employee=v_person THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IAM_FORBIDDEN'; END IF;
 IF v_employee IS NULL OR p_reason IS NULL OR CHAR_LENGTH(TRIM(p_reason)) NOT BETWEEN 1 AND 255 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IAM_INVALID';
 END IF;
 UPDATE role_assignments SET revoked_at=CURRENT_TIMESTAMP(6),revoked_by_account_id=p_actor,revocation_reason=TRIM(p_reason)
 WHERE id=p_assignment AND revoked_at IS NULL;
 IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='IAM_STATE'; END IF;
 COMMIT;
 SELECT p_assignment AS id;
END$$
DELIMITER ;
