<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
use App\Database\Connection;
$count=0;
function verify(bool $ok):void{global $count;if(!$ok){fwrite(STDERR,"Socket configuration assertion failed.\n");exit(1);}++$count;}
foreach(['DB_HOST'=>'127.0.0.1','DB_PORT'=>'3306','DB_NAME'=>'secure_payroll','DB_USER'=>'svc_php','DB_PASSWORD'=>bin2hex(random_bytes(16))] as $key=>$value){putenv($key.'='.$value);}
putenv('DB_SOCKET=/tmp/securepayroll-mysql-config/mysql.sock');Connection::validateConfiguration();verify(true);
foreach(['relative.sock','/tmp/a;host=127.0.0.1','/tmp/../main.sock',str_repeat('/a',60)] as $socket){
    putenv('DB_SOCKET='.$socket);$blocked=false;try{Connection::validateConfiguration();}catch(RuntimeException){$blocked=true;}verify($blocked);
}
putenv('DB_SOCKET=/tmp/securepayroll-mysql-config/mysql.sock');putenv('DB_USER=root');
$blocked=false;try{Connection::validateConfiguration();}catch(RuntimeException){$blocked=true;}verify($blocked);
putenv('APP_RUNTIME_DIR=/tmp');$blocked=false;try{App\Security\Runtime::directory();}catch(RuntimeException){$blocked=true;}verify($blocked);
echo "$count socket/runtime configuration checks passed; no connection made.\n";
