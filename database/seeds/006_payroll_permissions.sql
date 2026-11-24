USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
INSERT INTO permissions(code,description)
SELECT n.code,n.description FROM (
 SELECT 'payroll.read' AS code,'Read payroll data' AS description UNION ALL
 SELECT 'payroll.period.create','Create payroll periods with explicit caps' UNION ALL
 SELECT 'payroll.input.submit','Submit payroll adjustments and suspensions' UNION ALL
 SELECT 'payroll.input.decide','Independently decide payroll inputs' UNION ALL
 SELECT 'payroll.calculate','Prepare immutable payroll versions' UNION ALL
 SELECT 'payslip.verify','Verify current payroll amounts' UNION ALL
 SELECT 'payslip.decide','Independently approve or reject payslips' UNION ALL
 SELECT 'payslip.publish','Publish approved payslips' UNION ALL
 SELECT 'payslip.self','Read own published payslips'
) n LEFT JOIN permissions p ON p.code=n.code WHERE p.id IS NULL;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON
 (r.code IN ('hr_manager','payroll_manager','finance_manager','security_auditor') AND p.code='payroll.read') OR
 (r.code='finance_manager' AND p.code IN ('payroll.period.create','payroll.input.decide','payslip.decide')) OR
 (r.code='hr_manager' AND p.code='payroll.input.submit') OR
 (r.code='payroll_manager' AND p.code IN ('payroll.calculate','payslip.verify','payslip.publish')) OR
 (r.code='employee' AND p.code='payslip.self')
LEFT JOIN role_permissions rp ON rp.role_id=r.id AND rp.permission_id=p.id WHERE rp.role_id IS NULL;
