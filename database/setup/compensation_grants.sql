-- Aucun INSERT/UPDATE/DELETE direct sur les quatre tables financières.
GRANT SELECT ON secure_payroll.compensation_requests TO 'svc_php'@'localhost';
GRANT SELECT ON secure_payroll.compensation_decisions TO 'svc_php'@'localhost';
GRANT SELECT ON secure_payroll.salary_conditions TO 'svc_php'@'localhost';
GRANT SELECT ON secure_payroll.compensation_history TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_submit TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_decide TO 'svc_php'@'localhost';
