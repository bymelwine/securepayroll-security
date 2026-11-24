-- NEW routines. Payments are fictional ledger entries, never bank operations.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
DELIMITER $$
CREATE DEFINER='settlement_owner'@'localhost' PROCEDURE settlement_command(
 IN p_root BIGINT UNSIGNED,
 IN p_action VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_target BIGINT UNSIGNED, IN p_expected BIGINT UNSIGNED,
 IN p_base DECIMAL(12,2), IN p_bonus DECIMAL(12,2), IN p_deduction DECIMAL(12,2),
 IN p_reference VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_reason VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE v_employee BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_parent BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_preparer BIGINT UNSIGNED;
 DECLARE v_current BIGINT UNSIGNED DEFAULT 0;
 DECLARE v_revision INT UNSIGNED;
 DECLARE v_net DECIMAL(20,2);
 DECLARE v_balance DECIMAL(20,2);
 DECLARE v_amount DECIMAL(12,2);
 DECLARE v_requester BIGINT UNSIGNED;
 DECLARE v_id BIGINT UNSIGNED;
 DECLARE v_state VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
 DECLARE v_permission VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
 DECLARE v_role VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
 START TRANSACTION;
 SET v_permission=CASE p_action WHEN 'correct' THEN 'payslip.correct'
 WHEN 'verified' THEN 'payslip.verify' WHEN 'approved' THEN 'payslip.decide'
 WHEN 'rejected' THEN 'payslip.decide' WHEN 'published' THEN 'payslip.publish'
 WHEN 'pay' THEN 'payroll.payment' WHEN 'recover_request' THEN 'payroll.recovery.request'
 WHEN 'recover_approve' THEN 'payroll.recovery.decide' WHEN 'recover_reject' THEN 'payroll.recovery.decide'
 WHEN 'recover_receive' THEN 'payroll.recovery.receive' ELSE NULL END;
 IF v_permission IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_INVALID'; END IF;
 SET v_role=IF(p_action IN ('approved','rejected','pay','recover_approve','recover_reject'),'finance_manager','payroll_manager');
 SELECT employee_id,preparer_employee_id INTO v_employee,v_preparer FROM payroll_calculations WHERE id=p_root;
 SELECT id INTO v_parent FROM employees WHERE id=v_employee AND archived_at IS NULL FOR UPDATE;
 CALL payroll_assert(p_actor,v_permission,v_role,v_person);
 IF v_parent IS NULL OR NOT EXISTS(SELECT 1 FROM payslip_events WHERE calculation_id=p_root AND event_type='published') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_NOT_FOUND';
 END IF;
 IF v_person=v_employee THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_FORBIDDEN'; END IF;
 IF p_reason IS NULL OR CHAR_LENGTH(TRIM(p_reason)) NOT BETWEEN 1 AND 2000 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_INVALID';
 END IF;
 SELECT COALESCE(MAX(c.id),0) INTO v_current FROM payslip_corrections c
 JOIN correction_events e ON e.correction_id=c.id AND e.event_type='published' WHERE c.calculation_id=p_root;
 SELECT net_amount INTO v_net FROM payroll_calculations WHERE id=p_root;
 IF v_current<>0 THEN
  SELECT net_amount,preparer_employee_id INTO v_net,v_preparer FROM payslip_corrections WHERE id=v_current;
 END IF;
 SELECT v_net-COALESCE(SUM(IF(entry_type='payment',amount,-amount)),0) INTO v_balance
 FROM settlement_ledger WHERE calculation_id=p_root;
 IF p_expected IS NULL OR p_expected<>v_current THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_STALE'; END IF;
 IF p_action='correct' THEN
  IF EXISTS(SELECT 1 FROM payslip_corrections c WHERE c.calculation_id=p_root
   AND NOT EXISTS(SELECT 1 FROM correction_events e WHERE e.correction_id=c.id AND e.event_type IN ('published','rejected')))
   OR EXISTS(SELECT 1 FROM recovery_requests r WHERE r.calculation_id=p_root
   AND NOT EXISTS(SELECT 1 FROM recovery_events e WHERE e.request_id=r.id AND e.event_type IN ('received','rejected'))) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_STATE';
  END IF;
  IF p_base IS NULL OR p_bonus IS NULL OR p_deduction IS NULL OR p_base<0 OR p_bonus<0 OR p_deduction<0
   OR p_base+p_bonus>9999999999.99 OR p_base+p_bonus-p_deduction<0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_INVALID';
  END IF;
  IF EXISTS(SELECT 1 FROM payroll_calculations c JOIN payroll_periods p ON p.id=c.period_id
    WHERE c.id=p_root AND (p_bonus>p.bonus_cap OR p_deduction>p.deduction_cap)) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_CAP';
  END IF;
  -- Exceptional full replacement, explicitly reasoned and independently approved.
  -- Never rewrites the approved calendar or silently changes original sources.
  SELECT COALESCE(MAX(revision),0)+1 INTO v_revision FROM payslip_corrections WHERE calculation_id=p_root;
  INSERT INTO payslip_corrections(calculation_id,parent_id,revision,base_amount,bonus_amount,deduction_amount,gross_amount,net_amount,reason,prepared_by,preparer_employee_id)
  VALUES(p_root,NULLIF(v_current,0),v_revision,p_base,p_bonus,p_deduction,p_base+p_bonus,p_base+p_bonus-p_deduction,TRIM(p_reason),p_actor,v_person);
  SET v_id=LAST_INSERT_ID();
 ELSEIF p_action IN ('verified','approved','rejected','published') THEN
  SET v_state=NULL;
  SELECT c.preparer_employee_id,COALESCE((SELECT event_type FROM correction_events WHERE correction_id=c.id ORDER BY id DESC LIMIT 1),'prepared')
  INTO v_preparer,v_state FROM payslip_corrections c
  WHERE c.id=p_target AND c.calculation_id=p_root AND COALESCE(c.parent_id,0)=v_current;
  IF v_state IS NULL OR NOT ((p_action='verified' AND v_state='prepared')
    OR (p_action IN ('approved','rejected') AND v_state='verified') OR (p_action='published' AND v_state='approved')) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_STATE';
  END IF;
  IF p_action IN ('approved','rejected') AND v_person=v_preparer THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_FORBIDDEN';
  END IF;
  INSERT INTO correction_events(correction_id,event_type,reason,actor_id,actor_employee_id)
  VALUES(p_target,p_action,TRIM(p_reason),p_actor,v_person);
  SET v_id=LAST_INSERT_ID();
 ELSE
  -- Do not settle an old version while another version is being reviewed.
  IF EXISTS(SELECT 1 FROM payslip_corrections c WHERE c.calculation_id=p_root
   AND NOT EXISTS(SELECT 1 FROM correction_events e WHERE e.correction_id=c.id AND e.event_type IN ('published','rejected'))) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_STATE';
  END IF;
  IF p_action IN ('pay','recover_receive') AND (p_reference IS NULL OR NOT REGEXP_LIKE(p_reference,'^[A-Za-z0-9_-]{8,64}$','c')) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_INVALID';
  END IF;
  IF p_action='pay' THEN
   IF v_person=v_preparer THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_FORBIDDEN'; END IF;
   IF v_balance<=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_STATE'; END IF;
   INSERT INTO settlement_ledger(calculation_id,correction_id,entry_type,amount,reference,reason,actor_id,actor_employee_id)
   VALUES(p_root,NULLIF(v_current,0),'payment',v_balance,p_reference,TRIM(p_reason),p_actor,v_person);
   SET v_id=LAST_INSERT_ID();
  ELSEIF p_action='recover_request' THEN
   IF v_balance>=0 OR EXISTS(SELECT 1 FROM recovery_requests r WHERE r.calculation_id=p_root
     AND NOT EXISTS(SELECT 1 FROM recovery_events e WHERE e.request_id=r.id AND e.event_type IN ('received','rejected'))) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_STATE';
   END IF;
   INSERT INTO recovery_requests(calculation_id,correction_id,amount,reason,requested_by,requester_employee_id)
   VALUES(p_root,NULLIF(v_current,0),-v_balance,TRIM(p_reason),p_actor,v_person);
   SET v_id=LAST_INSERT_ID();
  ELSE
   SET v_state=NULL;
   SELECT r.amount,r.requester_employee_id,COALESCE((SELECT event_type FROM recovery_events WHERE request_id=r.id ORDER BY id DESC LIMIT 1),'pending')
   INTO v_amount,v_requester,v_state FROM recovery_requests r
   WHERE r.id=p_target AND r.calculation_id=p_root AND COALESCE(r.correction_id,0)=v_current;
   IF v_state IS NULL OR NOT ((p_action IN ('recover_approve','recover_reject') AND v_state='pending')
     OR (p_action='recover_receive' AND v_state='approved')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_STATE';
   END IF;
   IF p_action IN ('recover_approve','recover_reject') AND v_person IN (v_requester,v_preparer) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_FORBIDDEN';
   END IF;
   IF p_action='recover_receive' THEN
    IF v_balance>=0 OR v_amount> -v_balance THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_STATE'; END IF;
    INSERT INTO settlement_ledger(calculation_id,correction_id,recovery_id,entry_type,amount,reference,reason,actor_id,actor_employee_id)
    VALUES(p_root,NULLIF(v_current,0),p_target,'recovery',v_amount,p_reference,TRIM(p_reason),p_actor,v_person);
   END IF;
   INSERT INTO recovery_events(request_id,event_type,reason,actor_id,actor_employee_id)
   VALUES(p_target,CASE p_action WHEN 'recover_approve' THEN 'approved' WHEN 'recover_reject' THEN 'rejected' ELSE 'received' END,TRIM(p_reason),p_actor,v_person);
   SET v_id=LAST_INSERT_ID();
  END IF;
 END IF;
 COMMIT;
 SELECT v_id AS id;
END$$

CREATE DEFINER='settlement_owner'@'localhost' PROCEDURE settlement_detail(IN p_root BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER READS SQL DATA
BEGIN
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_staff BOOLEAN;
 DECLARE v_employee BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_parent BIGINT UNSIGNED;
 DECLARE v_current BIGINT UNSIGNED DEFAULT 0;
 DECLARE v_net DECIMAL(12,2);
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 SELECT employee_id INTO v_employee FROM payroll_calculations WHERE id=p_root;
 SELECT id INTO v_parent FROM employees WHERE id=v_employee FOR SHARE;
 CALL payroll_read_assert(p_actor,v_person,v_staff);
 IF v_employee IS NULL OR (NOT v_staff AND v_person<>v_employee)
  OR NOT EXISTS(SELECT 1 FROM payslip_events WHERE calculation_id=p_root AND event_type='published') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_NOT_FOUND';
 END IF;
 SELECT COALESCE(MAX(c.id),0) INTO v_current FROM payslip_corrections c
 JOIN correction_events e ON e.correction_id=c.id AND e.event_type='published' WHERE c.calculation_id=p_root;
 SELECT net_amount INTO v_net FROM payroll_calculations WHERE id=p_root;
 IF v_current<>0 THEN SELECT net_amount INTO v_net FROM payslip_corrections WHERE id=v_current; END IF;
 SELECT p_root AS calculation_id,v_employee AS employee_id,v_current AS current_correction_id,v_net AS net_amount,
 v_net-COALESCE(SUM(IF(entry_type='payment',amount,-amount)),0) AS balance,'MAD' AS currency
 FROM settlement_ledger WHERE calculation_id=p_root;
 SELECT c.*,COALESCE((SELECT event_type FROM correction_events WHERE correction_id=c.id ORDER BY id DESC LIMIT 1),'prepared') AS status
 FROM payslip_corrections c WHERE c.calculation_id=p_root AND (v_staff OR EXISTS(SELECT 1 FROM correction_events WHERE correction_id=c.id AND event_type='published')) ORDER BY c.revision;
 SELECT e.* FROM correction_events e JOIN payslip_corrections c ON c.id=e.correction_id
 WHERE c.calculation_id=p_root AND (v_staff OR EXISTS(SELECT 1 FROM correction_events WHERE correction_id=c.id AND event_type='published')) ORDER BY e.id;
 SELECT * FROM settlement_ledger WHERE calculation_id=p_root ORDER BY id;
 SELECT r.*,COALESCE((SELECT event_type FROM recovery_events WHERE request_id=r.id ORDER BY id DESC LIMIT 1),'pending') AS status
 FROM recovery_requests r WHERE r.calculation_id=p_root AND (v_staff OR EXISTS(SELECT 1 FROM recovery_events WHERE request_id=r.id AND event_type='received')) ORDER BY r.id;
 SELECT e.* FROM recovery_events e JOIN recovery_requests r ON r.id=e.request_id WHERE r.calculation_id=p_root AND v_staff ORDER BY e.id;
 COMMIT;
END$$
DELIMITER ;
