#!/usr/bin/env python3
"""Phase 8 — exercice réel de sauvegarde et restauration, dans une instance jetable isolée.

Démontre l'exigence de l'énoncé (« montrer qu'un fichier existe ne suffit pas ») sur le système
ACTUEL, phases 6 et 7 comprises :

  1. instance neuve isolée (/tmp), avec propriétaires verrouillés, données chiffrées (phase 6)
     et piste d'audit en ajout seul (phase 7) ;
  2. sauvegarde logique par le compte dédié en lecture seule backup_ops (rôle sp_backup) ;
  3. chiffrement du dump au repos (AES-256-GCM) ; la clé de sauvegarde ET les clés de
     chiffrement des données (phase 6) sont conservées SÉPARÉMENT de la sauvegarde ;
  4. perte de données contrôlée : suppression d'enregistrements (role_permissions, RBAC) ET
     suppression d'une table critique (employee_personal_data) ;
  5. restauration à partir de la sauvegarde CHIFFRÉE ;
  6. vérification d'intégrité : comptes de lignes, déchiffrement des données restaurées,
     piste d'audit intacte ET toujours en ajout seul, procédures/déclencheurs, lecture non-root.

Aucune base existante n'est touchée ; tout vit sous /tmp/securepayroll-recovery-*.
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('init_local', ROOT / 'bin/init-local.py')
init_local = importlib.util.module_from_spec(spec)
spec.loader.exec_module(init_local)

COUNT = 0


def check(ok, name):
    global COUNT
    status = 'PASS' if ok else 'FAIL'
    print(status + ' ' + name, flush=True)
    if not ok:
        raise SystemExit('Contrôle de reprise échoué : ' + name)
    COUNT += 1


def root_client(instance, user='root'):
    return ['mysql', '--no-defaults', '--no-login-paths', '--protocol=socket',
            '--socket=' + str(instance.socket), '--user=' + user,
            '--default-character-set=utf8mb4', '--binary-mode']


def encrypted_fingerprint(instance):
    """Empreinte des colonnes chiffrées (phase 6) pour prouver une restauration octet pour octet."""
    return instance.sql("SELECT COALESCE(SHA2(GROUP_CONCAT(HEX(id_document_number), HEX(cnss_number), "
                        "HEX(rib) ORDER BY employee_id SEPARATOR '|'), 256), 'VIDE') "
                        "FROM secure_payroll.employee_personal_data;")


def decrypt_cin(instance, directory, employee_id):
    """Déchiffre un document d'identité via le code applicatif et les clés runtime (sans la base)."""
    hex_blob = instance.sql("SELECT HEX(id_document_number) FROM secure_payroll.employee_personal_data "
                            "WHERE employee_id=" + str(int(employee_id)) + ";")
    helper = ("<?php require " + json.dumps(str(ROOT / 'app/bootstrap.php')) + ";"
              " echo App\\Security\\FieldCipher::fromRuntime()->decrypt(hex2bin(trim(stream_get_contents(STDIN))));")
    script = directory / 'decrypt.php'
    script.write_text(helper)
    env = {**os.environ, 'APP_RUNTIME_DIR': str(instance.directory / 'runtime')}
    done = subprocess.run(['php', str(script)], input=hex_blob, text=True, capture_output=True, env=env)
    script.unlink()
    if done.returncode != 0:
        raise RuntimeError('Déchiffrement applicatif indisponible : ' + done.stderr.strip())
    return done.stdout.strip()


def refused(instance, statement, sqlstate_fragment):
    env = {**init_local.clean_environment(), 'MYSQL_PWD': instance.password}
    done = subprocess.run(root_client(instance), input='USE secure_payroll; ' + statement,
                          text=True, capture_output=True, env=env)
    return done.returncode != 0 and sqlstate_fragment in done.stderr


def app_read_ok(instance, credentials):
    """Lecture non-root via un rôle applicatif : exige que les GRANT d'objet soient en place."""
    env = {**init_local.clean_environment(), 'MYSQL_PWD': credentials['svc_php']}
    done = subprocess.run(root_client(instance, 'svc_php'),
                          input="SET ROLE 'sp_session','sp_security_auditor'; CALL secure_payroll.audit_recent(1);",
                          text=True, capture_output=True, env=env)
    return done.returncode == 0


def reapply_privileges(instance):
    """Réapplique la couche de droits administrative : les GRANT d'objet (table/colonne/routine) ne
    figurent PAS dans un dump logique ; ils disparaissent avec les objets (DROP TABLE/PROCEDURE) et se
    restaurent depuis l'inventaire administratif. Les rôles/comptes existent déjà : on retire les
    CREATE ROLE/USER et on réapplique GRANT/REVOKE dans l'ordre d'installation."""
    files = [s for s in init_local.SOURCES if '/setup/' in s and not s.endswith('_owner.sql')]
    for rel in files:
        body = re.sub(r'CREATE\s+(?:ROLE|USER)\b[^;]*;', '', (ROOT / rel).read_text(), flags=re.IGNORECASE | re.DOTALL)
        instance.sql(body)
    return len(files)


