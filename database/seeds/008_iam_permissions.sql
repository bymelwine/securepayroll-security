USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
INSERT INTO permissions(code,description)
SELECT n.code,n.description FROM (
 SELECT 'iam.read' AS code,'Read role assignment audit without credentials' AS description UNION ALL
 SELECT 'iam.grant','Grant roles to other active employees with separation of duties' UNION ALL
 SELECT 'iam.revoke','Revoke another employee role preserving history'
) n LEFT JOIN permissions p ON p.code=n.code WHERE p.id IS NULL;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON
 (r.code='application_administrator' AND p.code IN ('iam.read','iam.grant','iam.revoke')) OR
 (r.code='security_auditor' AND p.code='iam.read')
LEFT JOIN role_permissions rp ON rp.role_id=r.id AND rp.permission_id=p.id WHERE rp.role_id IS NULL;
