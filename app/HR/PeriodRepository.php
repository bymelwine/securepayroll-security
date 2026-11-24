<?php

declare(strict_types=1);

namespace App\HR;

use PDO;
use RuntimeException;

final class PeriodRepository
{
    private const TABLES = ['contracts' => 'employment_contracts', 'assignments' => 'employee_assignments'];

    public function __construct(private PDO $db, private string $language = 'fr') {}

    private function table(string $kind): string
    {
        return self::TABLES[$kind] ?? throw new RuntimeException('Invalid HR resource.');
    }

    public function catalogue(string $kind, bool $activeOnly = false): array
    {
        $table = match ($kind) {
            'departments' => 'departments', 'positions' => 'positions',
            default => throw new RuntimeException('Invalid HR catalogue.'),
        };
        $label = $this->language === 'en' ? 'name_en' : 'name_fr';
        return $this->db->query("SELECT id,code,$label AS label,archived_at FROM $table"
            . ($activeOnly ? ' WHERE archived_at IS NULL' : '') . ' ORDER BY label,id')->fetchAll();
    }

    private function projection(string $kind): string
    {
        $table = $this->table($kind);
        $label = $this->language === 'en' ? 'name_en' : 'name_fr';
        return "SELECT rec.*,e.employee_number,e.first_name,e.last_name"
            . ($kind === 'assignments' ? ",d.$label AS department_label,p.$label AS position_label" : '')
            . " FROM $table rec JOIN employees e ON e.id=rec.employee_id"
            . ($kind === 'assignments' ? ' JOIN departments d ON d.id=rec.department_id JOIN positions p ON p.id=rec.position_id' : '');
    }

    public function listing(string $kind, ?int $employeeId, int $page): array
    {
        $table = $this->table($kind);
        $where = $employeeId === null ? '' : ' WHERE employee_id=?';
        $parameters = $employeeId === null ? [] : [$employeeId];
        $query = $this->db->prepare("SELECT COUNT(*) FROM $table" . $where);
        $query->execute($parameters);
        $total = (int) $query->fetchColumn();
        $page = min(max(1, $page), max(1, (int) ceil($total / 20)));
        $query = $this->db->prepare($this->projection($kind)
            . ($employeeId === null ? '' : ' WHERE rec.employee_id=?')
            . ' ORDER BY rec.starts_on DESC,rec.id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20));
        $query->execute($parameters);
        return ['rows' => $query->fetchAll(), 'total' => $total, 'page' => $page];
    }

    public function find(string $kind, int $id): ?array
    {
        $query = $this->db->prepare($this->projection($kind) . ' WHERE rec.id=?');
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    public function employeeByNumber(string $number): ?array
    {
        $query = $this->db->prepare('SELECT id,employee_number,first_name,last_name,archived_at FROM employees WHERE employee_number=?');
        $query->execute([$number]);
        return $query->fetch() ?: null;
    }

    public function create(string $kind, array $values, int $actor): int
    {
        $this->table($kind);
        if ($this->db->inTransaction()) {
            throw new RuntimeException('HR procedures require a standalone call.');
        }
        $assignment = $kind === 'assignments';
        $query = $this->db->prepare($assignment ? 'CALL hr_create_assignment(?,?,?,?,?,?)' : 'CALL hr_create_contract(?,?,?,?,?,?)');
        $primaryFailure = null;
        try {
            $query->execute([
                $values['employee_id'], $assignment ? $values['department_id'] : $values['reference'],
                $assignment ? $values['position_id'] : $values['contract_type'],
                $values['starts_on'], $values['ends_on'] ?: null, $actor,
            ]);
            $id = $query->fetchColumn();
            if ($id === false) {
                throw new RuntimeException('Missing HR record.');
            }
            return (int) $id;
        } catch (\Throwable $error) {
            $primaryFailure = $error;
            throw $error;
        } finally {
            if ($primaryFailure === null) {
                $query->closeCursor();
            } else {
                // Une erreur de nettoyage ne doit pas masquer le SQLSTATE de l’échec initial.
                try {
                    $query->closeCursor();
                } catch (\Throwable) {
                    // L’exception initiale est déjà en cours de propagation, sans message sensible.
                }
            }
        }
    }

    public function close(string $kind, int $id, string $end, int $actor): void
    {
        $this->table($kind);
        if ($this->db->inTransaction()) {
            throw new RuntimeException('HR procedures require a standalone call.');
        }
        $query = $this->db->prepare($kind === 'assignments' ? 'CALL hr_close_assignment(?,?,?)' : 'CALL hr_close_contract(?,?,?)');
        try {
            $query->execute([$id, $end, $actor]);
        } finally {
            $query->closeCursor();
        }
    }
}
