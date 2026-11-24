<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

/**
 * Active, pour la requête en cours, les seuls rôles MySQL de l'utilisateur authentifié
 * (database/setup/app_roles.sql). Le compte svc_php détient tous ces rôles, mais seul
 * sp_session est actif à la connexion.
 */
final class SessionRoles
{
    public const SESSION = 'sp_session';
    public const LOGIN = 'sp_login';
    public const PASSWORD_CHANGE = 'sp_password';

    /** Rôle applicatif → rôle MySQL. Liste fermée : SET ROLE n'accepte pas de paramètre lié. */
    private const DATABASE_ROLES = [
        'employee' => 'sp_employee',
        'hr_manager' => 'sp_hr_manager',
        'finance_manager' => 'sp_finance_manager',
        'payroll_manager' => 'sp_payroll_manager',
        'application_administrator' => 'sp_application_administrator',
        'security_auditor' => 'sp_security_auditor',
    ];

    /**
     * Rôles MySQL à activer pour un utilisateur relu par AuthService::currentUser.
     * En cas de cumul interdit de rôles financiers, l'application refuse déjà toute action
     * financière ; on n'active alors que les droits de salarié, que ce cumul n'affecte pas.
     *
     * @return list<string>
     */
    public static function names(array $user): array
    {
        // Mot de passe temporaire pas encore changé : compte pas encore actif, aucun droit métier.
        if ((bool) ($user['password_change_required'] ?? false)) {
            return [self::SESSION];
        }
        $codes = array_keys($user['roles'] ?? []);
        if ($user['payroll_conflict'] ?? true) {
            $codes = array_intersect($codes, ['employee']);
        }
        $names = [self::SESSION];
        foreach (self::DATABASE_ROLES as $code => $role) {
            if (in_array($code, $codes, true)) {
                $names[] = $role;
            }
        }
        return $names;
    }

    public static function activate(PDO $db, array $user): void
    {
        self::set($db, self::names($user));
    }

    /**
     * Exécute $work avec le seul rôle de connexion (lecture de password_hash), puis revient
     * au rôle par défaut, même en cas d'erreur.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function duringLogin(PDO $db, callable $work): mixed
    {
        self::set($db, [self::LOGIN]);
        try {
            return $work();
        } finally {
            $db->exec('SET ROLE DEFAULT');
        }
    }

    /**
     * Exécute $work avec le seul droit de changer un mot de passe (sp_session + sp_password),
     * puis rétablit les rôles de l'utilisateur, même en cas d'erreur.
     */
    public static function duringPasswordChange(PDO $db, array $user, callable $work): mixed
    {
        self::set($db, [self::SESSION, self::PASSWORD_CHANGE]);
        try {
            return $work();
        } finally {
            self::activate($db, $user);
        }
    }

    /** @param list<string> $names */
    private static function set(PDO $db, array $names): void
    {
        $allowed = [self::SESSION, self::LOGIN, self::PASSWORD_CHANGE, ...array_values(self::DATABASE_ROLES)];
        foreach ($names as $name) {
            if (!in_array($name, $allowed, true)) {
                throw new \RuntimeException('Rôle de base de données inconnu.');
            }
        }
        $db->exec('SET ROLE ' . implode(', ', array_map(static fn (string $name): string => "'" . $name . "'", $names)));
    }
}
