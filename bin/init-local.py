#!/usr/bin/env python3
"""Installation neuve autonome, socket privé uniquement ; aucun MySQL système."""
import argparse
import getpass
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import stat
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
ROLES = {
    'hr.demo': ('hr_manager', 'COLLAB-HR-001', 'Camille', 'RH'),
    'finance.demo': ('finance_manager', 'COLLAB-FIN-001', 'Samir', 'Finance'),
    'payroll.demo': ('payroll_manager', 'COLLAB-PAY-001', 'Alex', 'Paie'),
    'employee.demo': ('employee', 'EMP-2026-001', 'Nadia', 'TRAORE'),
    'admin.demo': ('application_administrator', 'COLLAB-ADM-001', 'Lou', 'Administration'),
    'audit.demo': ('security_auditor', 'COLLAB-AUD-001', 'Noa', 'Audit'),
}
# Ordre revu : nouvelles instances seulement, jamais une boucle aveugle sur les patches.
SOURCES = [
    'database/migrations/001_create_identity_and_rbac.sql',
    'database/seeds/001_application_roles.sql',
    'database/setup/advanced_hr_owner.sql',
    'database/migrations/002_advanced_hr.sql',
    'database/migrations/003_hr_period_operations.sql',
    'database/patches/001_fix_hr_assert_permission_collation.sql',
    'database/setup/compensation_owner.sql',
    'database/migrations/004_compensation.sql',
    'database/migrations/005_compensation_operations.sql',
    'database/migrations/006_mad_currency.sql',
    'database/setup/svc_php_grants.sql',
    'database/setup/advanced_hr_owner_grants.sql',
    'database/setup/advanced_hr_grants.sql',
    'database/setup/compensation_owner_grants.sql',
    'database/setup/compensation_grants.sql',
    'database/seeds/002_hr_permissions.sql',
    'database/seeds/003_advanced_hr_permissions.sql',
    'database/seeds/004_hr_reference_demo.sql',
    'database/seeds/005_compensation_permissions.sql',
    'database/setup/payroll_owner.sql',
    'database/migrations/007_payroll.sql',
    'database/migrations/008_payroll_operations.sql',
    'database/setup/payroll_owner_grants.sql',
    'database/seeds/006_payroll_permissions.sql',
    'database/setup/payroll_grants.sql',
    'database/setup/settlement_owner.sql',
    'database/migrations/009_settlement.sql',
    'database/migrations/010_settlement_operations.sql',
    'database/setup/settlement_owner_grants.sql',
    'database/seeds/007_settlement_permissions.sql',
    'database/setup/settlement_grants.sql',
    'database/setup/iam_owner.sql',
    'database/migrations/011_iam_operations.sql',
    'database/setup/iam_owner_grants.sql',
    'database/seeds/008_iam_permissions.sql',
    'database/setup/iam_grants.sql',
    # Données personnelles : aucune attribution de droit au service à ce stade.
    'database/migrations/012_employee_personal_data.sql',
    # Embauche : compte créé par la RH, mot de passe temporaire à changer (procédures account_*).
    'database/migrations/013_account_onboarding.sql',
    # Phase 2 : privilèges directs de svc_php révoqués et remplacés par des rôles MySQL.
    'database/setup/app_roles.sql',
    'database/setup/operator_roles.sql',
    'database/setup/onboarding_grants.sql',
    # Embauche : matricule EMP-<année>-<numéro> généré par la base (remplace account_onboard de 013).
    'database/migrations/014_account_onboard_matricule.sql',
    # Phase 6 : chiffrement au repos des données personnelles (RIB, CIN, CNSS) + accès masqué du salarié.
    'database/setup/personal_data_owner.sql',
    'database/migrations/015_personal_data_encryption.sql',
    'database/setup/personal_data_grants.sql',
    'database/seeds/011_personal_data_permissions.sql',
    # Phase 7 : journalisation et supervision de sécurité (piste d'audit en base, en ajout seul).
    'database/setup/audit_owner.sql',
    'database/migrations/016_security_audit.sql',
    'database/setup/audit_grants.sql',
    'database/migrations/017_security_audit_triggers.sql',
    'database/seeds/012_audit_permissions.sql',
]
# Mises à niveau d'une instance existante (`upgrade`) : colonne témoin, puis fichiers appliqués une seule fois.
UPGRADES = [
    ("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='secure_payroll' "
     "AND TABLE_NAME='accounts' AND COLUMN_NAME='password_change_required';",
     ['database/migrations/013_account_onboarding.sql', 'database/setup/onboarding_grants.sql']),
    # Matricule auto : la nouvelle account_onboard a 6 paramètres IN (l'ancienne en avait 7, dont p_number).
    ("SELECT COUNT(*)=6 FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA='secure_payroll' "
     "AND SPECIFIC_NAME='account_onboard' AND PARAMETER_MODE='IN';",
     ['database/migrations/014_account_onboard_matricule.sql']),
]
INVENTORY = '28\t31\t53'   # tables, procédures, triggers
# Comptes d'exploitation : rôle MySQL par défaut, et verrouillage après échecs pour les personnes.
OPERATORS = {
    'dba_ops': ('sp_dba', True),
    'backup_ops': ('sp_backup', False),
    'audit_ops': ('sp_db_auditor', True),
}
DATABASE_ROLES = 12
# Seeds dépendant des six comptes fictifs créés par l'installateur.
DEMO_SEEDS = [
    'database/seeds/009_collaboration_demo.sql',
    # Les données personnelles sensibles sont semées CHIFFRÉES par bin/seed-personal-data.php (phase 6),
    # plus par SQL en clair : voir seed_personal_data() ci-dessous.
]


