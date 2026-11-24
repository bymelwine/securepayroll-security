-- NEW procedures only. No replacement or replay of installed compensation objects.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone='+00:00';
SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
DELIMITER $$
CREATE DEFINER='payroll_owner'@'localhost' PROCEDURE payroll_assert(
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
 SELECT ra.account_id INTO v_grant FROM role_assignments ra JOIN roles r ON r.id=ra.role_id
 JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id
 WHERE ra.account_id=p_actor AND r.code=p_role AND p.code=p_permission
 AND ra.valid_from<=UTC_TIMESTAMP(6) AND (ra.expires_at IS NULL OR ra.expires_at>UTC_TIMESTAMP(6))
 AND (ra.revoked_at IS NULL OR ra.revoked_at>UTC_TIMESTAMP(6)) LIMIT 1 FOR SHARE;
 IF p_role<>'employee' THEN
  SELECT a.id INTO v_conflict FROM accounts a JOIN role_assignments ra ON ra.account_id=a.id
  JOIN roles r ON r.id=ra.role_id WHERE a.employee_id=p_person
  AND r.code IN ('hr_manager','payroll_manager','finance_manager','application_administrator','security_auditor')
  AND r.code<>p_role AND ra.valid_from<=UTC_TIMESTAMP(6)
  AND (ra.expires_at IS NULL OR ra.expires_at>UTC_TIMESTAMP(6))
  AND (ra.revoked_at IS NULL OR ra.revoked_at>UTC_TIMESTAMP(6)) LIMIT 1 FOR SHARE;
 END IF;
 IF p_person IS NULL OR v_grant IS NULL OR v_conflict IS NOT NULL THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_FORBIDDEN';
 END IF;
END$$

CREATE DEFINER='payroll_owner'@'localhost' PROCEDURE payroll_period_create(
 IN p_month DATE, IN p_bonus_cap DECIMAL(12,2), IN p_deduction_cap DECIMAL(12,2),
 IN p_reason VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_id BIGINT UNSIGNED;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 CALL payroll_assert(p_actor,'payroll.period.create','finance_manager',v_person);
 IF p_month IS NULL OR p_month<'1900-01-01' OR DAYOFMONTH(p_month)<>1
 OR p_bonus_cap IS NULL OR p_bonus_cap<0 OR p_deduction_cap IS NULL OR p_deduction_cap<0
 OR p_reason IS NULL OR CHAR_LENGTH(TRIM(p_reason))=0 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_INVALID';
 END IF;
 INSERT INTO payroll_periods(month_start,bonus_cap,deduction_cap,reason,created_by,creator_employee_id)
 VALUES(p_month,p_bonus_cap,p_deduction_cap,TRIM(p_reason),p_actor,v_person);
 SET v_id=LAST_INSERT_ID();
 COMMIT;
 SELECT v_id AS id;
END$$

CREATE DEFINER='payroll_owner'@'localhost' PROCEDURE payroll_input_submit(
 IN p_period BIGINT UNSIGNED, IN p_employee BIGINT UNSIGNED,
 IN p_kind VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_amount DECIMAL(12,2), IN p_start DATE, IN p_end DATE,
 IN p_reason VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_parent BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_month DATE DEFAULT NULL;
 DECLARE v_id BIGINT UNSIGNED;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
 START TRANSACTION;
 SELECT id INTO v_parent FROM employees WHERE id=p_employee AND archived_at IS NULL FOR UPDATE;
 CALL payroll_assert(p_actor,'payroll.input.submit','hr_manager',v_person);
 SELECT month_start INTO v_month FROM payroll_periods WHERE id=p_period;
 IF v_parent IS NULL OR v_month IS NULL OR p_start IS NULL OR p_end IS NULL
 OR p_start<v_month OR p_end>LAST_DAY(v_month) OR p_end<p_start
 OR p_reason IS NULL OR CHAR_LENGTH(TRIM(p_reason))=0 OR p_kind IS NULL OR p_amount IS NULL
 OR NOT ((p_kind IN ('bonus','deduction') AND p_amount>0) OR (p_kind IN ('paid_leave','unpaid_leave') AND p_amount=0)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_INVALID';
 END IF;
 IF EXISTS(SELECT 1 FROM payroll_approvals WHERE employee_id=p_employee AND period_id=p_period) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_FROZEN';
 END IF;
 INSERT INTO payroll_inputs(period_id,employee_id,kind,amount,starts_on,ends_on,reason,created_by,creator_employee_id)
 VALUES(p_period,p_employee,p_kind,p_amount,p_start,p_end,TRIM(p_reason),p_actor,v_person);
 SET v_id=LAST_INSERT_ID();
 COMMIT;
 SELECT v_id AS id;
END$$

CREATE DEFINER='payroll_owner'@'localhost' PROCEDURE payroll_input_decide(
 IN p_input BIGINT UNSIGNED,
 IN p_decision VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_reason VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE v_employee BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_period BIGINT UNSIGNED;
 DECLARE v_creator BIGINT UNSIGNED;
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_parent BIGINT UNSIGNED;
 DECLARE v_id BIGINT UNSIGNED;
 DECLARE v_bonus DECIMAL(20,2);
 DECLARE v_deduction DECIMAL(20,2);
 DECLARE v_day DATE;
 DECLARE v_end DATE;
 DECLARE v_kind VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
 DECLARE v_coverage INT;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
 START TRANSACTION;
 SELECT employee_id,period_id,creator_employee_id INTO v_employee,v_period,v_creator FROM payroll_inputs WHERE id=p_input;
 SELECT id INTO v_parent FROM employees WHERE id=v_employee AND archived_at IS NULL FOR UPDATE;
 CALL payroll_assert(p_actor,'payroll.input.decide','finance_manager',v_person);
 IF v_employee IS NULL OR v_parent IS NULL OR v_person IN (v_employee,v_creator)
 OR p_decision IS NULL OR p_decision NOT IN ('approved','rejected')
 OR p_reason IS NULL OR CHAR_LENGTH(TRIM(p_reason))=0 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_FORBIDDEN';
 END IF;
 IF EXISTS(SELECT 1 FROM payroll_input_decisions WHERE input_id=p_input) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_DECIDED';
 END IF;
 IF EXISTS(SELECT 1 FROM payroll_approvals WHERE employee_id=v_employee AND period_id=v_period) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_FROZEN';
 END IF;
 IF p_decision='approved' THEN
  SELECT kind,starts_on,ends_on INTO v_kind,v_day,v_end FROM payroll_inputs WHERE id=p_input;
  IF v_kind IN ('paid_leave','unpaid_leave') THEN
   coverage_loop: LOOP
    SELECT COUNT(*) INTO v_coverage FROM employment_contracts WHERE employee_id=v_employee
     AND starts_on<=v_day AND (effective_ends_on IS NULL OR effective_ends_on>=v_day);
    IF v_coverage<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_COVERAGE'; END IF;
    IF v_day=v_end THEN LEAVE coverage_loop; END IF;
    SET v_day=DATE_ADD(v_day,INTERVAL 1 DAY);
   END LOOP;
  END IF;
  SELECT COALESCE(SUM(IF(i.kind='bonus',i.amount,0)),0),COALESCE(SUM(IF(i.kind='deduction',i.amount,0)),0)
   INTO v_bonus,v_deduction FROM payroll_inputs i LEFT JOIN payroll_input_decisions d ON d.input_id=i.id
   WHERE i.employee_id=v_employee AND i.period_id=v_period AND (d.decision='approved' OR i.id=p_input);
  IF EXISTS(SELECT 1 FROM payroll_periods WHERE id=v_period AND (v_bonus>bonus_cap OR v_deduction>deduction_cap)) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_CAP';
  END IF;
  IF EXISTS(SELECT 1 FROM payroll_inputs i JOIN payroll_inputs n ON n.id=p_input
   JOIN payroll_input_decisions d ON d.input_id=i.id AND d.decision='approved'
   WHERE n.kind IN ('paid_leave','unpaid_leave') AND i.kind IN ('paid_leave','unpaid_leave')
   AND i.employee_id=v_employee AND i.period_id=v_period AND i.starts_on<=n.ends_on AND i.ends_on>=n.starts_on) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_OVERLAP';
  END IF;
 END IF;
 INSERT INTO payroll_input_decisions(input_id,decision,reason,decided_by,approver_employee_id)
 VALUES(p_input,p_decision,TRIM(p_reason),p_actor,v_person);
 SET v_id=LAST_INSERT_ID();
 COMMIT;
 SELECT v_id AS id;
END$$

-- Internal only. Caller holds exclusive employee lock and uses READ COMMITTED.
CREATE DEFINER='payroll_owner'@'localhost' PROCEDURE payroll_build(
 IN p_period BIGINT UNSIGNED, IN p_employee BIGINT UNSIGNED,
 OUT p_base DECIMAL(12,2), OUT p_bonus DECIMAL(12,2), OUT p_deduction DECIMAL(12,2), OUT p_snapshot JSON)
SQL SECURITY DEFINER READS SQL DATA
BEGIN
 DECLARE v_month DATE DEFAULT NULL;
 DECLARE v_day DATE;
 DECLARE v_contract BIGINT UNSIGNED;
 DECLARE v_condition BIGINT UNSIGNED;
 DECLARE v_count INT;
 DECLARE v_days INT DEFAULT 0;
 DECLARE v_amount DECIMAL(12,2);
 DECLARE v_numerator DECIMAL(24,2) DEFAULT 0;
 DECLARE v_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
 DECLARE v_bonus_cap DECIMAL(12,2);
 DECLARE v_deduction_cap DECIMAL(12,2);
 DECLARE v_total_bonus DECIMAL(20,2);
 DECLARE v_total_deduction DECIMAL(20,2);
 DECLARE v_unpaid INT;
 DECLARE v_pending INT;
 DECLARE v_input BIGINT UNSIGNED DEFAULT 0;
 DECLARE v_next BIGINT UNSIGNED;
 DECLARE v_inputs JSON;
 DECLARE v_calendar JSON;
 DECLARE v_row JSON;
 DECLARE v_good INT;
 SELECT month_start,bonus_cap,deduction_cap INTO v_month,v_bonus_cap,v_deduction_cap FROM payroll_periods WHERE id=p_period;
 IF v_month IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_INVALID'; END IF;
 SELECT COUNT(*) INTO v_pending FROM payroll_inputs i LEFT JOIN payroll_input_decisions d ON d.input_id=i.id
 WHERE i.period_id=p_period AND i.employee_id=p_employee AND d.id IS NULL;
 IF v_pending>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_PENDING'; END IF;
 SET v_day=v_month;
 SET v_calendar=JSON_ARRAY();
 SET v_inputs=JSON_ARRAY();
 calendar_loop: WHILE v_day<=LAST_DAY(v_month) DO
  SELECT COUNT(*),MAX(id) INTO v_count,v_contract FROM employment_contracts
   WHERE employee_id=p_employee AND starts_on<=v_day AND (effective_ends_on IS NULL OR effective_ends_on>=v_day);
  SELECT COUNT(*),COALESCE(SUM(i.kind='unpaid_leave'),0) INTO v_good,v_unpaid FROM payroll_inputs i
   JOIN payroll_input_decisions d ON d.input_id=i.id AND d.decision='approved'
   WHERE i.period_id=p_period AND i.employee_id=p_employee AND i.kind IN ('paid_leave','unpaid_leave')
   AND i.starts_on<=v_day AND i.ends_on>=v_day;
  IF v_count>1 OR v_good>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_OVERLAP'; END IF;
  IF v_count=0 AND v_good>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_COVERAGE'; END IF;
  IF v_count=1 THEN
   SET v_condition=NULL;
   SELECT id,monthly_amount,currency INTO v_condition,v_amount,v_currency FROM salary_conditions
    WHERE contract_id=v_contract AND employee_id=p_employee AND effective_on<=v_day
    ORDER BY effective_on DESC LIMIT 1;
   IF v_condition IS NULL OR v_currency<>'MAD' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_SALARY'; END IF;
   SELECT COUNT(*) INTO v_good FROM salary_conditions s
    JOIN compensation_decisions d ON d.id=s.decision_id AND d.request_id=s.request_id AND d.decision='approved'
     AND d.approver_employee_id<>s.employee_id
    JOIN compensation_requests q ON q.id=s.request_id AND q.employee_id=s.employee_id AND q.contract_id=s.contract_id
     AND q.monthly_amount=s.monthly_amount AND q.currency=s.currency AND q.effective_on=s.effective_on
     AND q.requester_employee_id<>d.approver_employee_id
    WHERE s.id=v_condition;
   IF v_good<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_SALARY'; END IF;
   SET v_days=v_days+1;
   IF v_unpaid=0 THEN SET v_numerator=v_numerator+v_amount; END IF;
   SELECT JSON_OBJECT('date',v_day,'contract_id',v_contract,'starts_on',starts_on,'ends_on',effective_ends_on,
     'salary_condition_id',v_condition,'monthly_amount',CAST(v_amount AS CHAR),'paid',IF(v_unpaid=0,1,0)) INTO v_row
    FROM employment_contracts WHERE id=v_contract;
   SET v_calendar=JSON_ARRAY_APPEND(v_calendar,'$',v_row);
  END IF;
  IF v_day=LAST_DAY(v_month) THEN LEAVE calendar_loop; END IF;
  SET v_day=DATE_ADD(v_day,INTERVAL 1 DAY);
 END WHILE;
 IF v_days=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_COVERAGE'; END IF;
 SELECT COALESCE(SUM(IF(i.kind='bonus',i.amount,0)),0),COALESCE(SUM(IF(i.kind='deduction',i.amount,0)),0)
  INTO v_total_bonus,v_total_deduction FROM payroll_inputs i JOIN payroll_input_decisions d ON d.input_id=i.id AND d.decision='approved'
  WHERE i.period_id=p_period AND i.employee_id=p_employee;
 IF v_total_bonus>v_bonus_cap OR v_total_deduction>v_deduction_cap THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_CAP'; END IF;
 IF v_total_bonus>9999999999.99 OR v_total_deduction>9999999999.99 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_INVALID'; END IF;
 SET p_base=ROUND(v_numerator/DAYOFMONTH(LAST_DAY(v_month)),2);
 SET p_bonus=v_total_bonus;
 SET p_deduction=v_total_deduction;
 IF p_base+p_bonus>9999999999.99 OR p_deduction>p_base+p_bonus THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_NET'; END IF;
 input_loop: LOOP
  SELECT MIN(i.id) INTO v_next FROM payroll_inputs i JOIN payroll_input_decisions d ON d.input_id=i.id AND d.decision='approved'
   WHERE i.period_id=p_period AND i.employee_id=p_employee AND i.id>v_input;
  IF v_next IS NULL THEN LEAVE input_loop; END IF;
  SELECT JSON_OBJECT('id',i.id,'decision_id',d.id,'kind',i.kind,'amount',CAST(i.amount AS CHAR),
   'starts_on',i.starts_on,'ends_on',i.ends_on) INTO v_row
   FROM payroll_inputs i JOIN payroll_input_decisions d ON d.input_id=i.id WHERE i.id=v_next;
  SET v_inputs=JSON_ARRAY_APPEND(v_inputs,'$',v_row);
  SET v_input=v_next;
 END LOOP;
 SET p_snapshot=JSON_OBJECT('algorithm','calendar-days-v1','month',DATE_FORMAT(v_month,'%Y-%m'),
  'employee_id',p_employee,'currency','MAD','bonus_cap',CAST(v_bonus_cap AS CHAR),
  'deduction_cap',CAST(v_deduction_cap AS CHAR),'days',v_calendar,'inputs',v_inputs);
END$$

CREATE DEFINER='payroll_owner'@'localhost' PROCEDURE payroll_calculate(
 IN p_period BIGINT UNSIGNED, IN p_employee BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_parent BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_version INT UNSIGNED;
 DECLARE v_base DECIMAL(12,2);
 DECLARE v_bonus DECIMAL(12,2);
 DECLARE v_deduction DECIMAL(12,2);
 DECLARE v_snapshot JSON;
 DECLARE v_id BIGINT UNSIGNED;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
 START TRANSACTION;
 SELECT id INTO v_parent FROM employees WHERE id=p_employee AND archived_at IS NULL FOR UPDATE;
 CALL payroll_assert(p_actor,'payroll.calculate','payroll_manager',v_person);
 IF v_parent IS NULL OR v_person=p_employee THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_FORBIDDEN'; END IF;
 IF EXISTS(SELECT 1 FROM payroll_approvals WHERE period_id=p_period AND employee_id=p_employee) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_FROZEN';
 END IF;
 CALL payroll_build(p_period,p_employee,v_base,v_bonus,v_deduction,v_snapshot);
 SELECT COALESCE(MAX(version_no),0)+1 INTO v_version FROM payroll_calculations WHERE period_id=p_period AND employee_id=p_employee;
 INSERT INTO payroll_calculations(period_id,employee_id,version_no,algorithm,base_amount,bonus_amount,deduction_amount,
  gross_amount,net_amount,snapshot,source_hash,prepared_by,preparer_employee_id)
 VALUES(p_period,p_employee,v_version,'calendar-days-v1',v_base,v_bonus,v_deduction,v_base+v_bonus,v_base+v_bonus-v_deduction,
  v_snapshot,SHA2(CAST(v_snapshot AS CHAR),256),p_actor,v_person);
 SET v_id=LAST_INSERT_ID();
 COMMIT;
 SELECT v_id AS id;
END$$

CREATE DEFINER='payroll_owner'@'localhost' PROCEDURE payslip_transition(
 IN p_calculation BIGINT UNSIGNED,
 IN p_event VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_reason VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE v_employee BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_period BIGINT UNSIGNED;
 DECLARE v_preparer BIGINT UNSIGNED;
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_parent BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin;
 DECLARE v_snapshot JSON;
 DECLARE v_base DECIMAL(12,2);
 DECLARE v_bonus DECIMAL(12,2);
 DECLARE v_deduction DECIMAL(12,2);
 DECLARE v_id BIGINT UNSIGNED;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
 START TRANSACTION;
 SELECT employee_id,period_id,preparer_employee_id,source_hash INTO v_employee,v_period,v_preparer,v_hash
  FROM payroll_calculations WHERE id=p_calculation;
 SELECT id INTO v_parent FROM employees WHERE id=v_employee AND archived_at IS NULL FOR UPDATE;
 IF p_event IN ('approved','rejected') THEN
  CALL payroll_assert(p_actor,'payslip.decide','finance_manager',v_person);
 ELSEIF p_event IN ('verified','published') THEN
  CALL payroll_assert(p_actor,IF(p_event='verified','payslip.verify','payslip.publish'),'payroll_manager',v_person);
 ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_INVALID';
 END IF;
 IF v_employee IS NULL OR v_parent IS NULL OR v_person=v_employee
 OR p_reason IS NULL OR CHAR_LENGTH(TRIM(p_reason))=0
 OR (p_event IN ('approved','rejected') AND v_person=v_preparer) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_FORBIDDEN';
 END IF;
 IF p_event='published' THEN
  IF NOT EXISTS(SELECT 1 FROM payroll_approvals WHERE calculation_id=p_calculation) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_STATE';
  END IF;
 ELSE
  IF EXISTS(SELECT 1 FROM payroll_approvals WHERE employee_id=v_employee AND period_id=v_period)
   OR EXISTS(SELECT 1 FROM payslip_events WHERE calculation_id=p_calculation AND event_type IN ('approved','rejected')) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_FROZEN';
  END IF;
  IF p_calculation<>(SELECT id FROM payroll_calculations WHERE employee_id=v_employee AND period_id=v_period ORDER BY version_no DESC LIMIT 1) THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_STALE';
  END IF;
  IF p_event IN ('approved','rejected') AND NOT EXISTS(SELECT 1 FROM payslip_events WHERE calculation_id=p_calculation AND event_type='verified') THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_STATE';
  END IF;
  IF p_event IN ('verified','approved') THEN
   CALL payroll_build(v_period,v_employee,v_base,v_bonus,v_deduction,v_snapshot);
   IF SHA2(CAST(v_snapshot AS CHAR),256)<>v_hash OR NOT EXISTS(SELECT 1 FROM payroll_calculations
    WHERE id=p_calculation AND base_amount=v_base AND bonus_amount=v_bonus AND deduction_amount=v_deduction
     AND gross_amount=v_base+v_bonus AND net_amount=v_base+v_bonus-v_deduction) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_STALE';
   END IF;
  END IF;
 END IF;
 INSERT INTO payslip_events(calculation_id,event_type,reason,actor_id,actor_employee_id)
 VALUES(p_calculation,p_event,TRIM(p_reason),p_actor,v_person);
 SET v_id=LAST_INSERT_ID();
 IF p_event='approved' THEN
  INSERT INTO payroll_approvals(calculation_id,period_id,employee_id) VALUES(p_calculation,v_period,v_employee);
 END IF;
 COMMIT;
 SELECT v_id AS id;
END$$

CREATE DEFINER='payroll_owner'@'localhost' PROCEDURE payroll_read_assert(
 IN p_actor BIGINT UNSIGNED, OUT p_person BIGINT UNSIGNED, OUT p_staff BOOLEAN)
SQL SECURITY DEFINER READS SQL DATA
BEGIN
 DECLARE v_role VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL;
 SELECT MIN(r.code) INTO v_role FROM role_assignments ra JOIN roles r ON r.id=ra.role_id
 WHERE ra.account_id=p_actor AND r.code IN ('hr_manager','payroll_manager','finance_manager','security_auditor')
 AND ra.valid_from<=UTC_TIMESTAMP(6) AND (ra.expires_at IS NULL OR ra.expires_at>UTC_TIMESTAMP(6))
 AND (ra.revoked_at IS NULL OR ra.revoked_at>UTC_TIMESTAMP(6));
 SET p_staff=(v_role IS NOT NULL);
 CALL payroll_assert(p_actor,IF(p_staff,'payroll.read','payslip.self'),COALESCE(v_role,'employee'),p_person);
END$$

CREATE DEFINER='payroll_owner'@'localhost' PROCEDURE payroll_overview(
 IN p_actor BIGINT UNSIGNED, IN p_page INT UNSIGNED)
SQL SECURITY DEFINER READS SQL DATA
BEGIN
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_staff BOOLEAN;
 DECLARE v_offset INT UNSIGNED;
 CALL payroll_read_assert(p_actor,v_person,v_staff);
 IF p_page IS NULL OR p_page<1 OR p_page>1000000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_INVALID'; END IF;
 SET v_offset=(p_page-1)*50;
 SELECT * FROM payroll_periods WHERE v_staff ORDER BY month_start DESC LIMIT 120;
 SELECT id,employee_number,first_name,last_name FROM employees WHERE v_staff AND archived_at IS NULL ORDER BY employee_number LIMIT 500;
 SELECT i.*,e.employee_number,COALESCE(d.decision,'pending') AS status,d.reason AS decision_reason
 FROM payroll_inputs i JOIN employees e ON e.id=i.employee_id LEFT JOIN payroll_input_decisions d ON d.input_id=i.id
 WHERE v_staff ORDER BY i.id DESC LIMIT 50 OFFSET v_offset;
 SELECT c.id,c.period_id,c.employee_id,c.version_no,c.currency,c.base_amount,c.bonus_amount,c.deduction_amount,
 c.gross_amount,c.net_amount,c.prepared_at,p.month_start,e.employee_number,e.first_name,e.last_name,
 COALESCE((SELECT event_type FROM payslip_events WHERE calculation_id=c.id ORDER BY id DESC LIMIT 1),'prepared') AS status
 FROM payroll_calculations c JOIN payroll_periods p ON p.id=c.period_id JOIN employees e ON e.id=c.employee_id
 WHERE v_staff OR (c.employee_id=v_person AND EXISTS(SELECT 1 FROM payslip_events WHERE calculation_id=c.id AND event_type='published'))
 ORDER BY c.id DESC LIMIT 50 OFFSET v_offset;
END$$

CREATE DEFINER='payroll_owner'@'localhost' PROCEDURE payslip_detail(
 IN p_calculation BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER READS SQL DATA
BEGIN
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_staff BOOLEAN;
 CALL payroll_read_assert(p_actor,v_person,v_staff);
 IF NOT EXISTS(SELECT 1 FROM payroll_calculations c WHERE c.id=p_calculation AND
  (v_staff OR (c.employee_id=v_person AND EXISTS(SELECT 1 FROM payslip_events WHERE calculation_id=c.id AND event_type='published')))) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_NOT_FOUND';
 END IF;
 SELECT c.*,p.month_start,e.employee_number,e.first_name,e.last_name,
 COALESCE((SELECT event_type FROM payslip_events WHERE calculation_id=c.id ORDER BY id DESC LIMIT 1),'prepared') AS status
 FROM payroll_calculations c JOIN payroll_periods p ON p.id=c.period_id JOIN employees e ON e.id=c.employee_id
 WHERE c.id=p_calculation;
 SELECT event_type,reason,actor_id,actor_employee_id,occurred_at FROM payslip_events WHERE calculation_id=p_calculation ORDER BY id;
 SELECT c.id,c.version_no,c.prepared_at,c.net_amount,c.currency FROM payroll_calculations c
 JOIN payroll_calculations original ON original.id=p_calculation AND c.employee_id=original.employee_id AND c.period_id=original.period_id
 WHERE v_staff OR EXISTS(SELECT 1 FROM payslip_events WHERE calculation_id=c.id AND event_type='published') ORDER BY c.version_no;
END$$
DELIMITER ;
