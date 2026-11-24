-- DBA read-only review: no password hashes or secrets selected.
SELECT User, Host, account_locked FROM mysql.user
WHERE User IN ('svc_php','hr_module_owner','compensation_owner','payroll_owner');
SHOW GRANTS FOR 'svc_php'@'localhost';
SHOW GRANTS FOR 'compensation_owner'@'localhost';
-- After payroll installation only:
-- SHOW GRANTS FOR 'payroll_owner'@'localhost';
SELECT ROUTINE_NAME, DEFINER, SECURITY_TYPE
FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='secure_payroll'
ORDER BY ROUTINE_NAME;
SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, DEFINER
FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='secure_payroll'
ORDER BY TRIGGER_NAME;
SELECT GRANTEE, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.USER_PRIVILEGES
WHERE GRANTEE IN ('''svc_php''@''localhost''','''compensation_owner''@''localhost''');
SELECT GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE, IS_GRANTABLE
FROM information_schema.SCHEMA_PRIVILEGES
WHERE GRANTEE IN ('''svc_php''@''localhost''','''compensation_owner''@''localhost''');
SELECT GRANTEE, TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE, IS_GRANTABLE
FROM information_schema.TABLE_PRIVILEGES
WHERE GRANTEE IN ('''svc_php''@''localhost''','''compensation_owner''@''localhost''');
SELECT GRANTEE, TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, PRIVILEGE_TYPE
FROM information_schema.COLUMN_PRIVILEGES
WHERE GRANTEE IN ('''svc_php''@''localhost''','''compensation_owner''@''localhost''');
SELECT FROM_USER, FROM_HOST, TO_USER, TO_HOST FROM mysql.role_edges
WHERE TO_USER IN ('svc_php','compensation_owner');
SELECT @@global.mandatory_roles;

-- New payroll owner grants (returns no rows before installation).
SELECT GRANTEE,PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE='''payroll_owner''@''localhost''';
SELECT GRANTEE,TABLE_SCHEMA,TABLE_NAME,PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE='''payroll_owner''@''localhost''';
SELECT FROM_USER,FROM_HOST,TO_USER,TO_HOST FROM mysql.role_edges WHERE TO_USER='payroll_owner';
