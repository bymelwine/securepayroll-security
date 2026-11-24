<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Payroll\Calculator;
$count = 0;
function check(bool $ok): void { global $count; if (!$ok) { throw new RuntimeException('Payroll assertion failed.'); } ++$count; }
function rejects(callable $call): void {
    try { $call(); } catch (InvalidArgumentException) { check(true); return; }
    check(false);
}
$days = [];
for ($i = 1; $i <= 30; ++$i) {
    $days[] = ['date' => sprintf('2026-11-%02d', $i), 'salary_condition_id' => 1,
        'monthly_amount' => '12000.00', 'currency' => 'MAD', 'paid' => true];
}
$calc = static fn(array $input, string $bonus = '0.00', string $deduction = '0.00') => Calculator::calculate('2026-11', $input, $bonus, $deduction, '500.00', '500.00');
check($calc($days)['net'] === '12000.00');
check($calc(array_slice($days, 2))['net'] === '11200.00');
$days[0]['paid'] = false;
check($calc($days)['base'] === '11600.00');
check($calc($days, '500.00', '100.00')['net'] === '12000.00');
rejects(fn() => $calc($days, '500.01'));
rejects(fn() => $calc($days, '0.00', '500.01'));
rejects(fn() => $calc([$days[0], $days[0]]));
rejects(fn() => $calc([array_replace($days[0], ['currency' => 'UM'])]));
rejects(fn() => $calc([array_replace($days[0], ['salary_condition_id' => 0])]));
rejects(fn() => $calc([array_replace($days[0], ['date' => '2026-11-31'])]));
rejects(fn() => $calc([], '1.00'));
rejects(fn() => $calc($days, '-1.00'));
rejects(fn() => $calc($days, '1e2'));
rejects(fn() => $calc(array_slice($days, 1, 1), '0.00', '401.00'));
$feb = [['date' => '2028-02-29', 'salary_condition_id' => 1, 'monthly_amount' => '100.00', 'currency' => 'MAD', 'paid' => true]];
check(Calculator::calculate('2028-02', $feb, '0.00', '0.00', '0.00', '0.00')['net'] === '3.45');
check($calc([])['net'] === '0.00');
check($calc(array_reverse($days)) === $calc($days));
echo "$count payroll checks passed.\n";
