<?php

declare(strict_types=1);

function t(string $key, array $parameters = []): string
{
    return \App\I18n\Translator::text($key, $parameters);
}

function locale(): string
{
    return \App\I18n\Translator::locale();
}

function roleNames(array $roles): string
{
    return implode(' · ', array_map(static fn (string $code): string => t('roles.' . $code), array_keys($roles)));
}

function languageReturnPath(mixed $value): string
{
    if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $value)) {
        return '/dashboard';
    }
    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['fragment'])
        || !preg_match('~\A/(?:login|dashboard|iam|payroll|payslips/[1-9][0-9]{0,17}(?:/settlement)?|compensation(?:/new|/employees|/pending|/history|/[1-9][0-9]{0,17})?|departments|positions|(?:contracts|assignments)(?:/new|/[1-9][0-9]{0,17})?|employees(?:/new|/[1-9][0-9]{0,17}(?:/(?:edit|contracts|assignments))?)?)?\z~D', $parts['path'] ?? '')) {
        return '/dashboard';
    }
    return $value;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function field(array $data, string $key): string
{
    return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
}

function redirectTo(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function employeeErrors(array $values): array
{
    // Le matricule n'est plus saisi : il est généré par la base (account_onboard).
    $errors = [];
    foreach (['first_name' => 100, 'last_name' => 100] as $key => $limit) {
        $value = $values[$key];
        $length = preg_match_all('/./us', $value);
        if ($value === '' || $length === false || $length > $limit || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            $errors[$key] = t('validation.employee_field', ['limit' => $limit]);
        }
    }
    return $errors;
}

/** @param array<string, scalar|null> $context détails techniques, jamais une valeur saisie ni une donnée métier */
function auditEvent(string $event, ?int $actor = null, ?int $employee = null, ?int $resource = null, array $context = []): void
{
    error_log(json_encode(['at' => gmdate('c'), 'event' => $event,
        'actor_id' => $actor, 'employee_id' => $employee, 'resource_id' => $resource] + $context, JSON_THROW_ON_ERROR));
    // Phase 7 : même événement dans la piste d'audit en base (en ajout seul), si une session l'autorise.
    \App\Audit\AuditTrail::record($event, $actor, $resource, $context);
}
