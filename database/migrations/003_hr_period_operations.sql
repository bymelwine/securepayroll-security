-- Propriétaire obligatoire : hr_module_owner@localhost, créé et verrouillé avant ce fichier.
-- Mission 05A : appliquer une fois après 002_advanced_hr.sql, avec validation DBA.
-- Aucune écriture directe des historiques par svc_php : seuls ces CALL sont autorisés.
-- Les procédures ouvrent/terminent leur transaction ; ne pas les envelopper dans une autre.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
DELIMITER $$
CREATE DEFINER = 'hr_module_owner'@'localhost' PROCEDURE hr_assert_permission(IN p_actor BIGINT UNSIGNED, IN p_permission VARCHAR(100))
SQL SECURITY DEFINER
READS SQL DATA
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM accounts a JOIN employees e ON e.id=a.employee_id
        JOIN role_assignments ra ON ra.account_id=a.id JOIN roles r ON r.id=ra.role_id
        JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id
        WHERE a.id=p_actor AND a.disabled_at IS NULL AND e.archived_at IS NULL
        AND r.code='hr_manager' AND p.code=p_permission AND ra.valid_from <= UTC_TIMESTAMP(6)
        AND (ra.expires_at IS NULL OR ra.expires_at > UTC_TIMESTAMP(6))
        AND (ra.revoked_at IS NULL OR ra.revoked_at > UTC_TIMESTAMP(6))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_FORBIDDEN';
    END IF;
END$$

