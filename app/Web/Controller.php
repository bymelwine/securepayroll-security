<?php

declare(strict_types=1);

namespace App\Web;

use App\Access\AccessHistory;
use App\Auth\AuthService;
use App\Auth\Onboarding;
use App\Auth\PasswordPolicy;
use App\Database\Connection;
use App\Database\SessionRoles;
use App\HR\PersonalDataRepository;
use App\HR\EmployeeRepository;
use App\Security\LabMode;
use App\Security\LoginLimiter;
use App\Security\Session;
use PDO;
use PDOException;

final class Controller
{
    private ?PDO $db = null;

    private function database(): PDO
    {
        if (getenv('DB_NAME') !== 'secure_payroll') {
            throw new \RuntimeException('Configuration applicative indisponible.');
        }
        if ($this->db === null) {
            $this->db = Connection::connect();
            $this->db->exec("SET SESSION time_zone = '+00:00'");
            // Phase 7 : brancher la piste d'audit en base dès la connexion (événements avant login compris).
            \App\Audit\AuditTrail::useConnection($this->db);
        }
        return $this->db;
    }

    private function render(string $view, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        extract($data, EXTR_SKIP);
        require dirname(__DIR__) . '/Views/' . $view . '.php';
    }

    private function existingMethods(string $path): array
    {
        return AdvancedHRController::matches($path) ? AdvancedHRController::methods($path) : (in_array($path, ['/logout', '/language'], true) ? ['POST'] : (
            $path === '/login' || $path === '/password' || $path === '/my-data' || $path === '/employees/new' || str_ends_with($path, '/edit') ? ['GET', 'POST'] : ['GET']));
    }

