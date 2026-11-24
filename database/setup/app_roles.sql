-- Phase 2 — Rôles MySQL du compte de service de l'application.
--
-- Avant ce script, svc_php détenait directement l'union des privilèges de tous les rôles métier
-- (svc_php_grants.sql et *_grants.sql). Toute requête, quel que soit l'utilisateur connecté,
-- pouvait donc lire les salaires et les empreintes de mots de passe (écart E1).
--
-- Ce script :
--   1. révoque tous les privilèges directs de svc_php ;
--   2. crée un rôle MySQL par rôle applicatif, plus deux rôles techniques ;
--   3. accorde ces rôles à svc_php avec, comme seul rôle actif par défaut, sp_session.
--
-- À chaque requête, l'application active uniquement les rôles de l'utilisateur authentifié
-- (app/Database/SessionRoles.php). Une requête d'une session « employee » s'exécute ainsi
-- sans aucun droit sur salary_conditions, même en cas d'erreur ou d'injection dans le code.
--
-- Les droits d'exécution des procédures ne remplacent pas leurs contrôles internes : chaque
-- procédure vérifie encore le rôle de l'acteur (p_actor). Les deux niveaux sont cumulatifs.

-- 1. Retrait des privilèges directs (USAGE subsiste : le compte peut seulement se connecter).
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'svc_php'@'localhost';

-- 2. Rôles.
CREATE ROLE 'sp_session', 'sp_login',
    'sp_employee', 'sp_hr_manager', 'sp_finance_manager', 'sp_payroll_manager',
    'sp_application_administrator', 'sp_security_auditor';

-- sp_session : actif à chaque requête authentifiée. Relecture du compte, des attributions et
-- de la fiche d'identité (AuthService::currentUser, profil personnel). Ni empreinte, ni login.
GRANT SELECT ON secure_payroll.employees TO 'sp_session';
GRANT SELECT (id, employee_id, disabled_at) ON secure_payroll.accounts TO 'sp_session';
GRANT SELECT (id, code, name) ON secure_payroll.roles TO 'sp_session';
GRANT SELECT (id, code) ON secure_payroll.permissions TO 'sp_session';
GRANT SELECT (role_id, permission_id) ON secure_payroll.role_permissions TO 'sp_session';
GRANT SELECT (account_id, role_id, valid_from, expires_at, revoked_at)
    ON secure_payroll.role_assignments TO 'sp_session';

-- sp_login : actif seul, le temps de la requête de connexion (AuthService::login).
-- C'est le seul rôle qui lit password_hash.
GRANT SELECT (id, employee_id, login, password_hash, disabled_at) ON secure_payroll.accounts TO 'sp_login';
GRANT SELECT (id, archived_at) ON secure_payroll.employees TO 'sp_login';

-- sp_employee : ses bulletins publiés et leur règlement, filtrés par les procédures.
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_overview TO 'sp_employee';
GRANT EXECUTE ON PROCEDURE secure_payroll.payslip_detail TO 'sp_employee';
GRANT EXECUTE ON PROCEDURE secure_payroll.settlement_detail TO 'sp_employee';

-- sp_hr_manager : identité, contrats, affectations, propositions salariales, éléments variables.
GRANT INSERT (employee_number, first_name, last_name) ON secure_payroll.employees TO 'sp_hr_manager';
GRANT UPDATE (first_name, last_name) ON secure_payroll.employees TO 'sp_hr_manager';
GRANT SELECT ON secure_payroll.departments TO 'sp_hr_manager';
GRANT SELECT ON secure_payroll.positions TO 'sp_hr_manager';
GRANT SELECT ON secure_payroll.employee_assignments TO 'sp_hr_manager';
GRANT SELECT ON secure_payroll.employment_contracts TO 'sp_hr_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_create_assignment TO 'sp_hr_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_close_assignment TO 'sp_hr_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_create_contract TO 'sp_hr_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.hr_close_contract TO 'sp_hr_manager';
GRANT SELECT ON secure_payroll.compensation_requests TO 'sp_hr_manager';
GRANT SELECT ON secure_payroll.compensation_decisions TO 'sp_hr_manager';
GRANT SELECT ON secure_payroll.salary_conditions TO 'sp_hr_manager';
GRANT SELECT ON secure_payroll.compensation_history TO 'sp_hr_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_submit TO 'sp_hr_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_input_submit TO 'sp_hr_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_overview TO 'sp_hr_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.payslip_detail TO 'sp_hr_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.settlement_detail TO 'sp_hr_manager';

-- sp_finance_manager : décisions salariales, périodes, décisions de paie, paiements.
GRANT SELECT ON secure_payroll.employment_contracts TO 'sp_finance_manager';
GRANT SELECT ON secure_payroll.compensation_requests TO 'sp_finance_manager';
GRANT SELECT ON secure_payroll.compensation_decisions TO 'sp_finance_manager';
GRANT SELECT ON secure_payroll.salary_conditions TO 'sp_finance_manager';
GRANT SELECT ON secure_payroll.compensation_history TO 'sp_finance_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.compensation_decide TO 'sp_finance_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_period_create TO 'sp_finance_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_input_decide TO 'sp_finance_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.payslip_transition TO 'sp_finance_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.settlement_command TO 'sp_finance_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_overview TO 'sp_finance_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.payslip_detail TO 'sp_finance_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.settlement_detail TO 'sp_finance_manager';

-- sp_payroll_manager : calcul, vérification, publication, corrections, restitutions.
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_calculate TO 'sp_payroll_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.payslip_transition TO 'sp_payroll_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.settlement_command TO 'sp_payroll_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_overview TO 'sp_payroll_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.payslip_detail TO 'sp_payroll_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.settlement_detail TO 'sp_payroll_manager';

-- sp_application_administrator : attribution et révocation des rôles applicatifs d'autrui.
GRANT EXECUTE ON PROCEDURE secure_payroll.iam_overview TO 'sp_application_administrator';
GRANT EXECUTE ON PROCEDURE secure_payroll.iam_grant TO 'sp_application_administrator';
GRANT EXECUTE ON PROCEDURE secure_payroll.iam_revoke TO 'sp_application_administrator';

-- sp_security_auditor : lecture de l'IAM et de la paie, sans aucune action.
GRANT EXECUTE ON PROCEDURE secure_payroll.iam_overview TO 'sp_security_auditor';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_overview TO 'sp_security_auditor';
GRANT EXECUTE ON PROCEDURE secure_payroll.payslip_detail TO 'sp_security_auditor';
GRANT EXECUTE ON PROCEDURE secure_payroll.settlement_detail TO 'sp_security_auditor';

-- 3. Attribution des rôles au compte de service. Aucun n'est actif à la connexion, sauf
-- sp_session ; l'activation de tous les rôles à la connexion (activate_all_roles_on_login)
-- doit rester désactivée, ce que vérifie bin/init-local.py.
GRANT 'sp_session', 'sp_login',
    'sp_employee', 'sp_hr_manager', 'sp_finance_manager', 'sp_payroll_manager',
    'sp_application_administrator', 'sp_security_auditor'
    TO 'svc_php'@'localhost';
SET DEFAULT ROLE 'sp_session' TO 'svc_php'@'localhost';
