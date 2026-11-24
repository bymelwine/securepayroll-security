-- Additive migration: existing payslips and approvals are never updated.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
CREATE TABLE payslip_corrections (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 calculation_id BIGINT UNSIGNED NOT NULL,
 parent_id BIGINT UNSIGNED NULL,
 revision INT UNSIGNED NOT NULL,
 base_amount DECIMAL(12,2) NOT NULL,
 bonus_amount DECIMAL(12,2) NOT NULL,
 deduction_amount DECIMAL(12,2) NOT NULL,
 gross_amount DECIMAL(12,2) NOT NULL,
 net_amount DECIMAL(12,2) NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'MAD',
 reason VARCHAR(2000) NOT NULL,
 prepared_by BIGINT UNSIGNED NOT NULL,
 preparer_employee_id BIGINT UNSIGNED NOT NULL,
 prepared_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_correction_revision(calculation_id,revision),
 UNIQUE KEY uq_correction_root(id,calculation_id),
 FOREIGN KEY(calculation_id) REFERENCES payroll_calculations(id),
 FOREIGN KEY(parent_id,calculation_id) REFERENCES payslip_corrections(id,calculation_id),
 FOREIGN KEY(prepared_by) REFERENCES accounts(id),
 FOREIGN KEY(preparer_employee_id) REFERENCES employees(id),
 CHECK(revision>0 AND currency='MAD' AND base_amount>=0 AND bonus_amount>=0 AND deduction_amount>=0 AND gross_amount=base_amount+bonus_amount AND net_amount=gross_amount-deduction_amount AND net_amount>=0),
 CHECK(CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;
CREATE TABLE correction_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 correction_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(16) NOT NULL,
 reason VARCHAR(2000) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 actor_employee_id BIGINT UNSIGNED NOT NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_correction_event(correction_id,event_type),
 FOREIGN KEY(correction_id) REFERENCES payslip_corrections(id),
 FOREIGN KEY(actor_id) REFERENCES accounts(id),
 FOREIGN KEY(actor_employee_id) REFERENCES employees(id),
 CHECK(event_type IN ('verified','approved','rejected','published')),
 CHECK(CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;
CREATE TABLE recovery_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 calculation_id BIGINT UNSIGNED NOT NULL,
 correction_id BIGINT UNSIGNED NULL,
 amount DECIMAL(12,2) NOT NULL,
 reason VARCHAR(2000) NOT NULL,
 requested_by BIGINT UNSIGNED NOT NULL,
 requester_employee_id BIGINT UNSIGNED NOT NULL,
 requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY(calculation_id) REFERENCES payroll_calculations(id),
 FOREIGN KEY(correction_id,calculation_id) REFERENCES payslip_corrections(id,calculation_id),
 FOREIGN KEY(requested_by) REFERENCES accounts(id),
 FOREIGN KEY(requester_employee_id) REFERENCES employees(id),
 CHECK(amount>0), CHECK(CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;
CREATE TABLE recovery_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(16) NOT NULL,
 reason VARCHAR(2000) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 actor_employee_id BIGINT UNSIGNED NOT NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_recovery_event(request_id,event_type),
 FOREIGN KEY(request_id) REFERENCES recovery_requests(id),
 FOREIGN KEY(actor_id) REFERENCES accounts(id),
 FOREIGN KEY(actor_employee_id) REFERENCES employees(id),
 CHECK(event_type IN ('approved','rejected','received')),
 CHECK(CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;
CREATE TABLE settlement_ledger (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 calculation_id BIGINT UNSIGNED NOT NULL,
 correction_id BIGINT UNSIGNED NULL,
 recovery_id BIGINT UNSIGNED NULL,
 entry_type VARCHAR(16) NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'MAD',
 reference VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reason VARCHAR(2000) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 actor_employee_id BIGINT UNSIGNED NOT NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_settlement_reference(reference),
 UNIQUE KEY uq_recovery_receipt(recovery_id),
 FOREIGN KEY(calculation_id) REFERENCES payroll_calculations(id),
 FOREIGN KEY(correction_id,calculation_id) REFERENCES payslip_corrections(id,calculation_id),
 FOREIGN KEY(recovery_id) REFERENCES recovery_requests(id),
 FOREIGN KEY(actor_id) REFERENCES accounts(id),
 FOREIGN KEY(actor_employee_id) REFERENCES employees(id),
 CHECK(amount>0 AND currency='MAD'),
 CHECK((entry_type='payment' AND recovery_id IS NULL) OR (entry_type='recovery' AND recovery_id IS NOT NULL)),
 CHECK(CHAR_LENGTH(reference) BETWEEN 8 AND 64),
 CHECK(CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;
DELIMITER $$
CREATE DEFINER='settlement_owner'@'localhost' TRIGGER payslip_corrections_no_update BEFORE UPDATE ON payslip_corrections FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE'; END$$
CREATE DEFINER='settlement_owner'@'localhost' TRIGGER payslip_corrections_no_delete BEFORE DELETE ON payslip_corrections FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE'; END$$
CREATE DEFINER='settlement_owner'@'localhost' TRIGGER correction_events_no_update BEFORE UPDATE ON correction_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE'; END$$
CREATE DEFINER='settlement_owner'@'localhost' TRIGGER correction_events_no_delete BEFORE DELETE ON correction_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE'; END$$
CREATE DEFINER='settlement_owner'@'localhost' TRIGGER recovery_requests_no_update BEFORE UPDATE ON recovery_requests FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE'; END$$
CREATE DEFINER='settlement_owner'@'localhost' TRIGGER recovery_requests_no_delete BEFORE DELETE ON recovery_requests FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE'; END$$
CREATE DEFINER='settlement_owner'@'localhost' TRIGGER recovery_events_no_update BEFORE UPDATE ON recovery_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE'; END$$
CREATE DEFINER='settlement_owner'@'localhost' TRIGGER recovery_events_no_delete BEFORE DELETE ON recovery_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE'; END$$
CREATE DEFINER='settlement_owner'@'localhost' TRIGGER settlement_ledger_no_update BEFORE UPDATE ON settlement_ledger FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE'; END$$
CREATE DEFINER='settlement_owner'@'localhost' TRIGGER settlement_ledger_no_delete BEFORE DELETE ON settlement_ledger FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PAY_IMMUTABLE'; END$$
DELIMITER ;
