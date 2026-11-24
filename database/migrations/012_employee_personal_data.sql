-- Migration 012 — MySQL 8.4. À appliquer UNE SEULE FOIS dans secure_payroll, après 001.
-- Données personnelles réalistes des salariés (contexte marocain, paie en MAD).
--
-- Choix de conception : table 1:1 séparée de `employees`, et non colonnes ajoutées.
--   * svc_php possède GRANT SELECT sur TOUTE la table employees (svc_php_grants.sql) :
--     des colonnes ajoutées y seraient immédiatement lisibles par le compte de service.
--   * Une table dédiée permet des privilèges distincts (phase 2), un chiffrement ciblé
--     (phase 6) et un audit des accès (phase 7), sans toucher l'identité stable.
--   * Aucun GRANT n'est accordé ici : à ce stade, aucun compte applicatif n'y accède.
--
-- Minimisation : seules les données nécessaires à une paie marocaine sont collectées
-- (pièce d'identité, affiliation CNSS, date de naissance, situation familiale pour les
-- charges de famille, coordonnées, RIB pour le virement). Aucune donnée de santé,
-- religion, origine ou photo.
--
-- État volontairement « avant » : valeurs en clair, formats contrôlés par CHECK.
-- Le chiffrement des colonnes les plus sensibles relève de la phase 6.
USE secure_payroll;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs;
SET SESSION time_zone = '+00:00';

CREATE TABLE employee_personal_data (
    employee_id BIGINT UNSIGNED NOT NULL,
    id_document_type VARCHAR(16) NOT NULL,
    id_document_number VARCHAR(12) NOT NULL,
    cnss_number CHAR(9) NULL,
    birth_date DATE NOT NULL,
    marital_status VARCHAR(16) NOT NULL,
    dependent_children TINYINT UNSIGNED NOT NULL DEFAULT 0,
    personal_email VARCHAR(254) NOT NULL,
    personal_phone VARCHAR(13) NOT NULL,
    address_line VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    postal_code CHAR(5) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    rib CHAR(24) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME(6) NULL,
    PRIMARY KEY (employee_id),
    CONSTRAINT uq_epd_id_document UNIQUE (id_document_type, id_document_number),
    -- NULL autorisé : affiliation CNSS parfois en cours à l'embauche.
    CONSTRAINT uq_epd_cnss UNIQUE (cnss_number),
    -- Un RIB par salarié : deux salaires vers un même compte est un indicateur de fraude.
    CONSTRAINT uq_epd_rib UNIQUE (rib),
    CONSTRAINT fk_epd_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_epd_created_by FOREIGN KEY (created_by) REFERENCES accounts (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_epd_updated_by FOREIGN KEY (updated_by) REFERENCES accounts (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    -- CIN : 1 ou 2 lettres puis chiffres ; titres de séjour et passeports : alphanumérique.
    CONSTRAINT ck_epd_id_document CHECK (
        (CAST(id_document_type AS BINARY) = CAST('cin' AS BINARY)
            AND REGEXP_LIKE(id_document_number, '^[A-Z]{1,2}[0-9]{4,6}$', 'c'))
        OR (CAST(id_document_type AS BINARY) = CAST('residence_card' AS BINARY)
            AND REGEXP_LIKE(id_document_number, '^[A-Z]{1,2}[0-9]{4,8}[A-Z]?$', 'c'))
        OR (CAST(id_document_type AS BINARY) = CAST('passport' AS BINARY)
            AND REGEXP_LIKE(id_document_number, '^[A-Z0-9]{6,9}$', 'c'))
    ),
    CONSTRAINT ck_epd_cnss CHECK (cnss_number IS NULL OR REGEXP_LIKE(cnss_number, '^[0-9]{9}$', 'c')),
    -- Âge minimal d'emploi de 15 ans révolus (Code du travail marocain, art. 143).
    CONSTRAINT ck_epd_birth_date CHECK (
        birth_date >= '1900-01-01' AND birth_date <= DATE(created_at) - INTERVAL 15 YEAR
    ),
    CONSTRAINT ck_epd_marital_status CHECK (
        CAST(marital_status AS BINARY) IN (CAST('single' AS BINARY), CAST('married' AS BINARY),
            CAST('divorced' AS BINARY), CAST('widowed' AS BINARY))
    ),
    CONSTRAINT ck_epd_children CHECK (dependent_children <= 20),
    CONSTRAINT ck_epd_email CHECK (
        REGEXP_LIKE(personal_email, '^[a-z0-9._%+-]+@[a-z0-9-]+(\\.[a-z0-9-]+)*\\.[a-z]{2,}$', 'c')
    ),
    -- Format international marocain : fixe (5) ou mobile (6, 7).
    CONSTRAINT ck_epd_phone CHECK (REGEXP_LIKE(personal_phone, '^\\+212[5-7][0-9]{8}$', 'c')),
    CONSTRAINT ck_epd_address CHECK (
        CHAR_LENGTH(TRIM(address_line)) > 0 AND address_line = TRIM(address_line)
        AND CHAR_LENGTH(TRIM(city)) > 0 AND city = TRIM(city)
    ),
    CONSTRAINT ck_epd_postal_code CHECK (REGEXP_LIKE(postal_code, '^[0-9]{5}$', 'c')),
    CONSTRAINT ck_epd_bank_name CHECK (CHAR_LENGTH(TRIM(bank_name)) > 0 AND bank_name = TRIM(bank_name)),
    -- RIB marocain : 24 chiffres (banque 3, localité 3, compte 16, clé 2). Format seul.
    CONSTRAINT ck_epd_rib CHECK (REGEXP_LIKE(rib, '^[0-9]{24}$', 'c')),
    CONSTRAINT ck_epd_update CHECK (
        (updated_at IS NULL AND updated_by IS NULL)
        OR (updated_at IS NOT NULL AND updated_by IS NOT NULL AND updated_at >= created_at)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

-- DELIMITER est une commande du client mysql, pas une instruction PDO.
DELIMITER $$
-- Provenance immuable ; toute modification déclare son auteur et reçoit l'heure SQL.
CREATE TRIGGER employee_personal_data_preserve_origin BEFORE UPDATE ON employee_personal_data FOR EACH ROW
BEGIN
    IF NOT (NEW.employee_id <=> OLD.employee_id)
        OR NOT (NEW.created_by <=> OLD.created_by)
        OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Personal data origin is immutable';
    END IF;
    IF NEW.updated_by IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Personal data update requires an author';
    END IF;
    SET NEW.updated_at = UTC_TIMESTAMP(6);
END$$
-- Pas de suppression ordinaire : l'effacement après durée légale de conservation
-- devra passer par une procédure administrative tracée, hors application.
CREATE TRIGGER employee_personal_data_prevent_delete BEFORE DELETE ON employee_personal_data FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Personal data cannot be deleted by ordinary DML';
END$$
DELIMITER ;
