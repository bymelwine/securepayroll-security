<?php

declare(strict_types=1);
namespace App\Web;
use App\Auth\AuthService;
use App\Auth\IAMRepository;
use App\Compensation\CompensationValidation;
use App\Payroll\Validation;
use PDO;
use PDOException;
final class IAMController
{
    public function __construct(private PDO $db) {}
    public static function matches(string $path): bool
    { return (bool)preg_match('~\A/iam(?:/grant|/[1-9][0-9]{0,17}/revoke)?\z~D',$path); }
    public static function methods(string $path): array { return $path==='/iam' ? ['GET'] : ['POST']; }
    private function render(string $view,array $data,int $status=200): void
    { http_response_code($status);extract($data,EXTR_SKIP);require dirname(__DIR__).'/Views/'.$view.'.php'; }
    public function handle(string $path,string $method,array $data): void
    {
        $data+=['section'=>'iam','title'=>t('iam.title')];
        if (getenv('IAM_ENABLED')!=='1') { $this->render('error',$data+['message'=>t('errors.not_found_message')],404);return; }
        $permission=$method==='GET' ? 'iam.read' : ($path==='/iam/grant' ? 'iam.grant' : 'iam.revoke');
        $actor=(int)$data['user']['account_id'];
        if (!AuthService::can($data['user'],$permission)) {
            auditEvent('iam.access_denied',$actor);
            $this->render('error',$data+['message'=>t('pay.forbidden')],403);return;
        }
        try {
            $repository=new IAMRepository($this->db);
            if ($method==='GET') {
                $page=filter_var($_GET['page']??'1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]])?:1;
                $this->render('iam',$data+$repository->overview($actor,$page)+['page'=>$page]);return;
            }
            $reason=trim(field($_POST,'reason'));
            if (!CompensationValidation::text($reason,255)) { throw new \InvalidArgumentException(); }
            if ($path==='/iam/grant') {
                $account=Validation::id(field($_POST,'account'));$role=field($_POST,'role');
                if ($account===null || !in_array($role,['employee','hr_manager','payroll_manager','finance_manager','security_auditor','application_administrator'],true)) { throw new \InvalidArgumentException(); }
                $repository->grant($account,$role,$reason,$actor);$id=$account;
            } else { $id=(int)explode('/',$path)[2];$repository->revoke($id,$reason,$actor); }
            auditEvent($permission,$actor,null,$id);$_SESSION['flash']='iam.saved';redirectTo('/iam');
        } catch (\InvalidArgumentException) { $this->render('error',$data+['message'=>t('pay.invalid')],422); }
        catch (PDOException $error) {
            $message=(string)($error->errorInfo[2]??'');$status=503;$key='pay.unavailable';
            if (str_contains($message,'FORBIDDEN')) { $status=403;$key='pay.forbidden'; }
            elseif (str_contains($message,'IAM_INVALID')) { $status=422;$key='pay.invalid'; }
            elseif (str_contains($message,'IAM_CONFLICT')) { $status=409;$key='iam.conflict'; }
            elseif (str_contains($message,'IAM_STATE') || in_array((int)($error->errorInfo[1]??0),[1062,1205,1213],true)) { $status=409;$key='pay.conflict'; }
            auditEvent('iam.operation_failed',$actor);
            $this->render('error',$data+['message'=>t($key)],$status);
        }
    }
}
