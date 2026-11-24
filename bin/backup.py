#!/usr/bin/env python3
"""Non-destructive local MySQL backup using an existing dedicated private login-path."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import time
import uuid

ROOT=Path(__file__).resolve().parents[1]


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    identity=parser.add_mutually_exclusive_group(required=True)
    identity.add_argument('--login-path')
    identity.add_argument('--user')
    parser.add_argument('--password-stdin',action='store_true')
    parser.add_argument('--socket')
    parser.add_argument('--logs-directory',help='Explicit private runtime log directory, otherwise project logs')
    parser.add_argument('--directory',required=True)
    parser.add_argument('--port',type=int,default=3306)
    args=parser.parse_args()
    if os.geteuid()==0 or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}',args.login_path or args.user) or not 1<=args.port<=65535:
        parser.error('Run without sudo, with a valid existing dedicated login-path and local port.')
    parent=Path(args.directory).expanduser().resolve(strict=True)
    info=parent.stat()
    if not parent.is_dir() or parent==ROOT or ROOT in parent.parents or info.st_uid!=os.geteuid() or stat.S_IMODE(info.st_mode)&0o077:
        parser.error('Output directory must be private (0700), owned by you and outside the project.')
    env={k:v for k,v in os.environ.items() if k not in {'MYSQL_PWD','DB_PASSWORD'}}
    if args.user:
        if not args.password_stdin: parser.error('--user requires --password-stdin; no password in command arguments.')
        env['MYSQL_PWD']=sys.stdin.read()
        if not env['MYSQL_PWD'] or '\x00' in env['MYSQL_PWD']: parser.error('A private password is required on stdin.')
        client=['--no-defaults','--no-login-paths','--user='+args.user]
    else:
        if args.password_stdin: parser.error('--password-stdin requires --user.')
        client=['--login-path='+args.login_path]
    if args.socket:
        if not re.fullmatch(r'/[A-Za-z0-9_./-]+',args.socket) or '..' in args.socket.split('/'):
            parser.error('Invalid explicit Unix socket.')
        client+=['--protocol=socket','--socket='+args.socket]
    else:
        client+=['--protocol=TCP','--host=127.0.0.1','--port='+str(args.port)]
    logs=Path(args.logs_directory).resolve(strict=True) if args.logs_directory else ROOT/'storage/logs'
    if not logs.is_dir():parser.error('Log directory unavailable.')
    check=subprocess.run(['mysql',*client,'--database=secure_payroll','--batch','--raw','--skip-column-names',
                          '--execute=SELECT CURRENT_USER(),DATABASE();'],text=True,capture_output=True,env=env)
    if check.returncode or not check.stdout.rstrip().endswith('\tsecure_payroll'):
        raise RuntimeError('Dedicated backup connection unavailable; nothing exported.')
    account=check.stdout.split('\t',1)[0].split('@',1)[0].lower()
    if account in {'root','svc_php','compensation_owner','hr_module_owner','payroll_owner'}:
        raise RuntimeError('A dedicated backup account is required; no export performed.')
    destination=parent/(time.strftime('securepayroll-%Y%m%dT%H%M%SZ',time.gmtime())+'-'+uuid.uuid4().hex[:8])
    destination.mkdir(mode=0o700)
    partial=destination/'database.partial.sql'
    print('Private backup destination: '+str(destination),flush=True)
    with partial.open('xb') as output:
        os.chmod(partial,0o600)
        result=subprocess.run(['mysqldump',*client,'--single-transaction','--quick','--routines','--triggers',
                               '--events','--hex-blob','--no-tablespaces','--set-gtid-purged=OFF',
                               '--databases','secure_payroll'],stdout=output,stderr=subprocess.PIPE,env=env)
        output.flush(); os.fsync(output.fileno())
    if result.returncode or partial.stat().st_size==0:
        raise RuntimeError('Dump incomplete; retained as database.partial.sql. No success marker created.')
    partial.rename(destination/'database.sql')
    for source in sorted(logs.glob('*.log')):
        if source.is_symlink() or not source.is_file():
            raise RuntimeError('Unexpected log artifact; backup retained without success marker.')
        target=destination/source.name
        with source.open('rb') as incoming,target.open('xb') as outgoing:
            os.chmod(target,0o600)
            shutil.copyfileobj(incoming,outgoing)
            outgoing.flush(); os.fsync(outgoing.fileno())
    inventory={}
    for item in sorted(destination.iterdir()):
        digest=hashlib.sha256()
        with item.open('rb') as data:
            for block in iter(lambda:data.read(1024*1024),b''): digest.update(block)
        inventory[item.name]={'sha256':digest.hexdigest(),'bytes':item.stat().st_size}
    manifest=destination/'manifest.json'
    with manifest.open('x',encoding='utf-8') as output:
        os.chmod(manifest,0o600)
        json.dump({'created_at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'database':'secure_payroll',
                   'files':inventory,'restoration_tested':False},output,indent=2)
        output.flush(); os.fsync(output.fileno())
    marker=destination/'EXPORT_COMPLETE'
    with marker.open('x') as output:
        os.chmod(marker,0o600); output.write('Export completed. Restoration not tested.\n'); output.flush(); os.fsync(output.fileno())
    print('Export and log copy complete. No source data/log removed. A restoration rehearsal is still required.')
    return 0


if __name__=='__main__':
    try:
        sys.exit(main())
    except (RuntimeError,OSError,KeyboardInterrupt) as error:
        print(str(error) if isinstance(error,RuntimeError) else 'Backup interrupted/unavailable; retain partial artifacts.',file=sys.stderr)
        sys.exit(1)
