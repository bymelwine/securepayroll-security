USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
INSERT INTO permissions(code,description)
SELECT n.code,n.description FROM (
 SELECT 'payslip.correct' AS code,'Prepare reasoned immutable correction versions' AS description UNION ALL
 SELECT 'payroll.payment','Record fictional payment of the outstanding published balance' UNION ALL
 SELECT 'payroll.recovery.request','Request controlled fictional overpayment recovery' UNION ALL
 SELECT 'payroll.recovery.decide','Independently decide overpayment recovery' UNION ALL
 SELECT 'payroll.recovery.receive','Record fictional receipt after independent approval'
) n LEFT JOIN permissions p ON p.code=n.code WHERE p.id IS NULL;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON
 (r.code='payroll_manager' AND p.code IN ('payslip.correct','payroll.recovery.request','payroll.recovery.receive')) OR
 (r.code='finance_manager' AND p.code IN ('payroll.payment','payroll.recovery.decide'))
LEFT JOIN role_permissions rp ON rp.role_id=r.id AND rp.permission_id=p.id WHERE rp.role_id IS NULL;
