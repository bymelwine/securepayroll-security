-- Migration 017 — Phase 7 : déclencheurs d'audit (DEFINER='audit_owner').
--
-- Appliquée APRÈS audit_grants.sql : créer un trigger DEFINER='audit_owner' exige que ce compte
-- détienne déjà le privilège TRIGGER sur la table concernée (vérifié à la création), et son INSERT
-- sur security_audit_log (vérifié à l'exécution).
--
-- Les triggers enregistrent le FAIT et la RESSOURCE, jamais la valeur protégée (seulement des
-- indicateurs de changement). @audit_actor est l'acteur applicatif posé par l'application ;
-- SESSION_USER() donne toujours l'identité MySQL réelle, même pour une écriture hors application.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone = '+00:00';

DELIMITER $$

-- Ajout seul : aucune modification ni suppression d'une trace, pour personne (y compris root et le
-- propriétaire). Une tentative est elle-même un signal — mais elle échoue avant toute écriture.
CREATE DEFINER='audit_owner'@'localhost' TRIGGER security_audit_log_no_update
BEFORE UPDATE ON security_audit_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AUDIT_APPEND_ONLY';
END$$

CREATE DEFINER='audit_owner'@'localhost' TRIGGER security_audit_log_no_delete
BEFORE DELETE ON security_audit_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AUDIT_APPEND_ONLY';
END$$

-- Données personnelles : création et modification (RIB/CIN/CNSS restent chiffrés ; on note juste ce
-- qui a changé). La suppression est déjà interdite par un trigger de la phase 1.
CREATE DEFINER='audit_owner'@'localhost' TRIGGER epd_audit_insert
AFTER INSERT ON employee_personal_data FOR EACH ROW
BEGIN
    INSERT INTO security_audit_log (event, outcome, source, actor_account_id, db_user, target_table, target_id)
    VALUES ('personal_data.created', 'success', 'trigger', @audit_actor, SESSION_USER(),
            'employee_personal_data', NEW.employee_id);
END$$

CREATE DEFINER='audit_owner'@'localhost' TRIGGER epd_audit_update
AFTER UPDATE ON employee_personal_data FOR EACH ROW
BEGIN
    INSERT INTO security_audit_log (event, outcome, source, actor_account_id, db_user, target_table, target_id, context)
    VALUES ('personal_data.modified', 'success', 'trigger', @audit_actor, SESSION_USER(),
            'employee_personal_data', NEW.employee_id,
            JSON_OBJECT('rib_change', NOT (NEW.rib <=> OLD.rib),
                        'contact_change', NOT (NEW.personal_email <=> OLD.personal_email AND NEW.personal_phone <=> OLD.personal_phone)));
END$$

-- Attributions de rôle : élévation (INSERT) et révocation/péremption (UPDATE). Action d'administration
-- la plus sensible du système.
CREATE DEFINER='audit_owner'@'localhost' TRIGGER ra_audit_insert
AFTER INSERT ON role_assignments FOR EACH ROW
BEGIN
    INSERT INTO security_audit_log (event, outcome, source, actor_account_id, db_user, target_table, target_id, context)
    VALUES ('role.granted', 'success', 'trigger', @audit_actor, SESSION_USER(),
            'role_assignments', NEW.account_id, JSON_OBJECT('role_id', NEW.role_id, 'assignment_id', NEW.id));
END$$

CREATE DEFINER='audit_owner'@'localhost' TRIGGER ra_audit_update
AFTER UPDATE ON role_assignments FOR EACH ROW
BEGIN
    INSERT INTO security_audit_log (event, outcome, source, actor_account_id, db_user, target_table, target_id, context)
    VALUES ('role.changed', 'success', 'trigger', @audit_actor, SESSION_USER(),
            'role_assignments', NEW.account_id,
            JSON_OBJECT('role_id', NEW.role_id, 'assignment_id', NEW.id,
                        'revoked', NOT (NEW.revoked_at <=> OLD.revoked_at),
                        'expiry_changed', NOT (NEW.expires_at <=> OLD.expires_at)));
END$$

-- Conditions salariales : création (table immuable ensuite). Montant non repris dans la trace.
CREATE DEFINER='audit_owner'@'localhost' TRIGGER sc_audit_insert
AFTER INSERT ON salary_conditions FOR EACH ROW
BEGIN
    INSERT INTO security_audit_log (event, outcome, source, actor_account_id, db_user, target_table, target_id, context)
    VALUES ('salary.created', 'success', 'trigger', @audit_actor, SESSION_USER(),
            'salary_conditions', NEW.id, JSON_OBJECT('employee_id', NEW.employee_id));
END$$

DELIMITER ;
