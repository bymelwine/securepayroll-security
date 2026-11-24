#!/usr/bin/env python3
"""Phase 10 — investigation d'un incident de sécurité simulé, sur une instance jetable isolée.

Scénario (énoncé) : « Plusieurs tentatives d'accès non autorisées à des données sensibles. Un compte
privilégié a aussi effectué des opérations inhabituelles. » L'équipe doit déterminer ce qui s'est passé
et produire une chronologie.

Le script joue l'incident avec les MÊMES mécanismes que le système réel (donc des preuves authentiques) :
  - tentatives d'accès → procédure applicative `audit_record` (rôle sp_session), comme l'application ;
  - opération privilégiée → le compte `dba_ops` (rôle sp_dba, ALL PRIVILEGES — risque T-14 accepté,
    « à détecter par la journalisation ») écrit EN DIRECT dans `role_assignments`, hors application :
    le déclencheur DEFINER l'enregistre (source='trigger', db_user='dba_ops', acteur applicatif NUL) ;
  - couverture de traces → `dba_ops` tente d'effacer la piste → refusé (ajout seul, 1644).

Puis il mène l'INVESTIGATION à partir des seules preuves, avec le bon rôle à moindre privilège :
  - journal d'audit applicatif via `audit_suspicious`/`audit_recent` (rôle sp_security_auditor) ;
  - records base via le rôle d'investigation sp_db_auditor (audit_ops), sans aucune donnée Restreinte.

Il établit les 7 points de l'énoncé et vérifie quels contrôles ont tenu ou cédé. Rejouable :
`python3 tests/incident-investigation.py`. Aucune base existante touchée (/tmp/securepayroll-incident-*).
"""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('init_local', ROOT / 'bin/init-local.py')
init_local = importlib.util.module_from_spec(spec)
spec.loader.exec_module(init_local)

COUNT = 0
ATTACKER_IP = '203.0.113.66'   # TEST-NET-3 (RFC 5737) : adresse de documentation, jamais réelle


def check(ok, name):
    global COUNT
    print(('PASS' if ok else 'FAIL') + ' ' + name, flush=True)
    if not ok:
        raise SystemExit('Contrôle d\'investigation échoué : ' + name)
    COUNT += 1


def run_as(instance, user, password, sql):
    """Exécute du SQL sous un compte/rôle donné ; renvoie (returncode, lignes[list[list]], stderr)."""
    env = {**init_local.clean_environment(), 'MYSQL_PWD': password}
    done = subprocess.run(['mysql', '--no-defaults', '--no-login-paths', '--protocol=socket',
                           '--socket=' + str(instance.socket), '--user=' + user,
                           '--skip-column-names', '--batch'],
                          input=sql, text=True, capture_output=True, env=env)
    rows = [line.split('\t') for line in done.stdout.splitlines() if line]
    return done.returncode, rows, done.stderr


