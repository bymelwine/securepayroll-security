<?php

declare(strict_types=1);

namespace App\Web;

use App\Auth\AuthService;
use App\Compensation\CompensationValidation;
use App\Payroll\Repository;
use App\Payroll\Validation;
use PDO;
use PDOException;

final class SettlementController
{
    public function __construct(private PDO $db) {}

    private function render(string $view, array $data, int $status = 200): void
    {
        http_response_code($status);
        extract($data, EXTR_SKIP);
        require dirname(__DIR__) . '/Views/' . $view . '.php';
    }

    public static function permission(string $action): ?string
    {
        return match ($action) {
            'correct' => 'payslip.correct', 'verified' => 'payslip.verify',
            'approved', 'rejected' => 'payslip.decide', 'published' => 'payslip.publish',
            'pay' => 'payroll.payment', 'recover_request' => 'payroll.recovery.request',
            'recover_approve', 'recover_reject' => 'payroll.recovery.decide',
            'recover_receive' => 'payroll.recovery.receive', default => null,
        };
    }

    public function handle(string $path, string $method, array $data): void
    {
        $data += ['section' => 'payroll', 'title' => t('settle.title')];
        if (getenv('SETTLEMENT_ENABLED') !== '1') {
            $this->render('error', $data + ['message' => t('errors.not_found_message')], 404);
            return;
        }
        $root = (int) explode('/', $path)[2];
        $actor = (int) $data['user']['account_id'];
        $action = field($_POST, 'action');
        $permission = $method === 'GET'
            ? (AuthService::can($data['user'], 'payroll.read') ? 'payroll.read' : 'payslip.self')
            : self::permission($action);
        if ($permission === null || !AuthService::can($data['user'], $permission)) {
            auditEvent('settlement.access_denied', $actor, null, $root);
            $this->render('error', $data + ['message' => t('pay.forbidden')], 403);
            return;
        }
        try {
            $repository = new Repository($this->db);
            if ($method === 'GET') {
                $this->render('settlement', $data + $repository->settlement($root, $actor));
                return;
            }
            $reason = trim(field($_POST, 'reason'));
            $expectedRaw = field($_POST, 'expected');
            $expected = $expectedRaw === '0' ? 0 : Validation::id($expectedRaw);
            $targetRaw = field($_POST, 'target');
            $target = $targetRaw === '' || $targetRaw === '0' ? 0 : Validation::id($targetRaw);
            $reference = trim(field($_POST, 'reference'));
            if ($expected === null || $target === null || !CompensationValidation::text($reason, 2000)
                || (in_array($action, ['pay','recover_receive'], true)
                    && !preg_match('/\A[A-Za-z0-9_-]{8,64}\z/D', $reference))) {
                throw new \InvalidArgumentException();
            }
            $amounts = [null, null, null];
            if ($action === 'correct') {
                foreach (['base','bonus','deduction'] as $index => $key) {
                    $amounts[$index] = Validation::amount(trim(field($_POST, $key)));
                    if ($amounts[$index] === null) { throw new \InvalidArgumentException(); }
                }
            }
            $id = $repository->settle([$root, $action, $target, $expected, ...$amounts, $reference, $reason, $actor]);
            auditEvent('settlement.' . $action, $actor, null, $id);
            $_SESSION['flash'] = 'settle.saved';
            redirectTo($path);
        } catch (\InvalidArgumentException) {
            $this->render('error', $data + ['message' => t('pay.invalid')], 422);
        } catch (PDOException $error) {
            $message = (string) ($error->errorInfo[2] ?? '');
            $code = (int) ($error->errorInfo[1] ?? 0);
            $status = 503; $key = 'pay.unavailable';
            foreach (['FORBIDDEN'=>[403,'forbidden'], 'NOT_FOUND'=>[404,'not_found'],
                'INVALID'=>[422,'invalid'], 'CAP'=>[422,'cap'], 'STATE'=>[409,'state'], 'STALE'=>[409,'stale']] as $token => [$http,$label]) {
                if (str_contains($message, 'PAY_' . $token)) { $status=$http; $key='pay.' . $label; break; }
            }
            if (in_array($code, [1062,1205,1213], true)) { $status=409; $key='pay.conflict'; }
            auditEvent('settlement.operation_failed', $actor, null, $root);
            $this->render('error', $data + ['message' => t($key)], $status);
        }
    }
}
