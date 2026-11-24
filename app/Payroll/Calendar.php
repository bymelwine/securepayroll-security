<?php

declare(strict_types=1);

namespace App\Payroll;

use App\HR\PeriodValidation;

/** Internal adapter for trusted database snapshots, never for request payloads. */
final class Calendar
{
    /**
     * Contracts: id, employee_id, starts_on, effective_ends_on (nullable).
     * Conditions: id, employee_id, contract_id, effective_on, monthly_amount,
     * currency, decision_id, decision ('approved' required).
     * Suspensions: id, employee_id, starts_on, ends_on, paid (boolean).
     * All bounds are inclusive. Missing salary on a contracted day is an error.
     */
    public static function build(int $employee, string $month, array $contracts,
        array $conditions, array $suspensions = []): array
    {
        if ($employee < 1 || !preg_match('/\A[0-9]{4}-(?:0[1-9]|1[0-2])\z/D', $month)
            || !PeriodValidation::date($month . '-01')) {
            throw new \InvalidArgumentException('Invalid employee or monthly period.');
        }
        $contractMap = [];
        foreach ($contracts as $contract) {
            self::identity($contract, $employee);
            self::period($contract, 'effective_ends_on', true);
            if (isset($contractMap[$contract['id']])) {
                throw new \InvalidArgumentException('Duplicate contract.');
            }
            $contractMap[$contract['id']] = $contract;
        }
        $versions = [];
        $ids = [];
        foreach ($conditions as $condition) {
            self::identity($condition, $employee);
            if (!is_int($condition['contract_id'] ?? null)
                || !isset($contractMap[$condition['contract_id']])
                || !is_int($condition['decision_id'] ?? null) || $condition['decision_id'] < 1
                || ($condition['decision'] ?? null) !== 'approved'
                || !is_string($condition['effective_on'] ?? null)
                || !PeriodValidation::date($condition['effective_on'])
                || !is_string($condition['monthly_amount'] ?? null)
                || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D', $condition['monthly_amount'])
                || $condition['monthly_amount'] === '0.00'
                || !in_array($condition['currency'] ?? null, ['MAD', 'UM'], true)
                || isset($ids[$condition['id']])) {
                throw new \InvalidArgumentException('Invalid approved salary condition.');
            }
            $contract = $contractMap[$condition['contract_id']];
            $date = $condition['effective_on'];
            if ($date < $contract['starts_on']
                || ($contract['effective_ends_on'] !== null && $date > $contract['effective_ends_on'])
                || isset($versions[$contract['id']][$date])) {
                throw new \InvalidArgumentException('Invalid salary coverage.');
            }
            $ids[$condition['id']] = true;
            $versions[$contract['id']][$date] = $condition;
        }
        foreach ($versions as &$list) { ksort($list); }
        unset($list);
        $suspensionIds = [];
        foreach ($suspensions as $suspension) {
            self::identity($suspension, $employee);
            self::period($suspension, 'ends_on', false);
            if (!is_bool($suspension['paid'] ?? null) || isset($suspensionIds[$suspension['id']])) {
                throw new \InvalidArgumentException('Invalid suspension.');
            }
            $suspensionIds[$suspension['id']] = true;
        }
        $days = [];
        $start = new \DateTimeImmutable($month . '-01', new \DateTimeZone('UTC'));
        $limit = (int) $start->format('t');
        for ($i = 0; $i < $limit; ++$i) {
            $date = $start->modify('+' . $i . ' days')->format('Y-m-d');
            $active = array_filter($contractMap, static fn(array $c): bool => $c['starts_on'] <= $date
                && ($c['effective_ends_on'] === null || $c['effective_ends_on'] >= $date));
            $suspended = array_filter($suspensions, static fn(array $s): bool => $s['starts_on'] <= $date && $s['ends_on'] >= $date);
            if (count($active) > 1 || count($suspended) > 1) {
                throw new \InvalidArgumentException('Overlapping payroll source periods.');
            }
            if ($active === []) {
                if ($suspended !== []) {
                    throw new \InvalidArgumentException('Suspension outside contract.');
                }
                continue;
            }
            $contract = reset($active);
            $selected = null;
            foreach ($versions[$contract['id']] ?? [] as $effective => $version) {
                if ($effective > $date) { break; }
                $selected = $version;
            }
            if ($selected === null || $selected['currency'] !== 'MAD') {
                throw new \InvalidArgumentException('Missing approved MAD salary coverage.');
            }
            $suspension = $suspended === [] ? null : reset($suspended);
            $days[] = ['date' => $date, 'salary_condition_id' => $selected['id'],
                'monthly_amount' => $selected['monthly_amount'], 'currency' => $selected['currency'],
                'paid' => $suspension === null || $suspension['paid']];
        }
        return $days;
    }

    private static function identity(mixed $row, int $employee): void
    {
        if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] < 1
            || ($row['employee_id'] ?? null) !== $employee) {
            throw new \InvalidArgumentException('Invalid source identity.');
        }
    }

    private static function period(array $row, string $endKey, bool $nullable): void
    {
        if (!is_string($row['starts_on'] ?? null) || !PeriodValidation::date($row['starts_on'])
            || !array_key_exists($endKey, $row)
            || !($nullable && $row[$endKey] === null)
                && (!is_string($row[$endKey]) || !PeriodValidation::date($row[$endKey]) || $row[$endKey] < $row['starts_on'])) {
            throw new \InvalidArgumentException('Invalid source period.');
        }
    }
}
