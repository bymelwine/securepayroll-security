#!/usr/bin/env python3
"""Recette réelle de l'installateur : nouvelle instance privée, jamais la principale."""
import http.cookiejar
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, newurl):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.token = ''
        self.http = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def request(self, path, fields=None):
        request = urllib.request.Request(self.base + path, data=None if fields is None
                                        else urllib.parse.urlencode(fields).encode())
        try:
            response = self.http.open(request, timeout=60)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read().decode()
            token = re.search(r'name="csrf" value="([a-f0-9]{64})"', body)
            if token:
                self.token = token[1]
            return response.status, response.headers, body

    def post(self, path, **fields):
        return self.request(path, dict(csrf=self.token, **fields))


def onboarding(local, base, hr, employee, instance_path, check, refused):
    """Embauche par la RH, invitation dans la boîte d'envoi, activation obligatoire par changement de mot de passe."""
    sql = lambda statement: local.sql('USE secure_payroll; ' + statement)
    outbox = instance_path / 'runtime' / 'outbox'
    journal_path = instance_path / 'runtime' / 'application.log'
    check(employee.request('/employees/new')[0] == 403, 'Embauche refusée au rôle Employé')
    hr.request('/employees/new')
    form = dict(first_name='Élodie', last_name='Benali-Tazi')  # plus de matricule saisi : la base le génère
    before = sql('SELECT COUNT(*) FROM employees;')
    rejected = [hr.post('/employees/new', **form, personal_email=email)[0]
                for email in ['', 'pas-une-adresse', 'elodie@securepayroll.ma', 'x@example.org\r\nBcc: y@example.org']]
    check(rejected == [422] * 4 and sql('SELECT COUNT(*) FROM employees;') == before,
          'Adresse personnelle absente, invalide ou professionnelle : 422, rien créé')
    status, headers, _ = hr.post('/employees/new', **form, personal_email='elodie.perso@example.org')
    check(status == 303, 'Embauche par la RH : salarié créé')
    _, _, page = hr.request(headers['Location'])
    login = 'elodie.benali-tazi@securepayroll.ma'
    number = sql("SELECT e.employee_number FROM accounts a JOIN employees e ON e.id=a.employee_id WHERE a.login='" + login + "';")
    check(re.fullmatch(r'EMP-2026-\d{3}', number) is not None and number == 'EMP-2026-002',
          'Matricule généré par la base, à la suite de EMP-2026-001 : ' + number)
    check(login in page and number in page and 'elodie.perso@example.org' not in page,
          'Identifiant professionnel et matricule annoncés à la RH ; adresse personnelle non réaffichée')
    row = sql("SELECT a.password_change_required, ROUND(TIMESTAMPDIFF(SECOND, a.created_at, a.temporary_password_expires_at) / 3600), "
              "a.password_changed_at IS NULL, r.code, (SELECT login FROM accounts WHERE id=ra.granted_by_account_id), "
              "i.personal_email, i.issued_by_account_id=ra.granted_by_account_id "
              "FROM accounts a JOIN employees e ON e.id=a.employee_id JOIN role_assignments ra ON ra.account_id=a.id "
              "JOIN roles r ON r.id=ra.role_id JOIN account_invitations i ON i.account_id=a.id "
              "WHERE a.login='" + login + "';")
    check(row == '1\t72\t1\temployee\thr.demo\telodie.perso@example.org\t1',
          'Compte en attente, mot de passe temporaire 72 h, rôle Employé tracé (auteur RH), invitation enregistrée')
    messages = sorted(outbox.glob('*.eml'))
    check(len(messages) == 1 and (outbox.stat().st_mode & 0o777) == 0o700
          and (messages[0].stat().st_mode & 0o777) == 0o600, 'Invitation dans la boîte d’envoi privée (0700/0600)')
    mail = messages[0].read_text()
    temporary = re.search(r'Mot de passe temporaire : (\S+)', mail)[1]
    check('To: elodie.perso@example.org' in mail and login in mail and len(temporary) == 19,
          'Invitation à l’adresse personnelle : identifiant et mot de passe temporaire')
    check(temporary not in page and temporary not in journal_path.read_text(),
          'Mot de passe temporaire jamais montré à la RH ni journalisé')
    service = local.read_private('credentials.json')['svc_php']
    check(refused("SET ROLE 'sp_session','sp_hr_manager'; SELECT personal_email FROM account_invitations;",
                  '1142', 'svc_php', service)
          and refused("SET ROLE 'sp_session','sp_hr_manager'; INSERT INTO employees(employee_number,first_name,last_name) "
                      "VALUES ('X','X','X');", '1142', 'svc_php', service),
          'RH en base : adresse personnelle illisible, INSERT direct sur employees retiré')
    check(refused("SET ROLE 'sp_session','sp_hr_manager'; CALL account_onboard((SELECT id FROM accounts WHERE login="
                  "'employee.demo'),'A','B','a.b','a@example.org','x');", '1644', 'svc_php', service),
          'Procédure : embauche refusée à un acteur sans rôle RH (PAY_FORBIDDEN)')
    hr.request('/employees/new')
    check(hr.post('/employees/new', first_name='Elodie', last_name='Benali Tazi',
                  personal_email='homonyme@example.org')[0] == 303
          and sql("SELECT e.employee_number, a.login FROM accounts a JOIN employees e ON e.id=a.employee_id "
                  "WHERE a.login='elodie.benali-tazi2@securepayroll.ma';") == 'EMP-2026-003\telodie.benali-tazi2@securepayroll.ma',
          'Homonyme : identifiant distinct elodie.benali-tazi2@… et matricule suivant EMP-2026-003')
    _, _, cform = hr.request('/contracts/new')
    check('<select id="employee_number"' in cform and ('· ' + number) in cform and 'Benali-Tazi' in cform,
          'Contrat : le salarié se choisit dans un menu déroulant des actifs (plus de matricule à taper)')

    newcomer = Client(base)
    newcomer.request('/login')
    check(newcomer.post('/login', login=login, password=temporary)[0] == 303, 'Première connexion avec le mot de passe temporaire')
    status, headers, _ = newcomer.request('/dashboard')
    check(status == 303 and headers['Location'] == '/password', 'Compte non activé : tableau de bord redirigé vers /password')
    check(all(newcomer.request(path)[0] == 303 for path in ['/my-access', '/payroll', '/employees/1']),
          'Compte non activé : aucune autre page accessible')
    status, _, body = newcomer.request('/password')
    check(status == 200 and 'href="/my-access"' not in body and 'Activez votre compte' in body,
          'Page d’activation seule, sans menu métier')
    second = Client(base)
    second.request('/login')
    second.post('/login', login=login, password=temporary)
    attempts = [newcomer.post('/password', current_password='faux-' + temporary, new_password='Cheval-Orange-Lampadaire-7',
                              confirm_password='Cheval-Orange-Lampadaire-7')[0],
                newcomer.post('/password', current_password=temporary, new_password='Elodie-et-ses-collines',
                              confirm_password='Elodie-et-ses-collines')[0],
                newcomer.post('/password', current_password=temporary, new_password='court', confirm_password='court')[0],
                newcomer.post('/password', current_password=temporary, new_password='Cheval-Orange-Lampadaire-7',
                              confirm_password='Cheval-Orange-Lampadaire-8')[0]]
    check(attempts == [401, 422, 422, 422] and sql("SELECT password_change_required FROM accounts WHERE login='" + login + "';") == '1',
          'Mot de passe actuel faux (401), nom inclus, trop court, confirmation différente (422) : rien changé')
    chosen = 'Cheval-Orange-Lampadaire-7'
    status, headers, _ = newcomer.post('/password', current_password=temporary, new_password=chosen, confirm_password=chosen)
    check(status == 303 and newcomer.request('/dashboard')[0] == 200 and newcomer.request('/my-access')[0] == 200,
          'Mot de passe changé : compte actif, pages ouvertes')
    check(sql("SELECT password_change_required, temporary_password_expires_at IS NULL, password_changed_at IS NOT NULL "
              "FROM accounts WHERE login='" + login + "';") == '0\t1\t1', 'Base : activation enregistrée et horodatée')
    check(second.request('/dashboard')[0] == 303 and second.request('/dashboard')[1]['Location'] == '/login',
          'Session ouverte avant le changement : fermée')
    fresh = Client(base)
    fresh.request('/login')
    check(fresh.post('/login', login=login, password=temporary)[0] == 401, 'Ancien mot de passe temporaire refusé')
    fresh.request('/login')
    check(fresh.post('/login', login=login, password=chosen)[0] == 303, 'Connexion avec le nouveau mot de passe')

    # Expiration : le mot de passe temporaire du homonyme est périmé.
    homonym = 'elodie.benali-tazi2@securepayroll.ma'
    mail = [m.read_text() for m in sorted(outbox.glob('*.eml')) if homonym in m.read_text()][0]
    expired_password = re.search(r'Mot de passe temporaire : (\S+)', mail)[1]
    sql("UPDATE accounts SET temporary_password_expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE login='" + homonym + "';")
    late = Client(base)
    late.request('/login')
    status, _, body = late.post('/login', login=homonym, password=expired_password)
    check(status == 401 and 'a expiré' in body and late.request('/dashboard')[0] == 303,
          'Mot de passe temporaire expiré : connexion refusée, message dédié')
    current_hash = sql("SELECT password_hash FROM accounts WHERE login='" + homonym + "';")
    homonym_id = sql("SELECT id FROM accounts WHERE login='" + homonym + "';")
    # Empreinte de remplacement bien formée, lue en base (aucune empreinte écrite dans le source) ; les deux appels échouent.
    replacement = sql("SELECT password_hash FROM accounts WHERE login='hr.demo';")
    check(refused("SET ROLE 'sp_session','sp_password'; CALL account_change_password(" + homonym_id + ",'" + current_hash
                  + "','" + replacement + "');",
                  '1644', 'svc_php', service), 'Procédure : mot de passe temporaire expiré non remplaçable')
    nadia = sql("SELECT id FROM accounts WHERE login='employee.demo';")
    check(refused("SET ROLE 'sp_session','sp_password'; CALL account_change_password(" + nadia + ",'devine','"
                  + replacement + "');",
                  '1644', 'svc_php', service), 'Procédure : sans l’empreinte actuelle, aucun changement possible')
    journal = journal_path.read_text()
    check('"account.onboarded"' in journal and '"auth.password_changed"' in journal and '"first_activation":true' in journal
          and '"reason":"temporary_expired"' in journal and chosen not in journal and expired_password not in journal
          and 'elodie.perso@example.org' not in journal,
          'Journal : embauche, activation et expiration tracées, sans secret ni adresse personnelle')


