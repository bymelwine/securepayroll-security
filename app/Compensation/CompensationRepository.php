<?php

declare(strict_types=1);

namespace App\Compensation;

use PDO;

final class CompensationRepository
{
    public function __construct(private PDO $db) {}

    /** Bloque les nouvelles demandes sur un schéma encore configuré pour UM. */
    public function referenceCurrencyReady(): bool
    {
        $query=$this->db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('compensation_requests','salary_conditions')
            AND COLUMN_NAME='currency' AND DATA_TYPE='char' AND CHARACTER_MAXIMUM_LENGTH=3 AND COLUMN_DEFAULT='MAD'");
        return (int)$query->fetchColumn()===2;
    }

    public function requests(string $status, int $page): array
    {
        $where = match ($status) {
            'pending' => 'd.id IS NULL',
            'approved', 'rejected' => 'd.decision = ?',
            default => '1=1',
        };
        $params = in_array($status, ['approved', 'rejected'], true) ? [$status] : [];
        $from = ' FROM compensation_requests q JOIN employees e ON e.id=q.employee_id
            JOIN employment_contracts c ON c.id=q.contract_id
            LEFT JOIN compensation_decisions d ON d.request_id=q.id WHERE ' . $where;
        $count = $this->db->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $page = max(1, min($page, max(1, (int) ceil($total / 20))));
        $query = $this->db->prepare("SELECT q.*,e.employee_number,e.first_name,e.last_name,c.reference,
            COALESCE(d.decision,'pending') AS status" . $from . ' ORDER BY q.id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20));
        $query->execute($params);
        return ['rows' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 20))];
    }

    public function find(int $id): ?array
    {
        $query = $this->db->prepare("SELECT q.*,e.employee_number,e.first_name,e.last_name,c.reference,
            c.starts_on,c.effective_ends_on, COALESCE(d.decision,'pending') AS status,
            d.reason AS decision_reason,d.decided_at,d.approver_employee_id,
            requester.first_name AS requester_first,requester.last_name AS requester_last,
            approver.first_name AS approver_first,approver.last_name AS approver_last
            FROM compensation_requests q JOIN employees e ON e.id=q.employee_id
            JOIN employment_contracts c ON c.id=q.contract_id
            JOIN employees requester ON requester.id=q.requester_employee_id
            LEFT JOIN compensation_decisions d ON d.request_id=q.id
            LEFT JOIN employees approver ON approver.id=d.approver_employee_id WHERE q.id=?");
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    public function events(int $request): array
    {
        $query = $this->db->prepare('SELECT h.event_type,h.occurred_at,e.first_name,e.last_name
            FROM compensation_history h JOIN accounts a ON a.id=h.actor_id JOIN employees e ON e.id=a.employee_id
            WHERE h.request_id=? ORDER BY h.id');
        $query->execute([$request]);
        return $query->fetchAll();
    }

    public function contract(string $number, string $reference): ?array
    {
        $query = $this->db->prepare('SELECT c.*,e.employee_number,e.first_name,e.last_name
            FROM employment_contracts c JOIN employees e ON e.id=c.employee_id
            WHERE e.employee_number=? AND c.reference=? AND e.archived_at IS NULL');
        $query->execute([$number, $reference]);
        return $query->fetch() ?: null;
    }

    public function contractedEmployees(int $page): array
    {
        $from = ' FROM employment_contracts c JOIN employees e ON e.id=c.employee_id WHERE e.archived_at IS NULL';
        $total = (int) $this->db->query('SELECT COUNT(*)' . $from)->fetchColumn();
        $page = max(1, min($page, max(1, (int) ceil($total / 20))));
        $rows = $this->db->query('SELECT c.*,e.employee_number,e.first_name,e.last_name' . $from
            . ' ORDER BY e.employee_number,c.starts_on DESC LIMIT 20 OFFSET ' . (($page - 1) * 20))->fetchAll();
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 20))];
    }

    public function history(int $page, ?int $employee): array
    {
        $where = $employee === null ? '' : ' WHERE s.employee_id=?';
        $params = $employee === null ? [] : [$employee];
        $from = ' FROM salary_conditions s JOIN employees e ON e.id=s.employee_id
            JOIN employment_contracts c ON c.id=s.contract_id' . $where;
        $count = $this->db->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $page = max(1, min($page, max(1, (int) ceil($total / 20))));
        // Fin inclusive dérivée ; aucune ancienne condition n'est réécrite.
        $query = $this->db->prepare("SELECT s.*,e.employee_number,e.first_name,e.last_name,c.reference,
            NULLIF(LEAST(COALESCE(c.effective_ends_on,'9999-12-31'), COALESCE(
                (SELECT DATE_SUB(MIN(n.effective_on),INTERVAL 1 DAY) FROM salary_conditions n
                 WHERE n.contract_id=s.contract_id AND n.effective_on>s.effective_on),'9999-12-31')),'9999-12-31') AS ends_on"
            . $from . ' ORDER BY s.effective_on DESC,s.id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20));
        $query->execute($params);
        return ['rows' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 20))];
    }

    public function submit(array $values, array $contract, int $actor): int
    {
        return $this->call('CALL compensation_submit(?,?,?,?,?,?)', [(int) $contract['employee_id'],
            (int) $contract['id'], $values['monthly_amount'], $values['effective_on'], $values['justification'], $actor]);
    }

    public function decide(int $request, string $decision, string $reason, int $actor): int
    {
        return $this->call('CALL compensation_decide(?,?,?,?)', [$request, $decision, $reason, $actor]);
    }

    private function call(string $sql, array $params): int
    {
        if ($this->db->inTransaction()) {
            throw new \LogicException('Transaction already active.');
        }
        $query = $this->db->prepare($sql);
        $failure = null;
        try {
            $query->execute($params);
            $id = (int) $query->fetchColumn();
            // Drainer avant de déclarer le CALL terminé, y compris ses erreurs différées.
            while ($query->nextRowset()) { $query->fetchAll(); }
            if ($id < 1) { throw new \RuntimeException('Missing operation result.'); }
            return $id;
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