    public function run(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        header('Content-Language: ' . locale());
        $known = in_array($path, ['/', '/login', '/logout', '/language', '/dashboard', '/my-access', '/my-data', '/audit', '/password', '/employees', '/employees/new'], true)
            || (is_string($path) && (IAMController::matches($path) || AdvancedHRController::matches($path) || CompensationController::matches($path) || PayrollController::matches($path)
                || preg_match('~\A/employees/[1-9][0-9]{0,17}(?:/edit)?\z~D', $path)));
        if (!$known) {
            $this->render('error', ['title' => t('errors.not_found_title'), 'message' => t('errors.not_found_message')], 404);
            return;
        }
        $allowed = IAMController::matches($path) ? IAMController::methods($path) : (PayrollController::matches($path) ? PayrollController::methods($path)
            : (CompensationController::matches($path) ? CompensationController::methods($path) : $this->existingMethods($path)));

        if (!in_array($method, $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            $this->render('error', ['title' => t('errors.method_title'), 'message' => t('errors.method_message')], 405);
            return;
        }
        if ($method === 'POST' && !Session::validCsrf($_POST['csrf'] ?? null)) {
            $this->render('error', ['title' => t('errors.csrf_title'), 'message' => t('errors.csrf_message')], 403);
            return;
        }
        if ($path === '/') {
            redirectTo(isset($_SESSION['account_id']) ? '/dashboard' : '/login');
        }
        if ($path === '/language') {
            $language = field($_POST, 'language');
            if (!in_array($language, ['fr', 'en'], true)) {
                $this->render('error', ['title' => t('errors.language_title'), 'message' => t('errors.language_message')], 422);
                return;
            }
            $_SESSION['language'] = $language;
            redirectTo(languageReturnPath($_POST['return_to'] ?? null));
        }
        if ($path === '/logout') {
            auditEvent('auth.logout', isset($_SESSION['account_id']) ? (int) $_SESSION['account_id'] : null);
            Session::clear();
            redirectTo('/login');
        }
        try {
            if ($path === '/login') {
                if (isset($_SESSION['account_id'])) {
                    redirectTo('/dashboard');
                }
                $error = null;
                $login = trim(field($_POST, 'login'));
                $status = 200;
                if ($method === 'POST') {
                    // Adresse et compte visé sont journalisés ; l'identifiant saisi ne l'est jamais
                    // (un mot de passe tapé par erreur dans ce champ finirait en clair dans le journal).
                    $address = ['ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '')];
                    if (!LoginLimiter::allow($login)) {
                        auditEvent('auth.throttled', null, null, null, $address + ['scope' => LoginLimiter::blockedScope()]);
                        $error = t('login.rate_limit');
                        $status = 429;
                        header('Retry-After: ' . LoginLimiter::WINDOW);
                    } else {
                        $password = field($_POST, 'password');
                        $auth = new AuthService($this->database());
                        if ($login !== '' && strlen($login) <= 760 && $password !== '' && strlen($password) <= 4096
                            && $auth->login($login, $password)) {
                            LoginLimiter::succeeded();
                            auditEvent('auth.login', (int) $_SESSION['account_id'], null, null, $address);
                            redirectTo('/dashboard');
                        }
                        auditEvent('auth.failed', null, null, $auth->failedAccountId, $address + [
                            'table' => 'accounts',
                            'target_account_id' => $auth->failedAccountId,
                            'known_account' => $auth->failedAccountId !== null,
                            'reason' => $auth->failure ?? 'invalid',
                        ]);
                        $error = $auth->failure === 'temporary_expired' ? t('login.temporary_expired') : t('login.invalid');
                        $status = 401;
                    }
                }
                $this->render('login', compact('error', 'login'), $status);
                return;
            }
            if (!isset($_SESSION['account_id'])) {
                redirectTo('/login');
            }
            $user = (new AuthService($this->database()))->currentUser();
            if ($user === null) {
                redirectTo('/login');
            }
            // Moindre privilège en base : seuls les rôles MySQL de cet utilisateur sont actifs.
            SessionRoles::activate($this->database(), $user);
            // Phase 7 : acteur de la requête (piste d'audit applicative + attribution des déclencheurs).
            \App\Audit\AuditTrail::actor((int) $user['account_id']);
            $this->database()->exec('SET @audit_actor = ' . (int) $user['account_id']);
            // Compte créé à l'embauche : rien d'autre n'est accessible avant le changement du mot de passe temporaire.
            if ((bool) $user['password_change_required'] && $path !== '/password') {
                redirectTo('/password');
            }
            $canRead = AuthService::can($user, 'employees.read');
            $canCreate = AuthService::can($user, 'employees.create');
            $canUpdate = AuthService::can($user, 'employees.update');
            $repository = new EmployeeRepository($this->database());
            $flash = $_SESSION['flash'] ?? null;
            unset($_SESSION['flash']);
            $data = compact('user', 'canRead', 'canCreate', 'canUpdate', 'flash');
            if (IAMController::matches($path)) {
                (new IAMController($this->database()))->handle($path, $method, $data);
                return;
            }
            if (PayrollController::matches($path)) {
                (new PayrollController($this->database()))->handle($path, $method, $data);
                return;
            }
            if (CompensationController::matches($path)) {
                (new CompensationController($this->database()))->handle($path, $method, $data);
                return;
            }
            if (AdvancedHRController::matches($path)) {
                (new AdvancedHRController($this->database()))->handle($path, $method, $data);
                return;
            }
            if ($path === '/dashboard') {
                $this->render('dashboard', $data);
                return;
            }
            if ($path === '/my-access') {
                $this->myAccess($data);
                return;
            }
            if ($path === '/my-data') {
                $this->myData($data, $method);
                return;
            }
            if ($path === '/audit') {
                $this->audit($data);
                return;
            }
            if ($path === '/password') {
                $this->password($data, $method);
                return;
            }
            if ($path === '/employees') {
                if (!$canRead) {
                    $this->denied($data);
                    return;
                }
                $search = trim(field($_GET, 'q'));
                if (strlen($search) > 400) {
                    $search = '';
                }
                $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) ?: 1;
                $result = $repository->listing($search, $page);
                $this->render('employees', $data + compact('result', 'search'));
                return;
            }
            $creating = $path === '/employees/new';
            $editing = !$creating && str_ends_with($path, '/edit');
            if (($creating && !$canCreate) || ($editing && !$canUpdate)) {
                $this->denied($data);
                return;
            }
            $row = null;
            if (!$creating) {
                $id = (int) explode('/', $path)[2];
                if (!$canRead && !$editing && $id !== (int) $user['employee_id']) {
                    $this->denied($data);
                    return;
                }
                $row = $repository->find($id);
                if ($row === null) {
                    $this->render('error', $data + ['title' => t('errors.employee_title'), 'message' => t('errors.employee_message')], 404);
                    return;
                }
            }
            if (!$creating && !$editing) {
                $this->render('employee', $data + compact('row'));
                return;
            }
            $values = $row ?? ['first_name' => '', 'last_name' => '', 'personal_email' => ''];
            $errors = [];
            $status = 200;
            if ($method === 'POST') {
                foreach (['first_name', 'last_name'] as $key) {
                    $values[$key] = trim(field($_POST, $key));
                }
                if ($creating) {
                    $values['personal_email'] = trim(field($_POST, 'personal_email'));
                }
                $errors = employeeErrors($values);
                if ($creating && ($emailError = Onboarding::personalEmailError($values['personal_email'])) !== null) {
                    $errors['personal_email'] = t($emailError);
                }
                if ($editing && (!hash_equals(EmployeeRepository::version($row), field($_POST, 'version')))) {
                    $errors['form'] = t('validation.version');
                    $status = 409;
                }
                if ($errors === []) {
                    try {
                        if ($creating) {
                            // Salarié, compte, invitation et rôle Employé en une transaction ; e-mail à l'adresse personnelle.
                            $created = (new Onboarding($this->database()))->onboard((int) $user['account_id'], $values, $values['personal_email']);
                            $id = $created['employee_id'];
                            auditEvent('account.onboarded', (int) $user['account_id'], $id, $created['account_id'],
                                ['mail_sent' => $created['mail_sent']]);
                        } elseif (!$repository->update($row, $values)) {
                            $errors['form'] = t('validation.concurrent');
                            $status = 409;
                        }
                        if ($errors === []) {
                            auditEvent($creating ? 'employee.created' : 'employee.updated', (int) $user['account_id'], $id);
                            $_SESSION['flash'] = $creating
                                ? [$created['mail_sent'] ? 'notifications.employee_onboarded' : 'notifications.employee_onboarded_no_mail',
                                    ['number' => $created['employee_number'], 'login' => $created['login']]]
                                : 'notifications.employee_updated';
                            redirectTo($canRead || $id === (int) $user['employee_id'] ? '/employees/' . $id : '/dashboard');
                        }
                    } catch (PDOException $exception) {
                        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                            throw $exception;
                        }
                        $errors['employee_number'] = t('validation.duplicate_number');
                        $status = 409;
                    }
                }
                if ($errors !== [] && $status === 200) {
                    $status = 422;
                }
            }
            $this->render('employee-form', $data + compact('values', 'row', 'errors', 'creating'), $status);
        } catch (\Throwable $exception) {
            // TEMPORAIRE : retirer ce diagnostic après identification du 503 contrats.
            // Seulement les codes techniques, jamais errorInfo[2], getMessage() ou une trace.
            if ($exception instanceof PDOException && $method === 'POST' && $path === '/contracts/new') {
                $this->logContractCreationDiagnostic($exception);
            }
            // Pas de message du pilote, de DSN, de requête ou de trace dans la réponse ou le journal.
            error_log('Application unavailable: ' . get_class($exception));
            if ($path === '/login') {
                $this->render('login', ['error' => t('login.unavailable'), 'login' => trim(field($_POST, 'login'))], 503);
            } else {
                $this->render('error', ['title' => t('errors.unavailable_title'), 'message' => t('errors.unavailable_message')], 503);
            }
        }
    }

