<?php

declare(strict_types=1);

namespace App\Web;

use App\Auth\AuthService;
use App\HR\EmployeeRepository;
use App\HR\PeriodRepository;
use App\HR\PeriodValidation;
use PDO;
use PDOException;

final class AdvancedHRController
{
    public function __construct(private PDO $db) {}

    public static function matches(string $path): bool
    {
        return (bool) preg_match('~\A/(?:departments|positions|(?:contracts|assignments)(?:/new|/[1-9][0-9]{0,17}(?:/close)?)?|employees/[1-9][0-9]{0,17}/(?:contracts|assignments))\z~D', $path);
    }

    public static function methods(string $path): array
    {
        return str_ends_with($path, '/close') ? ['POST'] : (str_ends_with($path, '/new') ? ['GET', 'POST'] : ['GET']);
    }

    private function render(string $view, array $data, int $status = 200): void
    {
        http_response_code($status);
        extract($data, EXTR_SKIP);
        require dirname(__DIR__) . '/Views/' . $view . '.php';
    }

    public function handle(string $path, string $method, array $data): void
    {
        $parts = explode('/', trim($path, '/'));
        $employeeRoute = $parts[0] === 'employees';
        $kind = $employeeRoute ? $parts[2] : $parts[0];
        $new = !$employeeRoute && ($parts[1] ?? '') === 'new';
        $closing = str_ends_with($path, '/close');
        $permission = $kind . ($new ? '.create' : ($closing ? '.close' : '.read'));
        if (!AuthService::can($data['user'], $permission)
            || ($closing && !AuthService::can($data['user'], $kind . '.read'))) {
            auditEvent('access.denied', (int) $data['user']['account_id']);
            $this->render('error', $data + ['title' => t('errors.denied_title'), 'message' => t('errors.denied_message')], 403);
            return;
        }
        $repo = new PeriodRepository($this->db, locale());
        $data += ['kind' => $kind, 'section' => $kind, 'title' => t('hr.' . $kind),
            'canNew' => AuthService::can($data['user'], $kind . '.create'),
            'canClose' => AuthService::can($data['user'], $kind . '.close'),
            'canPeriodRead' => AuthService::can($data['user'], $kind . '.read')];
        if (in_array($kind, ['departments', 'positions'], true)) {
            $this->render('hr-catalogue', $data + ['rows' => $repo->catalogue($kind)]);
            return;
        }
        $employee = null;
        $employeeId = $employeeRoute ? (int) $parts[1] : filter_var($_GET['employee_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($employeeId) {
            $employee = (new EmployeeRepository($this->db))->find($employeeId);
            if ($employee === null) {
                $this->notFound($data);
                return;
            }
        }
        $data += ['employee' => $employee];
        if ($new) {
            $values = ['employee_number' => $employee['employee_number'] ?? '', 'department_id' => '', 'position_id' => '',
                'reference' => '', 'contract_type' => 'permanent', 'starts_on' => gmdate('Y-m-d'), 'ends_on' => ''];
            if ($method === 'POST') {
                foreach (array_keys($values) as $field) {
                    $values[$field] = trim(field($_POST, $field));
                }
            }
            $errors = $method === 'POST' ? PeriodValidation::errors($kind, $values) : [];
            $status = 200;
            if ($method === 'POST' && $errors === []) {
                $target = $repo->employeeByNumber($values['employee_number']);
                if ($target === null || $target['archived_at'] !== null) {
                    $errors['employee_number'] = 'hr.invalid_employee';
                } else {
                    $values['employee_id'] = (int) $target['id'];
                    try {
                        $id = $repo->create($kind, $values, (int) $data['user']['account_id']);
                        auditEvent('hr.' . $kind . '.created', (int) $data['user']['account_id'], (int) $target['id']);
                        $_SESSION['flash'] = 'hr.saved';
                        redirectTo($data['canPeriodRead'] ? '/' . $kind . '/' . $id : '/dashboard');
                    } catch (PDOException $error) {
                        [$key, $status] = $this->databaseError($error);
                        $errors['form'] = $key;
                    }
                }
            }
            $catalogues = $kind === 'assignments' ? ['departments' => $repo->catalogue('departments', true), 'positions' => $repo->catalogue('positions', true)] : [];
            // Menu déroulant des employés actifs : plus besoin de connaître le matricule par cœur.
            $employees = (new EmployeeRepository($this->db))->activeOptions();
            $this->render('hr-period-form', $data + compact('values', 'errors', 'catalogues', 'employees'), $errors !== [] && $status === 200 ? 422 : $status);
            return;
        }
        if (!$employeeRoute && isset($parts[1])) {
            $row = $repo->find($kind, (int) $parts[1]);
            if ($row === null) {
                $this->notFound($data);
                return;
            }
            $errors = [];
            $closeEnd = field($_POST, 'closed_on');
            $status = 200;
            if ($closing) {
                if (!PeriodValidation::date($closeEnd) || $closeEnd < $row['starts_on']
                    || ($row['ends_on'] !== null && $closeEnd > $row['ends_on'])) {
                    $errors['closed_on'] = 'hr.invalid_period';
                    $status = 422;
                } else {
                    try {
                        $repo->close($kind, (int) $row['id'], $closeEnd, (int) $data['user']['account_id']);
                        auditEvent('hr.' . $kind . '.closed', (int) $data['user']['account_id'], (int) $row['employee_id']);
                        $_SESSION['flash'] = 'hr.closed';
                        redirectTo($data['canPeriodRead'] ? '/' . $kind . '/' . $row['id'] : '/dashboard');
                    } catch (PDOException $error) {
                        [$key, $status] = $this->databaseError($error);
                        $errors['form'] = $key;
                    }
                }
            }
            $this->render('hr-period-detail', $data + compact('row', 'errors', 'closeEnd'), $status);
            return;
        }
        $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) ?: 1;
        $result = $repo->listing($kind, $employee ? (int) $employee['id'] : null, $page);
        $this->render('hr-period-list', $data + compact('result'));
    }

    private function notFound(array $data): void
    {
        $this->render('error', $data + ['title' => t('errors.not_found_title'), 'message' => t('errors.not_found_message')], 404);
    }

    private function databaseError(PDOException $error): array
    {
        $code = (int) ($error->errorInfo[1] ?? 0);
        if ($code === 1062) {
            return ['hr.duplicate_reference', 409];
        }
        if (in_array($code, [1213, 1205], true)) {
            return ['hr.concurrent_retry', 409];
        }
        if ($code === 1644) {
            foreach (['COMP_CONTRACT_COVERAGE' => 'comp.contract_guard', 'HR_OVERLAP' => 'hr.overlap', 'HR_ALREADY_CLOSED' => 'hr.already_closed',
                'HR_DATES' => 'hr.invalid_period', 'HR_EMPLOYEE' => 'hr.invalid_employee',
                'HR_REFERENCE' => 'hr.choose_reference', 'HR_CONTRACT_TYPE' => 'hr.type_dates',
                'HR_FORBIDDEN' => 'errors.denied_message', 'HR_NOT_FOUND' => 'errors.not_found_message'] as $signal => $key) {
                if (($error->errorInfo[2] ?? '') === $signal) {
                    return [$key, $signal === 'HR_FORBIDDEN' ? 403 : 409];
                }
            }
        }
        if ($code === 3819 || $code === 1452) {
            return ['hr.invalid_record', 422];
        }
        throw $error;
    }
}
