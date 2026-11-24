-- Phase 6 — Droits d'accès aux données personnelles chiffrées.
-- Seul personal_data_owner touche la table ; le salarié n'obtient que l'EXECUTE des deux procédures.
USE secure_payroll;

-- Le propriétaire (DEFINER des procédures) : lecture des colonnes renvoyées, écriture du seul RIB,
-- et EXECUTE de payroll_assert qu'il appelle pour résoudre et autoriser l'acteur.
GRANT SELECT (employee_id, id_document_type, id_document_number, cnss_number, rib, bank_name,
    birth_date, marital_status, dependent_children, personal_email, personal_phone,
    address_line, city, postal_code, created_at, updated_at)
    ON secure_payroll.employee_personal_data TO 'personal_data_owner'@'localhost';
GRANT UPDATE (rib, rib_index, bank_name, updated_by)
    ON secure_payroll.employee_personal_data TO 'personal_data_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.payroll_assert TO 'personal_data_owner'@'localhost';

-- MySQL vérifie l'EXECUTE du DEFINER sur ses propres procédures (comportement constaté, comme pour iam_owner).
GRANT EXECUTE ON PROCEDURE secure_payroll.personal_data_view TO 'personal_data_owner'@'localhost';
GRANT EXECUTE ON PROCEDURE secure_payroll.personal_data_set_rib TO 'personal_data_owner'@'localhost';

-- Le salarié (rôle sp_employee) : exécuter les deux procédures, rien de plus. Aucun accès direct à la table.
GRANT EXECUTE ON PROCEDURE secure_payroll.personal_data_view TO 'sp_employee';
GRANT EXECUTE ON PROCEDURE secure_payroll.personal_data_set_rib TO 'sp_employee';
