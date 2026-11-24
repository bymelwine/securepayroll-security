-- Phase 6 — Propriétaire verrouillé de la table des données personnelles sensibles.
-- Lui seul détient le DML sur employee_personal_data et définit les procédures d'accès (DEFINER).
-- Mot de passe aléatoire inutilisable, compte verrouillé : personne ne se connecte avec.
CREATE USER 'personal_data_owner'@'localhost' IDENTIFIED BY RANDOM PASSWORD ACCOUNT LOCK;