def require(ok, message):
    if not ok:
        raise RuntimeError(message)


def clean_environment():
    return {k: v for k, v in os.environ.items()
            if not k.startswith(('DB_', 'PAYROLL_TEST_', 'MYSQL_', 'SECUREPAYROLL_LAB_'))
            and k not in ('APP_RUNTIME_DIR', 'MYSQL_HOME')}


def quote(value):
    return "'" + str(value).replace('\\', '\\\\').replace("'", "''") + "'"


def private_json(path, value):
    # O_EXCL protège les secrets existants ; aucun remplacement lors d'une relance.
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w') as output:
        json.dump(value, output, ensure_ascii=False, indent=2)
        output.write('\n')


class LocalInstance:
    def __init__(self, directory):
        require(os.geteuid() != 0, 'Utiliser un compte Linux ordinaire, sans sudo.')
        self.directory = Path(directory).absolute()
        require(not self.directory.is_symlink(), 'Répertoire local symbolique refusé.')
        require(self.directory.resolve() == self.directory, 'Chemin local canonique requis.')
        require(re.fullmatch(r'/[A-Za-z0-9_./-]+', str(self.directory)) is not None,
                'Le chemin doit être absolu, sans espace ni caractère spécial.')
        self.data = self.directory / 'data'
        self.socket = self.directory / 'mysql.sock'
        require(len(str(self.socket)) <= 100, 'Chemin socket trop long : choisir --directory plus court.')
        self.password = None
        self.server = None
        if self.directory.exists():
            info = self.directory.stat()
            require(info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o700,
                    'Répertoire local non privé : propriétaire courant et mode 0700 requis.')
            admin = self.directory / 'admin.json'
            if admin.exists():
                self.password = self.read_private('admin.json')['password']

    def read_private(self, name):
        path = self.directory / name
        info = path.lstat()
        require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid()
                and stat.S_IMODE(info.st_mode) == 0o600, 'Configuration privée non conforme.')
        return json.loads(path.read_text())

    def sql(self, statement, user='root', password=None):
        require(self.socket.exists() and not self.socket.is_symlink(), 'Socket privé absent ou invalide.')
        env = clean_environment()
        secret = self.password if user == 'root' and password is None else password
        if secret is not None:
            env['MYSQL_PWD'] = secret
        command = ['mysql', '--no-defaults', '--no-login-paths', '--protocol=socket',
                   '--socket=' + str(self.socket), '--user=' + user,
                   '--default-character-set=utf8mb4', '--batch', '--raw',
                   '--skip-column-names', '--binary-mode']
        prelude = ("SET SESSION time_zone='+00:00'; "
                   "SET NAMES utf8mb4 COLLATE utf8mb4_0900_as_cs; "
                   "SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,"
                   "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';\n")
        result = subprocess.run(command, input=prelude + statement, text=True,
                                capture_output=True, env=env, timeout=60)
        if result.returncode:
            codes = ','.join(re.findall(r'ERROR (\d+)', result.stderr)) or 'inconnu'
            raise RuntimeError('Échec MySQL privé (code ' + codes + '). Aucun secret affiché.')
        return result.stdout.strip()

    def assert_isolated(self):
        require(self.data.is_dir() and not self.data.is_symlink(), 'Datadir local invalide.')
        row = self.sql('SELECT @@datadir, @@skip_networking, @@port, VERSION();').split('\t')
        require(len(row) == 4 and Path(row[0]).resolve() == self.data.resolve()
                and row[1] == '1' and row[2] == '0' and row[3].startswith('8.4.'),
                'Instance privée MySQL 8.4 sans réseau non confirmée ; arrêt des opérations.')

    def assert_roles(self):
        # Les rôles n'ont de sens que si MySQL ne les active pas tous d'office.
        require(self.sql('SELECT @@activate_all_roles_on_login, @@mandatory_roles = \'\';') == '0\t1',
                'Activation automatique des rôles MySQL détectée ; moindre privilège non garanti.')
        direct = self.sql("SELECT (SELECT COUNT(*) FROM mysql.db WHERE User='svc_php')"
                          "+(SELECT COUNT(*) FROM mysql.tables_priv WHERE User='svc_php')"
                          "+(SELECT COUNT(*) FROM mysql.columns_priv WHERE User='svc_php')"
                          "+(SELECT COUNT(*) FROM mysql.procs_priv WHERE User='svc_php');")
        require(direct == '0', 'svc_php détient encore des privilèges directs.')
        roles = self.sql("SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'sp\\_%' AND Host='%' "
                         "AND account_locked='Y' AND authentication_string='';")
        require(roles == str(DATABASE_ROLES), 'Rôles MySQL attendus absents.')

    def upgrade(self):
        """Applique, une seule fois chacune, les évolutions de schéma à une instance déjà installée."""
        require((self.directory / 'installed.json').is_file(), 'Installation incomplète ou absente.')
        was_running = self.socket.exists()
        self.start()
        try:
            applied = []
            for probe, files in UPGRADES:
                if self.sql(probe) == '0':
                    for path in files:
                        self.sql((ROOT / path).read_text())
                    applied += files
            counts = self.sql("SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='secure_payroll'),"
                              "(SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='secure_payroll'),"
                              "(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='secure_payroll');")
            require(counts == INVENTORY, 'Inventaire SQL inattendu après mise à niveau ; conserver et diagnostiquer.')
            self.assert_roles()
            if applied:
                private_json(self.directory / time.strftime('upgrade-%Y%m%dT%H%M%SZ.json', time.gmtime()),
                             {'applied': applied, 'inventory': counts.split('\t'), 'database_roles': DATABASE_ROLES})
        finally:
            if not was_running and self.socket.exists():
                self.stop()
        print('Mise à niveau : ' + (', '.join(applied) if applied else 'instance déjà à jour') + '.')

    def outbox(self):
        """Messages de la boîte d'envoi locale (ils contiennent des mots de passe temporaires)."""
        folder = self.directory / 'runtime' / 'outbox'
        messages = sorted(folder.glob('*.eml')) if folder.is_dir() else []
        if not messages:
            print('Boîte d’envoi vide.')
        for message in messages:
            print('=' * 72 + '\n' + message.name + '\n' + '-' * 72 + '\n' + message.read_text())

    def start(self):
        require(self.data.is_dir() and not self.data.is_symlink(), 'Initialiser d’abord une nouvelle instance.')
        if self.socket.exists():
            self.assert_isolated()
            return
        pidfile = self.directory / 'mysql.pid'
        if pidfile.exists():
            pid = pidfile.read_text().strip()
            require(not (pid.isdigit() and Path('/proc', pid).exists()),
                    'Un processus porte déjà ce PID : inspecter les journaux, sans double démarrage.')
        daemon = shutil.which('mysqld') or '/usr/sbin/mysqld'
        require(Path(daemon).is_file(), 'mysqld absent : installer MySQL 8.4.')
        process = subprocess.Popen(
            [daemon, '--no-defaults', '--persisted-globals-load=OFF',
             '--datadir=' + str(self.data), '--socket=' + str(self.socket),
             '--pid-file=' + str(pidfile), '--skip-networking', '--mysqlx=OFF',
             '--log-error=' + str(self.directory / 'mysql.log'),
             # Durcissement (phase 9) : aucune opération serveur sur fichiers (LOAD_FILE,
             # INTO OUTFILE, LOAD DATA INFILE) même pour root, et LOCAL INFILE désactivé.
             '--secure-file-priv=NULL', '--local-infile=OFF',
             '--innodb-buffer-pool-size=64M'], env=clean_environment(),
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            start_new_session=True)
        self.server = process
        deadline = time.monotonic() + 60
        while time.monotonic() < deadline:
            require(process.poll() is None, 'Démarrage MySQL refusé ; consulter le journal local.')
            if self.socket.exists():
                try:
                    self.assert_isolated()
                    return
                except RuntimeError:
                    pass
            time.sleep(.2)
        raise RuntimeError('Délai MySQL dépassé ; conserver les fichiers et inspecter le journal local.')

    def stop(self):
        if not self.socket.exists():
            print('Aucun socket privé actif ; aucune action système.')
            return
        self.assert_isolated()
        self.sql('SHUTDOWN;')
        deadline = time.monotonic() + 45
        while (self.socket.exists() or (self.directory / 'mysql.pid').exists()) and time.monotonic() < deadline:
            time.sleep(.2)
        if self.server is not None:
            self.server.wait(timeout=45)
        require(not self.socket.exists() and not (self.directory / 'mysql.pid').exists(),
                'Arrêt en cours ; conserver les fichiers, aucun arrêt forcé.')
        print('MySQL local arrêté ; données conservées.')

    def initialize(self, interactive=False):
        require(not self.directory.exists(), 'Répertoire déjà présent : aucun rejeu de migration. Utiliser start ou serve.')
        for executable in ['mysql', 'php']:
            require(shutil.which(executable), executable + ' absent.')
        daemon = shutil.which('mysqld') or '/usr/sbin/mysqld'
        require(Path(daemon).is_file(), 'mysqld absent : installer MySQL 8.4.')
        version = subprocess.run([daemon, '--no-defaults', '--version'], capture_output=True,
                                 text=True, check=True).stdout
        require(re.search(r'\b8\.4\.', version), 'MySQL 8.4 requis (pas MariaDB).')
        for path in SOURCES + DEMO_SEEDS:
            require((ROOT / path).is_file(), 'Source SQL manquante : ' + path)
        self.directory.mkdir(mode=0o700, parents=False)
        (self.directory / 'runtime').mkdir(mode=0o700)
        credentials = {}
        for login in ['svc_php', *ROLES]:
            if interactive:
                value = getpass.getpass('Nouveau mot de passe local pour ' + login + ' : ')
                require(value == getpass.getpass('Confirmation : '), 'Confirmation différente.')
                require(len(value) >= 12 and not any(c in value for c in '\x00\r\n'),
                        'Au moins 12 caractères, sans NUL ni saut de ligne.')
            else:
                value = secrets.token_urlsafe(24)
            require(value not in credentials.values(), 'Les mots de passe doivent être distincts.')
            credentials[login] = value
        private_json(self.directory / 'credentials.json', credentials)
        operators = {login: secrets.token_urlsafe(24) for login in OPERATORS}
        private_json(self.directory / 'operators.json', operators)
        root_password = secrets.token_urlsafe(32)
        # Conserver le secret avant son attribution pour diagnostiquer un échec sans le perdre.
        private_json(self.directory / 'admin.json', {'password': root_password})
        result = subprocess.run([daemon, '--no-defaults', '--initialize-insecure',
                                 '--datadir=' + str(self.data),
                                 '--log-error=' + str(self.directory / 'initialize.log')],
                                env=clean_environment(), capture_output=True, timeout=120)
        # mysqld sans journal créé : cas typique du profil AppArmor Ubuntu/MySQL.
        require(result.returncode == 0 or (self.directory / 'initialize.log').exists(),
                'mysqld ne peut pas écrire dans ' + str(self.directory) + ' (AppArmor probable : '
                'voir « Dépannage » dans README.md). Répertoire conservé, ne pas rejouer.')
        require(result.returncode == 0, 'Initialisation échouée ; répertoire conservé, ne pas rejouer.')
        try:
            self.start()
            self.sql("ALTER USER 'root'@'localhost' IDENTIFIED BY " + quote(root_password) + ';')
            self.password = root_password
            self.assert_isolated()
            require(self.sql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='secure_payroll';") == '0',
                    'Schéma déjà présent : refus absolu de migration.')
            self.sql("CREATE DATABASE secure_payroll CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;"
                     "CREATE USER 'svc_php'@'localhost' IDENTIFIED BY " + quote(credentials['svc_php']) + ';')
            for path in SOURCES:
                self.sql((ROOT / path).read_text())
                print('Installé : ' + path, flush=True)
            for login, (role, number, first, last) in ROLES.items():
                hashed = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'log_errors=0', '-r',
                                         'echo password_hash(stream_get_contents(STDIN), PASSWORD_ARGON2ID);'],
                                        input=credentials[login], text=True, capture_output=True, check=True).stdout
                require(re.fullmatch(r'\$argon2id\$v=19\$m=\d+,t=\d+,p=\d+\$[A-Za-z0-9+/]+\$[A-Za-z0-9+/]+', hashed),
                        'Argon2id indisponible : installation incomplète conservée.')
                self.sql('USE secure_payroll; START TRANSACTION; '
                         'INSERT INTO employees(employee_number,first_name,last_name) VALUES ('
                         + ','.join(map(quote, [number, first, last])) + '); SET @person=LAST_INSERT_ID(); '
                         'INSERT INTO accounts(employee_id,login,password_hash) VALUES(@person,'
                         + quote(login) + ',' + quote(hashed) + '); SET @account=LAST_INSERT_ID(); '
                         'INSERT INTO role_assignments(account_id,role_id,grant_reason) '
                         "SELECT @account,id,'Nouveau jeu fictif local de collaboration' FROM roles WHERE code="
                         + quote(role) + '; COMMIT;')
            for login, (role, human) in OPERATORS.items():
                account = quote(login) + "@'localhost'"
                # Pas de verrouillage pour un compte technique : un tiers pourrait bloquer la sauvegarde.
                policy = (' FAILED_LOGIN_ATTEMPTS 5 PASSWORD_LOCK_TIME 1 PASSWORD EXPIRE INTERVAL 90 DAY'
                          if human else ' PASSWORD EXPIRE NEVER')
                self.sql('CREATE USER ' + account + ' IDENTIFIED BY ' + quote(operators[login]) + policy + ';'
                         'GRANT ' + quote(role) + ' TO ' + account + ';'
                         'SET DEFAULT ROLE ' + quote(role) + ' TO ' + account + ';')
            for path in DEMO_SEEDS:
                self.sql((ROOT / path).read_text())
                print('Installé : ' + path, flush=True)
            # Phase 6 : données personnelles chiffrées (RIB/CIN/CNSS) via PHP ; la base ne reçoit que du chiffré.
            seed_env = clean_environment()
            seed_env.update({'APP_RUNTIME_DIR': str(self.directory / 'runtime'), 'DB_SOCKET': str(self.socket),
                             'DB_NAME': 'secure_payroll', 'PD_ROOT_PWD': self.password})
            seeded = subprocess.run(['php', str(ROOT / 'bin/seed-personal-data.php'),
                                     str(ROOT / 'database/seeds/personal_data_demo.json')],
                                    env=seed_env, capture_output=True, text=True)
            require(seeded.returncode == 0,
                    'Semence des données personnelles chiffrées échouée : ' + seeded.stderr.strip())
            print(seeded.stdout.strip(), flush=True)
            settings = {'DB_HOST': 'localhost', 'DB_PORT': '3306', 'DB_NAME': 'secure_payroll',
                        'DB_USER': 'svc_php', 'DB_PASSWORD': credentials['svc_php'],
                        'DB_SOCKET': str(self.socket), 'APP_RUNTIME_DIR': str(self.directory / 'runtime'),
                        'SETTLEMENT_ENABLED': '1', 'IAM_ENABLED': '1'}
            private_json(self.directory / 'application.json', settings)
            self.assert_isolated()
            counts = self.sql("SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='secure_payroll'),"
                              "(SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='secure_payroll'),"
                              "(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='secure_payroll');")
            require(counts == INVENTORY, 'Inventaire SQL final inattendu ; conserver et diagnostiquer.')
            self.assert_roles()
            private_json(self.directory / 'installed.json', {'schema': 'secure_payroll', 'tables': 28,
                         'procedures': 31, 'triggers': 53, 'database_roles': DATABASE_ROLES,
                         'operators': len(OPERATORS), 'networking': False})
        finally:
            # Ne vise que notre socket ; aucune suppression, aucun kill système.
            if self.socket.exists():
                self.stop()
        print('Installation terminée. Identifiants privés : sous-commande credentials. Démarrage : serve.')

    def application_environment(self):
        require((self.directory / 'installed.json').is_file(), 'Installation incomplète ; ne pas démarrer le web.')
        settings = self.read_private('application.json')
        require(settings['DB_SOCKET'] == str(self.socket) and settings['DB_NAME'] == 'secure_payroll',
                'Configuration locale différente de l’instance privée.')
        env = clean_environment()
        env.update(settings)
        return env


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', nargs='?', default='init', choices=['init', 'start', 'serve', 'stop', 'status', 'credentials',
                                                                          'upgrade', 'outbox'])
    parser.add_argument('--directory', type=Path, default=ROOT / '.local')
    parser.add_argument('--interactive-passwords', action='store_true')
    parser.add_argument('--port', type=int, default=8080)
    args = parser.parse_args()
    if args.command in ('init', 'serve', 'upgrade'):
        php = shutil.which('php')
        require(php is not None, 'PHP CLI absent : voir docs/INSTALLATION.md.')
        check = subprocess.run(
            [php, '-r', 'foreach (["pdo_mysql", "session", "openssl", "intl", "mbstring"] as $extension) {'
             'if (!extension_loaded($extension)) echo $extension, " "; }'
             'if (!defined("PASSWORD_ARGON2ID")) echo "Argon2id ";'
             'if (PHP_INT_SIZE !== 8) echo "PHP-64-bits ";'],
            capture_output=True, text=True, timeout=10,
        )
        require(check.returncode == 0, 'Vérification PHP impossible : voir docs/INSTALLATION.md.')
        require(not check.stdout.strip(), 'Prérequis PHP manquants : ' + check.stdout.strip()
                + '. Voir docs/INSTALLATION.md.')
    instance = LocalInstance(args.directory)
    if args.command == 'init':
        instance.initialize(args.interactive_passwords)
    elif args.command == 'credentials':
        require(sys.stdout.isatty(), 'Affichage privé dans un terminal seulement ; ne pas rediriger les secrets.')
        for login, password in instance.read_private('credentials.json').items():
            print(login + ' : ' + password)
        if (instance.directory / 'operators.json').exists():
            print('Comptes MySQL d\'exploitation (socket ' + str(instance.socket) + ') :')
            for login, password in instance.read_private('operators.json').items():
                print('  ' + login + ' : ' + password)
    elif args.command == 'stop':
        instance.stop()
    elif args.command == 'upgrade':
        instance.upgrade()
    elif args.command == 'outbox':
        require(sys.stdout.isatty(), 'Affichage privé dans un terminal seulement ; ne pas rediriger les secrets.')
        instance.outbox()
    elif args.command == 'status':
        if instance.socket.exists():
            instance.assert_isolated()
            print('MySQL privé actif, sans réseau ; datadir confirmé.')
        else:
            print('MySQL privé arrêté ; aucune connexion système tentée.')
    else:
        require((instance.directory / 'installed.json').is_file(), 'Installation incomplète ou absente.')
        instance.start()
        if args.command == 'serve':
            require(1024 <= args.port <= 65535, 'Port HTTP invalide.')
            env = instance.application_environment()
            print('Application : http://127.0.0.1:' + str(args.port) + ' ; Ctrl+C arrête PHP uniquement.', flush=True)
            os.chdir(ROOT)
            os.execvpe('php', ['php', '-S', '127.0.0.1:' + str(args.port), '-t', 'public', 'public/index.php'], env)


if __name__ == '__main__':
    os.umask(0o077)
    try:
        main()
    except (RuntimeError, OSError, subprocess.SubprocessError) as error:
        print(str(error) if isinstance(error, RuntimeError) else 'Erreur système ; fichiers privés conservés pour diagnostic.', file=sys.stderr)
        sys.exit(1)
    except (KeyboardInterrupt, EOFError):
        print('Interrompu ; fichiers privés conservés.', file=sys.stderr)
        sys.exit(130)
