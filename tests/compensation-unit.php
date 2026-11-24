<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Auth\AuthService;
use App\Compensation\CompensationValidation;
use App\Finance\Money;
$count = 0;
function verify(bool $ok): void { global $count; if (!$ok) { throw new RuntimeException('Compensation assertion failed.'); } ++$count; }
foreach (['12000,00' => '12000.00', '001.5' => '1.50', '9999999999.99' => '9999999999.99'] as $input => $expected) {
    verify(CompensationValidation::amount($input) === $expected);
}
foreach (['0', '-1', '1e4', '1.001', '10000000000', "12\n", '1 OR 1=1'] as $input) {
    verify(CompensationValidation::amount($input) === null);
}
foreach (['employee', 'hr_manager', 'payroll_manager', 'finance_manager', 'application_administrator', 'security_auditor'] as $role) {
    $user = ['roles' => [$role => $role], 'permissions' => ['compensation.read', 'compensation.submit', 'compensation.decide'], 'compensation_conflict' => false];
    verify(AuthService::can($user, 'compensation.submit') === ($role === 'hr_manager'));
    verify(AuthService::can($user, 'compensation.decide') === ($role === 'finance_manager'));
    verify(AuthService::can($user, 'compensation.read') === in_array($role, ['hr_manager','finance_manager'], true));
    $user['compensation_conflict'] = true;
    verify(!AuthService::can($user, 'compensation.read'));
    verify(!AuthService::can($user, 'compensation.decide'));
}
verify(!AuthService::can(['roles' => ['hr_manager' => 'HR'], 'permissions' => ['compensation.submit']], 'compensation.submit'));
$_SESSION = ['language' => 'fr'];
verify(Money::format('12000.00', 'MAD') === "12\u{202F}000,00 MAD");
verify(Money::format('12000.00', 'UM') === "12\u{202F}000,00 UM");
$_SESSION['language'] = 'en';
verify(Money::format('12000.00', 'MAD') === '12,000.00 MAD');
$fr = require dirname(__DIR__) . '/lang/fr.php';
$en = require dirname(__DIR__) . '/lang/en.php';
verify(array_diff_key($fr, $en) === [] && array_diff_key($en, $fr) === []);
echo "$count compensation checks passed; no SQL workflow exercised.\n";
