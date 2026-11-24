<?php

declare(strict_types=1);

namespace App\Web;

use App\Auth\AuthService;
use App\Compensation\CompensationRepository;
use App\HR\EmployeeRepository;
use App\Compensation\CompensationValidation;
use PDO;
use PDOException;

final class CompensationController
{
    public function __construct(private PDO $db) {}

    public static function matches(string $path): bool
    {
        return (bool) preg_match('~\A/compensation(?:/new|/employees|/pending|/history|/[1-9][0-9]{0,17}(?:/decision)?)?\z~D', $path);
    }

    public static function methods(string $path): array
    {
        return str_ends_with($path, '/decision') ? ['POST'] : ($path === '/compensation/new' ? ['GET','POST'] : ['GET']);
    }

    private function render(string $view, array $data, int $status = 200): void
    {
        http_response_code($status);
        extract($data, EXTR_SKIP);
        require dirname(__DIR__) . '/Views/' . $view . '.php';
    }

    public function handle(string $path, string $method, array $data): void
    {
        $user = $data['user'];
        $new = $path === '/compensation/new';
        $deciding = str_ends_with($path, '/decision');
        if (!AuthService::can($user, 'compensation.read')
            || ($new && !AuthService::can($user, 'compensation.submit'))
            || ($deciding && !AuthService::can($user, 'compensation.decide'))) {
            auditEvent('compensation.access_denied', (int) $user['account_id']);
            $this->render('error', $data + ['title'=>t('errors.denied_title'),'message'=>t('errors.denied_message')], 403);
            return;
        }
        $repo = new CompensationRepository($this->db);
        $data += ['section'=>'compensation', 'title'=>t('comp.title'),
            'canSubmit'=>AuthService::can($user,'compensation.submit'),
            'canDecide'=>AuthService::can($user,'compensation.decide')];
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>1000000]]) ?: 1;
        if ($new) {
            if (!$repo->referenceCurrencyReady()) {
                $this->render('error',$data+['message'=>t('comp.currency_activation')],503);
                return;
            }
            $values = ['employee_number'=>trim(field($_GET,'employee_number')), 'reference'=>trim(field($_GET,'reference')),
                'monthly_amount'=>'', 'effective_on'=>trim(field($_GET,'effective_on')) ?: gmdate('Y-m-d'), 'justification'=>''];
            $errors=[]; $status=200;
            if ($method === 'POST') {
                foreach (array_keys($values) as $key) { $values[$key]=trim(field($_POST,$key)); }
                $errors=CompensationValidation::errors($values);
                if ($errors === []) {
                    $contract=$repo->contract($values['employee_number'],$values['reference']);
                    if ($contract === null) { $errors['reference']='comp.invalid_contract'; }
                    else {
                        $values['monthly_amount']=CompensationValidation::amount($values['monthly_amount']);
                        try {
                            $id=$repo->submit($values,$contract,(int)$user['account_id']);
                            auditEvent('compensation.submitted',(int)$user['account_id'],(int)$contract['employee_id']);
                            $_SESSION['flash']='comp.submitted';
                            redirectTo('/compensation/'.$id);
                        } catch (PDOException $error) { [$errors['form'],$status]=$this->databaseError($error); }
                    }
                }
            }
            // Employé choisi dans un menu déroulant des actifs ; la référence du contrat reste saisie.
            $employees=(new EmployeeRepository($this->db))->activeOptions();
            $this->render('compensation-form',$data+compact('values','errors','employees'),$errors !== [] && $status===200 ? 422 : $status);
            return;
        }
        if (preg_match('~\A/compensation/([1-9][0-9]{0,17})(?:/decision)?\z~D',$path,$matches)) {
            $row=$repo->find((int)$matches[1]);
            if ($row === null) {
                $this->render('error',$data+['title'=>t('errors.not_found_title'),'message'=>t('errors.not_found_message')],404);
                return;
            }
            $errors=[]; $status=200; $reason=trim(field($_POST,'reason'));
            $independent = (int)$row['requester_employee_id'] !== (int)$user['employee_id']
                && (int)$row['employee_id'] !== (int)$user['employee_id'];
            if ($deciding) {
                $decision=field($_POST,'decision');
                if ($decision==='approved' && $row['currency']!==\App\Finance\Money::REFERENCE_CURRENCY) { $errors['form']='comp.currency_review'; $status=409; }
                elseif (!$independent) { $errors['form']='comp.self_approval'; $status=403; }
                elseif (!in_array($decision,['approved','rejected'],true) || !CompensationValidation::text($reason,2000)) {
                    $errors['form']='comp.invalid_reason'; $status=422;
                } else {
                    try {
                        $repo->decide((int)$row['id'],$decision,$reason,(int)$user['account_id']);
                        auditEvent('compensation.'.$decision,(int)$user['account_id'],(int)$row['employee_id']);
                        $_SESSION['flash']='comp.decided';
                        redirectTo('/compensation/'.$row['id']);
                    } catch (PDOException $error) { [$errors['form'],$status]=$this->databaseError($error); }
                }
            }
            $events=$repo->events((int)$row['id']);
            // Phase 7 : consultation d'une condition salariale (donnée sensible) tracée.
            auditEvent('salary.viewed',(int)$user['account_id'],(int)$row['employee_id'],(int)$row['id'],['table'=>'compensation_requests']);
            $this->render('compensation-detail',$data+compact('row','events','errors','reason','independent'),$status);
            return;
        }
        if ($path === '/compensation/employees') {
            $result=$repo->contractedEmployees($page); $mode='employees';
        } elseif ($path === '/compensation/history') {
            $employee=filter_var($_GET['employee_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: null;
            $result=$repo->history($page,$employee); $mode='history';
        } else {
            $filter=$path === '/compensation/pending' ? 'pending' : field($_GET,'status');
            if (!in_array($filter,['','pending','approved','rejected'],true)) { $filter=''; }
            $result=$repo->requests($filter,$page); $mode='requests';
        }
        $this->render('compensation-list',$data+compact('result','mode')+['filter'=>$filter ?? '']);
    }

    private function databaseError(PDOException $error): array
    {
        $code=(int)($error->errorInfo[1] ?? 0);
        if (in_array($code,[1062,1205,1213],true)) { return ['comp.conflict',409]; }
        if ($code === 1644) {
            $signals=['COMP_FORBIDDEN'=>'errors.denied_message','COMP_COVERAGE'=>'comp.coverage',
                'COMP_EFFECTIVE_DATE'=>'comp.effective_rule','COMP_INVALID'=>'comp.invalid_reason',
                'COMP_SELF_APPROVAL'=>'comp.self_approval','COMP_ALREADY_DECIDED'=>'comp.already_decided',
                'COMP_CURRENCY_REVIEW'=>'comp.currency_review', 'COMP_STALE'=>'comp.stale','COMP_NOT_FOUND'=>'errors.not_found_message'];
            $signal=$error->errorInfo[2] ?? '';
            if (isset($signals[$signal])) { return [$signals[$signal],$signal==='COMP_FORBIDDEN' ? 403 : 409]; }
        }
        // Uniquement des codes techniques : aucun paramètre, montant, texte SQL ou trace.
        $state=$error->errorInfo[0] ?? '';
        error_log(json_encode(['operation'=>'compensation.write','mysql_code'=>$code,
            'sqlstate'=>is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/D',$state) ? $state : null],JSON_THROW_ON_ERROR));
        throw $error;
    }
}
