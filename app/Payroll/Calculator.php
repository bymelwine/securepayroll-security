<?php

declare(strict_types=1);

namespace App\Payroll;

/** Pure calculation only. Persistence must load and lock approved salary sources. */
final class Calculator
{
    public const VERSION = 'calendar-days-v1';

    /**
     * Each calendar day has exactly one approved salary source; unpaid days earn zero.
     * Caps are explicit absolute MAD amounts for the whole period, never implicit defaults.
     * @param array<array{date:string, salary_condition_id:int, monthly_amount:string, currency:string, paid:bool}> $days
     */
    public static function calculate(string $month, array $days, string $bonus, string $deduction,
        string $bonusCap, string $deductionCap): array
    {
        if (PHP_INT_SIZE < 8 || !preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/D', $month)
            || (int) substr($month, 0, 4) < 1900) {
            throw new \InvalidArgumentException('Invalid monthly period.');
        }
        $start = new \DateTimeImmutable($month . '-01', new \DateTimeZone('UTC'));
        $count = (int) $start->format('t');
        $seen = [];
        $numerator = 0;
        $sources = [];
        foreach ($days as $day) {
            if (!is_array($day) || !is_string($day['date'] ?? null)
                || !is_int($day['salary_condition_id'] ?? null) || $day['salary_condition_id'] <= 0
                || ($day['currency'] ?? null) !== 'MAD' || !is_bool($day['paid'] ?? null)
                || !is_string($day['monthly_amount'] ?? null)) {
                throw new \InvalidArgumentException('Invalid approved salary source.');
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day['date'], new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d') !== $day['date'] || $date->format('Y-m') !== $month
                || isset($seen[$day['date']])) {
                throw new \InvalidArgumentException('Overlapping or invalid calendar day.');
            }
            $seen[$day['date']] = true;
            $amount = self::cents($day['monthly_amount']);
            if ($amount === 0) {
                throw new \InvalidArgumentException('Salary must be positive.');
            }
            $id = $day['salary_condition_id'];
            if (isset($sources[$id]) && $sources[$id] !== $day['monthly_amount']) {
                throw new \InvalidArgumentException('Inconsistent salary version.');
            }
            $sources[$id] = $day['monthly_amount'];
            if ($day['paid']) {
                $numerator += $amount;
            }
        }
        $bonusCents = self::cents($bonus);
        $deductionCents = self::cents($deduction);
        if ($bonusCents > self::cents($bonusCap) || $deductionCents > self::cents($deductionCap)
            || ($days === [] && ($bonusCents > 0 || $deductionCents > 0))) {
            throw new \InvalidArgumentException('Adjustment exceeds policy.');
        }
        // Round half up once after summing day fractions, never day by day.
        $base = intdiv($numerator + intdiv($count, 2), $count);
        $gross = $base + $bonusCents;
        if ($deductionCents > $gross || $gross > 999999999999) {
            throw new \InvalidArgumentException('Invalid payroll total.');
        }
        ksort($sources);
        usort($days, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
        return [
            'algorithm' => self::VERSION, 'month' => $month, 'currency' => 'MAD',
            'calendar_days' => $count, 'covered_days' => count($days),
            'paid_days' => count(array_filter($days, static fn(array $day): bool => $day['paid'])),
            'base' => self::decimal($base), 'bonus' => self::decimal($bonusCents),
            'deduction' => self::decimal($deductionCents), 'gross' => self::decimal($gross),
            'net' => self::decimal($gross - $deductionCents),
            'inputs' => ['days' => $days, 'bonus_cap' => $bonusCap, 'deduction_cap' => $deductionCap],
            'salary_sources' => $sources,
        ];
    }

    private static function cents(string $amount): int
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D', $amount)) {
            throw new \InvalidArgumentException('Expected exact decimal amount.');
        }
        return (int) str_replace('.', '', $amount);
    }

    private static function decimal(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
