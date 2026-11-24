# SecurePayroll

SecurePayroll est un projet d’ingénierie en cybersécurité appliquée à la paie. L’application PHP/MySQL assure la gestion des salariés et de la rémunération, ainsi que la simulation de la paie en MAD. L’interface est disponible en français et en anglais. Les données d’exemple sont fictives et les règlements sont simulés.

La démonstration couvre l’embauche, l’activation du compte, la préparation de la paie, son approbation par la Finance, la publication du bulletin et l’enregistrement du règlement. Le périmètre des données dépend du rôle et du salarié concerné.

## Architecture et contrôles de sécurité

| Domaine | Mécanisme vérifié |
|---|---|
| Architecture | Application PHP avec PDO et MySQL 8.4. Le point d’entrée HTTP est dans `public/`. L’installateur lance une instance MySQL privée accessible par socket Unix. |
| Identité | Mots de passe hachés en Argon2id, changement obligatoire du mot de passe temporaire, limitation des échecs de connexion et rotation de l’identifiant de session. |
| Moindre privilège | RBAC applicatif et rôles natifs MySQL activés selon la requête. Les comptes d’administration, d’audit et de sauvegarde sont distincts. Des procédures `SQL SECURITY DEFINER` exécutent des opérations ciblées avec les droits de propriétaires verrouillés. |
| Injection SQL et IDOR | Requêtes préparées natives, exécution SQL multiple désactivée et contrôles d’autorisation sur les objets consultés. La recette vérifie notamment le rejet d’une entrée d’injection et l’isolation des bulletins par salarié. |
| Données personnelles | Chiffrement AES-256-GCM de la pièce d’identité, du numéro CNSS et du RIB. Des index aveugles HMAC-SHA256 permettent la recherche par égalité avec une clé distincte. |
| Journalisation et enquête | Événements applicatifs et déclencheurs SQL alimentent une piste d’audit en ajout seul. La supervision fait ressortir les échecs répétés et la recette d’investigation reconstitue une chronologie à partir des traces. |
| Sauvegarde et reprise | Export logique par un compte en lecture seule, chiffrement AES-256-GCM, restauration vérifiée en instance isolée et réapplication des droits MySQL. |
| Durcissement MySQL | Démarrage sans écoute réseau, opérations serveur sur fichiers et `LOCAL INFILE` désactivés, contrôle des comptes et privilèges. |

## Validation

Les quatre recettes ont réussi sur MySQL 8.4.13 dans des instances jetables isolées. La recette d’installation a validé les six rôles web, l’embauche, la matrice de privilèges et le parcours de paie jusqu’au règlement fictif.

| Recette | Contrôles réussis |
|---|---:|
| `tests/local-install.py` | 151/151 |
| `tests/backup-recovery.py` | 19/19 |
| `tests/hardening-audit.py` | 28/28 |
| `tests/incident-investigation.py` | 16/16 |

## Démarrer en local

Sous Linux, prévoir PHP CLI 8.1+ (64 bits) avec PDO MySQL, sessions, OpenSSL, Intl, Mbstring et Argon2id, Python 3.10+ et MySQL Community Server 8.4. Le guide détaille la dépendance supplémentaire pour les sauvegardes chiffrées.

```bash
python3 bin/init-local.py init
python3 bin/init-local.py credentials
python3 bin/init-local.py serve
```

Ouvrir `http://127.0.0.1:8080/login`. L’installateur crée une instance MySQL privée, accessible par socket Unix. Consulter les mots de passe dans un terminal privé. Après avoir arrêté PHP avec Ctrl+C, arrêter MySQL avec `python3 bin/init-local.py stop`.

- [Installation, dépendances et tests](docs/INSTALLATION.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Sécurité, sauvegarde et traitement des incidents](SECURITY.md)

## Limites et améliorations futures

- Le déploiement validé est local, avec données fictives et serveur HTTP de développement. Un déploiement réel demanderait une recette dédiée pour HTTPS, la gestion des secrets et la configuration de production.
- Une écriture directe par un DBA privilégié peut contourner les contrôles applicatifs. Le scénario d’investigation en démontre la détection par l’audit SQL. Une collecte hors instance renforcerait la conservation des preuves.
- La boîte d’envoi de démonstration et l’export logique intermédiaire contiennent des données en clair. Définir leur rétention et tester la rotation des clés ainsi que la reprise sur un environnement distinct.

## Équipe

Projet du module Database Security réalisé par **CISSE Marwane Mouawiyah**, **LANKOANDE Melwine Ange Lormel Lankoulmani**, **SOUNDA Abdelkerim Ahmat**, **TILENGAR Mbaihornom Lionel** et **ZONGO Nabonswendé Regis Epiphane**.
