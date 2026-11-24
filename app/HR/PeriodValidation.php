<?php

declare(strict_types=1);

namespace App\HR;

final class PeriodValidation
{
    public static function date(string $value): bool
    {
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) || $value < '1900-01-01') {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** Retourne des clés de traduction, jamais les messages du pilote SQL. */
    public static function errors(string $kind, array $values): array
    {
        $errors = [];
        $numberLength = preg_match_all('/./us', $values['employee_number']);
        if ($numberLength === false || $numberLength < 1 || $numberLength > 32
            || preg_match('/[\x00-\x1F\x7F]/u', $values['employee_number'])) {
            $errors['employee_number'] = 'hr.invalid_employee';
        }
        if (!self::date($values['starts_on'])) {
            $errors['starts_on'] = 'hr.invalid_date';
        }
        if ($values['ends_on'] !== '' && (!self::date($values['ends_on']) || $values['ends_on'] < $values['starts_on'])) {
            $errors['ends_on'] = 'hr.invalid_period';
        }
        if ($kind === 'contracts') {
            if (!in_array($values['contract_type'], ['permanent', 'fixed_term', 'internship'], true)) {
                $errors['contract_type'] = 'hr.invalid_type';
            } elseif (($values['contract_type'] === 'permanent') !== ($values['ends_on'] === '')) {
                $errors['ends_on'] = 'hr.type_dates';
            }
            $length = preg_match_all('/./us', $values['reference']);
            if ($length === false || $length < 1 || $length > 64 || preg_match('/[\x00-\x1F\x7F]/u', $values['reference'])) {
                $errors['reference'] = 'hr.invalid_reference';
            }
        } else {
            foreach (['department_id', 'position_id'] as $field) {
                if (!filter_var($values[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                    $errors[$field] = 'hr.choose_reference';
                }
            }
        }
        return $errors;
    }

    public static function status(array $row): string
    {
        $today = gmdate('Y-m-d');
        if ($row['effective_ends_on'] !== null && $row['effective_ends_on'] < $today) {
            return 'hr.status.ended';
        }
        return $row['starts_on'] > $today ? 'hr.status.planned' : 'hr.status.current';
    }
}