CREATE DEFINER = 'hr_module_owner'@'localhost' PROCEDURE hr_create_assignment(IN p_employee BIGINT UNSIGNED, IN p_department BIGINT UNSIGNED, IN p_position BIGINT UNSIGNED,
    IN p_start DATE, IN p_end DATE, IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER
MODIFIES SQL DATA
BEGIN
    DECLARE v_employee BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_conflict BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_created BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    CALL hr_assert_permission(p_actor, 'assignments.create');
    IF p_start IS NULL OR p_start < '1900-01-01' OR (p_end IS NOT NULL AND p_end < p_start) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_DATES';
    END IF;
    BEGIN
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_employee=NULL;
        SELECT id INTO v_employee FROM employees WHERE id=p_employee AND archived_at IS NULL FOR UPDATE;
    END;
    IF v_employee IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_EMPLOYEE';
    END IF;
    BEGIN
        DECLARE v_department BIGINT UNSIGNED DEFAULT NULL;
        DECLARE v_position BIGINT UNSIGNED DEFAULT NULL;
        DECLARE CONTINUE HANDLER FOR NOT FOUND BEGIN END;
        SELECT id INTO v_department FROM departments WHERE id=p_department AND archived_at IS NULL FOR SHARE;
        SELECT id INTO v_position FROM positions WHERE id=p_position AND archived_at IS NULL FOR SHARE;
        IF v_department IS NULL OR v_position IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_REFERENCE';
        END IF;
    END;
    -- Lecture courante après verrou du parent : pas de snapshot périmé sous REPEATABLE READ.
    BEGIN
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_conflict=NULL;
        SELECT id INTO v_conflict FROM employee_assignments
        WHERE employee_id=p_employee AND starts_on <= COALESCE(p_end,'9999-12-31')
        AND (effective_ends_on IS NULL OR effective_ends_on >= p_start)
        ORDER BY starts_on LIMIT 1 FOR UPDATE;
    END;
    IF v_conflict IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_OVERLAP';
    END IF;
    INSERT INTO employee_assignments (employee_id,department_id,position_id,starts_on,ends_on,created_by,created_at)
    VALUES (p_employee,p_department,p_position,p_start,p_end,p_actor,UTC_TIMESTAMP(6));
    SET v_created=LAST_INSERT_ID();
    COMMIT;
    SELECT v_created AS id;
END$$

CREATE DEFINER = 'hr_module_owner'@'localhost' PROCEDURE hr_close_assignment(IN p_id BIGINT UNSIGNED, IN p_end DATE, IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER
MODIFIES SQL DATA
BEGIN
    DECLARE v_employee BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_parent BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_start DATE;
    DECLARE v_end DATE;
    DECLARE v_closed DATE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    CALL hr_assert_permission(p_actor, 'assignments.close');
    -- L'identité du parent est immuable ; seule cette première lecture peut être non verrouillante.
    BEGIN
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_employee=NULL;
        SELECT employee_id INTO v_employee FROM employee_assignments WHERE id=p_id;
    END;
    IF v_employee IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_NOT_FOUND';
    END IF;
    SELECT id INTO v_parent FROM employees WHERE id=v_employee FOR UPDATE;
    -- Même ordre de verrous que la création : employé, puis historique.
    SELECT starts_on,ends_on,closed_on INTO v_start,v_end,v_closed
        FROM employee_assignments WHERE id=p_id FOR UPDATE;
    IF v_closed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_ALREADY_CLOSED';
    END IF;
    IF p_end IS NULL OR p_end < v_start OR (v_end IS NOT NULL AND p_end > v_end) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_DATES';
    END IF;
    UPDATE employee_assignments SET closed_on=p_end,closed_at=UTC_TIMESTAMP(6),closed_by=p_actor WHERE id=p_id;
    COMMIT;
END$$

CREATE DEFINER = 'hr_module_owner'@'localhost' PROCEDURE hr_create_contract(IN p_employee BIGINT UNSIGNED, IN p_reference VARCHAR(64), IN p_type VARCHAR(16),
    IN p_start DATE, IN p_end DATE, IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER
MODIFIES SQL DATA
BEGIN
    DECLARE v_employee BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_conflict BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_created BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    CALL hr_assert_permission(p_actor, 'contracts.create');
    IF p_start IS NULL OR p_start < '1900-01-01' OR (p_end IS NOT NULL AND p_end < p_start) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_DATES';
    END IF;
    BEGIN
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_employee=NULL;
        SELECT id INTO v_employee FROM employees WHERE id=p_employee AND archived_at IS NULL FOR UPDATE;
    END;
    IF v_employee IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_EMPLOYEE';
    END IF;
    IF p_type IS NULL OR p_type NOT IN ('permanent','fixed_term','internship')
        OR (p_type='permanent' AND p_end IS NOT NULL)
        OR (p_type IN ('fixed_term','internship') AND p_end IS NULL)
        OR p_reference IS NULL OR CHAR_LENGTH(TRIM(p_reference))=0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_CONTRACT_TYPE';
    END IF;
    -- Lecture courante après verrou du parent : pas de snapshot périmé sous REPEATABLE READ.
    BEGIN
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_conflict=NULL;
        SELECT id INTO v_conflict FROM employment_contracts
        WHERE employee_id=p_employee AND starts_on <= COALESCE(p_end,'9999-12-31')
        AND (effective_ends_on IS NULL OR effective_ends_on >= p_start)
        ORDER BY starts_on LIMIT 1 FOR UPDATE;
    END;
    IF v_conflict IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_OVERLAP';
    END IF;
    INSERT INTO employment_contracts (employee_id,reference,contract_type,starts_on,ends_on,created_by,created_at)
    VALUES (p_employee,p_reference,p_type,p_start,p_end,p_actor,UTC_TIMESTAMP(6));
    SET v_created=LAST_INSERT_ID();
    COMMIT;
    SELECT v_created AS id;
END$$

CREATE DEFINER = 'hr_module_owner'@'localhost' PROCEDURE hr_close_contract(IN p_id BIGINT UNSIGNED, IN p_end DATE, IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER
MODIFIES SQL DATA
BEGIN
    DECLARE v_employee BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_parent BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_start DATE;
    DECLARE v_end DATE;
    DECLARE v_closed DATE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    CALL hr_assert_permission(p_actor, 'contracts.close');
    -- L'identité du parent est immuable ; seule cette première lecture peut être non verrouillante.
    BEGIN
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_employee=NULL;
        SELECT employee_id INTO v_employee FROM employment_contracts WHERE id=p_id;
    END;
    IF v_employee IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_NOT_FOUND';
    END IF;
    SELECT id INTO v_parent FROM employees WHERE id=v_employee FOR UPDATE;
    -- Même ordre de verrous que la création : employé, puis historique.
    SELECT starts_on,ends_on,closed_on INTO v_start,v_end,v_closed
        FROM employment_contracts WHERE id=p_id FOR UPDATE;
    IF v_closed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_ALREADY_CLOSED';
    END IF;
    IF p_end IS NULL OR p_end < v_start OR (v_end IS NOT NULL AND p_end > v_end) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HR_DATES';
    END IF;
    UPDATE employment_contracts SET closed_on=p_end,closed_at=UTC_TIMESTAMP(6),closed_by=p_actor WHERE id=p_id;
    COMMIT;
END$$
DELIMITER ;
