-- Service can only call public operations. No SELECT or DML on new tables.
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_period_create TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_input_submit TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_input_decide TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_calculate TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.payslip_transition TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_overview TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.payslip_detail TO 'svc_php'@'localhost';
