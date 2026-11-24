-- Phase 7 — Propriétaire verrouillé de la piste d'audit de sécurité.
-- Lui seul détient le DML sur security_audit_log et définit (DEFINER) la procédure d'écriture,
-- les procédures de supervision et les déclencheurs d'audit des tables sensibles. Ainsi la piste
-- d'audit ne dépend d'aucun rôle applicatif : l'application ne peut qu'INSÉRER via la procédure,
-- jamais modifier ni supprimer. Mot de passe aléatoire inutilisable, compte verrouillé.
CREATE USER 'audit_owner'@'localhost' IDENTIFIED BY RANDOM PASSWORD ACCOUNT LOCK;
