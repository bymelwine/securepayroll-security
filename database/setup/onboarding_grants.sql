-- Embauche (migration 013) : droits du propriétaire des procédures et des rôles MySQL.
-- Appliqué après app_roles.sql (installation neuve) ou par `init-local.py upgrade` (instance existante).

-- Propriétaire : exactement ce que les deux procédures écrivent ou lisent.
GRANT INSERT (employee_number, first_name, last_name) ON secure_payroll.employees TO 'iam_owner'@'localhost';
GRANT INSERT (employee_id, login, password_hash, password_change_required, temporary_password_expires_at),
      UPDATE (password_hash, password_change_required, temporary_password_expires_at, password_changed_at),
      SELECT (id, employee_id, login, password_hash, password_change_required, temporary_password_expires_at, disabled_at)
    ON secure_payroll.accounts TO 'iam_owner'@'localhost';
GRANT INSERT (account_id, personal_email, issued_by_account_id, expires_at)
    ON secure_payroll.account_invitations TO 'iam_owner'@'localhost';
-- Comme pour iam_grant : MySQL vérifie aussi le droit EXECUTE du DEFINER sur sa propre procédure.
GRANT EXECUTE ON PROCEDURE secure_payroll.account_onboard TO 'iam_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.account_change_password TO 'iam_owner'@'localhost';

-- RH : la création passe désormais par account_onboard ; plus d'INSERT direct sur employees.
REVOKE INSERT (employee_number, first_name, last_name) ON secure_payroll.employees FROM 'sp_hr_manager';
GRANT EXECUTE ON PROCEDURE secure_payroll.account_onboard TO 'sp_hr_manager';

-- Session : identifiant et obligation de changer le mot de passe (aucune empreinte).
GRANT SELECT (login, password_change_required, password_changed_at) ON secure_payroll.accounts TO 'sp_session';
-- Connexion : refuser un mot de passe temporaire expiré.
GRANT SELECT (password_change_required, temporary_password_expires_at) ON secure_payroll.accounts TO 'sp_login';

-- Changement de mot de passe : rôle dédié, actif pendant cette seule opération.
CREATE ROLE 'sp_password';
GRANT EXECUTE ON PROCEDURE secure_payroll.account_change_password TO 'sp_password';
GRANT 'sp_password' TO 'svc_php'@'localhost';

-- Auditeur SQL : qui a invité qui et quand, sans l'e-mail personnel.
GRANT SELECT (id, account_id, issued_by_account_id, issued_at, expires_at)
    ON secure_payroll.account_invitations TO 'sp_db_auditor';
GRANT SELECT (password_change_required, temporary_password_expires_at, password_changed_at)
    ON secure_payroll.accounts TO 'sp_db_auditor';
