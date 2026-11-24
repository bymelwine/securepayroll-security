-- Mission 06 : nouvelle migration, à appliquer UNE SEULE FOIS après 004/005.
-- Arrêter l'application pendant l'installation : le DDL a des commits implicites.
-- Ne rejoue aucune ancienne migration ; aucune conversion/UPDATE de données.
-- Les anciennes valeurs UM restent enregistrées sous UM et ne sont pas réétiquetées.
-- Aucun nouveau compte, aucune nouvelle permission ni élargissement de privilèges.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone = '+00:00';
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

ALTER TABLE compensation_requests
 DROP CHECK ck_cr_amount,
 MODIFY currency CHAR(3) NOT NULL DEFAULT 'MAD',
 ADD CONSTRAINT ck_cr_amount CHECK (monthly_amount>0 AND currency IN ('MAD','UM'));
ALTER TABLE salary_conditions
 DROP CHECK ck_sc_amount,
 MODIFY currency CHAR(3) NOT NULL DEFAULT 'MAD',
 ADD CONSTRAINT ck_sc_amount CHECK (monthly_amount>0 AND currency IN ('MAD','UM'));

-- DROP/CREATE indispensable sous MySQL pour modifier le corps d'une procédure.
-- Les triggers d'immuabilité, RBAC, signatures et propriétaires restent conservés.
DROP PROCEDURE compensation_decide;
DROP PROCEDURE compensation_submit;
DROP PROCEDURE compensation_check_period;
DELIMITER $$
CREATE DEFINER = 'compensation_owner'@'localhost' PROCEDURE compensation_check_period(
 IN p_contract BIGINT UNSIGNED, IN p_employee BIGINT UNSIGNED, IN p_effective DATE,
 OUT p_kind VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs)
SQL SECURITY DEFINER READS SQL DATA
BEGIN
 DECLARE v_start DATE DEFAULT NULL;
 DECLARE v_end DATE;
 DECLARE v_latest DATE DEFAULT NULL;
 DECLARE v_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
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
  SELECT effective_on,currency INTO v_latest,v_currency FROM salary_conditions WHERE contract_id=p_contract
  ORDER BY effective_on DESC LIMIT 1 FOR SHARE;
 END;
 IF v_latest IS NOT NULL AND v_currency<>'MAD' THEN
  -- Aucun changement implicite de devise d'une rémunération déjà approuvée.
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_CURRENCY_REVIEW';
 END IF;
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
 VALUES(p_employee,p_contract,v_kind,p_amount,'MAD',p_effective,TRIM(p_justification),p_actor,v_person,UTC_TIMESTAMP(6));
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
 DECLARE v_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
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
 SELECT contract_id,requester_employee_id,monthly_amount,effective_on,request_kind,currency
 INTO v_contract,v_requester,v_amount,v_effective,v_kind,v_currency FROM compensation_requests WHERE id=p_request FOR SHARE;
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
  IF v_currency<>'MAD' THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_CURRENCY_REVIEW';
  END IF;
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
  VALUES(v_employee,v_contract,p_request,v_decision,v_amount,v_currency,v_effective,UTC_TIMESTAMP(6));
 END IF;
 INSERT INTO compensation_history(request_id,event_type,actor_id,occurred_at)
 VALUES(p_request,p_decision,p_actor,UTC_TIMESTAMP(6));
 COMMIT;
 SELECT v_decision AS id;
END$$
DELIMITER ;
-- La recréation supprime les privilèges spécifiques : restauration exacte.
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_check_period TO 'compensation_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_submit TO 'compensation_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_decide TO 'compensation_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_submit TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_decide TO 'svc_php'@'localhost';
