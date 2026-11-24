-- Phase 7 — Droits de la piste d'audit de sécurité.
-- Le propriétaire audit_owner porte le DML de la table ; ses procédures et triggers DEFINER
-- s'exécutent avec ces droits. Les rôles applicatifs ne reçoivent que l'EXECUTE des procédures.
-- Appliqué APRÈS la migration 016 (table + procédures) et AVANT la migration 017 (triggers) :
-- créer un trigger DEFINER='audit_owner' exige que ce compte détienne déjà le privilège TRIGGER.

-- Le propriétaire : écrire la piste, la relire pour la supervision, lire de quoi rendre un acteur
-- lisible (login + nom) dans audit_recent, et poser ses déclencheurs. Aucun UPDATE/DELETE sur la
-- table : elle reste en ajout seul (triggers de la migration 017).
GRANT INSERT, SELECT ON secure_payroll.security_audit_log TO 'audit_owner'@'localhost';
GRANT TRIGGER ON secure_payroll.security_audit_log TO 'audit_owner'@'localhost';
GRANT TRIGGER ON secure_payroll.employee_personal_data TO 'audit_owner'@'localhost';
GRANT TRIGGER ON secure_payroll.role_assignments TO 'audit_owner'@'localhost';
GRANT TRIGGER ON secure_payroll.salary_conditions TO 'audit_owner'@'localhost';
GRANT SELECT (id, login, employee_id) ON secure_payroll.accounts TO 'audit_owner'@'localhost';
GRANT SELECT (id, first_name, last_name) ON secure_payroll.employees TO 'audit_owner'@'localhost';

-- Les triggers lisent NEW/OLD de ces colonnes (comparaisons d'indicateurs) : MySQL exige le SELECT
-- du DEFINER sur elles. Rien de plus que ce que les triggers référencent ; le RIB n'est lu que
-- chiffré, pour calculer un simple booléen de changement.
GRANT SELECT (id, account_id, role_id, revoked_at, expires_at) ON secure_payroll.role_assignments TO 'audit_owner'@'localhost';
GRANT SELECT (employee_id, rib, personal_email, personal_phone) ON secure_payroll.employee_personal_data TO 'audit_owner'@'localhost';
GRANT SELECT (id, employee_id) ON secure_payroll.salary_conditions TO 'audit_owner'@'localhost';

-- MySQL vérifie l'EXECUTE du DEFINER sur ses propres procédures (comportement constaté, comme pour
-- les autres propriétaires de procédures).
GRANT EXECUTE ON PROCEDURE secure_payroll.audit_record TO 'audit_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.audit_recent TO 'audit_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.audit_suspicious TO 'audit_owner'@'localhost';

-- Écriture de la piste : disponible dès qu'une session existe. sp_session (toute requête authentifiée
-- + rôle par défaut), sp_login (fenêtre de connexion, pour tracer un échec) et sp_password (changement
-- de mot de passe). La procédure n'INSÈRE qu'une trace : aucun privilège d'écriture métier accordé.
GRANT EXECUTE ON PROCEDURE secure_payroll.audit_record TO 'sp_session';
GRANT EXECUTE ON PROCEDURE secure_payroll.audit_record TO 'sp_login';
GRANT EXECUTE ON PROCEDURE secure_payroll.audit_record TO 'sp_password';

-- Supervision : réservée à l'auditeur de sécurité (lecture seule, via procédures).
GRANT EXECUTE ON PROCEDURE secure_payroll.audit_recent TO 'sp_security_auditor';
GRANT EXECUTE ON PROCEDURE secure_payroll.audit_suspicious TO 'sp_security_auditor';
