-- Mission 03B — MySQL 8.4.11. À appliquer UNE SEULE FOIS dans secure_payroll.
-- Aucune création de base, de compte SQL ou de privilège.
-- DDL non transactionnel à l’échelle du fichier : examiner tout échec avant reprise.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone = '+00:00';

CREATE TABLE employees (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_number VARCHAR(32) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_employees_number UNIQUE (employee_number),
    CONSTRAINT ck_employees_number CHECK (
        CHAR_LENGTH(TRIM(employee_number)) > 0 AND employee_number = TRIM(employee_number)
    ),
    CONSTRAINT ck_employees_names CHECK (
        CHAR_LENGTH(TRIM(first_name)) > 0 AND CHAR_LENGTH(TRIM(last_name)) > 0
    ),
    CONSTRAINT ck_employees_archive CHECK (archived_at IS NULL OR archived_at >= created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NOT NULL,
    login VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    disabled_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_accounts_employee UNIQUE (employee_id),
    CONSTRAINT uq_accounts_login UNIQUE (login),
    CONSTRAINT fk_accounts_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_accounts_login CHECK (
        CHAR_LENGTH(TRIM(login)) > 0 AND login = TRIM(login)
    ),
    -- Contrôle de format PHC ; la validation cryptographique reste du ressort de PHP.
    CONSTRAINT ck_accounts_argon2id CHECK (
        REGEXP_LIKE(password_hash,
            '^[$]argon2id[$]v=19[$]m=[1-9][0-9]*,t=[1-9][0-9]*,p=[1-9][0-9]*[$][A-Za-z0-9+/]{16,}[$][A-Za-z0-9+/]{32,}$', 'c')
    ),
    CONSTRAINT ck_accounts_disabled CHECK (disabled_at IS NULL OR disabled_at >= created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_roles_code UNIQUE (code),
    CONSTRAINT uq_roles_name UNIQUE (name),
    CONSTRAINT ck_roles_text CHECK (CHAR_LENGTH(TRIM(code)) > 0 AND CHAR_LENGTH(TRIM(name)) > 0),
    -- Catalogue fermé : toute extension exigera une migration explicite.
    CONSTRAINT ck_roles_canonical CHECK (
        (CAST(code AS BINARY) = CAST('employee' AS BINARY) AND CAST(name AS BINARY) = CAST('Employee' AS BINARY))
        OR (CAST(code AS BINARY) = CAST('hr_manager' AS BINARY) AND CAST(name AS BINARY) = CAST('HR Manager' AS BINARY))
        OR (CAST(code AS BINARY) = CAST('payroll_manager' AS BINARY) AND CAST(name AS BINARY) = CAST('Payroll Manager' AS BINARY))
        OR (CAST(code AS BINARY) = CAST('finance_manager' AS BINARY) AND CAST(name AS BINARY) = CAST('Finance Manager' AS BINARY))
        OR (CAST(code AS BINARY) = CAST('application_administrator' AS BINARY) AND CAST(name AS BINARY) = CAST('Application Administrator' AS BINARY))
        OR (CAST(code AS BINARY) = CAST('security_auditor' AS BINARY) AND CAST(name AS BINARY) = CAST('Security Auditor' AS BINARY))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_permissions_code UNIQUE (code),
    CONSTRAINT ck_permissions_text CHECK (
        CHAR_LENGTH(TRIM(code)) > 0 AND CHAR_LENGTH(TRIM(description)) > 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE role_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    granted_by_account_id BIGINT UNSIGNED NULL,
    granted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    -- Omettre ces deux colonnes pour un octroi immédiat : même instant SQL.
    valid_from DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NULL,
    grant_reason VARCHAR(255) NOT NULL,
    revoked_at DATETIME(6) NULL,
    revoked_by_account_id BIGINT UNSIGNED NULL,
    revocation_reason VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY ix_assignments_access (account_id, role_id, valid_from, expires_at, revoked_at),
    CONSTRAINT uq_assignments_start UNIQUE (account_id, role_id, valid_from),
    CONSTRAINT fk_assignments_account FOREIGN KEY (account_id) REFERENCES accounts (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_assignments_role FOREIGN KEY (role_id) REFERENCES roles (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_assignments_grantor FOREIGN KEY (granted_by_account_id) REFERENCES accounts (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_assignments_revoker FOREIGN KEY (revoked_by_account_id) REFERENCES accounts (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_assignments_validity CHECK (
        valid_from >= granted_at AND (expires_at IS NULL OR expires_at > valid_from)
    ),
    CONSTRAINT ck_assignments_grant_reason CHECK (CHAR_LENGTH(TRIM(grant_reason)) > 0),
    CONSTRAINT ck_assignments_revocation CHECK (
        (revoked_at IS NULL AND revocation_reason IS NULL)
        OR (revoked_at IS NOT NULL AND revoked_at >= granted_at
            AND revocation_reason IS NOT NULL
            AND CHAR_LENGTH(TRIM(revocation_reason)) > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

-- DELIMITER est une commande du client mysql, pas une instruction PDO.
DELIMITER $$
CREATE TRIGGER employees_preserve_identity BEFORE UPDATE ON employees FOR EACH ROW
BEGIN
    IF NOT (NEW.id <=> OLD.id)
        OR NOT (CAST(NEW.employee_number AS BINARY) <=> CAST(OLD.employee_number AS BINARY))
        OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Employee identity is immutable';
    END IF;
END$$

CREATE TRIGGER employees_prevent_delete BEFORE DELETE ON employees FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Archive employees instead of deleting them';
END$$

CREATE TRIGGER accounts_preserve_identity BEFORE UPDATE ON accounts FOR EACH ROW
BEGIN
    IF NOT (NEW.id <=> OLD.id) OR NOT (NEW.employee_id <=> OLD.employee_id)
        OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account identity is immutable';
    END IF;
END$$

CREATE TRIGGER accounts_prevent_delete BEFORE DELETE ON accounts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Disable accounts instead of deleting them';
END$$

-- CURRENT_TIMESTAMP(6) est constant pendant l’instruction, defaults inclus.
-- La session d’écriture doit être explicitement UTC ; aucun horodatage PHP préparé avant l’INSERT.
CREATE TRIGGER assignments_validate_insert BEFORE INSERT ON role_assignments FOR EACH ROW
BEGIN
    IF @@session.time_zone <> '+00:00'
        OR NOT (NEW.granted_at <=> CURRENT_TIMESTAMP(6)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Grant time must be the current SQL instant in UTC';
    END IF;
    IF NEW.revoked_at IS NOT NULL OR NEW.revoked_by_account_id IS NOT NULL
        OR NEW.revocation_reason IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'New assignments cannot already be revoked';
    END IF;
END$$

CREATE TRIGGER assignments_preserve_history BEFORE UPDATE ON role_assignments FOR EACH ROW
BEGIN
    IF NOT (NEW.id <=> OLD.id) OR NOT (NEW.account_id <=> OLD.account_id) OR NOT (NEW.role_id <=> OLD.role_id)
        OR NOT (NEW.granted_by_account_id <=> OLD.granted_by_account_id)
        OR NOT (NEW.granted_at <=> OLD.granted_at) OR NOT (NEW.valid_from <=> OLD.valid_from)
        OR NOT (NEW.expires_at <=> OLD.expires_at)
        OR NOT (CAST(NEW.grant_reason AS BINARY) <=> CAST(OLD.grant_reason AS BINARY)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Assignment grant data is immutable';
    END IF;
    -- Un UPDATE strictement identique reste autorisé, même après révocation.
    IF NOT (NEW.revoked_at <=> OLD.revoked_at)
        OR NOT (NEW.revoked_by_account_id <=> OLD.revoked_by_account_id)
        OR NOT (CAST(NEW.revocation_reason AS BINARY) <=> CAST(OLD.revocation_reason AS BINARY)) THEN
        IF OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL
            OR NEW.revoked_by_account_id IS NULL OR @@session.time_zone <> '+00:00'
            OR NOT (NEW.revoked_at <=> CURRENT_TIMESTAMP(6)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a first revocation at the current SQL instant is allowed';
        END IF;
    END IF;
END$$

CREATE TRIGGER assignments_prevent_delete BEFORE DELETE ON role_assignments FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Role assignment history cannot be deleted';
END$$
DELIMITER ;
