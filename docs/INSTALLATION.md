# Installation et vérification

## Prérequis

- Linux et compte ordinaire. Utiliser un chemin absolu court sans espaces ou accents pour le répertoire MySQL.
- Python 3.10+ et Git pour l’audit de publication.
- PHP CLI 8.1+ en 64 bits avec `pdo_mysql`, `session`, `openssl`, `intl`, `mbstring` et Argon2id. Sur Debian ou Ubuntu, les paquets PHP incluent généralement `php-cli`, `php-mysql`, `php-intl` et `php-mbstring`.
- MySQL Community Server 8.4 avec `mysql`, `mysqld` et `mysqldump`. Le chiffrement des sauvegardes demande également le paquet Python `cryptography` dans l’environnement utilisé pour les scripts.

## Instance privée

Depuis la racine du projet, exécuter `python3 bin/init-local.py init`, puis `python3 bin/init-local.py serve`. L’instance privée est créée dans `.local/`. Le lanceur transmet la configuration au processus PHP. `.env.example` documente des variables et le lanceur ne charge pas automatiquement un fichier `.env`.

Pour utiliser un répertoire différent, passer `--directory /chemin/absolu/neuf` à toutes les commandes. `status` vérifie l’instance privée. `credentials` et `outbox` affichent des données sensibles, uniquement dans un terminal. Ctrl+C arrête le serveur PHP et `stop` arrête MySQL.

Si l’initialisation échoue, consulter en privé `initialize.log` puis `mysql.log` dans le répertoire d’instance, si ce dernier existe. Conserver le répertoire d’essai pour le diagnostic et utiliser un chemin neuf pour une nouvelle tentative. Garder confidentiels les journaux, invitations, clés et configurations privées.

Si InnoDB signale l’erreur système 122 pendant la création des journaux redo, vérifier le quota du système de fichiers et l’espace disponible. Choisir un répertoire neuf sur un volume disposant d’un quota suffisant et autorisé par le profil MySQL de la machine. Vérifier le chargement d’Intl avec `php --ri intl` avant de tester l’embauche.

## Tests

Les tests PHP unitaires et statiques s’exécutent par fichier, par exemple `php tests/field-cipher-unit.php` ou `php tests/secure-coding-static.php`. Le test `tests/database-config.php` attend des valeurs `DB_*` et ne se connecte pas. Le test d’intégrité SQL nécessite sa base dédiée et possède un lanceur isolé dans `lab/`.

Les quatre recettes ci-dessous créent chacune leur propre instance sous `/tmp`. Exécuter les recettes une à une et conserver leurs journaux privés pour faciliter le diagnostic.

```bash
python3 -B tests/local-install.py
python3 -B tests/backup-recovery.py
python3 -B tests/hardening-audit.py
python3 -B tests/incident-investigation.py
```

`tests/privilege_matrix.py` fournit les contrôles de privilèges utilisés par la recette d’installation. `python3 -B tests/secret-audit.py` vérifie le détecteur de secrets avec des données fictives.
