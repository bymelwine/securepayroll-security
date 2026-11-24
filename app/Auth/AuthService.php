<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database\SessionRoles;
use App\Security\Session;
use PDO;

final class AuthService
{
    /** Compte existant visé par le dernier échec de connexion, pour la journalisation ; null si inconnu. */
    public ?int $failedAccountId = null;
    /** Motif du dernier échec : 'invalid', ou 'temporary_expired' (mot de passe temporaire correct mais périmé). */
    public ?string $failure = null;

    public function __construct(private PDO $db) {}

    public function login(string $login, #[\SensitiveParameter] string $password): bool
    {
        // Seule requête qui lit password_hash : elle s'exécute sous le rôle MySQL sp_login.
        $account = SessionRoles::duringLogin($this->db, function () use ($login): array|false {
            $query = $this->db->prepare('SELECT a.id, a.password_hash, a.password_change_required,
                (a.temporary_password_expires_at <= UTC_TIMESTAMP(6)) AS temporary_expired
                FROM accounts a JOIN employees e ON e.id = a.employee_id
                WHERE a.login = ? AND a.disabled_at IS NULL AND e.archived_at IS NULL LIMIT 1');
            $query->execute([$login]);
            return $query->fetch();
        });
        // Travail Argon2id même pour un identifiant absent ; cette empreinte n’est liée à aucun compte.
        $hash = $account['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$MTIzNDU2Nzg5MGFiY2RlZg$phRfPWZoyG2Rva8DQN7nGkXzB0JRslvJM5t30pPJbF4';
        $verified = password_verify($password, $hash);
        if (!$account || !$verified || password_get_info($hash)['algoName'] !== 'argon2id') {
            $this->failedAccountId = $account ? (int) $account['id'] : null;
            $this->failure = 'invalid';
            return false;
        }
        // Mot de passe temporaire correct mais expiré : seul son détenteur l'apprend, sans ouvrir de session.
        if ((bool) $account['password_change_required'] && (bool) $account['temporary_expired']) {
            $this->failedAccountId = (int) $account['id'];
            $this->failure = 'temporary_expired';
            return false;
        }
        Session::signIn((int) $account['id']);
        return true;
    }

    /** Empreinte actuelle si $password est bien le mot de passe du compte, sinon null (lecture sous sp_login). */
    public function verifyCurrentPassword(int $accountId, #[\SensitiveParameter] string $password): ?string
    {
        $hash = SessionRoles::duringLogin($this->db, function () use ($accountId): ?string {
            $query = $this->db->prepare('SELECT password_hash FROM accounts WHERE id = ? AND disabled_at IS NULL');
            $query->execute([$accountId]);
            $value = $query->fetchColumn();
            return is_string($value) ? $value : null;
        });
        $candidate = $hash ?? '$argon2id$v=19$m=65536,t=4,p=1$MTIzNDU2Nzg5MGFiY2RlZg$phRfPWZoyG2Rva8DQN7nGkXzB0JRslvJM5t30pPJbF4';
        return password_verify($password, $candidate) && $hash !== null ? $hash : null;
    }

    /** Remplace l'empreinte (procédure account_change_password, rôle sp_password), puis renouvelle la session. */
    public function changePassword(array $user, string $currentHash, #[\SensitiveParameter] string $newPassword): void
    {
        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
        SessionRoles::duringPasswordChange($this->db, $user, function () use ($user, $currentHash, $newHash): void {
            $query = $this->db->prepare('CALL account_change_password(?,?,?)');
            try {
                $query->execute([(int) $user['account_id'], $currentHash, $newHash]);
                while ($query->nextRowset()) {
                }
            } finally {
                $query->closeCursor();
            }
        });
        Session::signIn((int) $user['account_id']);
    }

    /** L’état du compte et les attributions sont relus à chaque requête protégée. */
    public function currentUser(): ?array
    {
        if (!isset($_SESSION['account_id'])) {
            return null;
        }
        $query = $this->db->prepare('SELECT a.id AS account_id, a.login, a.password_change_required, FLOOR(UNIX_TIMESTAMP(a.password_changed_at)) AS password_changed, e.id AS employee_id, e.employee_number, e.first_name, e.last_name FROM accounts a JOIN employees e ON e.id = a.employee_id WHERE a.id = ? AND a.disabled_at IS NULL AND e.archived_at IS NULL');
        $query->execute([$_SESSION['account_id']]);
        $user = $query->fetch();
        // Session ouverte avant le dernier changement de mot de passe (autre navigateur, vol de session) : fermée.
        if (!$user || ($user['password_changed'] !== null && (int) ($_SESSION['signed_in'] ?? 0) < (int) $user['password_changed'])) {
            Session::clear();
            return null;
        }
        $roles = $this->db->prepare('SELECT DISTINCT r.code, r.name, p.code AS permission FROM role_assignments ra JOIN roles r ON r.id = ra.role_id LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id WHERE ra.account_id = ? AND ra.valid_from <= UTC_TIMESTAMP(6) AND (ra.expires_at IS NULL OR ra.expires_at > UTC_TIMESTAMP(6)) AND (ra.revoked_at IS NULL OR ra.revoked_at > UTC_TIMESTAMP(6)) ORDER BY r.name');
        $roles->execute([$user['account_id']]);
        $user['roles'] = [];
        $user['permissions'] = [];
        foreach ($roles->fetchAll() as $row) {
            $user['roles'][$row['code']] = $row['name'];
            if ($row['permission'] !== null) {
                $user['permissions'][] = $row['permission'];
            }
        }
        // Cumul interdit au niveau de la personne, sur tous ses comptes.
        $personRoles = $this->db->prepare("SELECT DISTINCT r.code FROM accounts a
            JOIN role_assignments ra ON ra.account_id=a.id JOIN roles r ON r.id=ra.role_id
            WHERE a.employee_id=? AND r.code IN ('hr_manager','finance_manager')
            AND ra.valid_from<=UTC_TIMESTAMP(6) AND (ra.expires_at IS NULL OR ra.expires_at>UTC_TIMESTAMP(6))
            AND (ra.revoked_at IS NULL OR ra.revoked_at>UTC_TIMESTAMP(6))");
        $personRoles->execute([$user['employee_id']]);
        $user['compensation_conflict'] = count($personRoles->fetchAll(PDO::FETCH_COLUMN)) > 1;
        $financialRoles = $this->db->prepare("SELECT DISTINCT r.code FROM accounts a
            JOIN role_assignments ra ON ra.account_id=a.id JOIN roles r ON r.id=ra.role_id
            WHERE a.employee_id=? AND r.code IN ('hr_manager','payroll_manager','finance_manager','application_administrator','security_auditor')
            AND ra.valid_from<=UTC_TIMESTAMP(6) AND (ra.expires_at IS NULL OR ra.expires_at>UTC_TIMESTAMP(6))
            AND (ra.revoked_at IS NULL OR ra.revoked_at>UTC_TIMESTAMP(6))");
        $financialRoles->execute([$user['employee_id']]);
        $user['payroll_conflict'] = count($financialRoles->fetchAll(PDO::FETCH_COLUMN)) > 1;
        return $user;
    }

    public static function can(array $user, string $permission): bool
    {
        if (str_starts_with($permission, 'iam.')) {
            $roles = $permission === 'iam.read' ? ['application_administrator','security_auditor']
                : (in_array($permission, ['iam.grant','iam.revoke'], true) ? ['application_administrator'] : []);
            return !($user['payroll_conflict'] ?? true)
                && array_intersect($roles, array_keys($user['roles'])) !== []
                && in_array($permission, $user['permissions'], true);
        }
        if (str_starts_with($permission, 'payroll.') || str_starts_with($permission, 'payslip.')) {
            $roles = match ($permission) {
                'payroll.read' => ['hr_manager','payroll_manager','finance_manager','security_auditor'],
                'payroll.period.create', 'payroll.input.decide', 'payslip.decide', 'payroll.payment', 'payroll.recovery.decide' => ['finance_manager'],
                'payroll.input.submit' => ['hr_manager'],
                'payroll.calculate', 'payslip.verify', 'payslip.publish', 'payslip.correct', 'payroll.recovery.request', 'payroll.recovery.receive' => ['payroll_manager'],
                'payslip.self' => ['employee'],
                default => [],
            };
            return ($permission === 'payslip.self' || !($user['payroll_conflict'] ?? true))
                && array_intersect($roles, array_keys($user['roles'])) !== []
                && in_array($permission, $user['permissions'], true);
        }
        if (str_starts_with($permission, 'compensation.')) {
            $allowedRole = match ($permission) {
                'compensation.submit' => isset($user['roles']['hr_manager']),
                'compensation.decide' => isset($user['roles']['finance_manager']),
                'compensation.read' => isset($user['roles']['hr_manager']) || isset($user['roles']['finance_manager']),
                default => false,
            };
            return $allowedRole && !($user['compensation_conflict'] ?? true)
                && in_array($permission, $user['permissions'], true);
        }
        if (str_starts_with($permission, 'personal_data.')) {
            // Libre-service : le salarié accède à SES SEULES données personnelles (phase 6).
            return isset($user['roles']['employee']) && in_array($permission, $user['permissions'], true);
        }
        if (str_starts_with($permission, 'audit.')) {
            // Supervision de la piste d'audit : auditeur de sécurité seul (phase 7).
            return isset($user['roles']['security_auditor']) && in_array($permission, $user['permissions'], true);
        }
        return isset($user['roles']['hr_manager']) && in_array($permission, $user['permissions'], true);
    }
}
