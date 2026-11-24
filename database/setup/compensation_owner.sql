-- Le lanceur masque la sortie contenant le mot de passe aléatoire inutilisable.
CREATE USER 'compensation_owner'@'localhost' IDENTIFIED BY RANDOM PASSWORD ACCOUNT LOCK;
