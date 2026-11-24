<?php

declare(strict_types=1);

namespace App\Compensation;

use App\HR\PeriodValidation;

final class CompensationValidation
{
    /** Montant traité comme chaîne décimale, sans arrondi flottant. */
    public static function amount(string $value): ?string
    {
        if (!preg_match('/\A([0-9]{1,10})(?:[.,]([0-9]{1,2}))?\z/D', $value, $parts)) { return null; }
        $whole = ltrim($parts[1], '0');
        $normalized = ($whole === '' ? '0' : $whole) . '.' . str_pad($parts[2] ?? '', 2, '0');
        return $normalized === '0.00' ? null : $normalized;
    }

    public static function text(string $value, int $limit): bool
    {
        $length = preg_match_all('/./us', $value);
        return trim($value) !== '' && $length !== false && $length <= $limit
            && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value);
    }

    public static function errors(array $values): array
    {
        $errors = [];
        if (self::amount($values['monthly_amount']) === null) { $errors['monthly_amount'] = 'comp.invalid_amount'; }
        if (!PeriodValidation::date($values['effective_on'])) { $errors['effective_on'] = 'hr.invalid_date'; }
        if (!self::text($values['justification'], 2000)) { $errors['justification'] = 'comp.invalid_reason'; }
        if (!self::text($values['employee_number'], 32) || !self::text($values['reference'], 64)) {
            $errors['reference'] = 'comp.invalid_contract';
        }
        return $errors;
    }
}
