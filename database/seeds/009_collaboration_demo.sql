-- Instance NEUVE de bin/init-local.py uniquement. Aucun mot de passe ni hash fixe.
-- Six comptes fictifs distincts ont été créés avec des secrets locaux propres.
USE secure_payroll;
SET SESSION time_zone='+00:00';
SET @rh=(SELECT id FROM accounts WHERE login='hr.demo');
SET @finance=(SELECT id FROM accounts WHERE login='finance.demo');
SET @nadia=(SELECT id FROM employees WHERE employee_number='EMP-2026-001');
SET @it=(SELECT id FROM departments WHERE code='IT');
SET @post=(SELECT id FROM positions WHERE code='SOFTWARE_ENGINEER');
CALL hr_create_assignment(@nadia,@it,@post,'2026-11-03',NULL,@rh);
CALL hr_create_contract(@nadia,'CDI-EMP-2026-001-01','permanent','2026-11-03',NULL,@rh);
SET @contract=(SELECT id FROM employment_contracts WHERE reference='CDI-EMP-2026-001-01');
CALL compensation_submit(@nadia,@contract,12000.00,'2026-11-03','Données fictives locales : salaire initial MAD',@rh);
SET @request=(SELECT MAX(id) FROM compensation_requests WHERE employee_id=@nadia);
CALL compensation_decide(@request,'approved','Validation Finance fictive indépendante',@finance);
CALL payroll_period_create('2026-11-01',3300.00,560.00,'Novembre 2026 : période fictive de collaboration',@finance);
-- Le collaborateur peut maintenant calculer, vérifier, approuver et publier la paie.
