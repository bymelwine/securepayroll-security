-- Droits ADDITIONNELS pour le svc_php@localhost existant, après validation.
-- Aucune écriture directe sur les nouveaux catalogues ou historiques.
-- À appliquer APRÈS advanced_hr_owner_grants.sql. Aucun EXECUTE sur le helper.
-- Les neuf objets déclarent hr_module_owner@localhost comme DEFINER, jamais svc_php.
GRANT SELECT ON secure_payroll.departments TO 'svc_php'@'localhost';
GRANT SELECT ON secure_payroll.positions TO 'svc_php'@'localhost';
GRANT SELECT ON secure_payroll.employee_assignments TO 'svc_php'@'localhost';
GRANT SELECT ON secure_payroll.employment_contracts TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_create_assignment TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_close_assignment TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_create_contract TO 'svc_php'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_close_contract TO 'svc_php'@'localhost';
