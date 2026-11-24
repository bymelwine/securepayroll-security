-- Mission 06A : UNE SEULE FOIS après 003 et sa correction de collation.
-- Validation DBA requise. Aucun changement des données RH existantes.
-- Propriétaire compensation_owner@localhost préalablement créé et verrouillé.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone = '+00:00';
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE TABLE compensation_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 employee_id BIGINT UNSIGNED NOT NULL,
 contract_id BIGINT UNSIGNED NOT NULL,
 request_kind VARCHAR(16) NOT NULL,
 monthly_amount DECIMAL(12,2) NOT NULL,
 currency CHAR(2) NOT NULL DEFAULT 'UM',
 effective_on DATE NOT NULL,
 justification VARCHAR(2000) NOT NULL,
 requested_by BIGINT UNSIGNED NOT NULL,
 requester_employee_id BIGINT UNSIGNED NOT NULL,
 requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 KEY ix_cr_employee (employee_id,effective_on),
 CONSTRAINT fk_cr_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_cr_contract FOREIGN KEY (contract_id) REFERENCES employment_contracts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_cr_requester FOREIGN KEY (requested_by) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_cr_person FOREIGN KEY (requester_employee_id) REFERENCES employees(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT ck_cr_amount CHECK (monthly_amount > 0 AND currency='UM'),
 CONSTRAINT ck_cr_kind CHECK (request_kind IN ('initial','revision')),
 CONSTRAINT ck_cr_date CHECK (effective_on >= '1900-01-01' AND (request_kind='initial' OR DAYOFMONTH(effective_on)=1)),
 CONSTRAINT ck_cr_reason CHECK (CHAR_LENGTH(TRIM(justification)) BETWEEN 1 AND 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE compensation_decisions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 decision VARCHAR(16) NOT NULL,
 reason VARCHAR(2000) NOT NULL,
 decided_by BIGINT UNSIGNED NOT NULL,
 approver_employee_id BIGINT UNSIGNED NOT NULL,
 decided_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 CONSTRAINT uq_cd_request UNIQUE (request_id),
 CONSTRAINT uq_cd_request_id UNIQUE (request_id,id),
 CONSTRAINT fk_cd_request FOREIGN KEY (request_id) REFERENCES compensation_requests(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_cd_actor FOREIGN KEY (decided_by) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_cd_person FOREIGN KEY (approver_employee_id) REFERENCES employees(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT ck_cd_decision CHECK (decision IN ('approved','rejected')),
 CONSTRAINT ck_cd_reason CHECK (CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 2000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

-- Versions immuables : chaque date démarre une nouvelle condition ; sa fin est
-- dérivée de la version suivante du même contrat et de la fin effective du contrat.
CREATE TABLE salary_conditions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 employee_id BIGINT UNSIGNED NOT NULL,
 contract_id BIGINT UNSIGNED NOT NULL,
 request_id BIGINT UNSIGNED NOT NULL,
 decision_id BIGINT UNSIGNED NOT NULL,
 monthly_amount DECIMAL(12,2) NOT NULL,
 currency CHAR(2) NOT NULL DEFAULT 'UM',
 effective_on DATE NOT NULL,
 recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 CONSTRAINT uq_sc_request UNIQUE (request_id),
 CONSTRAINT uq_sc_employee_date UNIQUE (employee_id,effective_on),
 KEY ix_sc_contract_date (contract_id,effective_on),
 CONSTRAINT fk_sc_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_sc_contract FOREIGN KEY (contract_id) REFERENCES employment_contracts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_sc_decision FOREIGN KEY (request_id,decision_id) REFERENCES compensation_decisions(request_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT ck_sc_amount CHECK (monthly_amount>0 AND currency='UM'),
 CONSTRAINT ck_sc_date CHECK (effective_on >= '1900-01-01')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE compensation_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(16) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 CONSTRAINT uq_ch_event UNIQUE (request_id,event_type),
 CONSTRAINT fk_ch_request FOREIGN KEY (request_id) REFERENCES compensation_requests(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT fk_ch_actor FOREIGN KEY (actor_id) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT ck_ch_event CHECK (event_type IN ('submitted','approved','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

DELIMITER $$
CREATE DEFINER = 'compensation_owner'@'localhost' TRIGGER cr_no_update BEFORE UPDATE ON compensation_requests FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_IMMUTABLE';
END$$
CREATE DEFINER = 'compensation_owner'@'localhost' TRIGGER cr_no_delete BEFORE DELETE ON compensation_requests FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_IMMUTABLE';
END$$
CREATE DEFINER = 'compensation_owner'@'localhost' TRIGGER cd_no_update BEFORE UPDATE ON compensation_decisions FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_IMMUTABLE';
END$$
CREATE DEFINER = 'compensation_owner'@'localhost' TRIGGER cd_no_delete BEFORE DELETE ON compensation_decisions FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_IMMUTABLE';
END$$
CREATE DEFINER = 'compensation_owner'@'localhost' TRIGGER sc_no_update BEFORE UPDATE ON salary_conditions FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_IMMUTABLE';
END$$
CREATE DEFINER = 'compensation_owner'@'localhost' TRIGGER sc_no_delete BEFORE DELETE ON salary_conditions FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_IMMUTABLE';
END$$
CREATE DEFINER = 'compensation_owner'@'localhost' TRIGGER ch_no_update BEFORE UPDATE ON compensation_history FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_IMMUTABLE';
END$$
CREATE DEFINER = 'compensation_owner'@'localhost' TRIGGER ch_no_delete BEFORE DELETE ON compensation_history FOR EACH ROW
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_IMMUTABLE';
END$$

-- Extension de contrôle uniquement : aucune modification des contrats existants.
-- Empêche une clôture ultérieure d'invalider une date salariale déjà approuvée.
CREATE DEFINER = 'compensation_owner'@'localhost' TRIGGER ec_salary_coverage BEFORE UPDATE ON employment_contracts FOR EACH ROW
BEGIN
 DECLARE v_condition BIGINT UNSIGNED DEFAULT NULL;
 IF NOT (NEW.closed_on <=> OLD.closed_on) AND NEW.closed_on IS NOT NULL THEN
  BEGIN
   DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_condition=NULL;
   -- Lecture courante, même si hr_close_contract a déjà ouvert un snapshot RR.
   SELECT id INTO v_condition FROM salary_conditions
   WHERE contract_id=OLD.id AND effective_on>NEW.closed_on LIMIT 1 FOR SHARE;
  END;
  IF v_condition IS NOT NULL THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='COMP_CONTRACT_COVERAGE';
  END IF;
 END IF;
END$$
DELIMITER ;
