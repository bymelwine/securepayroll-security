-- Embauche : création du salarié et de son compte par la RH, mot de passe temporaire, changement obligatoire.
-- Appliqué une seule fois (installation neuve, ou `init-local.py upgrade` sur une instance existante).
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone = '+00:00';

-- Un compte créé à l'embauche n'est pleinement actif qu'après le changement de son mot de passe temporaire.
ALTER TABLE accounts
    ADD COLUMN password_change_required BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN temporary_password_expires_at DATETIME(6) NULL,
    ADD COLUMN password_changed_at DATETIME(6) NULL,
    ADD CONSTRAINT ck_accounts_temporary_password CHECK (
        (password_change_required AND temporary_password_expires_at IS NOT NULL)
        OR (NOT password_change_required AND temporary_password_expires_at IS NULL)
    );

-- Trace de chaque invitation. L'e-mail personnel (Confidentiel) n'est lisible par aucun rôle de l'application :
-- la RH l'écrit à l'embauche sans pouvoir le relire.
CREATE TABLE account_invitations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    personal_email VARCHAR(254) NOT NULL,
    issued_by_account_id BIGINT UNSIGNED NOT NULL,
    issued_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY ix_invitations_account (account_id),
    CONSTRAINT fk_invitations_account FOREIGN KEY (account_id) REFERENCES accounts (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_invitations_issuer FOREIGN KEY (issued_by_account_id) REFERENCES accounts (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_invitations_email CHECK (
        CHAR_LENGTH(personal_email) BETWEEN 6 AND 254
        AND REGEXP_LIKE(personal_email, '^[^@[:space:]]+@[^@[:space:]]+[.][^@[:space:]]+$', 'c')
        AND NOT REGEXP_LIKE(personal_email, '@securepayroll[.]ma$', 'i')
    ),
    CONSTRAINT ck_invitations_expiry CHECK (expires_at > issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

DELIMITER $$
CREATE TRIGGER invitations_immutable BEFORE UPDATE ON account_invitations FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invitation history is immutable';
END$$

CREATE TRIGGER invitations_prevent_delete BEFORE DELETE ON account_invitations FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invitation history cannot be deleted';
END$$

-- Création atomique : salarié, compte (identifiant professionnel unique), invitation et rôle Employé.
-- Le mot de passe temporaire arrive déjà haché (Argon2id) : la base ne voit jamais le mot de passe en clair.
CREATE DEFINER='iam_owner'@'localhost' PROCEDURE account_onboard(
 IN p_actor BIGINT UNSIGNED,
 IN p_number VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_first_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_last_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_login_base VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_personal_email VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs,
 IN p_temporary_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE v_person BIGINT UNSIGNED;
 DECLARE v_employee BIGINT UNSIGNED;
 DECLARE v_account BIGINT UNSIGNED;
 DECLARE v_role BIGINT UNSIGNED DEFAULT NULL;
 DECLARE v_login VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL;
 DECLARE v_suffix INT UNSIGNED DEFAULT 1;
 DECLARE v_expires DATETIME(6);
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
 START TRANSACTION;
 CALL payroll_assert(p_actor, 'employees.create', 'hr_manager', v_person);
 IF p_number IS NULL OR p_first_name IS NULL OR p_last_name IS NULL OR p_personal_email IS NULL
  OR p_temporary_hash IS NULL OR p_login_base IS NULL
  OR NOT REGEXP_LIKE(p_login_base, '^[a-z0-9]+([.-][a-z0-9]+)*$', 'c') OR CHAR_LENGTH(p_login_base) > 150 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ONBOARD_INVALID';
 END IF;
 SELECT id INTO v_role FROM roles WHERE code = 'employee';
 INSERT INTO employees(employee_number, first_name, last_name) VALUES (p_number, p_first_name, p_last_name);
 SET v_employee = LAST_INSERT_ID();
 -- Identifiant professionnel : prenom.nom@securepayroll.ma, puis prenom.nom2@…, prenom.nom3@… en cas d'homonyme.
 WHILE v_login IS NULL DO
  IF v_suffix > 99 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ONBOARD_LOGIN'; END IF;
  SET v_login = CONCAT(p_login_base, IF(v_suffix = 1, '', v_suffix), '@securepayroll.ma');
  -- Deux créations simultanées du même identifiant : l'index unique fait échouer la seconde (1062).
  IF EXISTS(SELECT 1 FROM accounts WHERE login = v_login) THEN
   SET v_login = NULL;
   SET v_suffix = v_suffix + 1;
  END IF;
 END WHILE;
 SET v_expires = CURRENT_TIMESTAMP(6) + INTERVAL 72 HOUR;
 INSERT INTO accounts(employee_id, login, password_hash, password_change_required, temporary_password_expires_at)
 VALUES (v_employee, v_login, p_temporary_hash, TRUE, v_expires);
 SET v_account = LAST_INSERT_ID();
 INSERT INTO account_invitations(account_id, personal_email, issued_by_account_id, expires_at)
 VALUES (v_account, p_personal_email, p_actor, v_expires);
 -- Droit de base de tout salarié ; les autres rôles restent attribués par l'administrateur (iam_grant).
 INSERT INTO role_assignments(account_id, role_id, granted_by_account_id, grant_reason)
 VALUES (v_account, v_role, p_actor, 'Embauche : rôle de base attribué à la création du compte');
 COMMIT;
 SELECT v_employee AS employee_id, v_account AS account_id, v_login AS login, v_expires AS expires_at;
END$$

-- Changement de mot de passe. p_current_hash doit être l'empreinte actuelle, que PHP vient de lire et de vérifier
-- sous le rôle sp_login : le seul droit EXECUTE ne suffit donc pas à changer le mot de passe d'un autre compte.
CREATE DEFINER='iam_owner'@'localhost' PROCEDURE account_change_password(
 IN p_account BIGINT UNSIGNED,
 IN p_current_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
 IN p_new_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 IF p_account IS NULL OR p_current_hash IS NULL OR p_new_hash IS NULL OR p_new_hash = p_current_hash THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PASSWORD_INVALID';
 END IF;
 START TRANSACTION;
 UPDATE accounts a JOIN employees e ON e.id = a.employee_id
 SET a.password_hash = p_new_hash, a.password_change_required = FALSE,
     a.temporary_password_expires_at = NULL, a.password_changed_at = CURRENT_TIMESTAMP(6)
 WHERE a.id = p_account AND a.password_hash = p_current_hash AND a.disabled_at IS NULL AND e.archived_at IS NULL
  AND (NOT a.password_change_required OR a.temporary_password_expires_at > CURRENT_TIMESTAMP(6));
 IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PASSWORD_STATE'; END IF;
 COMMIT;
 SELECT p_account AS id;
END$$
DELIMITER ;
