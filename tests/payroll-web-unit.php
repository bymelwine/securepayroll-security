<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Web/helpers.php';
use App\Auth\AuthService;
use App\Payroll\Validation;
use App\Web\PayrollController;
$count=0;
function verify(bool $ok): void { global $count; if (!$ok) { fwrite(STDERR,'Payroll web assertion failed at check '.($count+1).PHP_EOL); exit(1); } ++$count; }
$expected = ['hr_manager'=>['payroll.read','payroll.input.submit'], 'payroll_manager'=>['payroll.read','payroll.calculate','payslip.verify','payslip.publish'],
 'finance_manager'=>['payroll.read','payroll.period.create','payroll.input.decide','payslip.decide'], 'security_auditor'=>['payroll.read'],
 'employee'=>['payslip.self'], 'application_administrator'=>[]];
$permissions=array_values(array_unique(array_merge(...array_values($expected))));
foreach ($expected as $role=>$allowed) {
    $user=['account_id'=>2,'employee_id'=>2,'first_name'=>'Demo <script>','last_name'=>'Example','employee_number'=>'DEMO-UNIT',
        'roles'=>[$role=>$role],'permissions'=>$permissions,'payroll_conflict'=>false,'compensation_conflict'=>false];
    foreach ($permissions as $permission) { verify(AuthService::can($user,$permission)===in_array($permission,$allowed,true)); }
    $user['payroll_conflict']=true;
    foreach ($permissions as $permission) { if ($permission!=='payslip.self') { verify(!AuthService::can($user,$permission)); } }
}
foreach (['/payroll','/payroll/periods','/payroll/inputs','/payroll/calculate','/payroll/inputs/1/decision','/payslips/1','/payslips/1/transition'] as $path) { verify(PayrollController::matches($path)); }
foreach (['/payslips/0','/payslips/-1','/payroll/inputs/1','/payslips/1/edit','/payroll/unknown','/payslips/1/transition/extra'] as $path) { verify(!PayrollController::matches($path)); }
verify(Validation::amount('0,00')==='0.00');
verify(Validation::amount('1.001')===null);
verify(!Validation::month('2026-13'));
verify(Validation::month('2028-02'));
verify(Validation::id('1 OR 1=1')===null);
$values=['period_id'=>'1','employee_id'=>'1','kind'=>'unpaid_leave','amount'=>'0','starts_on'=>'2026-11-03','ends_on'=>'2026-11-10','reason'=>'Demo'];
verify(Validation::input($values));
verify(!Validation::input(array_replace($values,['amount'=>'10'])));
verify(!Validation::input(array_replace($values,['ends_on'=>'2026-11-02'])));
$user=['account_id'=>2,'employee_id'=>2,'first_name'=>'<script>alert(1)</script>','last_name'=>'Demo','employee_number'=>'DEMO-UNIT',
    'roles'=>['payroll_manager'=>'Payroll Manager'],'permissions'=>$expected['payroll_manager'],'payroll_conflict'=>false,'compensation_conflict'=>false];
$canRead=$canCreate=$canUpdate=false; $flash=null; $section='payroll'; $page=1;
$_SESSION=['csrf'=>str_repeat('a',64)]; $_SERVER['REQUEST_URI']='/payroll';
$period=['id'=>1,'month_start'=>'2026-11-01','bonus_cap'=>'500.00','deduction_cap'=>'500.00','reason'=>'<script>'];
$calculation=['id'=>1,'period_id'=>1,'employee_id'=>1,'version_no'=>1,'currency'=>'MAD','base_amount'=>'12000.00','bonus_amount'=>'0.00','deduction_amount'=>'0.00','gross_amount'=>'12000.00','net_amount'=>'12000.00','prepared_at'=>'2026-11-04 12:00:00',
    'month_start'=>'2026-11-01','employee_number'=>'DEMO-UNIT','first_name'=>'<script>','last_name'=>'Demo','status'=>'prepared'];
$result=['periods'=>[$period],'employees'=>[['id'=>1,'employee_number'=>'DEMO-UNIT','first_name'=>'<script>','last_name'=>'Demo']], 'inputs'=>[], 'calculations'=>[$calculation]];
$row=$calculation+['snapshot'=>['days'=>[['date'=>'2026-11-01','monthly_amount'=>'12000.00','paid'=>1]]],'preparer_employee_id'=>2];
$events=[]; $versions=[['id'=>1,'version_no'=>1,'net_amount'=>'12000.00','currency'=>'MAD']];
foreach (['fr','en'] as $language) {
    $_SESSION['language']=$language;
    foreach (['payroll','payslip'] as $view) {
        ob_start(); require dirname(__DIR__).'/app/Views/'.$view.'.php'; $html=ob_get_clean();
        verify(str_contains($html,'<html lang="'.$language.'">'));
        verify(!str_contains($html,'<script>alert(1)</script>') && str_contains($html,'&lt;script&gt;'));
        verify(str_contains($html,'MAD'));
    }
}
$fr=require dirname(__DIR__).'/lang/fr.php'; $en=require dirname(__DIR__).'/lang/en.php';
verify(array_diff_key($fr,$en)===[] && array_diff_key($en,$fr)===[]);
foreach (['payroll','payslip','payroll-select'] as $view) {
    preg_match_all("/t\('([^']+)'/",file_get_contents(dirname(__DIR__).'/app/Views/'.$view.'.php'),$keys);
    foreach ($keys[1] as $key) { if (!str_ends_with($key,'.') && !str_ends_with($key,'_')) { verify(isset($fr[$key]) && isset($en[$key])); } }
}
echo "$count payroll authorization, validation and rendering checks passed.\n";
