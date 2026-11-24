#!/usr/bin/env python3
"""Phase 9 — audit de durcissement vérifiable, sur une instance jetable isolée.

L'énoncé demande un bilan « avant / après durcissement » couvrant au minimum : comptes par défaut,
utilisateurs inutiles, privilèges excessifs, comptes applicatifs faibles, exposition réseau,
journalisation, fichiers de configuration, fonctions inutiles, permissions de fichiers, niveau de
correctifs, protection des sauvegardes.

Ce projet étant construit « sécurité d'abord », la colonne « après » est l'état livré par les phases
1-8 (plus le correctif secure_file_priv=NULL de la phase 9). Ce script **prouve** cet état « après » :
il installe une instance neuve, puis contrôle chaque zone programmatiquement. Montrer la configuration
ne suffit pas ; on la vérifie. Rejouable : `python3 tests/hardening-audit.py`.

Aucune base existante n'est touchée ; tout vit sous /tmp/securepayroll-hardening-*.
"""
import importlib.util
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('init_local', ROOT / 'bin/init-local.py')
init_local = importlib.util.module_from_spec(spec)
spec.loader.exec_module(init_local)

COUNT = 0
# Comptes capables de s'authentifier (non verrouillés). Tout le reste est verrouillé : propriétaires
# DEFINER, comptes système MySQL, et rôles (sans mot de passe par conception).
EXPECTED_LOGIN = {('root', 'localhost'), ('svc_php', 'localhost'), ('dba_ops', 'localhost'),
                  ('backup_ops', 'localhost'), ('audit_ops', 'localhost')}
# Privilèges globaux dangereux : admissibles uniquement pour root, les comptes système MySQL et le
# rôle d'administration sp_dba (DBA assumé). Jamais pour l'application ni ses rôles.
DANGEROUS = ('FILE', 'SUPER', 'SHUTDOWN', 'PROCESS', 'CREATE USER', 'RELOAD',
             'REPLICATION SLAVE', 'REPLICATION CLIENT', 'GRANT OPTION')
BACKUP_ALLOWED = {'SELECT', 'SHOW VIEW', 'TRIGGER', 'EVENT', 'LOCK TABLES', 'SHOW_ROUTINE', 'USAGE'}


def check(ok, name):
    global COUNT
    print(('PASS' if ok else 'FAIL') + ' ' + name, flush=True)
    if not ok:
        raise SystemExit('Contrôle de durcissement échoué : ' + name)
    COUNT += 1


def area(title):
    print('\n--- ' + title + ' ---', flush=True)


def mode(path):
    return stat.S_IMODE(path.stat().st_mode)


def refused(instance, statement, fragment):
    env = {**init_local.clean_environment(), 'MYSQL_PWD': instance.password}
    done = subprocess.run(['mysql', '--no-defaults', '--no-login-paths', '--protocol=socket',
                           '--socket=' + str(instance.socket), '--user=root'],
                          input='USE secure_payroll; ' + statement, text=True, capture_output=True, env=env)
    return done.returncode != 0 and fragment in done.stderr


