<?php

declare(strict_types=1);

// Sélection des rôles MySQL activés par requête (app/Database/SessionRoles.php), sans connexion.

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database\SessionRoles;

$count = 0;
$verify = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) {
        fwrite(STDERR, 'FAIL ' . $label . PHP_EOL);
        exit(1);
    }
    $count++;
};
$user = static fn (array $roles, bool $conflict = false): array
    => ['roles' => array_fill_keys($roles, 'x'), 'payroll_conflict' => $conflict];

$verify(SessionRoles::names($user([])) === ['sp_session'], 'Sans rôle : session seule');
foreach (['employee', 'hr_manager', 'finance_manager', 'payroll_manager', 'application_administrator', 'security_auditor'] as $role) {
    $verify(SessionRoles::names($user([$role])) === ['sp_session', 'sp_' . $role], 'Rôle unique : ' . $role);
}
$verify(SessionRoles::names($user(['employee', 'hr_manager'])) === ['sp_session', 'sp_employee', 'sp_hr_manager'],
    'Cumul autorisé Salarié + RH');
$verify(SessionRoles::names($user(['employee', 'hr_manager', 'finance_manager'], true)) === ['sp_session', 'sp_employee'],
    'Cumul interdit : seuls les droits de salarié restent actifs');
$verify(SessionRoles::names($user(['hr_manager', 'finance_manager'], true)) === ['sp_session'],
    'Cumul interdit sans rôle Salarié : session seule');
$verify(SessionRoles::names(['roles' => ['hr_manager' => 'x']]) === ['sp_session'],
    'Indicateur de cumul absent : refus par défaut');
$verify(SessionRoles::names($user(['sp_dba', "x'); DROP", 'root'])) === ['sp_session'],
    'Code inconnu ignoré : aucun nom de rôle construit depuis une donnée');

// Cohérence avec le script SQL : chaque rôle activable par PHP existe et est accordé à svc_php.
$sql = file_get_contents(dirname(__DIR__) . '/database/setup/app_roles.sql');
preg_match("/CREATE ROLE (.*?);/s", $sql, $created);
preg_match("/GRANT ('sp_session'.*?)\s+TO 'svc_php'@'localhost';/s", $sql, $granted);
foreach (['sp_session', 'sp_login', 'sp_employee', 'sp_hr_manager', 'sp_finance_manager', 'sp_payroll_manager',
          'sp_application_administrator', 'sp_security_auditor'] as $role) {
    $verify(str_contains($created[1] ?? '', "'" . $role . "'") && str_contains($granted[1] ?? '', "'" . $role . "'"),
        'Rôle créé et accordé à svc_php : ' . $role);
}
$verify(str_contains($sql, "SET DEFAULT ROLE 'sp_session' TO 'svc_php'@'localhost';"), 'Rôle par défaut sp_session');
$verify(str_contains($sql, "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'svc_php'@'localhost';"), 'Privilèges directs révoqués');
$verify(preg_match_all("/password_hash/", preg_replace('/--[^\n]*/', '', $sql)) === 1
    && str_contains($sql, "password_hash, disabled_at) ON secure_payroll.accounts TO 'sp_login'"),
    'password_hash accordé au seul rôle sp_login');

echo $count . " contrôles des rôles MySQL réussis, sans connexion.\n";