def main():
    private = Path(tempfile.mkdtemp(prefix='securepayroll-install-', dir='/tmp'))
    instance_path = private / 'mysql'
    env = os.environ.copy()
    env['PYTHONDONTWRITEBYTECODE'] = '1'
    count = 0

    def check(ok, label):
        nonlocal count
        if not ok:
            raise RuntimeError(label)
        count += 1
        print('PASS ' + label, flush=True)

    spec = importlib.util.spec_from_file_location('local_initializer', ROOT / 'bin/init-local.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    print('Artefacts privés conservés : ' + str(private), flush=True)
    result = subprocess.run(['python3', str(ROOT / 'bin/init-local.py'), 'init',
                             '--directory', str(instance_path)], cwd=ROOT, env=env)
    check(result.returncode == 0, 'Initialisation réelle depuis les sources SQL existantes')
    local = module.LocalInstance(instance_path)
    process = None
    try:
        check(not local.socket.exists(), 'Initialisation termine avec MySQL arrêté')
        local.start()
        local.assert_isolated()
        check(True, 'Datadir exact, MySQL 8.4, réseau désactivé et port zéro')
        credentials = local.read_private('credentials.json')
        check(len(credentials) == len(set(credentials.values())) == 7, 'Sept mots de passe neufs distincts')
        for name in ['credentials.json', 'admin.json', 'application.json', 'operators.json']:
            check((instance_path / name).stat().st_mode & 0o777 == 0o600, 'Fichier privé 0600 : ' + name)
        check(local.sql("USE secure_payroll; SELECT COUNT(*),COUNT(DISTINCT password_hash) FROM accounts;") == '6\t6',
              'Six comptes web et six empreintes distinctes')
        check(local.sql("USE secure_payroll; SELECT COUNT(*) FROM permissions;") == '34', '34 permissions installées (phase 7 : +1 audit.read)')
        check(local.sql("SELECT COUNT(*) FROM mysql.user WHERE User IN ('hr_module_owner','compensation_owner','payroll_owner','settlement_owner','iam_owner','personal_data_owner','audit_owner') AND account_locked='Y';") == '7',
              'Sept propriétaires de procédures verrouillés (phase 7 : audit_owner)')
        sql = lambda statement: local.sql('USE secure_payroll; ' + statement)
        check(sql('SELECT monthly_amount,currency FROM salary_conditions;') == '12000.00\tMAD', 'Salaire fictif approuvé MAD')
        check(sql('SELECT COUNT(*) FROM payroll_calculations;') == '0', 'Fixture laisse le calcul au collaborateur')
        grants = local.sql('SHOW GRANTS;', 'svc_php', credentials['svc_php'])
        check('GRANT OPTION' not in grants and 'ON *.*' not in grants.replace('GRANT USAGE ON *.*', ''),
              'Service sans privilège global ni GRANT OPTION')

        def refused(statement, code, user='root', password=None):
            try:
                local.sql('USE secure_payroll; ' + statement, user, password)
            except RuntimeError as error:
                return '(code ' + code + ')' in str(error)
            return False

        check(sql("SELECT COUNT(*),COUNT(DISTINCT rib_index),SUM(created_by=(SELECT id FROM accounts WHERE login='hr.demo')) "
                  'FROM employee_personal_data;') == '6\t6\t6',
              'Données personnelles fictives des six salariés, auteur RH')
        check(sql("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='secure_payroll' "
                  "AND TABLE_NAME='employee_personal_data' AND COLUMN_NAME IN ('rib','id_document_number','cnss_number') "
                  "ORDER BY COLUMN_NAME;").split('\n') == ['varbinary'] * 3,
              'RIB, pièce d\'identité et CNSS chiffrés au repos (colonnes VARBINARY)')
        check('employee_personal_data' not in grants
              and refused('SELECT rib FROM employee_personal_data;', '1142', 'svc_php', credentials['svc_php']),
              'Service PHP sans accès aux données personnelles')
        nadia = "employee_id=(SELECT id FROM employees WHERE employee_number='EMP-2026-001')"
        check(refused("SET ROLE 'sp_session','sp_employee'; UPDATE employee_personal_data SET rib=0x00 WHERE " + nadia + ';',
                      '1142', 'svc_php', credentials['svc_php']),
              'Écriture directe du RIB refusée au rôle applicatif (le salarié passe par la procédure chiffrée)')
        check(refused("UPDATE employee_personal_data SET city='Rabat' WHERE " + nadia + ';', '1644'),
              'Modification sans auteur refusée')
        check(refused('DELETE FROM employee_personal_data WHERE ' + nadia + ';', '1644'),
              'Suppression des données personnelles refusée')
        sql("UPDATE employee_personal_data SET city='Mohammedia',postal_code='28810',updated_by=created_by WHERE "
            + nadia + ';')
        check(sql('SELECT city,updated_at IS NOT NULL FROM employee_personal_data WHERE ' + nadia + ';')
              == 'Mohammedia\t1', 'Modification tracée avec auteur et horodatage SQL')

        # Phase 7 : piste d'audit en base, en ajout seul, alimentée par les déclencheurs des tables sensibles.
        audit_service = credentials['svc_php']
        audit_svc = lambda statement: local.sql('USE secure_payroll; ' + statement, 'svc_php', audit_service)
        check(sql("SELECT COUNT(DISTINCT event) FROM security_audit_log WHERE source='trigger' "
                  "AND event IN ('role.granted','salary.created','personal_data.created');") == '3',
              'Déclencheurs d\'audit : rôles, salaires et données personnelles tracés dès la semence')
        check(refused("UPDATE security_audit_log SET event='x' WHERE id=1;", '1644')
              and refused("DELETE FROM security_audit_log WHERE id=1;", '1644'),
              'Piste d\'audit en ajout seul : UPDATE et DELETE refusés, même en root')
        before_audit = int(sql('SELECT COUNT(*) FROM security_audit_log;'))
        audit_svc("SET ROLE 'sp_session'; CALL audit_record('test.probe','success',NULL,NULL,NULL,NULL,NULL);")
        check(int(sql('SELECT COUNT(*) FROM security_audit_log;')) == before_audit + 1,
              'Session applicative : écriture d\'un événement via la procédure audit_record')
        check(refused("SET ROLE 'sp_session'; SELECT COUNT(*) FROM security_audit_log;", '1142', 'svc_php', audit_service),
              'Session applicative : aucune lecture directe de la piste d\'audit')
        check(refused("SET ROLE 'sp_session','sp_security_auditor'; SELECT COUNT(*) FROM security_audit_log;",
                      '1142', 'svc_php', audit_service),
              'Auditeur : jamais de lecture directe de la table, seulement par procédure')
        check(audit_svc("SET ROLE 'sp_session','sp_security_auditor'; CALL audit_recent(3);") is not None,
              'Auditeur : supervision via la procédure audit_recent')

        # Phase 2 : rôles MySQL, moindre privilège et comptes d'exploitation.
        service = credentials['svc_php']
        svc = lambda statement: local.sql('USE secure_payroll; ' + statement, 'svc_php', service)
        # SHOW GRANTS dans la session montre les droits effectifs (rôle actif compris) ;
        # SHOW GRANTS FOR, exécuté par root, montre les droits déclarés du compte.
        lines = local.sql("SHOW GRANTS FOR 'svc_php'@'localhost';").splitlines()
        app_roles = ['sp_session', 'sp_login', 'sp_password', 'sp_employee', 'sp_hr_manager', 'sp_finance_manager',
                     'sp_payroll_manager', 'sp_application_administrator', 'sp_security_auditor']
        check(len(lines) == 2 and lines[0].startswith('GRANT USAGE ON *.*')
              and all('`' + role + '`@`%`' in lines[1] for role in app_roles),
              'SHOW GRANTS svc_php : aucun privilège direct, neuf rôles accordés')
        check(local.sql('SELECT @@activate_all_roles_on_login, @@mandatory_roles = \'\';') == '0\t1',
              'Aucun rôle activé d\'office (activate_all_roles_on_login=OFF, mandatory_roles vide)')
        check(svc('SELECT CURRENT_ROLE();') == '`sp_session`@`%`', 'Rôle actif par défaut : sp_session seul')
        check(refused('SELECT password_hash FROM accounts;', '1143', 'svc_php', service),
              'Session ordinaire : empreintes de mots de passe illisibles')
        check(refused('SELECT monthly_amount FROM salary_conditions;', '1142', 'svc_php', service),
              'Session ordinaire : salaires illisibles')
        check(svc("SET ROLE 'sp_login'; SELECT COUNT(password_hash) FROM accounts;") == '6',
              'Rôle sp_login : seule lecture des empreintes, pour la connexion')
        check(refused("SET ROLE 'sp_session','sp_employee'; SELECT monthly_amount FROM salary_conditions;",
                      '1142', 'svc_php', service), 'Rôle Salarié : salaires illisibles en base (écart E1 corrigé)')
        check(refused("SET ROLE 'sp_session','sp_employee'; CALL compensation_decide(1,'approved','x',1);",
                      '1370', 'svc_php', service), 'Rôle Salarié : procédure de décision non exécutable')
        check(refused("SET ROLE 'sp_session','sp_security_auditor'; SELECT COUNT(*) FROM salary_conditions;",
                      '1142', 'svc_php', service), 'Rôle Auditeur : lecture directe des salaires refusée')
        check(svc("SET ROLE 'sp_session','sp_hr_manager'; SELECT COUNT(*) FROM salary_conditions;") == '1',
              'Rôle RH : lecture des conditions salariales autorisée')
        check(refused("SET ROLE 'sp_dba';", '3530', 'svc_php', service), 'Rôle non accordé impossible à activer')
        employee_grants = local.sql("SHOW GRANTS FOR 'svc_php'@'localhost' USING 'sp_session','sp_employee';")
        check('payslip_detail' in employee_grants and 'salary_conditions' not in employee_grants
              and 'password_hash' not in employee_grants, 'SHOW GRANTS ... USING : privilèges effectifs du Salarié')

        operators = local.read_private('operators.json')
        check(sorted(operators) == sorted(module.OPERATORS) and len(set(operators.values())) == 3
              and service not in operators.values(), 'Trois comptes d\'exploitation, mots de passe distincts')
        check(local.sql("SELECT COUNT(*) FROM mysql.user WHERE User IN ('dba_ops','audit_ops') AND password_lifetime=90 "
                        "AND JSON_EXTRACT(User_attributes,'$.Password_locking.failed_login_attempts')=5;") == '2',
              'Comptes humains : expiration à 90 jours, verrouillage après 5 échecs')
        check(local.sql("SELECT COUNT(*) FROM mysql.user WHERE account_locked='N' AND User NOT LIKE 'mysql.%' "
                        "AND plugin<>'caching_sha2_password';") == '0'
              and local.sql("SELECT COUNT(*) FROM mysql.user WHERE User LIKE '%\\_owner' AND account_locked='Y';") == '7',
              'Comptes MySQL : caching_sha2_password ; 7 propriétaires verrouillés')
        operator = lambda login, statement: local.sql('USE secure_payroll; ' + statement, login, operators[login])
        check(operator('audit_ops', 'SELECT COUNT(*)>0 FROM role_assignments;') == '1',
              'Auditeur SQL : historique des attributions lisible')
        check(refused('SELECT amount FROM settlement_ledger;', '1143', 'audit_ops', operators['audit_ops'])
              and refused('SELECT rib FROM employee_personal_data;', '1142', 'audit_ops', operators['audit_ops'])
              and refused('SELECT password_hash FROM accounts;', '1143', 'audit_ops', operators['audit_ops']),
              'Auditeur SQL : ni montant, ni RIB, ni empreinte')
        check(refused("INSERT INTO departments(code,name_fr,name_en) VALUES ('X','X','X');", '1142',
                      'backup_ops', operators['backup_ops']), 'Compte de sauvegarde en lecture seule')
        check(operator('dba_ops', 'CREATE TABLE dba_probe(id INT PRIMARY KEY); DROP TABLE dba_probe; SELECT 1;') == '1',
              'DBA : maintenance du schéma autorisée')
        check(refused("CREATE USER 'intrus'@'localhost';", '1227', 'dba_ops', operators['dba_ops'])
              and refused("GRANT SELECT ON secure_payroll.employees TO 'audit_ops'@'localhost';", '1142', 'dba_ops',
                          operators['dba_ops']), 'DBA : ni création de compte, ni distribution de droits')
        local.sql("REVOKE 'sp_db_auditor' FROM 'audit_ops'@'localhost';")
        check(refused('SELECT COUNT(*) FROM payslip_events;', '1044', 'audit_ops', operators['audit_ops']),
              'REVOKE du rôle : schéma inaccessible dès la session suivante')
        local.sql("GRANT 'sp_db_auditor' TO 'audit_ops'@'localhost'; "
                  "SET DEFAULT ROLE 'sp_db_auditor' TO 'audit_ops'@'localhost';")
        check(operator('audit_ops', 'SELECT COUNT(*)>=0 FROM payslip_events;') == '1', 'Rôle réattribué et actif')
        backups, logs = private / 'backups', private / 'logs'
        backups.mkdir(mode=0o700)
        logs.mkdir(mode=0o700)
        dump = subprocess.run(['python3', str(ROOT / 'bin/backup.py'), '--user', 'backup_ops', '--password-stdin',
                               '--socket', str(local.socket), '--logs-directory', str(logs),
                               '--directory', str(backups)], input=operators['backup_ops'], text=True,
                              capture_output=True, env=env, cwd=ROOT)
        exports = list(backups.glob('securepayroll-*/EXPORT_COMPLETE'))
        exported = exports[0].parent / 'database.sql' if len(exports) == 1 else None
        check(dump.returncode == 0 and exported is not None
              and 'CREATE TABLE `employee_personal_data`' in exported.read_text()
              and 'PROCEDURE `payslip_detail`' in exported.read_text(),
              'Sauvegarde complète (tables, procédures) avec le seul rôle sp_backup')
        repeated = subprocess.run(['python3', str(ROOT / 'bin/init-local.py'), 'init',
                                   '--directory', str(instance_path)], capture_output=True, text=True, env=env)
        check(repeated.returncode != 0 and sql('SELECT COUNT(*) FROM accounts;') == '6',
              'Deuxième initialisation refusée sans rejeu des migrations')
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        with (private / 'http.log').open('xb') as log:
            process = subprocess.Popen(['php', '-S', '127.0.0.1:' + str(port), '-t', 'public', 'public/index.php'],
                                       # Drapeau de laboratoire volontairement présent : le verrou doit le refuser,
                                       # car cette instance n'est pas sous /tmp/securepayroll-lab-* (phase 4).
                                       cwd=ROOT, env={**local.application_environment(),
                                                      'SECUREPAYROLL_LAB_VULNERABLE': 'access-history'},
                                       stdout=log, stderr=log)
            base = 'http://127.0.0.1:' + str(port)
            deadline = time.monotonic() + 20
            while True:
                try:
                    urllib.request.urlopen(base + '/login', timeout=1).close()
                    break
                except urllib.error.URLError:
                    if time.monotonic() > deadline:
                        raise RuntimeError('Démarrage HTTP privé')
                    time.sleep(.1)
            clients = {}
            for login in module.ROLES:
                client = Client(base)
                check(client.request('/login')[0] == 200, 'Page connexion : ' + login)
                check(client.post('/login', login=login, password=credentials[login])[0] == 303,
                      'Authentification Argon2id réelle : ' + login)
                check(client.request('/dashboard')[0] == 200, 'Tableau de bord : ' + login)
                clients[login] = client
            hr, finance, payroll, employee, admin, auditor = [clients[x] for x in module.ROLES]
            # Phase 3 : échecs de connexion bloqués par compte, sans bloquer les autres (T-18), et tracés (T-15).
            attacker = Client(base)
            attacker.request('/login')
            wrong = 'Mauvais-Essai-' + secrets.token_hex(8)
            failures = [attacker.post('/login', login='employee.demo', password=wrong)[0] for _ in range(5)]
            check(failures == [401] * 5, 'Cinq échecs de connexion : réponse générique 401')
            locked = attacker.post('/login', login='employee.demo', password=credentials['employee.demo'])
            check(locked[0] == 429 and locked[1].get('Retry-After') == '900',
                  'Compte verrouillé 15 min, même avec le bon mot de passe')
            other = Client(base)
            other.request('/login')
            check(other.post('/login', login='hr.demo', password=credentials['hr.demo'])[0] == 303,
                  'Autre compte, même adresse 127.0.0.1 : connexion possible (T-18 corrigée)')
            journal = (instance_path / 'runtime' / 'application.log').read_text()
            failed = [json.loads(line[line.index('{'):]) for line in journal.splitlines() if '"auth.failed"' in line]
            employee_id = local.sql("USE secure_payroll; SELECT id FROM accounts WHERE login='employee.demo';")
            check(len(failed) >= 5 and all(e['ip'] == '127.0.0.1' for e in failed[-5:])
                  and all(str(e['target_account_id']) == employee_id for e in failed[-5:])
                  and '"auth.throttled"' in journal and '"scope":"account"' in journal,
                  'Échecs journalisés avec compte visé et adresse')
            check(wrong not in journal and 'employee.demo' not in journal
                  and all(p not in journal for p in credentials.values()),
                  'Journal sans mot de passe ni identifiant saisi')
            # Phases 4 et 5 : « Mes accès » est la version corrigée, même avec le drapeau de laboratoire.
            status, _, body = employee.request('/my-access')
            check(status == 200 and body.count('<tr><td><strong>') == 1 and 'LABORATOIRE' not in body,
                  'Mes accès : historique du seul compte connecté, composant sécurisé')
            injection = "2999-01-01' UNION SELECT login, password_hash, NULL, NULL FROM accounts -- -"
            status, _, body = employee.request('/my-access?' + urllib.parse.urlencode({'since': injection}))
            check(status == 422 and '$argon2id$' not in body and 'SQLSTATE' not in body and 'UNION' not in body,
                  'Injection SQL refusée (422), sans message du SGBD ni écho de la saisie')
            journal = (instance_path / 'runtime' / 'application.log').read_text()
            check('"lab.vulnerable_refused"' in journal and '"sql_metacharacters":true' in journal,
                  'Drapeau de laboratoire refusé hors laboratoire ; tentative journalisée')
            onboarding(local, base, hr, employee, instance_path, check, refused)
            for path in ['/employees', '/departments', '/positions', '/contracts', '/assignments', '/compensation/history']:
                check(hr.request(path)[0] == 200, 'Page RH : ' + path)
            _, _, compform = hr.request('/compensation/new')
            check('<select id="employee_number"' in compform and 'EMP-2026-001' in compform and 'TRAORE' in compform,
                  'Rémunération : employé choisi dans un menu déroulant des actifs (plus de matricule à taper)')
            check(admin.request('/iam')[0] == 200, 'IAM administrateur disponible')
            check(auditor.request('/iam')[0] == 200, 'IAM auditeur disponible en lecture')
            check(employee.request('/iam')[0] == 403, 'IAM refusé à Employee')
            # Phase 6 : données personnelles chiffrées — libre-service du salarié, masquage, unicité du RIB.
            status, _, body = employee.request('/my-data')
            check(status == 200 and 'ZZ••••21' in body and '•••• •••• •••• •••• •••• 0117' in body
                  and '••••••101' in body
                  and all(secret not in body for secret in ('999780000000000000010117', 'ZZ104521', '999000101')),
                  'Mes données : identité et RIB affichés masqués, aucun secret en clair dans la page')
            check(hr.request('/my-data')[0] == 403, 'Mes données : refusé à un non-salarié (RH)')
            status, headers, _ = employee.post('/my-data', rib='999110000000000000009999', bank_name='Banque test')
            check(status == 303 and headers.get('Location', '').endswith('/my-data'),
                  'RIB saisi par le salarié : enregistré puis redirection')
            check('•••• •••• •••• •••• •••• 9999' in employee.request('/my-data')[2],
                  'RIB mis à jour visible masqué (seuls les 4 derniers chiffres)')
            stored = local.sql("USE secure_payroll; SELECT HEX(LEFT(rib,1)),LENGTH(rib),"
                               "LOCATE(_binary'999110000000000000009999',rib) FROM employee_personal_data "
                               "WHERE employee_id=(SELECT id FROM employees WHERE employee_number='EMP-2026-001');")
            check(stored == '01\t53\t0',
                  'RIB stocké chiffré (version||nonce||chiffré||tag, 53 octets), jamais le clair')
            dup = employee.post('/my-data', rib='999810000000000000010223', bank_name='Banque test')
            check(dup[0] == 422 and 'déjà enregistré' in dup[2],
                  'RIB déjà pris par un autre salarié (index aveugle unique) : rejeté')
            bad = employee.post('/my-data', rib='123', bank_name='Banque test')
            check(bad[0] == 422 and '24 chiffres attendus' in bad[2],
                  'RIB mal formé : rejeté par la validation serveur avant chiffrement')
            # Phase 7 : la piste d'audit a capté les événements applicatifs de cette session.
            captured = local.sql("USE secure_payroll; SELECT COUNT(*) FROM security_audit_log WHERE source='application' "
                                 "AND event IN ('auth.login','personal_data.viewed','personal_data.rib_updated');")
            check(int(captured) >= 3, 'Audit applicatif : connexion, lecture et saisie du salarié enregistrées en base')
            check(int(local.sql("USE secure_payroll; SELECT COUNT(*) FROM security_audit_log WHERE event='personal_data.modified' "
                                "AND source='trigger' AND actor_account_id IS NOT NULL;")) >= 1,
                  'Audit par déclencheur : modification du RIB attribuée à l\'acteur applicatif (@audit_actor)')
            check(employee.request('/audit')[0] == 403, 'Audit : supervision refusée au salarié (403)')
            check('echecs_connexion' in local.sql('USE secure_payroll; CALL audit_suspicious();'),
                  'Détection : la rafale d\'échecs de connexion ressort comme signal suspect (24 h)')
            status, _, abody = auditor.request('/audit')
            check(status == 200 and 'auth.failed' in abody and 'personal_data.viewed' in abody,
                  'Audit : supervision accessible à l\'auditeur, événements affichés')
            person = sql("SELECT id FROM employees WHERE employee_number='EMP-2026-001';")
            period = sql("SELECT id FROM payroll_periods WHERE month_start='2026-11-01';")
            payroll.request('/payroll')
            calculated = payroll.post('/payroll/calculate', period_id=period, employee_id=person)
            check(calculated[0] == 303, 'Calcul de paie réel via HTTP')
            payslip = sql('SELECT MAX(id) FROM payroll_calculations;')
            path = '/payslips/' + payslip
            check(sql('SELECT base_amount,net_amount,currency FROM payroll_calculations;') == '11200.00\t11200.00\tMAD',
                  'Prorata exact : 28/30 jours et 11 200 MAD')
            check(employee.request(path)[0] == 404, 'Bulletin non publié inaccessible à Employee')
            check(payroll.request(path)[0] == 200, 'Détail bulletin Paie')
            check(payroll.post(path + '/transition', event='verified', reason='Vérification isolée de l’installation')[0] == 303,
                  'Vérification Paie')
            finance.request(path)
            check(finance.post(path + '/transition', event='approved', reason='Approbation isolée indépendante')[0] == 303,
                  'Approbation Finance indépendante')
            payroll.request(path)
            check(payroll.post(path + '/transition', event='published', reason='Publication de recette isolée')[0] == 303,
                  'Publication Paie')
            check(employee.request(path)[0] == 200, 'Bulletin publié propre accessible à Employee')
            check(employee.post('/language', language='en', return_to=path)[0] == 303
                  and employee.request(path)[0] == 200, 'Consultation anglaise du bulletin')
            check(employee.post('/language', language='fr', return_to=path)[0] == 303, 'Retour au français')
            settlement = path + '/settlement'
            check(finance.request(settlement)[0] == 200, 'Module règlement disponible')
            check(finance.request(settlement, dict(action='pay', expected='0', target='0', reason='test',
                                                 reference='INSTALL-CSRF-REJECT'))[0] == 403, 'CSRF manquant refusé')
            check(finance.post(settlement, action='pay', expected='0', target='0', reason='Paiement fictif isolé',
                               reference='INSTALL-TEST-PAYMENT')[0] == 303, 'Paiement fictif du solde SQL')
            check(finance.post(settlement, action='pay', expected='0', target='0', reason='Refus du doublon isolé',
                               reference='INSTALL-TEST-DUPLICATE')[0] == 409, 'Second paiement refusé')
            check(sql('SELECT COUNT(*),SUM(amount) FROM settlement_ledger;') == '1\t11200.00',
                  'Une seule écriture financière fictive de 11 200 MAD')
            check(employee.request(settlement)[0] == 200, 'Solde consultable par le bénéficiaire')
        local.stop()
        local.start()
        check(sql('SELECT COUNT(*) FROM settlement_ledger;') == '1', 'Données conservées après arrêt et redémarrage')
        sys.path.insert(0, str(ROOT / 'tests'))
        import privilege_matrix
        privilege_matrix.verify(local, credentials['svc_php'], check)
        report = {'passed': True, 'checks': count, 'primary_connected': False,
                  'mysql_version': local.sql('SELECT VERSION();'), 'private_directory': str(private),
                  'tables': 28, 'procedures': 31, 'triggers': 53, 'permissions': 34,
                  'database_roles': 12, 'operators': 3, 'http_roles': 6, 'nadia_net': '11200.00', 'currency': 'MAD'}
        (private / 'result.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
        print(json.dumps(report, ensure_ascii=False), flush=True)
    finally:
        if process is not None and process.poll() is None:
            process.terminate()
            process.wait(timeout=30)
        if local.socket.exists():
            local.stop()


if __name__ == '__main__':
    os.umask(0o077)
    main()
