-- Propriétaire neuf verrouillé. Aucun privilège global ou autre base.
GRANT SELECT (id,archived_at) ON secure_payroll.employees TO 'compensation_owner'@'localhost';
-- Exigence MySQL 8.4 pour FOR UPDATE ; les routines ne modifient jamais employees.
GRANT UPDATE (id) ON secure_payroll.employees TO 'compensation_owner'@'localhost';
GRANT SELECT (id,employee_id,disabled_at) ON secure_payroll.accounts TO 'compensation_owner'@'localhost';
GRANT SELECT (id,code) ON secure_payroll.roles TO 'compensation_owner'@'localhost';
GRANT SELECT (id,code) ON secure_payroll.permissions TO 'compensation_owner'@'localhost';
GRANT SELECT (role_id,permission_id) ON secure_payroll.role_permissions TO 'compensation_owner'@'localhost';
GRANT SELECT (account_id,role_id,valid_from,expires_at,revoked_at) ON secure_payroll.role_assignments TO 'compensation_owner'@'localhost';
GRANT SELECT (id,employee_id,starts_on,effective_ends_on,closed_on) ON secure_payroll.employment_contracts TO 'compensation_owner'@'localhost';
GRANT TRIGGER ON secure_payroll.employment_contracts TO 'compensation_owner'@'localhost';
GRANT SELECT,INSERT,TRIGGER ON secure_payroll.compensation_requests TO 'compensation_owner'@'localhost';
GRANT SELECT,INSERT,TRIGGER ON secure_payroll.compensation_decisions TO 'compensation_owner'@'localhost';
GRANT SELECT,INSERT,TRIGGER ON secure_payroll.salary_conditions TO 'compensation_owner'@'localhost';
GRANT SELECT,INSERT,TRIGGER ON secure_payroll.compensation_history TO 'compensation_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_assert_permission TO 'compensation_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_check_period TO 'compensation_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_submit TO 'compensation_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_decide TO 'compensation_owner'@'localhost';
