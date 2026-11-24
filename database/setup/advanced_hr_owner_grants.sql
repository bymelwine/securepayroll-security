-- Après création des tables ET procédures 002/003 ; propriétaire neuf et verrouillé.
-- Aucun droit global, GRANT OPTION, CREATE/ALTER/DROP, DELETE ou autre base.
GRANT SELECT (id, archived_at) ON secure_payroll.employees TO 'hr_module_owner'@'localhost';
-- Nécessaire au SELECT FOR UPDATE de la ligne parent sous MySQL 8.4.
-- Les procédures ne font aucun UPDATE employees. Le trigger 001 protège aussi id.
GRANT UPDATE (id) ON secure_payroll.employees TO 'hr_module_owner'@'localhost';
GRANT SELECT (id, employee_id, disabled_at) ON secure_payroll.accounts TO 'hr_module_owner'@'localhost';
GRANT SELECT (id, code) ON secure_payroll.roles TO 'hr_module_owner'@'localhost';
GRANT SELECT (id, code) ON secure_payroll.permissions TO 'hr_module_owner'@'localhost';
GRANT SELECT (role_id, permission_id) ON secure_payroll.role_permissions TO 'hr_module_owner'@'localhost';
GRANT SELECT (account_id, role_id, valid_from, expires_at, revoked_at)
    ON secure_payroll.role_assignments TO 'hr_module_owner'@'localhost';
GRANT SELECT (id, archived_at) ON secure_payroll.departments TO 'hr_module_owner'@'localhost';
GRANT SELECT (id, archived_at) ON secure_payroll.positions TO 'hr_module_owner'@'localhost';
GRANT SELECT ON secure_payroll.employee_assignments TO 'hr_module_owner'@'localhost';
GRANT SELECT ON secure_payroll.employment_contracts TO 'hr_module_owner'@'localhost';
GRANT INSERT (employee_id, department_id, position_id, starts_on, ends_on, created_by, created_at)
    ON secure_payroll.employee_assignments TO 'hr_module_owner'@'localhost';
GRANT INSERT (employee_id, reference, contract_type, starts_on, ends_on, created_by, created_at)
    ON secure_payroll.employment_contracts TO 'hr_module_owner'@'localhost';
GRANT UPDATE (closed_on, closed_at, closed_by)
    ON secure_payroll.employee_assignments TO 'hr_module_owner'@'localhost';
GRANT UPDATE (closed_on, closed_at, closed_by)
    ON secure_payroll.employment_contracts TO 'hr_module_owner'@'localhost';
-- TRIGGER est vérifié à l’activation, même pour les triggers qui refusent un DELETE.
GRANT TRIGGER ON secure_payroll.employee_assignments TO 'hr_module_owner'@'localhost';
GRANT TRIGGER ON secure_payroll.employment_contracts TO 'hr_module_owner'@'localhost';
-- Le DEFINER doit avoir EXECUTE sur ses routines, y compris le helper imbriqué.
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_assert_permission TO 'hr_module_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_create_assignment TO 'hr_module_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_close_assignment TO 'hr_module_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_create_contract TO 'hr_module_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_close_contract TO 'hr_module_owner'@'localhost';