    private function logContractCreationDiagnostic(PDOException $exception): void
    {
        $state = $exception->errorInfo[0] ?? $exception->getCode();
        $sqlstate = is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/D', $state) ? $state : null;
        $driverCode = $exception->errorInfo[1] ?? null;
        $mysqlCode = (is_int($driverCode) && $driverCode >= 0)
            || (is_string($driverCode) && preg_match('/\A[0-9]{1,6}\z/D', $driverCode))
            ? (int) $driverCode : null;

        error_log(json_encode([
            'operation' => 'contract.create',
            'sqlstate' => $sqlstate,
            'mysql_code' => $mysqlCode,
        ], JSON_THROW_ON_ERROR));
    }

    /** Changement de mot de passe : obligatoire après l'embauche, possible à tout moment ensuite. */
    private function password(array $data, string $method): void
    {
        $user = $data['user'];
        $required = (bool) $user['password_change_required'];
        $error = null;
        $policy = null;
        $status = 200;
        if ($method === 'POST') {
            $current = field($_POST, 'current_password');
            $new = field($_POST, 'new_password');
            $auth = new AuthService($this->database());
            // Même limiteur que la connexion : ce formulaire permettrait sinon de deviner le mot de passe actuel.
            if (!LoginLimiter::allow('#account-' . $user['account_id'])) {
                auditEvent('auth.throttled', (int) $user['account_id'], null, null, ['scope' => 'password_change']);
                $error = t('login.rate_limit');
                $status = 429;
            } elseif (strlen($current) > 4096 || ($hash = $auth->verifyCurrentPassword((int) $user['account_id'], $current)) === null) {
                SessionRoles::activate($this->database(), $user);
                auditEvent('auth.password_change_failed', (int) $user['account_id'], null, null, ['reason' => 'current_password']);
                $error = t('password.current_invalid');
                $status = 401;
            } else {
                // Mot de passe actuel correct : la tentative n'est pas décomptée, quelle que soit la suite.
                LoginLimiter::succeeded();
                $policy = PasswordPolicy::error($new, field($_POST, 'confirm_password'), $current,
                    [$user['login'], $user['first_name'], $user['last_name']]);
            }
            if ($error === null && $policy !== null) {
                SessionRoles::activate($this->database(), $user);
                auditEvent('auth.password_change_failed', (int) $user['account_id'], null, null, ['reason' => 'policy']);
                $error = t($policy, ['min' => PasswordPolicy::MIN_LENGTH, 'max' => PasswordPolicy::MAX_LENGTH]);
                $status = 422;
            } elseif ($error === null) {
                $auth->changePassword($user, $hash, $new);
                auditEvent('auth.password_changed', (int) $user['account_id'], null, null, ['first_activation' => $required]);
                $_SESSION['flash'] = $required ? 'notifications.account_activated' : 'notifications.password_changed';
                redirectTo('/dashboard');
            }
        }
        $this->render('password', $data + compact('required', 'error'), $status);
    }

