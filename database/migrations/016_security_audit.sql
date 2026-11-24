-- Migration 016 — Phase 7 : table d'audit de sécurité (en ajout seul) et procédures.
--
-- Une table d'audit CENTRALE répond aux cinq questions de l'énoncé pour chaque activité sensible :
-- QUI (actor_account_id + db_user), QUOI (event), QUAND (occurred_at), QUELLE RESSOURCE
-- (target_table/target_id), SUCCÈS/ÉCHEC (outcome).
--
-- Deux sources l'alimentent : 'application' (le code appelle audit_record) et 'trigger' (des
-- déclencheurs sur les tables sensibles, migration 017). Le propriétaire audit_owner (compte
-- verrouillé) porte le DML ; ses procédures DEFINER s'exécutent avec ses droits. Le rôle applicatif
-- n'obtient que l'EXECUTE d'audit_record : il INSÈRE, sans pouvoir modifier ni supprimer une trace.
--
-- Les droits d'audit_owner et les déclencheurs viennent APRÈS (audit_grants.sql puis migration 017) :
-- créer un trigger DEFINER='audit_owner' exige que ce compte détienne déjà le privilège TRIGGER.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone = '+00:00';

CREATE TABLE security_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    event VARCHAR(64) NOT NULL,
    outcome ENUM('success', 'failure', 'denied') NOT NULL DEFAULT 'success',
    source ENUM('application', 'trigger') NOT NULL,
    actor_account_id BIGINT UNSIGNED NULL,
    actor_label VARCHAR(100) NULL,
    db_user VARCHAR(97) NOT NULL,
    target_table VARCHAR(64) NULL,
    target_id BIGINT UNSIGNED NULL,
    context JSON NULL,
    PRIMARY KEY (id),
    KEY idx_audit_time (occurred_at),
    KEY idx_audit_event (event),
    KEY idx_audit_actor (actor_account_id),
    KEY idx_audit_target (target_table, target_id),
    KEY idx_audit_outcome (outcome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

DELIMITER $$

-- Écriture d'un événement applicatif. SESSION_USER() (et non CURRENT_USER(), qui vaudrait le
-- DEFINER) capte le compte MySQL réellement connecté. Jamais de donnée sensible en clair ici :
-- l'appelant passe des identifiants et des indicateurs, pas des valeurs protégées.
CREATE DEFINER='audit_owner'@'localhost' PROCEDURE audit_record(
    IN p_event VARCHAR(64),
    IN p_outcome VARCHAR(16),
    IN p_actor BIGINT UNSIGNED,
    IN p_actor_label VARCHAR(100),
    IN p_target_table VARCHAR(64),
    IN p_target_id BIGINT UNSIGNED,
    IN p_context JSON)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
    INSERT INTO security_audit_log
        (event, outcome, source, actor_account_id, actor_label, db_user, target_table, target_id, context)
    VALUES
        (p_event, COALESCE(NULLIF(p_outcome, ''), 'success'), 'application',
         p_actor, p_actor_label, SESSION_USER(), p_target_table, p_target_id, p_context);
END$$

-- Supervision : les N événements récents, acteur rendu lisible (login + nom). Réservé à l'auditeur.
CREATE DEFINER='audit_owner'@'localhost' PROCEDURE audit_recent(IN p_limit INT)
SQL SECURITY DEFINER READS SQL DATA
BEGIN
    IF p_limit IS NULL OR p_limit < 1 OR p_limit > 500 THEN
        SET p_limit = 100;
    END IF;
    -- L'acteur ET la cible (quand c'est un compte, ex. une connexion échouée) sont résolus en
    -- libellé lisible ; l'IP source est extraite du contexte pour l'investigation.
    SELECT l.occurred_at, l.event, l.outcome, l.source,
           l.actor_account_id, COALESCE(a.login, l.actor_label) AS actor,
           TRIM(CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, ''))) AS actor_name,
           l.db_user, l.target_table, l.target_id, l.context,
           ta.login AS target_login,
           TRIM(CONCAT(COALESCE(te.first_name, ''), ' ', COALESCE(te.last_name, ''))) AS target_name,
           JSON_UNQUOTE(JSON_EXTRACT(l.context, '$.ip')) AS source_ip
    FROM security_audit_log l
    LEFT JOIN accounts a ON a.id = l.actor_account_id
    LEFT JOIN employees e ON e.id = a.employee_id
    LEFT JOIN accounts ta ON l.target_table = 'accounts' AND ta.id = l.target_id
    LEFT JOIN employees te ON te.id = ta.employee_id
    ORDER BY l.id DESC
    LIMIT p_limit;
END$$

-- Détection : signaux d'activité suspecte sur 24 h. Rafales d'échecs de connexion, refus d'accès
-- répétés, et accès en lecture massifs aux données sensibles. C'est la démonstration « comment les
-- traces collectées permettent de détecter / investiguer une activité suspecte ».
CREATE DEFINER='audit_owner'@'localhost' PROCEDURE audit_suspicious()
SQL SECURITY DEFINER READS SQL DATA
BEGIN
    SELECT signal_type, subject, occurrences, first_seen, last_seen FROM (
        SELECT 'echecs_connexion' AS signal_type,
               COALESCE(CONCAT('compte#', target_id), CONCAT('ip ', context->>'$.ip'), 'inconnu') AS subject,
               COUNT(*) AS occurrences, MIN(occurred_at) AS first_seen, MAX(occurred_at) AS last_seen
        FROM security_audit_log
        WHERE event = 'auth.failed' AND occurred_at > UTC_TIMESTAMP(6) - INTERVAL 24 HOUR
        GROUP BY target_id, context->>'$.ip' HAVING occurrences >= 5
        UNION ALL
        SELECT 'acces_refuses',
               COALESCE(CONCAT('compte#', actor_account_id), db_user),
               COUNT(*), MIN(occurred_at), MAX(occurred_at)
        FROM security_audit_log
        WHERE outcome = 'denied' AND occurred_at > UTC_TIMESTAMP(6) - INTERVAL 24 HOUR
        GROUP BY actor_account_id, db_user HAVING COUNT(*) >= 3
        UNION ALL
        SELECT 'lectures_sensibles',
               COALESCE(CONCAT('compte#', actor_account_id), db_user),
               COUNT(*), MIN(occurred_at), MAX(occurred_at)
        FROM security_audit_log
        WHERE event LIKE '%.viewed' AND occurred_at > UTC_TIMESTAMP(6) - INTERVAL 24 HOUR
        GROUP BY actor_account_id, db_user HAVING COUNT(*) >= 10
    ) AS signals
    ORDER BY last_seen DESC;
END$$

DELIMITER ;
