<?php

declare(strict_types=1);

namespace App\Web;

use App\Auth\AuthService;
use App\Compensation\CompensationValidation;
use App\Payroll\Repository;
use App\Payroll\Validation;
use PDO;
use PDOException;

final class PayrollController
{
    public function __construct(private PDO $db) {}

    public static function matches(string $path): bool
    {
        return (bool) preg_match('~\A/(?:payroll(?:/periods|/inputs|/inputs/[1-9][0-9]{0,17}/decision|/calculate)?|payslips/[1-9][0-9]{0,17}(?:/transition|/settlement)?)\z~D', $path);
    }

    public static function methods(string $path): array
    {
        if (str_ends_with($path, '/settlement')) { return ['GET', 'POST']; }
        return $path === '/payroll' || preg_match('~\A/payslips/[1-9][0-9]{0,17}\z~D', $path) ? ['GET'] : ['POST'];
    }

    private function render(string $view, array $data, int $status = 200): void
    {
        http_response_code($status);
        extract($data, EXTR_SKIP);
        require dirname(__DIR__) . '/Views/' . $view . '.php';
    }

    public function handle(string $path, string $method, array $data): void
    {
        if (str_ends_with($path, '/settlement')) {
            (new SettlementController($this->db))->handle($path, $method, $data);
            return;
        }
        $user = $data['user'];
        $actor = (int) $user['account_id'];
        $repository = new Repository($this->db);
        $data += ['section' => 'payroll'];
        $permission = match (true) {
            $path === '/payroll/periods' => 'payroll.period.create',
            $path === '/payroll/inputs' => 'payroll.input.submit',
            str_ends_with($path, '/decision') => 'payroll.input.decide',
            $path === '/payroll/calculate' => 'payroll.calculate',
            str_ends_with($path, '/transition') => match (field($_POST, 'event')) {
                'verified' => 'payslip.verify', 'approved', 'rejected' => 'payslip.decide',
                'published' => 'payslip.publish', default => 'payroll.invalid',
            },
            default => AuthService::can($user, 'payroll.read') ? 'payroll.read' : 'payslip.self',
        };
        if (!AuthService::can($user, $permission)) {
            auditEvent('payroll.access_denied', $actor);
            $this->render('error', $data + ['title' => t('errors.denied_title'), 'message' => t('errors.denied_message')], 403);
            return;
        }
        try {
            if ($method === 'GET') {
                if ($path === '/payroll') {
                    $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) ?: 1;
                    $result = $repository->overview($actor, $page);
                    $this->render('payroll', $data + compact('result', 'page'));
                } else {
                    $id = (int) basename($path);
                    $detail = $repository->detail($id, $actor);
                    // Phase 7 : consultation d'un bulletin (donnée sensible) tracée.
                    auditEvent('payslip.viewed', (int) $actor, null, $id, ['table' => 'payslip_events']);
                    $this->render('payslip', $data + $detail);
                }
                return;
            }
            $reason = trim(field($_POST, 'reason'));
            $target = '/payroll';
            $audit = 'payroll.operation';
            if ($path === '/payroll/periods') {
                $month = field($_POST, 'month');
                $bonusCap = Validation::amount(trim(field($_POST, 'bonus_cap')));
                $deductionCap = Validation::amount(trim(field($_POST, 'deduction_cap')));
                if (!Validation::month($month) || $bonusCap === null || $deductionCap === null || !CompensationValidation::text($reason, 2000)) {
                    throw new \InvalidArgumentException();
                }
                $id = $repository->createPeriod($month, $bonusCap, $deductionCap, $reason, $actor);
                $audit = 'payroll.period_created';
            } elseif ($path === '/payroll/inputs') {
                $values = [];
                foreach (['period_id', 'employee_id', 'kind', 'amount', 'starts_on', 'ends_on', 'reason'] as $key) {
                    $values[$key] = trim(field($_POST, $key));
                }
                if (!Validation::input($values)) { throw new \InvalidArgumentException(); }
                $id = $repository->submitInput((int) $values['period_id'], (int) $values['employee_id'], $values['kind'],
                    Validation::amount($values['amount']), $values['starts_on'], $values['ends_on'], $values['reason'], $actor);
                $audit = 'payroll.input_submitted';
            } elseif (str_ends_with($path, '/decision')) {
                $id = (int) explode('/', $path)[3];
                $decision = field($_POST, 'decision');
                if (!in_array($decision, ['approved', 'rejected'], true) || !CompensationValidation::text($reason, 2000)) {
                    throw new \InvalidArgumentException();
                }
                $repository->decideInput($id, $decision, $reason, $actor);
                $audit = 'payroll.input_' . $decision;
            } elseif ($path === '/payroll/calculate') {
                $period = Validation::id(field($_POST, 'period_id'));
                $employee = Validation::id(field($_POST, 'employee_id'));
                if ($period === null || $employee === null) { throw new \InvalidArgumentException(); }
                $id = $repository->calculate($period, $employee, $actor);
                $target = '/payslips/' . $id;
                $audit = 'payroll.calculated';
            } else {
                $id = (int) explode('/', $path)[2];
                if (!CompensationValidation::text($reason, 2000)) { throw new \InvalidArgumentException(); }
                $event = field($_POST, 'event');
                $repository->transition($id, $event, $reason, $actor);
                $target = '/payslips/' . $id;
                $audit = 'payslip.' . $event;
            }
            // IDs only, never financial amounts or free text in the technical log.
            auditEvent($audit, $actor, null, $id);
            $_SESSION['flash'] = 'pay.saved';
            redirectTo($target);
        } catch (\InvalidArgumentException) {
            $this->render('error', $data + ['title' => t('pay.title'), 'message' => t('pay.invalid')], 422);
        } catch (PDOException $exception) {
            $message = $exception->errorInfo[2] ?? '';
            $code = (int) ($exception->errorInfo[1] ?? 0);
            $key = 'pay.unavailable';
            $status = 503;
            foreach (['FORBIDDEN' => [403,'forbidden'], 'NOT_FOUND' => [404,'not_found'], 'INVALID' => [422,'invalid'],
                'DECIDED' => [409,'decided'], 'FROZEN' => [409,'frozen'], 'CAP' => [422,'cap'], 'OVERLAP' => [409,'overlap'],
                'COVERAGE' => [422,'coverage'], 'SALARY' => [422,'salary'], 'NET' => [422,'net_error'], 'PENDING' => [409,'pending_inputs'],
                'STATE' => [409,'state'], 'STALE' => [409,'stale']] as $token => [$http, $label]) {
                if (is_string($message) && str_contains($message, 'PAY_' . $token)) { $status = $http; $key = 'pay.' . $label; break; }
            }
            if (in_array($code, [1062,1205,1213], true)) { $status = 409; $key = 'pay.conflict'; }
            auditEvent($status === 403 ? 'payroll.access_denied' : 'payroll.operation_failed', $actor);
            $this->render('error', $data + ['title' => t('pay.title'), 'message' => t($key)], $status);
        }
    }
}