    /** « Mes accès » : version sécurisée, ou variante vulnérable dans une instance de laboratoire (phase 4). */
    /** Supervision de sécurité (phase 7) : piste d'audit récente + signaux suspects, pour l'auditeur. */
    private function audit(array $data): void
    {
        if (!AuthService::can($data['user'], 'audit.read')) {
            $this->denied($data);
            return;
        }
        $repository = new \App\Audit\AuditRepository($this->database());
        $events = $repository->recent(100);
        $signals = $repository->suspicious();
        $this->render('audit', $data + compact('events', 'signals'));
    }

    /** Données personnelles du salarié : affichage masqué et saisie de son propre RIB (phase 6). */
    private function myData(array $data, string $method): void
    {
        $user = $data['user'];
        if (!AuthService::can($user, 'personal_data.view_own')) {
            $this->denied($data);
            return;
        }
        $repository = new PersonalDataRepository($this->database());
        $errors = [];
        $status = 200;
        $values = ['rib' => '', 'bank_name' => ''];
        if ($method === 'POST') {
            $values['rib'] = trim(field($_POST, 'rib'));
            $values['bank_name'] = trim(field($_POST, 'bank_name'));
            $error = $repository->setRib((int) $user['account_id'], $values['rib'], $values['bank_name']);
            if ($error === null) {
                auditEvent('personal_data.rib_updated', (int) $user['account_id'], (int) $user['employee_id']);
                $_SESSION['flash'] = 'personal.rib_saved';
                redirectTo('/my-data');
            }
            $errors['rib'] = $error;
            $status = 422;
        }
        $record = $repository->viewOwn((int) $user['account_id']);
        if ($record !== null) {
            // Phase 7 : accès en lecture à une donnée sensible (RIB/CIN/CNSS masqués) tracé.
            auditEvent('personal_data.viewed', (int) $user['account_id'], (int) $user['employee_id'],
                (int) $user['employee_id'], ['table' => 'employee_personal_data']);
        }
        $this->render('my-data', $data + compact('record', 'errors', 'values'), $status);
    }

    private function myAccess(array $data): void
    {
        $since = trim(field($_GET, 'since'));
        $accountId = (int) $data['user']['account_id'];
        $vulnerable = LabMode::vulnerableAccessHistory();
        $rows = [];
        $error = null;
        $status = 200;
        if ($vulnerable) {
            require_once LabMode::vulnerableFile();
            $labVariant = (string) LabMode::variant();
            auditEvent('lab.vulnerable_query', $accountId, null, null, ['length' => strlen($since), 'variant' => $labVariant]);
            $result = \Lab\Vulnerable\VulnerableAccessHistory::searchVariant($this->database(), $accountId,
                $since === '' ? AccessHistory::DEFAULT_SINCE : $since, $labVariant);
            [$rows, $error, $status] = [$result['rows'], $result['error'], $result['status']];
        } else {
            // Validation serveur d'abord, requête préparée ensuite : deux contrôles indépendants.
            $date = $since === '' ? AccessHistory::DEFAULT_SINCE : AccessHistory::parseDate($since);
            if ($date === null) {
                // Ni la saisie ni un message du SGBD dans la réponse ; le journal garde un indice de détection.
                auditEvent('input.rejected', $accountId, null, null, ['field' => 'since', 'length' => strlen($since),
                    'sql_metacharacters' => preg_match("/['\";#\\\\]|--|\/\*/", $since) === 1]);
                $error = t('access.invalid_date');
                $status = 422;
                $since = '';   // ne pas renvoyer une saisie refusée dans la page
            } else {
                $rows = AccessHistory::search($this->database(), $accountId, $date);
            }
        }
        $labVariant ??= null;
        $this->render('my-access', $data + compact('rows', 'since', 'error', 'vulnerable', 'labVariant'), $status);
    }

    private function denied(array $data): void
    {
        auditEvent('access.denied', (int) $data['user']['account_id']);
        $this->render('error', $data + ['title' => t('errors.denied_title'), 'message' => t('errors.denied_message')], 403);
    }
}
