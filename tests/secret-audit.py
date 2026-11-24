#!/usr/bin/env python3
"""Vérifie la barrière de publication avec de faux secrets dans un dépôt temporaire."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def main():
    private = Path(tempfile.mkdtemp(prefix='securepayroll-audit-', dir='/tmp'))
    spec = importlib.util.spec_from_file_location('secret_auditor', ROOT / 'bin/audit-secrets.py')
    auditor = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(auditor)
    auditor.ROOT = private
    subprocess.run(['git', 'init', '-q', str(private)], check=True)
    (private / '.gitignore').write_text('.local/\n')
    count = 0

    def check(ok, name):
        nonlocal count
        if not ok:
            raise RuntimeError(name)
        count += 1
        print('PASS ' + name)

    def scan():
        output = io.StringIO()
        previous = sys.argv
        try:
            sys.argv = ['audit-secrets.py']
            with contextlib.redirect_stdout(output):
                code = auditor.main()
        finally:
            sys.argv = previous
        return code, json.loads(output.getvalue())

    code, report = scan()
    check(code == 0 and report['passed'], 'Dépôt neuf sans secret accepté')
    (private / '.local').mkdir(mode=0o700)
    fake_password = secrets.token_urlsafe(24)
    (private / '.local/credentials.json').write_text(json.dumps({'employee.demo': fake_password}))
    code, report = scan()
    check(code == 0, 'Secret local ignoré non proposé à la publication')
    (private / 'accidental.txt').write_text('Valeur copiée par erreur : ' + fake_password)
    code, report = scan()
    check(code == 1 and any(x['rule'] == 'existing_private_credential_reused' for x in report['findings']),
          'Réutilisation d’un secret privé détectée sans dépendre d’un nom de variable')
    check(fake_password not in json.dumps(report), 'Rapport sans valeur du secret')
    (private / 'accidental.txt').write_text('Valeur retirée, fichier conservé.\n')
    check(scan()[0] == 0, 'Contenu corrigé accepté')
    # Jeton synthétique aléatoire, jamais fourni par GitHub et jamais utilisé sur le réseau.
    fake_token = 'ghp_' + secrets.token_hex(20)
    (private / 'history.txt').write_text(fake_token)
    check(scan()[0] == 1, 'Motif de jeton synthétique détecté dans un fichier non suivi')
    subprocess.run(['git', '-C', str(private), 'add', '.'], check=True)
    subprocess.run(['git', '-C', str(private), '-c', 'user.name=Audit fixture', '-c', 'user.email=audit@example.invalid',
                    'commit', '-qm', 'Synthetic fixture'], check=True)
    (private / 'history.txt').write_text('Valeur retirée du fichier courant.\n')
    subprocess.run(['git', '-C', str(private), 'add', '.'], check=True)
    subprocess.run(['git', '-C', str(private), '-c', 'user.name=Audit fixture', '-c', 'user.email=audit@example.invalid',
                    'commit', '-qm', 'Remove synthetic fixture from current file'], check=True)
    code, report = scan()
    check(code == 1 and report['reachable_commits'] == 2
          and any(x['source'].startswith('git-object:') for x in report['findings']),
          'Ancien secret synthétique encore détecté dans l’historique après retrait du fichier')
    check(fake_token not in json.dumps(report), 'Rapport historique sans valeur du jeton')
    print(str(count) + ' contrôles de la barrière de publication réussis ; aucun réseau ni secret opérateur utilisé.')
    print('Artefacts fictifs conservés : ' + str(private))


if __name__ == '__main__':
    os.umask(0o077)
    main()