def main():
    workspace = Path(tempfile.mkdtemp(prefix='securepayroll-incident-', dir='/tmp'))
    instance_dir = workspace / 'mysql'
    print('Instance d\'investigation isolée : ' + str(workspace), flush=True)
    created = subprocess.run(['python3', str(ROOT / 'bin/init-local.py'), 'init', '--directory', str(instance_dir)],
                             cwd=ROOT, env={**os.environ, 'PYTHONDONTWRITEBYTECODE': '1'}, capture_output=True, text=True)
    check(created.returncode == 0, 'Instance d\'investigation installée')
    instance = init_local.LocalInstance(instance_dir)
    instance.start()
    q = instance.sql
    try:
        creds = instance.read_private('credentials.json')
        ops = instance.read_private('operators.json')
        svc, dba, auditor = creds['svc_php'], ops['dba_ops'], ops['audit_ops']
        E = q("SELECT id FROM secure_payroll.accounts WHERE login='employee.demo';")
        H = q("SELECT id FROM secure_payroll.accounts WHERE login='hr.demo';")
        R = q("SELECT id FROM secure_payroll.roles WHERE code='application_administrator';")
        victims = q("SELECT GROUP_CONCAT(id) FROM (SELECT id FROM secure_payroll.employees "
                    "ORDER BY id LIMIT 3) t;").split(',')
        check(E and H and R, 'Comptes et rôle de référence localisés (employee.demo, hr.demo, rôle admin)')

        # ================= MISE EN SCÈNE DE L'INCIDENT (preuves authentiques) =================
        print('\n>>> Déroulement de l\'incident...', flush=True)

        # T0 — rafale d'échecs de connexion sur hr.demo depuis une IP externe (force brute).
        brute = "SET ROLE 'sp_session';\n" + '\n'.join(
            "CALL secure_payroll.audit_record('auth.failed','failure',NULL,'tentative non authentifiée',"
            "'accounts'," + H + ",JSON_OBJECT('ip','" + ATTACKER_IP + "','reason','bad_password','known_account',true));"
            for _ in range(6))
        rc, _, err = run_as(instance, 'svc_php', svc, brute)
        check(rc == 0, 'T0 — rafale de 6 échecs de connexion enregistrée (auth.failed)')
        time.sleep(1.1)

        # T1 — pied dans la place en tant que salarié : tentatives IDOR + accès supervision (refusés).
        denied = ["SET ROLE 'sp_session';"]
        for vid in victims:
            denied.append("CALL secure_payroll.audit_record('employees.access_denied','denied'," + E + ",NULL,"
                          "'employees'," + vid + ",JSON_OBJECT('ip','" + ATTACKER_IP + "','requested'," + vid + "));")
        denied.append("CALL secure_payroll.audit_record('audit.access_denied','denied'," + E + ",NULL,"
                      "'security_audit_log',NULL,JSON_OBJECT('ip','" + ATTACKER_IP + "'));")
        rc, _, err = run_as(instance, 'svc_php', svc, '\n'.join(denied))
        check(rc == 0, 'T1 — tentatives IDOR et accès supervision refusés (access_denied)')
        time.sleep(1.1)

        # T2 — COMPTE PRIVILÉGIÉ : dba_ops écrit EN DIRECT dans role_assignments (escalade), hors app.
        escalate = ("SET ROLE 'sp_dba';\nSET time_zone='+00:00';\nINSERT INTO secure_payroll.role_assignments "
                    "(account_id, role_id, grant_reason) VALUES (" + E + "," + R
                    + ",'octroi direct hors application');")
        rc, _, err = run_as(instance, 'dba_ops', dba, escalate)
        check(rc == 0, 'T2 — opération privilégiée directe réussie (dba_ops octroie un rôle admin)')
        time.sleep(1.1)

        # T3 — couverture de traces : dba_ops tente d'effacer la piste → doit être REFUSÉ (ajout seul).
        rc_del, _, err_del = run_as(instance, 'dba_ops', dba,
                                    "SET ROLE 'sp_dba';\nDELETE FROM secure_payroll.security_audit_log "
                                    "WHERE event='role.granted';")
        rc_upd, _, err_upd = run_as(instance, 'dba_ops', dba,
                                    "SET ROLE 'sp_dba';\nUPDATE secure_payroll.security_audit_log "
                                    "SET outcome='success' WHERE event='auth.failed';")
        check(rc_del != 0 and '1644' in err_del and rc_upd != 0 and '1644' in err_upd,
              'T3 — tentatives d\'effacement de la piste REFUSÉES (ajout seul, 1644)')

        # ================= INVESTIGATION (à partir des seules preuves) =================
        print('\n>>> Investigation...', flush=True)

        # 1) Détection — journal d'audit applicatif via le rôle auditeur (sp_security_auditor).
        rc, signals, err = run_as(instance, 'svc_php', svc,
                                  "SET ROLE 'sp_session','sp_security_auditor';\nCALL secure_payroll.audit_suspicious();")
        kinds = {r[0] for r in signals}
        check(rc == 0 and 'echecs_connexion' in kinds and 'acces_refuses' in kinds,
              'Détection : signaux « rafale d\'échecs » et « refus répétés » levés (audit_suspicious)')

        # 2) Chronologie — audit_recent (même rôle). Colonnes : occurred_at,event,outcome,source,
        #    actor_id,actor,actor_name,db_user,target_table,target_id,context,target_login,target_name,ip
        rc, recent, err = run_as(instance, 'svc_php', svc,
                                 "SET ROLE 'sp_session','sp_security_auditor';\nCALL secure_payroll.audit_recent(100);")
        check(rc == 0 and recent, 'Chronologie reconstituée depuis la piste (audit_recent)')
        priv = [r for r in recent if len(r) >= 14 and r[1] == 'role.granted' and r[3] == 'trigger'
                and 'dba_ops' in r[7]]
        incident = [r for r in recent if len(r) >= 14 and r[13] == ATTACKER_IP] + priv
        failed = [r for r in incident if r[1] == 'auth.failed']
        denied_rows = [r for r in incident if r[2] == 'denied']
        check(len(failed) >= 5 and all(r[13] == ATTACKER_IP for r in failed),
              'Preuve : ≥5 auth.failed depuis la même IP externe ' + ATTACKER_IP)
        check(len(denied_rows) >= 3, 'Preuve : ≥3 refus d\'accès attribués au compte salarié')
        check(len(priv) == 1 and priv[0][4] in ('NULL', ''),
              'Preuve : opération privilégiée tracée (source=trigger, db_user=dba_ops, acteur applicatif NUL)')

        # 3) Corroboration dans les records base, via le rôle d'investigation à moindre privilège.
        rc, grant_row, err = run_as(instance, 'audit_ops', auditor,
                                    "SET ROLE 'sp_db_auditor';\nSELECT account_id, role_id, grant_reason "
                                    "FROM secure_payroll.role_assignments WHERE account_id=" + E + " AND role_id=" + R + ";")
        check(rc == 0 and grant_row, 'Corroboration : l\'octroi de rôle non autorisé existe bien dans role_assignments')

        # 4) Moindre privilège de l'investigateur : aucun accès à une donnée Restreinte (chiffrée).
        rc_r, _, err_r = run_as(instance, 'audit_ops', auditor,
                                "SET ROLE 'sp_db_auditor';\nSELECT * FROM secure_payroll.employee_personal_data LIMIT 1;")
        check(rc_r != 0 and '1142' in err_r,
              'Investigateur à moindre privilège : aucun accès aux données Restreintes (1142)')

        # 5) Bilan des contrôles : ce qui a tenu / ce qui a cédé.
        check(len(denied_rows) >= 3, 'Contrôle TENU : autorisation par objet — les accès non autorisés ont été refusés')
        check('1644' in err_del, 'Contrôle TENU : piste d\'audit inviolable — effacement impossible même pour dba_ops')
        check(grant_row and len(priv) == 1,
              'Contrôle par DÉTECTION : l\'écriture directe du DBA (risque T-14 accepté) n\'est pas empêchée mais tracée')

        # ================= CHRONOLOGIE LISIBLE =================
        print('\n=== Chronologie de l\'incident (reconstituée) ===', flush=True)
        nz = lambda v: '' if v in ('NULL', None, '') else v
        for r in sorted(incident, key=lambda r: r[0]):
            who = (r[7] + ' (hors app)') if r[3] == 'trigger' else ('compte#' + r[4] if nz(r[4]) else 'non authentifié')
            tgt = nz(r[12]) or nz(r[11]) or (r[8] + '#' + nz(r[9])) or r[8]
            print('  ' + r[0] + '  ' + r[1] + ' [' + r[2] + ']  par ' + who + '  → ' + tgt, flush=True)

        result = {'workspace': str(workspace), 'checks': COUNT, 'attacker_ip': ATTACKER_IP,
                  'auth_failed': len(failed), 'access_denied': len(denied_rows),
                  'privileged_operation': 'dba_ops → role_assignments (application_administrator)'}
        report = workspace / 'incident-result.json'
        report.write_text(json.dumps(result, indent=2)); os.chmod(report, 0o600)
        print('\n' + json.dumps(result))
    finally:
        instance.stop()
    print('\n' + str(COUNT) + ' contrôles d\'investigation réussis. Instance isolée arrêtée.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
