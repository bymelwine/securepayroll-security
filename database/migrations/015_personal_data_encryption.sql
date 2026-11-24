-- Migration 015 — Phase 6 : chiffrement au repos des données personnelles les plus sensibles.
--
-- RIB, numéro de pièce d'identité et numéro CNSS passent de clair à CHIFFRÉ (AES-256-GCM, côté PHP,
-- App\Security\FieldCipher). Les colonnes deviennent VARBINARY (texte chiffré : version||nonce||chiffré||tag).
-- Les clés restent hors base : un vidage SQL ne révèle donc rien.
--
-- Index aveugle (HMAC-SHA256, clé distincte) : une colonne *_index conserve l'unicité et la recherche,
-- impossibles sur un chiffré à nonce aléatoire. Les anciennes contraintes d'unicité et de format (REGEXP sur le
-- clair) sont retirées ; le format est désormais validé en PHP avant chiffrement (App\HR\PersonalData).
--
-- RIB rendu FACULTATIF : le salarié le saisit lui-même (libre-service) ; il est NULL tant qu'il ne l'a pas fait.
--
-- À appliquer sur une table VIDE (installation neuve : les semences viennent après). Une instance détenant déjà
-- des données en clair doit être réinstallée pour la phase 6 (données de démonstration fictives uniquement).
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone = '+00:00';

ALTER TABLE employee_personal_data
    DROP CHECK ck_epd_id_document,
    DROP CHECK ck_epd_cnss,
    DROP CHECK ck_epd_rib,
    DROP INDEX uq_epd_id_document,
    DROP INDEX uq_epd_cnss,
    DROP INDEX uq_epd_rib,
    MODIFY id_document_number VARBINARY(128) NOT NULL,
    MODIFY cnss_number VARBINARY(128) NULL,
    MODIFY rib VARBINARY(128) NULL,
    ADD COLUMN id_document_index BINARY(32) NOT NULL AFTER id_document_number,
    ADD COLUMN cnss_index BINARY(32) NULL AFTER cnss_number,
    ADD COLUMN rib_index BINARY(32) NULL AFTER rib,
    ADD CONSTRAINT uq_epd_id_document_index UNIQUE (id_document_index),
    ADD CONSTRAINT uq_epd_cnss_index UNIQUE (cnss_index),
    ADD CONSTRAINT uq_epd_rib_index UNIQUE (rib_index);

DELIMITER $$
-- Lecture par le salarié de SES SEULES données personnelles. p_actor est l'identifiant de compte de la
-- session (fourni par le serveur, jamais par l'utilisateur) : payroll_assert le résout vers l'employé
-- correspondant, si bien qu'aucun paramètre ne permet de désigner la fiche d'un autre. Le texte chiffré est
-- renvoyé tel quel ; le déchiffrement et le masquage se font en PHP.
CREATE DEFINER='personal_data_owner'@'localhost' PROCEDURE personal_data_view(IN p_actor BIGINT UNSIGNED)
SQL SECURITY DEFINER READS SQL DATA
BEGIN
    DECLARE v_person BIGINT UNSIGNED;
    CALL payroll_assert(p_actor, 'personal_data.view_own', 'employee', v_person);
    SELECT id_document_type, id_document_number, cnss_number, rib, bank_name,
           birth_date, marital_status, dependent_children, personal_email, personal_phone,
           address_line, city, postal_code, created_at, updated_at
    FROM employee_personal_data WHERE employee_id = v_person;
END$$

-- Saisie / mise à jour par le salarié de SON SEUL RIB. Le RIB chiffré et son index aveugle sont calculés en
-- PHP ; la procédure ne stocke que des octets opaques. L'index unique rejette un RIB déjà enregistré (1062).
-- Exige une fiche existante (créée par la RH) : un salarié sans fiche reçoit PDATA_NO_RECORD.
CREATE DEFINER='personal_data_owner'@'localhost' PROCEDURE personal_data_set_rib(
    IN p_actor BIGINT UNSIGNED,
    IN p_rib_cipher VARBINARY(128),
    IN p_rib_index BINARY(32),
    IN p_bank_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs)
SQL SECURITY DEFINER MODIFIES SQL DATA
BEGIN
    DECLARE v_person BIGINT UNSIGNED;
    DECLARE v_exists BIGINT UNSIGNED DEFAULT NULL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    CALL payroll_assert(p_actor, 'personal_data.update_rib', 'employee', v_person);
    SELECT employee_id INTO v_exists FROM employee_personal_data WHERE employee_id = v_person FOR UPDATE;
    IF v_exists IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PDATA_NO_RECORD';
    END IF;
    UPDATE employee_personal_data
    SET rib = p_rib_cipher, rib_index = p_rib_index, bank_name = p_bank_name, updated_by = p_actor
    WHERE employee_id = v_person;
    COMMIT;
END$$
DELIMITER ;
