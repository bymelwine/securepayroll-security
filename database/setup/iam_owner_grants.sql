GRANT SELECT(id,employee_number,archived_at),UPDATE(id) ON secure_payroll.employees TO 'iam_owner'@'localhost';
GRANT SELECT(id,employee_id,login,disabled_at) ON secure_payroll.accounts TO 'iam_owner'@'localhost';
GRANT SELECT(id,code,name) ON secure_payroll.roles TO 'iam_owner'@'localhost';
GRANT SELECT,INSERT(account_id,role_id,granted_by_account_id,grant_reason),UPDATE(revoked_at,revoked_by_account_id,revocation_reason) ON secure_payroll.role_assignments TO 'iam_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_assert TO 'iam_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.iam_overview TO 'iam_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.iam_grant TO 'iam_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.iam_revoke TO 'iam_owner'@'localhost';
