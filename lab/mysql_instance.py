"""Private instance lifecycle for authorized lab recovery; no system/default connections."""
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time


class PrivateInstance:
    def __init__(self,existing=None,temporary_root='/tmp'):
        if os.geteuid()==0:raise RuntimeError('Use the regular OS user.')
        if temporary_root not in ('/tmp','/var/tmp'):raise RuntimeError('Unexpected temporary root.')
        self.private=Path(existing).resolve(strict=True) if existing else Path(tempfile.mkdtemp(prefix='securepayroll-mysql-',dir=temporary_root))
        if self.private.parent not in (Path('/tmp'),Path('/var/tmp')) or not re.fullmatch(r'securepayroll-mysql-[A-Za-z0-9_-]+',self.private.name) or self.private.stat().st_uid!=os.geteuid():
            raise RuntimeError('Invalid private instance directory.')
        if self.private.stat().st_mode & 0o077:raise RuntimeError('Instance directory must already be private.')
        self.data=self.private/'data'
        if self.data.exists() and self.data.resolve().parent!=self.private:raise RuntimeError('Unexpected datadir link.')
        self.socket=self.private/'mysql.sock'
        self.env={k:v for k,v in os.environ.items() if k not in {'MYSQL_PWD','DB_PASSWORD'}}
        self.server=None
    def client(self,user='root'):
        return ['mysql','--no-defaults','--no-login-paths','--protocol=socket','--socket='+str(self.socket),
                '--user='+user,'--default-character-set=utf8mb4','--batch','--raw','--skip-column-names','--binary-mode']
    def sql(self,statement):
        result=subprocess.run(self.client(),input="SET SESSION time_zone='+00:00';\n"+statement,text=True,capture_output=True,env=self.env,timeout=30)
        if result.returncode:
            codes=','.join(re.findall(r'ERROR (\d+)',result.stderr)) or '?'
            raise RuntimeError('Private recovery SQL error '+codes)
        return result.stdout.strip()
    def __enter__(self):
        if os.geteuid()==0:raise RuntimeError('Use the regular OS user.')
        if not self.data.exists():
            initialized=subprocess.run(['/usr/sbin/mysqld','--no-defaults','--initialize-insecure','--datadir='+str(self.data),
                                       '--log-error='+str(self.private/'initialize.log')],capture_output=True,env=self.env,timeout=90)
            if initialized.returncode:raise RuntimeError('Private restore initialization failed.')
        if self.socket.exists():raise RuntimeError('Private instance appears active; refuse duplicate start.')
        self.server=subprocess.Popen(['/usr/sbin/mysqld','--no-defaults','--persisted-globals-load=OFF','--datadir='+str(self.data),
                                      '--socket='+str(self.socket),'--pid-file='+str(self.private/'server.pid'),
                                      '--skip-networking','--mysqlx=OFF','--log-error='+str(self.private/'server.log'),
                                      '--innodb-buffer-pool-size=64M'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=self.env)
        deadline=time.monotonic()+45
        while not self.socket.exists():
            if self.server.poll() is not None or time.monotonic()>deadline:raise RuntimeError('Private recovery server unavailable.')
            time.sleep(.1)
        if Path(self.sql('SELECT @@datadir;')).resolve()!=self.data.resolve() or self.sql('SELECT @@skip_networking;')!='1':
            self.__exit__(None,None,None)
            raise RuntimeError('Private recovery isolation not proven.')
        return self
    def __exit__(self,*unused):
        if self.server is not None and self.server.poll() is None:
            self.sql('SHUTDOWN;')
            self.server.wait(timeout=45)
