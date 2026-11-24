-- À créer AVANT 002/003, par un administrateur après revue. Jamais par PHP.
-- Compte de contexte d’exécution uniquement, jamais de login applicatif.
-- Le lanceur capture et ignore la sortie contenant le secret aléatoire : ne pas
-- exécuter ce fichier isolément vers un terminal ou un fichier de journal.
-- Pas de IF NOT EXISTS : ne jamais adopter/réutiliser silencieusement un compte.
CREATE USER 'hr_module_owner'@'localhost' IDENTIFIED BY RANDOM PASSWORD ACCOUNT LOCK;
