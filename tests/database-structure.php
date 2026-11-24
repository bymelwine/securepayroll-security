<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 2 || $argv[1] !== '--verify') {
    fwrite(STDERR, "Usage : php tests/database-structure.php --verify\nAucune connexion effectuée.\n");
    exit(2);
}

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Database/Connection.php';

// Contrôle de métadonnées et des six rôles uniquement : aucune écriture.
try {
    if (getenv('DB_NAME') !== 'secure_payroll' || strtolower((string) getenv('DB_USER')) === 'root') {
        throw new RuntimeException();
    }
    $pdo = \App\Database\Connection::connect();
    $failures = [];
    $check = static function (bool $ok, string $label) use (&$failures): void {
        if (!$ok) {
            $failures[] = $label;
        }
    };
    $tables = [
        'employees' => 'id,employee_number,first_name,last_name,created_at,archived_at',
        'accounts' => 'id,employee_id,login,password_hash,created_at,disabled_at',
        'roles' => 'id,code,name',
        'permissions' => 'id,code,description',
        'role_permissions' => 'role_id,permission_id',
        'role_assignments' => 'id,account_id,role_id,granted_by_account_id,granted_at,valid_from,expires_at,grant_reason,revoked_at,revoked_by_account_id,revocation_reason',
    ];
    $tableQuery = $pdo->prepare('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = \'BASE TABLE\'');
    $columnsQuery = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    foreach ($tables as $table => $columns) {
        $tableQuery->execute([$table]);
        $metadata = $tableQuery->fetch();
        $check($metadata !== false && $metadata['ENGINE'] === 'InnoDB'
            && $metadata['TABLE_COLLATION'] === 'utf8mb4_0900_as_cs', "$table : moteur/collation");
        $columnsQuery->execute([$table]);
        $rows = $columnsQuery->fetchAll();
        $check(implode(',', array_column($rows, 'COLUMN_NAME')) === $columns, "$table : colonnes");
        $nullable = ['archived_at', 'disabled_at', 'granted_by_account_id', 'expires_at', 'revoked_at', 'revoked_by_account_id', 'revocation_reason'];
        foreach ($rows as $row) {
            $name = $row['COLUMN_NAME'];
            if (str_ends_with($name, '_at') || $name === 'valid_from') {
                $check($row['COLUMN_TYPE'] === 'datetime(6)', "$table.$name : précision temporelle");
            }
            if ($table === 'role_assignments' && in_array($name, ['granted_at', 'valid_from'], true)) {
                $check(strtolower((string) $row['COLUMN_DEFAULT']) === 'current_timestamp(6)', "$table.$name : défaut immédiat");
            }
            $check($row['IS_NULLABLE'] === (in_array($name, $nullable, true) ? 'YES' : 'NO'), "$table.$name : nullabilité");
            if ($name === 'id' || str_ends_with($name, '_id')) {
                $check($row['COLUMN_TYPE'] === 'bigint unsigned', "$table.$name : type identifiant");
            }
            if ($name === 'password_hash') {
                $check($row['COLUMN_TYPE'] === 'varchar(255)' && $row['COLLATION_NAME'] === 'utf8mb4_bin', 'accounts : stockage empreinte');
            }
        }
    }

    $expectedKeys = [
        'employees' => ['PRIMARY' => 'id', 'uq_employees_number' => 'employee_number'],
        'accounts' => ['PRIMARY' => 'id', 'uq_accounts_employee' => 'employee_id', 'uq_accounts_login' => 'login'],
        'roles' => ['PRIMARY' => 'id', 'uq_roles_code' => 'code', 'uq_roles_name' => 'name'],
        'permissions' => ['PRIMARY' => 'id', 'uq_permissions_code' => 'code'],
        'role_permissions' => ['PRIMARY' => 'role_id,permission_id'],
        'role_assignments' => ['PRIMARY' => 'id', 'uq_assignments_start' => 'account_id,role_id,valid_from'],
    ];
    $indexQuery = $pdo->prepare('SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 GROUP BY INDEX_NAME');
    foreach ($expectedKeys as $table => $keys) {
        $indexQuery->execute([$table]);
        $actual = array_column($indexQuery->fetchAll(), 'cols', 'INDEX_NAME');
        ksort($actual);
        ksort($keys);
        $check($actual === $keys, "$table : clés primaires/uniques");
    }

    $expectedFks = [
        'fk_accounts_employee' => 'accounts.employee_id>employees.id',
        'fk_role_permissions_role' => 'role_permissions.role_id>roles.id',
        'fk_role_permissions_permission' => 'role_permissions.permission_id>permissions.id',
        'fk_assignments_account' => 'role_assignments.account_id>accounts.id',
        'fk_assignments_role' => 'role_assignments.role_id>roles.id',
        'fk_assignments_grantor' => 'role_assignments.granted_by_account_id>accounts.id',
        'fk_assignments_revoker' => 'role_assignments.revoked_by_account_id>accounts.id',
    ];
    $rows = $pdo->query('SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.CONSTRAINT_SCHEMA = DATABASE()')->fetchAll();
    $actualFks = [];
    foreach ($rows as $row) {
        if (!isset($tables[$row['TABLE_NAME']])) {
            continue;
        }
        $actualFks[$row['CONSTRAINT_NAME']] = $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'] . '>' . $row['REFERENCED_TABLE_NAME'] . '.' . $row['REFERENCED_COLUMN_NAME'];
        $check($row['DELETE_RULE'] === 'RESTRICT' && $row['UPDATE_RULE'] === 'RESTRICT', 'FK : ' . $row['CONSTRAINT_NAME']);
    }
    ksort($actualFks);
    ksort($expectedFks);
    $check($actualFks === $expectedFks, 'Clés étrangères attendues');

    $expectedChecks = [
        'employees' => ['ck_employees_number', 'ck_employees_names', 'ck_employees_archive'],
        'accounts' => ['ck_accounts_login', 'ck_accounts_argon2id', 'ck_accounts_disabled'],
        'roles' => ['ck_roles_text', 'ck_roles_canonical'],
        'permissions' => ['ck_permissions_text'],
        'role_assignments' => ['ck_assignments_validity', 'ck_assignments_grant_reason', 'ck_assignments_revocation'],
    ];
    $checks = $pdo->query("SELECT TABLE_NAME, CONSTRAINT_NAME, ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'")->fetchAll();
    foreach ($expectedChecks as $table => $names) {
        foreach ($names as $name) {
            $check(count(array_filter($checks, static fn (array $row): bool => $row['TABLE_NAME'] === $table && $row['CONSTRAINT_NAME'] === $name && $row['ENFORCED'] === 'YES')) === 1, "CHECK : $name");
        }
    }

    $expectedTriggers = [
        'employees_preserve_identity' => 'employees:UPDATE',
        'employees_prevent_delete' => 'employees:DELETE',
        'accounts_preserve_identity' => 'accounts:UPDATE',
        'accounts_prevent_delete' => 'accounts:DELETE',
        'assignments_validate_insert' => 'role_assignments:INSERT',
        'assignments_preserve_history' => 'role_assignments:UPDATE',
        'assignments_prevent_delete' => 'role_assignments:DELETE',
    ];
    $triggers = $pdo->query('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchAll();
    foreach ($expectedTriggers as $name => $target) {
        $check(count(array_filter($triggers, static fn (array $row): bool => $row['TRIGGER_NAME'] === $name
            && $row['EVENT_OBJECT_TABLE'] . ':' . $row['EVENT_MANIPULATION'] === $target
            && $row['ACTION_TIMING'] === 'BEFORE' && str_contains($row['ACTION_STATEMENT'], '45000'))) === 1, "Trigger : $name");
    }

    $roles = $pdo->query('SELECT code, name FROM roles')->fetchAll();
    $expectedRoles = [
        'employee' => 'Employee', 'hr_manager' => 'HR Manager',
        'payroll_manager' => 'Payroll Manager', 'finance_manager' => 'Finance Manager',
        'application_administrator' => 'Application Administrator', 'security_auditor' => 'Security Auditor',
    ];
    $actualRoles = array_column($roles, 'name', 'code');
    ksort($actualRoles);
    ksort($expectedRoles);
    $check($actualRoles === $expectedRoles, 'Six rôles canoniques');
    if ($failures !== []) {
        fwrite(STDERR, "Écarts structurels :\n- " . implode("\n- ", $failures) . "\n");
        exit(1);
    }
    fwrite(STDOUT, "Vérification structurelle réussie (métadonnées et rôles).\n");
} catch (Throwable) {
    fwrite(STDERR, "Vérification impossible. Vérifier en privé la configuration, la visibilité des métadonnées et l’état de la migration.\n");
    exit(1);
}
