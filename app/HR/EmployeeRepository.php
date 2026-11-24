<?php

declare(strict_types=1);

namespace App\HR;

use PDO;

final class EmployeeRepository
{
    public function __construct(private PDO $db) {}

    public function listing(string $search, int $page): array
    {
        // Recherche littérale : ne pas laisser % et _ élargir silencieusement le filtre.
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $where = " WHERE employee_number LIKE ? ESCAPE '!' OR first_name LIKE ? ESCAPE '!' OR last_name LIKE ? ESCAPE '!'";
        $count = $this->db->prepare('SELECT COUNT(*) FROM employees' . $where);
        $count->execute([$pattern, $pattern, $pattern]);
        $total = (int) $count->fetchColumn();
        $page = min($page, max(1, (int) ceil($total / 20)));
        $query = $this->db->prepare('SELECT id, employee_number, first_name, last_name, created_at, archived_at FROM employees' . $where . ' ORDER BY last_name, first_name, id LIMIT 20 OFFSET ' . (($page - 1) * 20));
        $query->execute([$pattern, $pattern, $pattern]);
        return ['rows' => $query->fetchAll(), 'total' => $total, 'page' => $page];
    }

    public function find(int $id): ?array
    {
        $query = $this->db->prepare('SELECT id, employee_number, first_name, last_name, created_at, archived_at FROM employees WHERE id = ?');
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    /** Employés actifs, pour peupler un menu déroulant (contrats, affectations) sans saisir le matricule. */
    public function activeOptions(): array
    {
        return $this->db->query('SELECT employee_number, first_name, last_name FROM employees
            WHERE archived_at IS NULL ORDER BY last_name, first_name, id')->fetchAll();
    }

    public static function version(array $row): string
    {
        return hash_hmac('sha256', json_encode([$row['id'], $row['first_name'], $row['last_name']], JSON_THROW_ON_ERROR), $_SESSION['csrf']);
    }

    public function update(array $row, array $values): bool
    {
        $query = $this->db->prepare('UPDATE employees SET first_name = ?, last_name = ? WHERE id = ? AND CAST(first_name AS BINARY) = CAST(? AS BINARY) AND CAST(last_name AS BINARY) = CAST(? AS BINARY)');
        $query->execute([$values['first_name'], $values['last_name'], $row['id'], $row['first_name'], $row['last_name']]);
        if ($query->rowCount() === 1) {
            return true;
        }
        $current = $this->find((int) $row['id']);
        return $current !== null && $current['first_name'] === $values['first_name']
            && $current['last_name'] === $values['last_name'];
    }
}
