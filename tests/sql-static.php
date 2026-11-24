<?php

declare(strict_types=1);

// Hors connexion : ne charge ni le bootstrap ni PDO ni l’environnement privé.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/migrations/001_create_identity_and_rbac.sql');
$seed = file_get_contents($root . '/database/seeds/001_application_roles.sql');
$structure = file_get_contents(__DIR__ . '/database-structure.php');
$integrity = file_get_contents(__DIR__ . '/database-integrity.php');
if ($migration === false || $seed === false || $structure === false || $integrity === false) {
    fwrite(STDERR, "Fichier de test manquant.\n");
    exit(1);
}
$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) {
        fwrite(STDERR, "ÉCHEC : $label\n");
        exit(1);
    }
    ++$passed;
};
$sqlOnly = static fn (string $sql): string => preg_replace('/--[^\n]*/', '', $sql);
$ddl = $sqlOnly($migration);
$dml = $sqlOnly($seed);
$withoutLiterals = preg_replace("/'(?:''|[^'])*'/s", "''", $ddl . $dml);
$check(!preg_match('/\b(?:CREATE\s+(?:DATABASE|USER)|GRANT|DROP|TRUNCATE)\b/i', $withoutLiterals), 'Pas de DDL destructif ou d’administration');
preg_match_all('/CREATE TABLE (\w+)/', $ddl, $tables);
$check($tables[1] === ['employees', 'accounts', 'roles', 'permissions', 'role_permissions', 'role_assignments'], 'Six relations');
$check(substr_count($ddl, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs') === 6, 'Moteurs/collations');
foreach (['ck_' => 12, 'fk_' => 7, 'uq_' => 7] as $prefix => $count) {
    preg_match_all('/CONSTRAINT (' . $prefix . '\w+)\b/', $ddl, $names);
    $check(count($names[1]) === $count && count(array_unique($names[1])) === $count, "$prefix : nombre et unicité");
    foreach ($names[1] as $name) {
        $check(str_contains($structure, "'$name'"), "Vérificateur : $name");
    }
}
$check(substr_count($ddl, 'ON DELETE RESTRICT ON UPDATE RESTRICT') === 7 && !str_contains($ddl, 'CASCADE'), 'FK sans cascade');
preg_match_all('/CREATE TRIGGER (\w+) BEFORE (UPDATE|DELETE|INSERT) ON (\w+) FOR EACH ROW\s+BEGIN\s+(.*?)END\$\$/s', $ddl, $matches, PREG_SET_ORDER);
$check(count($matches) === 7, 'Sept triggers');
$triggers = [];
foreach ($matches as $match) {
    $triggers[$match[1]] = $match[4];
    $check(str_contains($structure, "'{$match[1]}'") && str_contains($match[4], "SIGNAL SQLSTATE '45000'"), 'Trigger vérifié : ' . $match[1]);
}
foreach (['employees_prevent_delete', 'accounts_prevent_delete', 'assignments_prevent_delete'] as $name) {
    $check(!preg_match('/\bIF\b/', $triggers[$name]), "DELETE toujours refusé : $name");
}
foreach (['employees_preserve_identity', 'accounts_preserve_identity'] as $name) {
    $check(str_contains($triggers[$name], 'NEW.created_at <=> OLD.created_at'), "Création immuable : $name");
}
$check(str_contains($triggers['employees_preserve_identity'], 'CAST(NEW.employee_number AS BINARY) <=> CAST(OLD.employee_number AS BINARY)'), 'Matricule comparé octet par octet');
$check(str_contains($triggers['accounts_preserve_identity'], 'NEW.employee_id <=> OLD.employee_id'), 'Compte non réaffectable');
foreach (['granted_at', 'valid_from'] as $column) {
    $check((bool) preg_match('/\b' . $column . ' DATETIME\(6\) NOT NULL DEFAULT CURRENT_TIMESTAMP\(6\)/', $ddl), "Défaut microsecondes : $column");
}
$check(str_contains($ddl, 'valid_from >= granted_at AND (expires_at IS NULL OR expires_at > valid_from)'), 'Validité et expiration stricte');
$check(str_contains($triggers['assignments_validate_insert'], 'NEW.granted_at <=> CURRENT_TIMESTAMP(6)'), 'Octroi horodaté par MySQL');
$check(str_contains($triggers['assignments_validate_insert'], 'NEW.revoked_at IS NOT NULL OR NEW.revoked_by_account_id IS NOT NULL'), 'Insertion pré-révoquée refusée');
foreach (['id', 'account_id', 'role_id', 'granted_by_account_id', 'granted_at', 'valid_from', 'expires_at'] as $column) {
    $check(str_contains($triggers['assignments_preserve_history'], "NEW.$column <=> OLD.$column"), "Historique immuable : $column");
}
$check(str_contains($triggers['assignments_preserve_history'], 'CAST(NEW.grant_reason AS BINARY) <=> CAST(OLD.grant_reason AS BINARY)'), 'Motif d’octroi immuable');
foreach (['assignments_validate_insert', 'assignments_preserve_history'] as $name) {
    $check(str_contains($triggers[$name], "@@session.time_zone <> '+00:00'"), "UTC exigé : $name");
}
foreach (['OLD.revoked_at IS NOT NULL', 'NEW.revoked_by_account_id IS NULL', 'NEW.revoked_at <=> CURRENT_TIMESTAMP(6)', 'NEW.revocation_reason AS BINARY'] as $fragment) {
    $check(str_contains($triggers['assignments_preserve_history'], $fragment), "Révocation définitive : $fragment");
}
$check(str_contains($ddl, '^[$]argon2id[$]v=19[$]'), 'Empreintes Argon2id');
$check(!preg_match('/\b(?:NOT\s+EXISTS|IGNORE|REPLACE|UPDATE|DELETE)\b/i', $dml), 'Seed sans sous-requête cible ou modification silencieuse');
$check(substr_count($dml, 'INSERT INTO roles') === 1 && str_contains($dml, 'LEFT JOIN roles AS existing') && str_contains($dml, 'WHERE existing.id IS NULL'), 'Anti-jointure directe');
foreach (['code', 'name'] as $column) {
    $check(str_contains($dml, "CAST(existing.$column AS BINARY) = CAST(incoming.$column AS BINARY)"), "Collision exacte : $column");
}
$expected = [
    'employee' => 'Employee', 'hr_manager' => 'HR Manager',
    'payroll_manager' => 'Payroll Manager', 'finance_manager' => 'Finance Manager',
    'application_administrator' => 'Application Administrator', 'security_auditor' => 'Security Auditor',
];
preg_match_all("/SELECT '([^']+)'(?: AS code)?, '([^']+)'(?: AS name)?/", $dml, $pairs, PREG_SET_ORDER);
$actual = [];
foreach ($pairs as $pair) {
    $actual[$pair[1]] = $pair[2];
    $check(str_contains($ddl, "CAST(code AS BINARY) = CAST('$pair[1]' AS BINARY) AND CAST(name AS BINARY) = CAST('$pair[2]' AS BINARY)"), 'Catalogue CHECK : ' . $pair[1]);
}
$check(count($pairs) === 6 && $actual === $expected, 'Exactement six couples canoniques');
foreach (['duplicate_employee', 'immutable_employee_number', 'delete_employee', 'valid_assignment', 'immediate_assignment', 'valid_revocation', 'second_revocation', 'mutate_revoked_assignment', 'invalid_password_hash', 'seed_rerun', 'seed_collision', 'retroactive_assignment', 'noop_revoked_assignment'] as $scenario) {
    $check(str_contains($integrity, "'$scenario'"), 'Scénario préparé : ' . $scenario);
}
$check(str_contains($integrity, "'secure_payroll_test_integrity'") && str_contains($integrity, '$pdo->rollBack()'), 'Base de test explicite et rollback');
fwrite(STDOUT, "$passed contrôles statiques réussis, sans connexion. Ce contrôle textuel ne valide pas le moteur SQL.\n");
