-- NEW objects only. Apply once after DBA review and preflight; never replay 001-006.
-- Requires locked payroll_owner@localhost. No existing table is altered.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone='+00:00';
SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE TABLE payroll_periods (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 month_start DATE NOT NULL,
 bonus_cap DECIMAL(12,2) NOT NULL,
 deduction_cap DECIMAL(12,2) NOT NULL,
 reason VARCHAR(2000) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 creator_employee_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_pp_month(month_start),
 CONSTRAINT fk_pp_actor FOREIGN KEY(created_by) REFERENCES accounts(id),
 CONSTRAINT fk_pp_person FOREIGN KEY(creator_employee_id) REFERENCES employees(id),
 CONSTRAINT ck_pp_month CHECK(month_start>='1900-01-01' AND DAYOFMONTH(month_start)=1),
 CONSTRAINT ck_pp_caps CHECK(bonus_cap>=0 AND deduction_cap>=0),
 CONSTRAINT ck_pp_reason CHECK(CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE payroll_inputs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 period_id BIGINT UNSIGNED NOT NULL,
 employee_id BIGINT UNSIGNED NOT NULL,
 kind VARCHAR(16) NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 starts_on DATE NOT NULL,
 ends_on DATE NOT NULL,
 reason VARCHAR(2000) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 creator_employee_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 KEY ix_pi_employee(period_id,employee_id),
 CONSTRAINT fk_pi_period FOREIGN KEY(period_id) REFERENCES payroll_periods(id),
 CONSTRAINT fk_pi_employee FOREIGN KEY(employee_id) REFERENCES employees(id),
 CONSTRAINT fk_pi_actor FOREIGN KEY(created_by) REFERENCES accounts(id),
 CONSTRAINT fk_pi_person FOREIGN KEY(creator_employee_id) REFERENCES employees(id),
 CONSTRAINT ck_pi_dates CHECK(starts_on>='1900-01-01' AND ends_on>=starts_on),
 CONSTRAINT ck_pi_amount CHECK((kind IN ('bonus','deduction') AND amount>0) OR (kind IN ('paid_leave','unpaid_leave') AND amount=0)),
 CONSTRAINT ck_pi_reason CHECK(CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE payroll_input_decisions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 input_id BIGINT UNSIGNED NOT NULL,
 decision VARCHAR(16) NOT NULL,
 reason VARCHAR(2000) NOT NULL,
 decided_by BIGINT UNSIGNED NOT NULL,
 approver_employee_id BIGINT UNSIGNED NOT NULL,
 decided_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_pid_input(input_id),
 CONSTRAINT fk_pid_input FOREIGN KEY(input_id) REFERENCES payroll_inputs(id),
 CONSTRAINT fk_pid_actor FOREIGN KEY(decided_by) REFERENCES accounts(id),
 CONSTRAINT fk_pid_person FOREIGN KEY(approver_employee_id) REFERENCES employees(id),
 CONSTRAINT ck_pid_decision CHECK(decision IN ('approved','rejected')),
 CONSTRAINT ck_pid_reason CHECK(CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE payroll_calculations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 period_id BIGINT UNSIGNED NOT NULL,
 employee_id BIGINT UNSIGNED NOT NULL,
 version_no INT UNSIGNED NOT NULL,
 algorithm VARCHAR(40) NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'MAD',
 base_amount DECIMAL(12,2) NOT NULL,
 bonus_amount DECIMAL(12,2) NOT NULL,
 deduction_amount DECIMAL(12,2) NOT NULL,
 gross_amount DECIMAL(12,2) NOT NULL,
 net_amount DECIMAL(12,2) NOT NULL,
 snapshot JSON NOT NULL,
 source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 prepared_by BIGINT UNSIGNED NOT NULL,
 preparer_employee_id BIGINT UNSIGNED NOT NULL,
 prepared_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_pc_version(period_id,employee_id,version_no),
 UNIQUE KEY uq_pc_identity(id,period_id,employee_id),
 CONSTRAINT fk_pc_period FOREIGN KEY(period_id) REFERENCES payroll_periods(id),
 CONSTRAINT fk_pc_employee FOREIGN KEY(employee_id) REFERENCES employees(id),
 CONSTRAINT fk_pc_actor FOREIGN KEY(prepared_by) REFERENCES accounts(id),
 CONSTRAINT fk_pc_person FOREIGN KEY(preparer_employee_id) REFERENCES employees(id),
 CONSTRAINT ck_pc_amounts CHECK(currency='MAD' AND base_amount>=0 AND bonus_amount>=0 AND deduction_amount>=0 AND gross_amount=base_amount+bonus_amount AND net_amount=gross_amount-deduction_amount AND net_amount>=0),
 CONSTRAINT ck_pc_version CHECK(version_no>0 AND algorithm='calendar-days-v1')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE payslip_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 calculation_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(16) NOT NULL,
 reason VARCHAR(2000) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 actor_employee_id BIGINT UNSIGNED NOT NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_pe_event(calculation_id,event_type),
 CONSTRAINT fk_pe_calc FOREIGN KEY(calculation_id) REFERENCES payroll_calculations(id),
 CONSTRAINT fk_pe_actor FOREIGN KEY(actor_id) REFERENCES accounts(id),
 CONSTRAINT fk_pe_person FOREIGN KEY(actor_employee_id) REFERENCES employees(id),
 CONSTRAINT ck_pe_type CHECK(event_type IN ('verified','approved','rejected','published')),
 CONSTRAINT ck_pe_reason CHECK(CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE payroll_approvals (
 calculation_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 period_id BIGINT UNSIGNED NOT NULL,
 employee_id BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_pa_employee_month(period_id,employee_id),
 CONSTRAINT fk_pa_calc FOREIGN KEY(calculation_id,period_id,employee_id) REFERENCES payroll_calculations(id,period_id,employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

DELIMITER $$
CREATE DEFINER='payroll_owner'@'localhost' TRIGGER payroll_periods_no_update BEFORE UPDATE ON payroll_periods FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE';
END$$
CREATE DEFINER='payroll_owner'@'localhost' TRIGGER payroll_periods_no_delete BEFORE DELETE ON payroll_periods FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE';
END$$
CREATE DEFINER='payroll_owner'@'localhost' TRIGGER payroll_inputs_no_update BEFORE UPDATE ON payroll_inputs FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE';
END$$
CREATE DEFINER='payroll_owner'@'localhost' TRIGGER payroll_inputs_no_delete BEFORE DELETE ON payroll_inputs FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE';
END$$
CREATE DEFINER='payroll_owner'@'localhost' TRIGGER payroll_input_decisions_no_update BEFORE UPDATE ON payroll_input_decisions FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE';
END$$
CREATE DEFINER='payroll_owner'@'localhost' TRIGGER payroll_input_decisions_no_delete BEFORE DELETE ON payroll_input_decisions FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE';
END$$
CREATE DEFINER='payroll_owner'@'localhost' TRIGGER payroll_calculations_no_update BEFORE UPDATE ON payroll_calculations FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE';
END$$
CREATE DEFINER='payroll_owner'@'localhost' TRIGGER payroll_calculations_no_delete BEFORE DELETE ON payroll_calculations FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE';
END$$
CREATE DEFINER='payroll_owner'@'localhost' TRIGGER payslip_events_no_update BEFORE UPDATE ON payslip_events FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE';
END$$
CREATE DEFINER='payroll_owner'@'localhost' TRIGGER payslip_events_no_delete BEFORE DELETE ON payslip_events FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE';
END$$
CREATE DEFINER='payroll_owner'@'localhost' TRIGGER payroll_approvals_no_update BEFORE UPDATE ON payroll_approvals FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE';
END$$
CREATE DEFINER='payroll_owner'@'localhost' TRIGGER payroll_approvals_no_delete BEFORE DELETE ON payroll_approvals FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE';
END$$
DELIMITER ;
