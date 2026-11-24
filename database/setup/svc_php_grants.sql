-- À appliquer uniquement après validation, au compte NOUVEAU svc_php@localhost.
-- Ce fichier ne crée pas de compte et ne contient aucun secret.
-- Aucun privilège global, aucun droit sur une autre base, aucun GRANT OPTION.
GRANT SELECT ON secure_payroll.employees TO 'svc_php'@'localhost';
GRANT SELECT (id, employee_id, login, password_hash, disabled_at)
    ON secure_payroll.accounts TO 'svc_php'@'localhost';
GRANT SELECT (id, code, name) ON secure_payroll.roles TO 'svc_php'@'localhost';
GRANT SELECT (id, code) ON secure_payroll.permissions TO 'svc_php'@'localhost';
GRANT SELECT (role_id, permission_id)
    ON secure_payroll.role_permissions TO 'svc_php'@'localhost';
GRANT SELECT (account_id, role_id, valid_from, expires_at, revoked_at)
    ON secure_payroll.role_assignments TO 'svc_php'@'localhost';
GRANT INSERT (employee_number, first_name, last_name)
    ON secure_payroll.employees TO 'svc_php'@'localhost';
GRANT UPDATE (first_name, last_name)
    ON secure_payroll.employees TO 'svc_php'@'localhost';