def main():
    workspace = Path(tempfile.mkdtemp(prefix='securepayroll-hardening-', dir='/tmp'))
    instance_dir = workspace / 'mysql'
    print('Instance d\'audit isolée : ' + str(workspace), flush=True)
    created = subprocess.run(['python3', str(ROOT / 'bin/init-local.py'), 'init', '--directory', str(instance_dir)],
                             cwd=ROOT, env={**os.environ, 'PYTHONDONTWRITEBYTECODE': '1'}, capture_output=True, text=True)
    check(created.returncode == 0, 'Instance de durcissement installée')
    instance = init_local.LocalInstance(instance_dir)
    instance.start()
    q = instance.sql
    try:
        area('Comptes par défaut et anonymes')
        check(q("SELECT COUNT(*) FROM mysql.user WHERE User='';") == '0', 'Aucun compte anonyme')
        check(q("SELECT COUNT(*) FROM mysql.user WHERE User='root' AND Host<>'localhost';") == '0',
              'root limité à localhost (aucun root distant)')
        check(q("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='test';") == '0',
              'Aucune base « test » résiduelle')

        area('Utilisateurs inutiles')
        login = {tuple(r.split('\t')) for r in
                 q("SELECT User, Host FROM mysql.user WHERE account_locked='N';").splitlines() if r}
        check(login == EXPECTED_LOGIN,
              'Seuls les comptes de connexion attendus sont actifs (' + str(len(EXPECTED_LOGIN)) + ')')
        check(q("SELECT COUNT(*) FROM mysql.user WHERE authentication_string='' AND account_locked='N';") == '0',
              'Aucun compte actif sans mot de passe')

        area('Privilèges excessifs')
        rows = [r.split('\t') for r in q("SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES "
                                         "WHERE PRIVILEGE_TYPE IN ('" + "','".join(DANGEROUS) + "');").splitlines() if r]
        stray = [g for g, _ in rows if not (g.startswith("'root'") or g.startswith("'mysql.")
                                            or g.startswith("'sp_dba'"))]
        check(stray == [], 'Aucun privilège global dangereux hors root / comptes système / sp_dba')
        for table in ['TABLE_PRIVILEGES', 'COLUMN_PRIVILEGES', 'SCHEMA_PRIVILEGES']:
            n = q("SELECT COUNT(*) FROM information_schema." + table + " WHERE GRANTEE=\"'svc_php'@'localhost'\";")
            check(n == '0', 'svc_php sans privilège direct (' + table.split('_')[0].lower() + ') : accès par rôles seuls')

        area('Comptes applicatifs faibles')
        weak = q("SELECT COUNT(*) FROM mysql.user WHERE account_locked='N' AND plugin<>'caching_sha2_password';")
        check(weak == '0', 'Tous les comptes de connexion utilisent caching_sha2_password')
        check(q("SELECT LEFT(password_hash,10) FROM secure_payroll.accounts WHERE password_hash<>'' LIMIT 1;")
              == '$argon2id$', 'Mots de passe applicatifs hachés en Argon2id')

        area('Exposition réseau')
        net = q("SELECT @@GLOBAL.skip_networking, @@GLOBAL.port;").split('\t')
        check(net == ['1', '0'], 'Aucune écoute TCP (skip_networking=1, port=0) : socket privé seul')
        mx = q("SELECT COUNT(*) FROM performance_schema.global_variables WHERE VARIABLE_NAME='mysqlx_port' "
               "AND VARIABLE_VALUE NOT IN ('0','');")
        check(mx == '0', 'Protocole X désactivé (mysqlx off)')

        area('Fonctions inutiles / services')
        check(q("SELECT @@GLOBAL.local_infile;") == '0', 'local_infile désactivé (pas de LOAD DATA LOCAL)')
        check(q("SELECT IFNULL(@@GLOBAL.secure_file_priv,'NULL');") == 'NULL',
              'secure_file_priv=NULL : opérations serveur sur fichiers interdites (même root)')

        area('Journalisation')
        check(q("SELECT @@GLOBAL.log_error;").endswith('mysql.log'), 'Journal d\'erreur dirigé vers un fichier privé')
        check(q("SELECT @@GLOBAL.general_log;") == '0', 'general_log désactivé (pas de journal verbeux de requêtes)')
        check(q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='secure_payroll' "
                "AND TABLE_NAME='security_audit_log';") == '1', 'Piste d\'audit de sécurité présente (phase 7)')
        check(refused(instance, "UPDATE security_audit_log SET event='x' WHERE id=1;", '1644'),
              'Piste d\'audit en ajout seul (inviolable)')

        area('Fichiers de configuration')
        check(list(instance_dir.glob('*.cnf')) == [], 'Aucun fichier de configuration lu (--no-defaults)')
        check(q("SELECT COUNT(*) FROM performance_schema.persisted_variables;") == '0',
              'Aucune variable persistée (--persisted-globals-load=OFF)')

        area('Permissions de fichiers')
        check(mode(instance_dir) == 0o700, 'Répertoire d\'instance privé (0700)')
        secrets = ['credentials.json', 'operators.json', 'admin.json',
                   'runtime/keys/field.key', 'runtime/keys/blind.key']
        bad = [s for s in secrets if mode(instance_dir / s) & 0o077]
        check(bad == [], 'Secrets et clés en 0600 (ni groupe ni autres)')
        check(mode(instance_dir / 'data') & 0o007 == 0, 'Datadir non accessible aux « autres »')

        area('Niveau de correctifs')
        version = q("SELECT VERSION();")
        parts = tuple(int(n) for n in version.split('-')[0].split('.'))
        check(parts >= (8, 4, 0), 'MySQL à jour (' + version + ' ≥ 8.4.0)')

        area('Protection des sauvegardes')
        bprivs = {r.split('\t')[0] for r in
                  (q("SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=\"'sp_backup'@'%'\";")
                   + '\n' + q("SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES "
                              "WHERE GRANTEE=\"'sp_backup'@'%'\";")).splitlines() if r}
        check(bprivs and bprivs <= BACKUP_ALLOWED,
              'Rôle de sauvegarde en lecture seule (aucune écriture ni DDL) : ' + ', '.join(sorted(bprivs)))
        check((ROOT / 'bin/backup-crypt.py').is_file() and (ROOT / 'bin/backup.py').is_file(),
              'Outils de sauvegarde chiffrée et de restauration présents (phase 8)')
    finally:
        instance.stop()
    print('\n' + str(COUNT) + ' contrôles de durcissement réussis. Instance isolée arrêtée.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
