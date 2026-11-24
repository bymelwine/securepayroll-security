-- Phase 2 — Rôles MySQL des comptes d'exploitation (personnes et tâches techniques).
--
-- Les comptes eux-mêmes (dba_ops, backup_ops, audit_ops) sont créés par bin/init-local.py,
-- qui génère leurs mots de passe ; ce script ne définit que les rôles et leurs privilèges.
-- root reste réservé à l'installation et à la gestion des comptes et des rôles.

CREATE ROLE 'sp_dba', 'sp_backup', 'sp_db_auditor';

-- sp_dba : maintenance du schéma secure_payroll (structure, index, procédures, triggers).
-- Aucun privilège global, aucun GRANT OPTION : le DBA ne crée pas de comptes et ne distribue
-- pas de droits. Sans SET_USER_ID, il ne peut pas créer une procédure au nom d'un compte
-- propriétaire (*_owner). Il peut lire et modifier les données : ce risque est accepté et
-- doit être détecté par la journalisation (menace T-14, phase 7).
GRANT ALL PRIVILEGES ON secure_payroll.* TO 'sp_dba';

-- sp_backup : exactement ce qu'exige bin/backup.py (mysqldump --single-transaction --routines
-- --triggers --events --no-tablespaces). Lecture seule : aucune écriture ni aucun DDL.
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON secure_payroll.* TO 'sp_backup';
GRANT SHOW_ROUTINE ON *.* TO 'sp_backup';

-- sp_db_auditor : investigation en SQL des traces (qui a fait quoi, quand), sans aucune
-- donnée Restreint : ni montant, ni empreinte, ni donnée personnelle.
GRANT SELECT ON secure_payroll.employees TO 'sp_db_auditor';
GRANT SELECT (id, employee_id, login, created_at, disabled_at) ON secure_payroll.accounts TO 'sp_db_auditor';
GRANT SELECT ON secure_payroll.roles TO 'sp_db_auditor';
GRANT SELECT ON secure_payroll.permissions TO 'sp_db_auditor';
GRANT SELECT ON secure_payroll.role_permissions TO 'sp_db_auditor';
GRANT SELECT ON secure_payroll.role_assignments TO 'sp_db_auditor';
GRANT SELECT ON secure_payroll.compensation_decisions TO 'sp_db_auditor';
GRANT SELECT ON secure_payroll.compensation_history TO 'sp_db_auditor';
GRANT SELECT ON secure_payroll.payroll_input_decisions TO 'sp_db_auditor';
GRANT SELECT ON secure_payroll.payslip_events TO 'sp_db_auditor';
GRANT SELECT ON secure_payroll.payroll_approvals TO 'sp_db_auditor';
GRANT SELECT ON secure_payroll.correction_events TO 'sp_db_auditor';
GRANT SELECT ON secure_payroll.recovery_events TO 'sp_db_auditor';
GRANT SELECT (id, calculation_id, correction_id, recovery_id, entry_type, currency, reference,
              reason, actor_id, actor_employee_id, occurred_at)
    ON secure_payroll.settlement_ledger TO 'sp_db_auditor';
