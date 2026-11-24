#!/usr/bin/env python3
"""Run identity integrity checks in a NEW socket-only laboratory, without sudo."""
import argparse
import json
from pathlib import Path
import secrets
import subprocess

from mysql_instance import PrivateInstance

ROOT = Path(__file__).resolve().parents[1]
SCHEMA = 'secure_payroll_test_integrity'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--initialize-isolated', action='store_true', required=True)
    parser.parse_args()
    # PrivateInstance checks datadir ownership and networking before any DDL.
    with PrivateInstance() as instance:
        print('Integrity artifacts retained at ' + str(instance.private), flush=True)
        if not instance.sql('SELECT VERSION();').startswith('8.4.'):
            raise RuntimeError('MySQL 8.4 required.')
        migration = (ROOT / 'database/migrations/001_create_identity_and_rbac.sql').read_text()
        if migration.count('USE secure_payroll;') != 1:
            raise RuntimeError('Unexpected migration database selector.')
        instance.sql(f'CREATE DATABASE {SCHEMA} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;')
        instance.sql(migration.replace('USE secure_payroll;', f'USE {SCHEMA};'))
        password = secrets.token_urlsafe(32)
        instance.sql("CREATE USER 'lab_integrity'@'localhost' IDENTIFIED BY '" + password + "'; "
                     f"GRANT SELECT,INSERT,UPDATE,DELETE ON {SCHEMA}.* TO 'lab_integrity'@'localhost';")
        runtime = instance.private / 'runtime'
        runtime.mkdir(mode=0o700)
        env = instance.env.copy()
        env.update(DB_HOST='localhost', DB_PORT='1', DB_NAME=SCHEMA,
                   DB_USER='lab_integrity', DB_PASSWORD=password,
                   DB_SOCKET=str(instance.socket), APP_RUNTIME_DIR=str(runtime))
        result = subprocess.run(['php', str(ROOT / 'tests/database-integrity.php'),
                                 '--run', '--confirm-test-database'],
                                env=env, text=True, capture_output=True, timeout=120)
        # PHP reports only assertion totals or a scenario name, never credentials.
        print(result.stdout, end='', flush=True)
        if result.returncode:
            print(result.stderr, end='', flush=True)
        tables = instance.sql(f"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='{SCHEMA}' ORDER BY TABLE_NAME;").splitlines()
        counts = {table: int(instance.sql(f'SELECT COUNT(*) FROM {SCHEMA}.`{table}`;')) for table in tables}
        passed = result.returncode == 0 and len(counts) == 6 and not any(counts.values())
        (instance.private / 'integrity-result.json').write_text(json.dumps({
            'passed': passed, 'php_exit_code': result.returncode,
            'output': result.stdout, 'rows_after_rollback': counts,
            'scope': 'new private datadir only; main instance never connected',
        }, indent=2) + '\n')
        if not passed:
            raise RuntimeError('Integrity campaign failed; inspect retained result.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
