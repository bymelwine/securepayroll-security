-- UNE SEULE FOIS après 004 ; aucun appel aux anciennes migrations.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone = '+00:00';
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
DELIMITER $$
CREATE DEFINER = 'compensation_owner'@'localhost' PROCEDURE compensation_assert_permission(
 IN p_actor BIGINT UNSIGNED,
 IN p_permission VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_role VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 OUT p_person BIGINT UNSIGNED)
SQL SECURITY DEFINER READS SQL DATA
BEGIN
 DECLARE v_grant BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_conflict BIGINT UNSIGNED DEFAULT NULL;
 DECLARE CONTINUE HANDLER FOR NOT FOUND BEGIN END;
 SET p_person=NULL;
 SELECT a.employee_id INTO p_person FROM accounts a JOIN employees e ON e.id=a.employee_id
 WHERE a.id=p_actor AND a.disabled_at IS NULL AND e.archived_at IS NULL FOR SHARE;
 -- Lectures courantes et verrous partagés : pas de décision sur un ancien snapshot RBAC.
 SELECT ra.account_id INTO v_grant FROM role_assignments ra JOIN roles r ON r.id=ra.role_id
 JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id
 WHERE ra.account_id=p_actor AND r.code=p_role AND p.code=p_permission
 AND ra.valid_from<=UTC_TIMESTAMP(6) AND (ra.expires_at IS NULL OR ra.expires_at>UTC_TIMESTAMP(6))
 AND (ra.revoked_at IS NULL OR ra.revoked_at>UTC_TIMESTAMP(6)) LIMIT 1 FOR SHARE;
 -- Toutes les identités de la personne, pas seulement le compte/session courant.
 SELECT a.id INTO v_conflict FROM accounts a JOIN role_assignments ra ON ra.account_id=a.id JOIN roles r ON r.id=ra.role_id
 WHERE a.employee_id=p_person AND r.code IN ('hr_manager','finance_manager') AND r.code<>p_role
 AND ra.valid_from<=UTC_TIMESTAMP(6) AND (ra.expires_at IS NULL OR ra.expires_at>UTC_TIMESTAMP(6))
 AND (ra.revoked_at IS NULL OR ra.revoked_at>UTC_TIMESTAMP(6)) LIMIT 1 FOR SHARE;
 IF p_person IS NULL OR v_grant IS NULL OR v_conflict IS NOT NULL THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_FORBIDDEN';
 END IF;
END$$

-- Appelé uniquement après verrou exclusif du parent employees par les deux commandes.
CREATE DEFINER = 'compensation_owner'@'localhost' PROCEDURE compensation_check_period(
 IN p_contract BIGINT UNSIGNED, IN p_employee BIGINT UNSIGNED, IN p_effective DATE,
 OUT p_kind VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs)
