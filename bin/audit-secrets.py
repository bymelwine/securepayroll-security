#!/usr/bin/env python3
"""Audit local prudent des fichiers publiables, de l'index et des objets Git ; aucune valeur sensible affichée."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
RULES = {
    'private_key': rb'-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----',
    'github_token': rb'(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})',
    'aws_access_key': rb'\b(?:AKIA|ASIA)[A-Z0-9]{16}\b',
    'url_credentials': rb'https?://[^\s/:@]+:[^\s/@]{5,}@',
    'database_password_literal': rb'''(?m)^\s*(?:export\s+)?(?:DB_PASSWORD|MYSQL_PWD|GH_TOKEN|GITHUB_TOKEN)\s*=\s*['"]?([^\s'"#]{4,})''',
    'password_hash': rb'\$argon2id\$v=19\$m=\d+,t=\d+,p=\d+\$[A-Za-z0-9+/]{16,}\$[A-Za-z0-9+/]{32,}',
    'mysql_password_argument': rb'--password=(?![\s\'"<$\[(])[^\s\'";]{4,}',
    'assigned_credential': rb'''(?im)(?:\b(?:db_password|passwd|password|api_key|client_secret)\b|['"](?:DB_PASSWORD|MYSQL_PWD|password|token|secret)['"])\s*(?:=>|=|:)\s*['"]([^'"\r\n]{8,})['"]''',
}
# Empreinte publique de travail constant pour login inexistant ; jamais liée à un compte.
DUMMY_ARGON = (b'$argon2id$v=19$m=65536,t=4,p=1$MTIzNDU2Nzg5MGFiY2RlZg$'
               b'phRfPWZoyG2Rva8DQN7nGkXzB0JRslvJM5t30pPJbF4')
PRIVATE_NAMES = {'admin.json', 'application.json', 'credentials.json', '.my.cnf', '.mylogin.cnf'}


def git(*args, input=None):
    return subprocess.run(['git', *args], cwd=ROOT, input=input, capture_output=True, check=True).stdout


def private_path(path):
    parts = Path(path).parts
    return (any(p in {'.local', '.aws', '.agents', '.codex', '__pycache__', 'backups'} for p in parts)
            or path.startswith('storage/') and path != 'storage/logs/.gitkeep'
            or Path(path).name in PRIVATE_NAMES
            or Path(path).name.startswith('.env') and Path(path).name != '.env.example'
            or '/generated/' in path)


def scan(data, label, findings, allowed, known=()):
    for value in known:
        if value in data:
            findings.append({'source': label, 'rule': 'existing_private_credential_reused'})
    for rule, pattern in RULES.items():
        for match in re.finditer(pattern, data):
            if rule == 'password_hash' and match.group() == DUMMY_ARGON:
                allowed.add('Empreinte Argon2id publique de travail constant, sans compte associé')
                continue
            if (rule == 'assigned_credential' and match.group(1) == b'deliberately-invalid'
                    and b'absent-laboratory-user' in data):
                allowed.add('Chaîne volontairement invalide du test de refus pour utilisateur inexistant')
                continue
            # Valeurs explicites de remplacement, pas des mots de passe opérateur.
            if rule == 'database_password_literal' and (match.group(1) in {b'$(...', b'${...', b'None'}
                    or match.group(1).startswith((b'getpass.getpass(', b'os.environ['))):
                continue
            findings.append({'source': label, 'line': data[:match.start()].count(b'\n') + 1,
                             'rule': rule})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    findings, allowed = [], set()
    known = set()
    unreadable_private = []
    # Comparer aux secrets privés lisibles sans jamais copier leurs valeurs dans le rapport.
    def collect(node, key=''):
        if isinstance(node, dict):
            for k, v in node.items():
                collect(v, str(k))
        elif isinstance(node, list):
            for value in node:
                collect(value, key)
        elif isinstance(node, str) and len(node) >= 12 and re.search(r'password|passwd|secret|token', key, re.I):
            known.add(node.encode())
    for folder in ['storage', '.local']:
        for path in (ROOT / folder).rglob('*.json'):
            try:
                content = json.loads(path.read_text())
                collect(content)
                if path.name.endswith('credentials.json') and isinstance(content, dict):
                    for value in content.values():
                        if isinstance(value, str) and len(value) >= 12:
                            known.add(value.encode())
            except (OSError, ValueError):
                unreadable_private.append(str(path.relative_to(ROOT)))
    candidates = sorted(set(git('ls-files', '-z', '--cached', '--others', '--exclude-standard').decode().split('\0')) - {''})
    for path in candidates:
        if private_path(path):
            findings.append({'source': path, 'rule': 'private_file_in_publication'})
            continue
        local = ROOT / path
        if local.is_symlink():
            findings.append({'source': path, 'rule': 'symlink_requires_review'})
        elif local.is_file():
            data = local.read_bytes()
            if local.suffix.lower() == '.pdf' and shutil.which('pdftotext'):
                extracted = subprocess.run(['pdftotext', str(local), '-'], capture_output=True, check=True).stdout
                data += extracted
            scan(data, 'working:' + path, findings, allowed, known)
    # L'index peut différer du disque : chaque blob indexé est vérifié séparément.
    for entry in git('ls-files', '--stage', '-z').decode().split('\0'):
        if not entry:
            continue
        metadata, path = entry.split('\t', 1)
        object_id = metadata.split()[1]
        scan(git('cat-file', 'blob', object_id), 'index:' + path, findings, allowed, known)
    # Inclut objets accessibles, inaccessibles et ancien index ; aucun prune/réécriture.
    objects = git('cat-file', '--batch-all-objects', '--batch-check=%(objectname) %(objecttype)').decode().splitlines()
    blobs = 0
    for line in objects:
        oid, kind = line.split()
        if kind in {'blob', 'commit', 'tag'}:
            scan(git('cat-file', kind, oid), 'git-object:' + oid, findings, allowed, known)
            blobs += kind == 'blob'
    commits = int(git('rev-list', '--all', '--count').strip())
    report = {'passed': not findings, 'scope': 'working publication candidates, index, all local Git objects',
              'candidate_files': len(candidates), 'reachable_commits': commits,
              'git_objects': len(objects), 'git_blobs': blobs, 'findings': findings,
              'private_files_unreadable_and_excluded': unreadable_private,
              'reviewed_non_secrets': sorted(allowed),
              'limits': 'Pattern-based and path-based checks, not a proof of absence of every possible secret; ignored runtime/backups are not published.'}
    if args.output:
        output = args.output.resolve()
        if not output.is_relative_to(ROOT / 'docs'):
            raise RuntimeError('Rapport attendu dans docs/.')
        output.write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if report['passed'] else 1


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (OSError, subprocess.SubprocessError, RuntimeError):
        print('Audit incomplet : publication interdite ; aucune valeur sensible affichée.', file=sys.stderr)
        sys.exit(2)
