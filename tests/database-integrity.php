<?php

declare(strict_types=1);

// Ce script écrit dans une base de test préexistante et vide.
// lab/mysql-integrity.py prépare et valide une instance privée pour ce scénario.
// Il ne crée pas de base et n’exécute aucune migration. Ne jamais cibler secure_payroll.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 3 || $argv[1] !== '--run' || $argv[2] !== '--confirm-test-database') {
    fwrite(STDERR, "Tests non lancés. Usage réservé au laboratoire autorisé :\nphp tests/database-integrity.php --run --confirm-test-database\n");
    exit(2);
}
if (getenv('DB_NAME') !== 'secure_payroll_test_integrity'
    || strtolower(trim((string) getenv('DB_USER'))) === 'root') {
    fwrite(STDERR, "Base de test dédiée et compte non-root obligatoires. Aucune connexion effectuée.\n");
    exit(2);
}

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Database/Connection.php';

$pdo = null;
$scenario = 'preflight';
try {
    $pdo = \App\Database\Connection::connect();
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'secure_payroll_test_integrity') {
        throw new RuntimeException();
    }
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $tables = ['accounts', 'employees', 'permissions', 'role_assignments', 'role_permissions', 'roles'];
    $metadata = $pdo->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll();
    if (array_column($metadata, 'TABLE_NAME') !== $tables
        || count(array_filter($metadata, static fn (array $row): bool => $row['ENGINE'] === 'InnoDB')) !== 6) {
        throw new RuntimeException();
    }
    // Interdire tout jeu de données existant. Utiliser cette base sans autre session active.
    foreach ($tables as $table) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() !== 0) {
            throw new RuntimeException();
        }
    }
    $seed = file_get_contents(dirname(__DIR__) . '/database/seeds/001_application_roles.sql');
    // Extraire seulement le DML testé : ne jamais exécuter USE secure_payroll ni COMMIT.
    if ($seed === false || preg_match('/\bINSERT INTO roles\b[^;]+;/s', $seed, $match) !== 1) {
        throw new RuntimeException();
    }
    $seedInsert = $match[0];
    $pdo->beginTransaction();
    $passed = 0;
    $check = static function (bool $ok) use (&$passed): void {
        if (!$ok) {
            throw new RuntimeException();
        }
        ++$passed;
    };
    $reject = static function (callable $operation, array $codes) use ($check): void {
        try {
            $operation();
        } catch (PDOException $exception) {
            $check(in_array((int) ($exception->errorInfo[1] ?? 0), $codes, true));
            return;
        }
        throw new RuntimeException('Expected SQL rejection');
    };

    $scenario = 'seed_first_run';
    $check($pdo->exec($seedInsert) === 6);
    $rolesBefore = $pdo->query('SELECT id, code, name FROM roles ORDER BY code')->fetchAll();
    $scenario = 'seed_rerun';
    $check($pdo->exec($seedInsert) === 0);
    $check($pdo->query('SELECT id, code, name FROM roles ORDER BY code')->fetchAll() === $rolesBefore);
    $scenario = 'seed_missing_role';
    $pdo->exec("DELETE FROM roles WHERE code = 'security_auditor'");
    $check($pdo->exec($seedInsert) === 1);
    $check((int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn() === 6);
    $scenario = 'seed_collision';
    // Source volontairement incohérente : la collision doit échouer, sans changement.
    $badSeed = str_replace("'Employee' AS name", "'Incorrect label' AS name", $seedInsert);
    $check($badSeed !== $seedInsert);
    $reject(static fn () => $pdo->exec($badSeed), [1062, 3819]);
    $scenario = 'roles_closed_catalogue';
    $reject(static fn () => $pdo->exec("INSERT INTO roles (code, name) VALUES ('unknown_role', 'Unknown Role')"), [3819]);

    $pdo->exec("INSERT INTO employees (employee_number, first_name, last_name) VALUES ('TEST-001', 'Test', 'One')");
    $employee = (int) $pdo->lastInsertId();
    $scenario = 'duplicate_employee';
    $reject(static fn () => $pdo->exec("INSERT INTO employees (employee_number, first_name, last_name) VALUES ('TEST-001', 'Duplicate', 'Test')"), [1062]);
    $scenario = 'immutable_employee_number';
    $reject(static fn () => $pdo->exec("UPDATE employees SET employee_number = 'TEST-CHANGED' WHERE id = $employee"), [1644]);
    $scenario = 'delete_employee';
    $reject(static fn () => $pdo->exec("DELETE FROM employees WHERE id = $employee"), [1644]);
    $scenario = 'employee_ordinary_updates';
    $check($pdo->exec("UPDATE employees SET first_name = 'Renamed' WHERE id = $employee") === 1);
    $pdo->exec("UPDATE employees SET archived_at = CURRENT_TIMESTAMP(6) WHERE id = $employee");
    $pdo->exec("UPDATE employees SET archived_at = NULL WHERE id = $employee");

    $scenario = 'invalid_password_hash';
    $accountInsert = $pdo->prepare('INSERT INTO accounts (employee_id, login, password_hash) VALUES (?, ?, ?)');
    $reject(static fn () => $accountInsert->execute([$employee, 'test-one', 'invalid-hash-format']), [3819]);
    // Empreinte éphémère : aucune valeur d’authentification n’est conservée ou affichée.
    $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID);
    $scenario = 'valid_password_hash';
    $check($accountInsert->execute([$employee, 'test-one', $hash]));
    $account = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO employees (employee_number, first_name, last_name) VALUES ('TEST-002', 'Test', 'Actor')");
    $actorEmployee = (int) $pdo->lastInsertId();
    $accountInsert->execute([$actorEmployee, 'test-actor', $hash]);
    $actor = (int) $pdo->lastInsertId();
    unset($hash);
    $scenario = 'account_reassignment';
    $reject(static fn () => $pdo->exec("UPDATE accounts SET employee_id = $actorEmployee WHERE id = $account"), [1644]);
    $scenario = 'account_delete';
    $reject(static fn () => $pdo->exec("DELETE FROM accounts WHERE id = $account"), [1644]);
    $scenario = 'account_ordinary_updates';
    $check($pdo->exec("UPDATE accounts SET login = 'test-one-renamed', disabled_at = CURRENT_TIMESTAMP(6) WHERE id = $account") === 1);
    $pdo->exec("UPDATE accounts SET disabled_at = NULL WHERE id = $account");

    $role = (int) $pdo->query("SELECT id FROM roles WHERE code = 'employee'")->fetchColumn();
    $hrRole = (int) $pdo->query("SELECT id FROM roles WHERE code = 'hr_manager'")->fetchColumn();
    $scenario = 'valid_assignment';
    $check($pdo->exec("INSERT INTO role_assignments (account_id, role_id, granted_by_account_id, valid_from, expires_at, grant_reason) VALUES ($account, $role, $actor, CURRENT_TIMESTAMP(6) + INTERVAL 1 HOUR, CURRENT_TIMESTAMP(6) + INTERVAL 2 HOUR, 'Planned test grant')") === 1);
    $planned = (int) $pdo->lastInsertId();
    $scenario = 'immediate_assignment';
    // Omission de granted_at ET valid_from, sans instant calculé préalablement en PHP.
    $check($pdo->exec("INSERT INTO role_assignments (account_id, role_id, granted_by_account_id, grant_reason) VALUES ($account, $hrRole, $actor, 'Immediate test grant')") === 1);
    $immediate = (int) $pdo->lastInsertId();
    $check((int) $pdo->query("SELECT granted_at = valid_from FROM role_assignments WHERE id = $immediate")->fetchColumn() === 1);
    $scenario = 'retroactive_assignment';
    $reject(static fn () => $pdo->exec("INSERT INTO role_assignments (account_id, role_id, granted_at, valid_from, grant_reason) VALUES ($account, $role, CURRENT_TIMESTAMP(6) - INTERVAL 1 DAY, CURRENT_TIMESTAMP(6) - INTERVAL 1 DAY, 'Backdated test')"), [1644]);
    $scenario = 'retroactive_valid_from';
    $reject(static fn () => $pdo->exec("INSERT INTO role_assignments (account_id, role_id, valid_from, grant_reason) VALUES ($account, $role, CURRENT_TIMESTAMP(6) - INTERVAL 1 SECOND, 'Backdated start')"), [3819]);
    $scenario = 'invalid_expiration';
    $reject(static fn () => $pdo->exec("INSERT INTO role_assignments (account_id, role_id, expires_at, grant_reason) VALUES ($account, $role, CURRENT_TIMESTAMP(6), 'Invalid expiration')"), [3819]);
    $scenario = 'insert_already_revoked';
    $reject(static fn () => $pdo->exec("INSERT INTO role_assignments (account_id, role_id, grant_reason, revoked_at, revoked_by_account_id, revocation_reason) VALUES ($account, $role, 'Invalid history', CURRENT_TIMESTAMP(6), $actor, 'Invalid insertion')"), [1644]);
    $scenario = 'future_revocation';
    $reject(static fn () => $pdo->exec("UPDATE role_assignments SET revoked_at = CURRENT_TIMESTAMP(6) + INTERVAL 1 DAY, revoked_by_account_id = $actor, revocation_reason = 'Future revocation' WHERE id = $planned"), [1644]);
    $scenario = 'valid_revocation';
    $check($pdo->exec("UPDATE role_assignments SET revoked_at = CURRENT_TIMESTAMP(6), revoked_by_account_id = $actor, revocation_reason = 'Test revocation' WHERE id = $planned") === 1);
    $revokedBefore = $pdo->query("SELECT * FROM role_assignments WHERE id = $planned")->fetch();
    $scenario = 'second_revocation';
    $reject(static fn () => $pdo->exec("UPDATE role_assignments SET revoked_at = CURRENT_TIMESTAMP(6), revoked_by_account_id = $actor, revocation_reason = 'Second revocation' WHERE id = $planned"), [1644]);
    $scenario = 'mutate_revoked_assignment';
    $reject(static fn () => $pdo->exec("UPDATE role_assignments SET grant_reason = 'Changed' WHERE id = $planned"), [1644]);
    $scenario = 'unrevoke_assignment';
    $reject(static fn () => $pdo->exec("UPDATE role_assignments SET revoked_at = NULL, revoked_by_account_id = NULL, revocation_reason = NULL WHERE id = $planned"), [1644]);
    $scenario = 'noop_revoked_assignment';
    $pdo->exec("UPDATE role_assignments SET revoked_at = revoked_at, grant_reason = grant_reason WHERE id = $planned");
    $check($pdo->query("SELECT * FROM role_assignments WHERE id = $planned")->fetch() === $revokedBefore);
    $scenario = 'delete_assignment';
    $reject(static fn () => $pdo->exec("DELETE FROM role_assignments WHERE id = $planned"), [1644]);
    $scenario = 'grant_mutation';
    $reject(static fn () => $pdo->exec("UPDATE role_assignments SET expires_at = CURRENT_TIMESTAMP(6) + INTERVAL 1 DAY WHERE id = $immediate"), [1644]);
    $scenario = 'non_utc_assignment';
    $pdo->exec("SET SESSION time_zone = '+01:00'");
    $reject(static fn () => $pdo->exec("INSERT INTO role_assignments (account_id, role_id, grant_reason) VALUES ($account, $role, 'Non UTC')"), [1644]);
    $pdo->exec("SET SESSION time_zone = '+00:00'");

    $pdo->rollBack();
    fwrite(STDOUT, "$passed assertions d’intégrité réussies ; données de test annulées. Les compteurs AUTO_INCREMENT peuvent avoir avancé.\n");
} catch (Throwable) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Échec du scénario : $scenario. Aucun détail de connexion affiché.\n");
    exit(1);
}
