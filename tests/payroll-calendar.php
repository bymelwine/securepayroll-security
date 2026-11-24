<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Payroll\Calendar;
use App\Payroll\Calculator;
$count = 0;
function verify(bool $ok): void { global $count; if (!$ok) { throw new RuntimeException('Calendar assertion failed.'); } ++$count; }
function refuses(callable $action): void { try { $action(); } catch (InvalidArgumentException) { verify(true); return; } verify(false); }
$contract = ['id' => 10, 'employee_id' => 1, 'starts_on' => '2026-11-03', 'effective_ends_on' => null];
$salary = ['id' => 20, 'employee_id' => 1, 'contract_id' => 10, 'effective_on' => '2026-11-03',
    'monthly_amount' => '12000.00', 'currency' => 'MAD', 'decision_id' => 30, 'decision' => 'approved'];
$build = static fn(array $c, array $s, array $a = []) => Calendar::build(1, '2026-11', $c, $s, $a);
$days = $build([$contract], [$salary]);
verify(count($days) === 28 && $days[0]['date'] === '2026-11-03' && $days[27]['date'] === '2026-11-30');
verify(Calculator::calculate('2026-11', $days, '0.00', '0.00', '0.00', '0.00')['net'] === '11200.00');
$leave = ['id' => 40, 'employee_id' => 1, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-12', 'paid' => false];
$days = $build([$contract], [$salary], [$leave]);
verify(count(array_filter($days, static fn(array $d): bool => !$d['paid'])) === 3);
verify(Calculator::calculate('2026-11', $days, '0.00', '0.00', '0.00', '0.00')['net'] === '10000.00');
verify($build([$contract], [$salary], [array_replace($leave, ['paid' => true])]) === $build([$contract], [$salary]));
verify(count($build([array_replace($contract, ['effective_ends_on' => '2026-11-15'])], [$salary])) === 13);
refuses(fn() => $build([$contract], []));
foreach (['pending', 'rejected'] as $decision) { refuses(fn() => $build([$contract], [array_replace($salary, ['decision' => $decision])])); }
foreach ([['employee_id' => 2], ['decision_id' => 0], ['currency' => 'UM'], ['effective_on' => '2026-11-04'], ['contract_id' => 11], ['monthly_amount' => '0.00']] as $change) {
    refuses(fn() => $build([$contract], [array_replace($salary, $change)]));
}
refuses(fn() => $build([$contract, array_replace($contract, ['id' => 11])], [$salary]));
refuses(fn() => $build([$contract], [$salary, array_replace($salary, ['id' => 21])]));
refuses(fn() => $build([$contract], [$salary], [$leave, array_replace($leave, ['id' => 41])]));
refuses(fn() => $build([$contract], [$salary], [array_replace($leave, ['starts_on' => '2026-11-01'])]));
refuses(fn() => $build([$contract], [$salary], [array_replace($leave, ['paid' => 'false'])]));
verify($build([], []) === []);
$old = array_replace($salary, ['id' => 19, 'effective_on' => '2026-10-01', 'currency' => 'UM']);
$earlyContract = array_replace($contract, ['starts_on' => '2026-10-01']);
$new = array_replace($salary, ['effective_on' => '2026-11-01']);
verify(count($build([$earlyContract], [$new, $old])) === 30);
$next = array_replace($salary, ['id' => 22, 'effective_on' => '2026-12-01', 'monthly_amount' => '13000.00']);
verify($build([$contract], [$salary, $next]) === $build([$contract], [$salary]));
echo "$count calendar checks passed; trusted snapshots only, no SQL approval proof.\n";