SQL SECURITY DEFINER READS SQL DATA
BEGIN
 DECLARE v_start DATE DEFAULT NULL;
 DECLARE v_end DATE;
 DECLARE v_latest DATE DEFAULT NULL;
 BEGIN
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_start=NULL;
  SELECT starts_on,effective_ends_on INTO v_start,v_end FROM employment_contracts
  WHERE id=p_contract AND employee_id=p_employee FOR SHARE;
 END;
 IF v_start IS NULL OR p_effective IS NULL OR p_effective<v_start OR (v_end IS NOT NULL AND p_effective>v_end) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_COVERAGE';
 END IF;
 BEGIN
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_latest=NULL;
  SELECT effective_on INTO v_latest FROM salary_conditions WHERE contract_id=p_contract
  ORDER BY effective_on DESC LIMIT 1 FOR SHARE;
 END;
 SET p_kind=IF(v_latest IS NULL,'initial','revision');
 IF (v_latest IS NULL AND p_effective<>v_start)
 OR (v_latest IS NOT NULL AND (DAYOFMONTH(p_effective)<>1 OR p_effective<=v_latest)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_EFFECTIVE_DATE';
 END IF;
END$$

CREATE DEFINER = 'compensation_owner'@'localhost' PROCEDURE compensation_submit(
 IN p_employee BIGINT UNSIGNED, IN p_contract BIGINT UNSIGNED, IN p_amount DECIMAL(12,2),
 IN p_effective DATE, IN p_justification VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE v_parent BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_kind VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
 DECLARE v_id BIGINT UNSIGNED;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 -- Même verrou parent que les créations/clôtures de contrats RH.
 SELECT id INTO v_parent FROM employees WHERE id=p_employee AND archived_at IS NULL FOR UPDATE;
 CALL compensation_assert_permission(p_actor,'compensation.submit','hr_manager',v_person);
 IF v_parent IS NULL OR p_amount IS NULL OR p_amount<=0 OR p_justification IS NULL
 OR CHAR_LENGTH(TRIM(p_justification))=0 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_INVALID';
 END IF;
 CALL compensation_check_period(p_contract,p_employee,p_effective,v_kind);
 INSERT INTO compensation_requests(employee_id,contract_id,request_kind,monthly_amount,currency,
  effective_on,justification,requested_by,requester_employee_id,requested_at)
 VALUES(p_employee,p_contract,v_kind,p_amount,'UM',p_effective,TRIM(p_justification),p_actor,v_person,UTC_TIMESTAMP(6));
 SET v_id=LAST_INSERT_ID();
 INSERT INTO compensation_history(request_id,event_type,actor_id,occurred_at)
 VALUES(v_id,'submitted',p_actor,UTC_TIMESTAMP(6));
 COMMIT;
 SELECT v_id AS id;
END$$

CREATE DEFINER = 'compensation_owner'@'localhost' PROCEDURE compensation_decide(
 IN p_request BIGINT UNSIGNED,
 IN p_decision VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_reason VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE v_employee BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_parent BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_contract BIGINT UNSIGNED;
 DECLARE v_requester BIGINT UNSIGNED;
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_amount DECIMAL(12,2);
 DECLARE v_effective DATE;
 DECLARE v_kind VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
 DECLARE v_current_kind VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
 DECLARE v_existing BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_decision BIGINT UNSIGNED;
 DECLARE v_archived DATETIME(6);
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 SELECT employee_id INTO v_employee FROM compensation_requests WHERE id=p_request;
 IF v_employee IS NULL THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_NOT_FOUND';
 END IF;
 SELECT id,archived_at INTO v_parent,v_archived FROM employees WHERE id=v_employee FOR UPDATE;
 CALL compensation_assert_permission(p_actor,'compensation.decide','finance_manager',v_person);
 IF v_parent IS NULL OR (p_decision='approved' AND v_archived IS NOT NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_INVALID';
 END IF;
 SELECT contract_id,requester_employee_id,monthly_amount,effective_on,request_kind
 INTO v_contract,v_requester,v_amount,v_effective,v_kind FROM compensation_requests WHERE id=p_request FOR SHARE;
 IF v_person=v_requester OR v_person=v_employee THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_SELF_APPROVAL';
 END IF;
 IF p_decision IS NULL OR p_decision NOT IN ('approved','rejected') OR p_reason IS NULL OR CHAR_LENGTH(TRIM(p_reason))=0 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_INVALID';
 END IF;
 BEGIN
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_existing=NULL;
  SELECT id INTO v_existing FROM compensation_decisions WHERE request_id=p_request FOR SHARE;
 END;
 IF v_existing IS NOT NULL THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_ALREADY_DECIDED';
 END IF;
 IF p_decision='approved' THEN
  CALL compensation_check_period(v_contract,v_employee,v_effective,v_current_kind);
  IF v_current_kind<>v_kind THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_STALE';
  END IF;
 END IF;
 INSERT INTO compensation_decisions(request_id,decision,reason,decided_by,approver_employee_id,decided_at)
 VALUES(p_request,p_decision,TRIM(p_reason),p_actor,v_person,UTC_TIMESTAMP(6));
 SET v_decision=LAST_INSERT_ID();
 IF p_decision='approved' THEN
  INSERT INTO salary_conditions(employee_id,contract_id,request_id,decision_id,monthly_amount,currency,effective_on,recorded_at)
  VALUES(v_employee,v_contract,p_request,v_decision,v_amount,'UM',v_effective,UTC_TIMESTAMP(6));
 END IF;
 INSERT INTO compensation_history(request_id,event_type,actor_id,occurred_at)
 VALUES(p_request,p_decision,p_actor,UTC_TIMESTAMP(6));
 COMMIT;
 SELECT v_decision AS id;
END$$
DELIMITER ;