def main():
    workspace = Path(tempfile.mkdtemp(prefix='securepayroll-recovery-', dir='/tmp'))
    instance_dir = workspace / 'mysql'
    # Répertoires PRIVÉS et SÉPARÉS : la sauvegarde d'un côté, les clés de l'autre.
    backups = workspace / 'backups'; backups.mkdir(mode=0o700)
    keyvault = workspace / 'keyvault'; keyvault.mkdir(mode=0o700)
    restore_work = workspace / 'restore'; restore_work.mkdir(mode=0o700)
    print('Espace de reprise isolé : ' + str(workspace), flush=True)

    created = subprocess.run(['python3', str(ROOT / 'bin/init-local.py'), 'init', '--directory', str(instance_dir)],
                             cwd=ROOT, env={**os.environ, 'PYTHONDONTWRITEBYTECODE': '1'},
                             capture_output=True, text=True)
    check(created.returncode == 0, 'Instance isolée installée (propriétaires, chiffrement, audit)')
    instance = init_local.LocalInstance(instance_dir)
    instance.start()
    try:
        # --- 1. Vérité terrain, avant toute perte ---
        tables = instance.sql("SELECT TABLE_NAME FROM information_schema.TABLES "
                              "WHERE TABLE_SCHEMA='secure_payroll' ORDER BY TABLE_NAME;").split()
        truth_counts = {t: instance.sql('SELECT COUNT(*) FROM secure_payroll.`' + t + '`;') for t in tables}
        truth_fingerprint = encrypted_fingerprint(instance)
        truth_cin = decrypt_cin(instance, workspace, 4)
        truth_audit = instance.sql('SELECT COUNT(*) FROM secure_payroll.security_audit_log;')
        routines = instance.sql("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='secure_payroll';")
        triggers = instance.sql("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='secure_payroll';")
        check(truth_cin != '' and truth_fingerprint != 'VIDE', 'Données chiffrées lisibles avant sauvegarde (CIN déchiffré)')
        check(refused(instance, "UPDATE security_audit_log SET event='x' WHERE id=1;", '1644'),
              'Piste d\'audit en ajout seul avant sauvegarde')

        # --- 2. Sauvegarde par le compte dédié en lecture seule ---
        operators = instance.read_private('operators.json')
        runtime = instance.directory / 'runtime'
        backup = subprocess.run(['python3', str(ROOT / 'bin/backup.py'), '--user', 'backup_ops',
                                 '--password-stdin', '--socket', str(instance.socket),
                                 '--directory', str(backups), '--logs-directory', str(runtime)],
                                input=operators['backup_ops'], text=True, capture_output=True,
                                env=init_local.clean_environment())
        check(backup.returncode == 0, 'Sauvegarde logique par backup_ops (rôle sp_backup, lecture seule)')
        exported = [p for p in backups.iterdir() if p.is_dir()]
        check(len(exported) == 1 and (exported[0] / 'EXPORT_COMPLETE').is_file(), 'Export complet et marqueur de succès')
        dump = exported[0] / 'database.sql'
        manifest = json.loads((exported[0] / 'manifest.json').read_text())
        check(hashlib.sha256(dump.read_bytes()).hexdigest() == manifest['files']['database.sql']['sha256'],
              'Empreinte SHA-256 du dump conforme au manifeste')

        # --- 3. Chiffrement au repos + clés conservées séparément ---
        backup_key = keyvault / 'backup.key'
        subprocess.run(['python3', str(ROOT / 'bin/backup-crypt.py'), 'keygen', '--key', str(backup_key)], check=True,
                       capture_output=True)
        encrypted = keyvault / 'database.sql.enc'
        subprocess.run(['python3', str(ROOT / 'bin/backup-crypt.py'), 'encrypt', '--in', str(dump),
                        '--key', str(backup_key), '--out', str(encrypted)], check=True, capture_output=True)
        header = encrypted.read_bytes()[:4]
        check(header == b'SPB1' and b'secure_payroll' not in encrypted.read_bytes(),
              'Dump chiffré au repos (AES-256-GCM) : plus aucun texte clair')
        # Sauvegarde SÉPARÉE des clés de chiffrement des données (phase 6) : sans elles, le dump restauré est illisible.
        for name in ['field.key', 'blind.key']:
            shutil.copy(runtime / 'keys' / name, keyvault / name)
            os.chmod(keyvault / name, 0o600)
        # La sauvegarde stockée ne garde que le chiffré : on retire le dump en clair.
        dump.unlink()
        wrong = subprocess.run(['python3', str(ROOT / 'bin/backup-crypt.py'), 'keygen', '--key', str(keyvault / 'wrong.key')],
                               capture_output=True)
        bad = subprocess.run(['python3', str(ROOT / 'bin/backup-crypt.py'), 'decrypt', '--in', str(encrypted),
                              '--key', str(keyvault / 'wrong.key'), '--out', str(restore_work / 'nope.sql')],
                             capture_output=True)
        check(wrong.returncode == 0 and bad.returncode != 0,
              'Sans la bonne clé de sauvegarde, la restauration est refusée (GCM)')

        # --- 4. Perte de données contrôlée ---
        instance.sql('DELETE FROM secure_payroll.role_permissions;')          # perte d'enregistrements (RBAC)
        instance.sql('DROP TABLE secure_payroll.employee_personal_data;')     # suppression d'une table critique
        check(instance.sql('SELECT COUNT(*) FROM secure_payroll.role_permissions;') == '0'
              and instance.sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='secure_payroll' "
                               "AND TABLE_NAME='employee_personal_data';") == '0',
              'Perte simulée : role_permissions vidée ET table employee_personal_data supprimée')

        # --- 5. Restauration depuis la sauvegarde chiffrée ---
        restored_sql = restore_work / 'database.sql'
        subprocess.run(['python3', str(ROOT / 'bin/backup-crypt.py'), 'decrypt', '--in', str(encrypted),
                        '--key', str(backup_key), '--out', str(restored_sql)], check=True, capture_output=True)
        env = {**init_local.clean_environment(), 'MYSQL_PWD': instance.password}
        imported = subprocess.run(root_client(instance), stdin=restored_sql.open('r'),
                                  stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True, env=env)
        check(imported.returncode == 0, 'Restauration réussie depuis la sauvegarde déchiffrée')

        # --- 6. Vérification d'intégrité ---
        restored_tables = instance.sql("SELECT TABLE_NAME FROM information_schema.TABLES "
                                       "WHERE TABLE_SCHEMA='secure_payroll' ORDER BY TABLE_NAME;").split()
        check(restored_tables == tables, 'Toutes les tables sont de retour')
        mismatches = [t for t in tables if instance.sql('SELECT COUNT(*) FROM secure_payroll.`' + t + '`;') != truth_counts[t]]
        check(mismatches == [], 'Comptes de lignes identiques à l\'avant-perte (' + str(len(tables)) + ' tables)')
        check(encrypted_fingerprint(instance) == truth_fingerprint,
              'Colonnes chiffrées restaurées octet pour octet')
        check(decrypt_cin(instance, workspace, 4) == truth_cin,
              'Données sensibles restaurées toujours déchiffrables (CIN identique)')
        check(instance.sql('SELECT COUNT(*) FROM secure_payroll.security_audit_log;') == truth_audit,
              'Piste d\'audit restaurée intégralement')
        check(refused(instance, "UPDATE security_audit_log SET event='x' WHERE id=1;", '1644')
              and refused(instance, "DELETE FROM security_audit_log WHERE id=1;", '1644'),
              'Piste d\'audit toujours en ajout seul après restauration (déclencheurs restaurés)')
        check(instance.sql("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='secure_payroll';") == routines
              and instance.sql("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='secure_payroll';") == triggers,
              'Procédures et déclencheurs restaurés (' + routines + ' / ' + triggers + ')')
        credentials = instance.read_private('credentials.json')
        # Leçon clé : un dump logique ne contient PAS les GRANT d'objet. Schéma seul = accès applicatif
        # encore refusé ; la reprise n'est complète qu'après réapplication de la couche de droits.
        check(not app_read_ok(instance, credentials),
              'Schéma restauré seul : lecture applicative encore refusée (GRANT absents du dump)')
        reapplied = reapply_privileges(instance)
        check(app_read_ok(instance, credentials),
              'Après réapplication de la couche de droits (' + str(reapplied)
              + ' fichiers) : lecture applicative non-root rétablie')

        result = {'workspace': str(workspace), 'checks': COUNT, 'tables': len(tables),
                  'audit_rows': int(truth_audit), 'primary_instance_touched': False}
        report = workspace / 'recovery-result.json'
        report.write_text(json.dumps(result, indent=2)); os.chmod(report, 0o600)
        print(json.dumps(result))
    finally:
        instance.stop()
    print(str(COUNT) + ' contrôles de sauvegarde/restauration réussis. Instance isolée arrêtée, artefacts conservés.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
