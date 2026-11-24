-- Propriétaire obligatoire : hr_module_owner@localhost, créé et verrouillé avant ce fichier.
-- Mission 05A. À relire/valider et appliquer UNE SEULE FOIS après la migration 001.
-- Aucun changement aux six tables existantes. DDL à commits implicites.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone = '+00:00';
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE TABLE departments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    name_fr VARCHAR(120) NOT NULL,
    name_en VARCHAR(120) NOT NULL,
    archived_at DATETIME(6) NULL,
    CONSTRAINT uq_departments_code UNIQUE (code),
    CONSTRAINT ck_departments_text CHECK (CHAR_LENGTH(TRIM(code)) > 0
        AND CHAR_LENGTH(TRIM(name_fr)) > 0 AND CHAR_LENGTH(TRIM(name_en)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

-- Un poste est un métier réutilisable dans plusieurs départements.
CREATE TABLE positions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    name_fr VARCHAR(120) NOT NULL,
    name_en VARCHAR(120) NOT NULL,
    archived_at DATETIME(6) NULL,
    CONSTRAINT uq_positions_code UNIQUE (code),
    CONSTRAINT ck_positions_text CHECK (CHAR_LENGTH(TRIM(code)) > 0
        AND CHAR_LENGTH(TRIM(name_fr)) > 0 AND CHAR_LENGTH(TRIM(name_en)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE employee_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NULL,
    closed_on DATE NULL,
    effective_ends_on DATE GENERATED ALWAYS AS (COALESCE(closed_on, ends_on)) STORED,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_by BIGINT UNSIGNED NOT NULL,
    closed_at DATETIME(6) NULL,
    closed_by BIGINT UNSIGNED NULL,
    KEY ix_employee_assignments_period (employee_id, starts_on, effective_ends_on),
    CONSTRAINT fk_ea_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ea_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ea_position FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ea_creator FOREIGN KEY (created_by) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ea_closer FOREIGN KEY (closed_by) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_ea_dates CHECK (starts_on >= '1900-01-01' AND (ends_on IS NULL OR ends_on >= starts_on)
        AND (closed_on IS NULL OR (closed_on >= starts_on AND (ends_on IS NULL OR closed_on <= ends_on)))),
    CONSTRAINT ck_ea_closure CHECK ((closed_on IS NULL AND closed_at IS NULL)
        OR (closed_on IS NOT NULL AND closed_at IS NOT NULL AND closed_at >= created_at))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE employment_contracts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    reference VARCHAR(64) NOT NULL,
    contract_type VARCHAR(16) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NULL,
    closed_on DATE NULL,
    effective_ends_on DATE GENERATED ALWAYS AS (COALESCE(closed_on, ends_on)) STORED,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_by BIGINT UNSIGNED NOT NULL,
    closed_at DATETIME(6) NULL,
    closed_by BIGINT UNSIGNED NULL,
    CONSTRAINT uq_contracts_reference UNIQUE (reference),
    KEY ix_contracts_period (employee_id, starts_on, effective_ends_on),
    CONSTRAINT fk_ec_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ec_creator FOREIGN KEY (created_by) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ec_closer FOREIGN KEY (closed_by) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_ec_reference CHECK (CHAR_LENGTH(TRIM(reference)) > 0 AND reference = TRIM(reference)),
    CONSTRAINT ck_ec_type CHECK ((contract_type = 'permanent' AND ends_on IS NULL)
        OR (contract_type IN ('fixed_term', 'internship') AND ends_on IS NOT NULL)),
    CONSTRAINT ck_ec_dates CHECK (starts_on >= '1900-01-01' AND (ends_on IS NULL OR ends_on >= starts_on)
        AND (closed_on IS NULL OR (closed_on >= starts_on AND (ends_on IS NULL OR closed_on <= ends_on)))),
    CONSTRAINT ck_ec_closure CHECK ((closed_on IS NULL AND closed_at IS NULL)
        OR (closed_on IS NOT NULL AND closed_at IS NOT NULL AND closed_at >= created_at))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

DELIMITER $$
CREATE DEFINER = 'hr_module_owner'@'localhost' TRIGGER ea_no_delete BEFORE DELETE ON employee_assignments FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HR_HISTORY_IMMUTABLE';
END$$
CREATE DEFINER = 'hr_module_owner'@'localhost' TRIGGER ec_no_delete BEFORE DELETE ON employment_contracts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HR_HISTORY_IMMUTABLE';
END$$

CREATE DEFINER = 'hr_module_owner'@'localhost' TRIGGER ea_preserve_history BEFORE UPDATE ON employee_assignments FOR EACH ROW
BEGIN
    IF NOT (NEW.id <=> OLD.id) OR NOT (NEW.employee_id <=> OLD.employee_id)
        OR NOT (NEW.department_id <=> OLD.department_id) OR NOT (NEW.position_id <=> OLD.position_id)
        OR NOT (NEW.starts_on <=> OLD.starts_on) OR NOT (NEW.ends_on <=> OLD.ends_on)
        OR NOT (NEW.created_at <=> OLD.created_at) OR NOT (NEW.created_by <=> OLD.created_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HR_HISTORY_IMMUTABLE';
    END IF;
    IF NOT (NEW.closed_on <=> OLD.closed_on) OR NOT (NEW.closed_at <=> OLD.closed_at)
        OR NOT (NEW.closed_by <=> OLD.closed_by) THEN
        IF OLD.closed_on IS NOT NULL OR NEW.closed_on IS NULL OR NEW.closed_at IS NULL OR NEW.closed_by IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HR_ALREADY_CLOSED';
        END IF;
    END IF;
END$$

CREATE DEFINER = 'hr_module_owner'@'localhost' TRIGGER ec_preserve_history BEFORE UPDATE ON employment_contracts FOR EACH ROW
BEGIN
    IF NOT (NEW.id <=> OLD.id) OR NOT (NEW.employee_id <=> OLD.employee_id)
        OR NOT (CAST(NEW.reference AS BINARY) <=> CAST(OLD.reference AS BINARY))
        OR NOT (CAST(NEW.contract_type AS BINARY) <=> CAST(OLD.contract_type AS BINARY))
        OR NOT (NEW.starts_on <=> OLD.starts_on) OR NOT (NEW.ends_on <=> OLD.ends_on)
        OR NOT (NEW.created_at <=> OLD.created_at) OR NOT (NEW.created_by <=> OLD.created_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HR_HISTORY_IMMUTABLE';
    END IF;
    IF NOT (NEW.closed_on <=> OLD.closed_on) OR NOT (NEW.closed_at <=> OLD.closed_at)
        OR NOT (NEW.closed_by <=> OLD.closed_by) THEN
        IF OLD.closed_on IS NOT NULL OR NEW.closed_on IS NULL OR NEW.closed_at IS NULL OR NEW.closed_by IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HR_ALREADY_CLOSED';
        END IF;
    END IF;
END$$
DELIMITER ;
