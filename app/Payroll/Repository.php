<?php

declare(strict_types=1);

namespace App\Payroll;

use PDO;

/** All new data access passes through actor-scoped SQL procedures. */
final class Repository
{
    public function __construct(private PDO $db) {}

    public function overview(int $actor, int $page): array
    {
        $sets = $this->call('CALL payroll_overview(?,?)', [$actor, $page]);
        return ['periods' => $sets[0] ?? [], 'employees' => $sets[1] ?? [],
            'inputs' => $sets[2] ?? [], 'calculations' => $sets[3] ?? []];
    }

    public function detail(int $id, int $actor): array
    {
        $sets = $this->call('CALL payslip_detail(?,?)', [$id, $actor]);
        if (!isset($sets[0][0])) { throw new \UnexpectedValueException('Missing payslip.'); }
        $row = $sets[0][0];
        $row['snapshot'] = json_decode($row['snapshot'], true, 32, JSON_THROW_ON_ERROR);
        return ['row' => $row, 'events' => $sets[1] ?? [], 'versions' => $sets[2] ?? []];
    }

    public function createPeriod(string $month, string $bonusCap, string $deductionCap, string $reason, int $actor): int
    {
        return $this->command('CALL payroll_period_create(?,?,?,?,?)', [$month . '-01', $bonusCap, $deductionCap, $reason, $actor]);
    }

    public function submitInput(int $period, int $employee, string $kind, string $amount, string $start, string $end, string $reason, int $actor): int
    {
        return $this->command('CALL payroll_input_submit(?,?,?,?,?,?,?,?)', [$period, $employee, $kind, $amount, $start, $end, $reason, $actor]);
    }

    public function decideInput(int $id, string $decision, string $reason, int $actor): int
    {
        return $this->command('CALL payroll_input_decide(?,?,?,?)', [$id, $decision, $reason, $actor]);
    }

    public function calculate(int $period, int $employee, int $actor): int
    {
        return $this->command('CALL payroll_calculate(?,?,?)', [$period, $employee, $actor]);
    }

    public function transition(int $id, string $event, string $reason, int $actor): int
    {
        return $this->command('CALL payslip_transition(?,?,?,?)', [$id, $event, $reason, $actor]);
    }

    public function settlement(int $root, int $actor): array
    {
        $sets = $this->call('CALL settlement_detail(?,?)', [$root, $actor]);
        if (!isset($sets[0][0])) { throw new \UnexpectedValueException('Missing settlement.'); }
        return ['summary' => $sets[0][0], 'corrections' => $sets[1] ?? [],
            'correctionEvents' => $sets[2] ?? [], 'ledger' => $sets[3] ?? [],
            'recoveries' => $sets[4] ?? [], 'recoveryEvents' => $sets[5] ?? []];
    }

    public function settle(array $parameters): int
    {
        return $this->command('CALL settlement_command(?,?,?,?,?,?,?,?,?,?)', $parameters);
    }

    private function command(string $sql, array $parameters): int
    {
        $sets = $this->call($sql, $parameters);
        $id = (int) ($sets[0][0]['id'] ?? 0);
        if ($id < 1) { throw new \UnexpectedValueException('Missing operation result.'); }
        return $id;
    }

    private function call(string $sql, array $parameters): array
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Transaction already active.'); }
        $query = $this->db->prepare($sql);
        $failure = null;
        try {
            $query->execute($parameters);
            $sets = [];
            do {
                if ($query->columnCount() > 0) { $sets[] = $query->fetchAll(); }
            } while ($query->nextRowset());
            return $sets;
        } catch (\Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            try { $query->closeCursor(); } catch (\Throwable $cleanup) {
                if ($failure === null) { throw $cleanup; }
            }
        }
    }
}
