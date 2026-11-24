<?php

declare(strict_types=1);

namespace App\Payroll;

use App\Compensation\CompensationValidation;
use App\HR\PeriodValidation;

final class Validation
{
    public static function amount(string $value): ?string
    {
        if (preg_match('/\A0(?:[.,]0{1,2})?\z/D', $value)) { return '0.00'; }
        return CompensationValidation::amount($value);
    }

    public static function id(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,17}\z/D', $value)) { return null; }
        return (int) $value;
    }

    public static function month(string $value): bool
    {
        return preg_match('/\A[0-9]{4}-(?:0[1-9]|1[0-2])\z/D', $value) === 1 && PeriodValidation::date($value . '-01');
    }

    public static function input(array $values): bool
    {
        $amount = self::amount($values['amount']);
        return self::id($values['period_id']) !== null && self::id($values['employee_id']) !== null
            && in_array($values['kind'], ['bonus','deduction','paid_leave','unpaid_leave'], true)
            && $amount !== null
            && (in_array($values['kind'], ['bonus','deduction'], true) ? $amount !== '0.00' : $amount === '0.00')
            && PeriodValidation::date($values['starts_on']) && PeriodValidation::date($values['ends_on'])
            && $values['ends_on'] >= $values['starts_on']
            && CompensationValidation::text($values['reason'], 2000);
    }
}
