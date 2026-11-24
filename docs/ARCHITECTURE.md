# Architecture

`public/index.php` reçoit les requêtes HTTP. Les contrôleurs de `app/Web/` orchestrent la validation, la session et les autorisations. Les vues PHP rendent l’interface à partir des catalogues de traduction `lang/fr.php` et `lang/en.php`. Les dépôts métier exécutent les requêtes préparées via PDO ou appellent des procédures SQL.

| Composant | Responsabilité |
| --- | --- |
| `app/Auth/`, `app/Security/`, `app/Database/` | Authentification, activation, sessions, contrôle d’accès MySQL et chiffrement |
| `app/HR/`, `app/Compensation/` | Dossiers salariés, contrats, périodes RH et rémunération |
| `app/Payroll/`, contrôleurs de paie et règlement | Calendrier, calcul, approbation et publication, suivi du règlement fictif |
| `app/Audit/`, `app/Access/` | Piste d’audit et consultation de l’historique |
| `database/` | Migrations, procédures, déclencheurs, permissions et données fictives |
| `bin/` | Installation privée, sauvegarde, chiffrement et contrôle des secrets |

`bin/init-local.py` définit l’ordre d’installation des objets SQL et des privilèges. Les migrations d’identité et métier sont complétées par les migrations 012 à 017 pour les données personnelles, l’embauche, le chiffrement et l’audit. Les données chiffrées restent en MySQL tandis que les clés résident dans le répertoire d’exécution privé. Le journal d’application et la boîte d’envoi se trouvent aussi dans le répertoire d’exécution privé de l’instance locale.
