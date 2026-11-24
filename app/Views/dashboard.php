<?php
$title = t('nav.dashboard'); $section = 'dashboard'; require __DIR__ . '/header.php';
$descriptions = [
    'employee' => [t('dashboard.employee.title'), t('dashboard.employee.description')],
    'hr_manager' => [t('dashboard.hr_manager.title'), t('dashboard.hr_manager.description')],
    'payroll_manager' => [t('dashboard.payroll_manager.title'), t('dashboard.payroll_manager.description')],
    'finance_manager' => [t('dashboard.finance_manager.title'), t('dashboard.finance_manager.description')],
    'application_administrator' => [t('dashboard.application_administrator.title'), t('dashboard.application_administrator.description')],
    'security_auditor' => [t('dashboard.security_auditor.title'), t('dashboard.security_auditor.description')],
];
?>
<div class="page-heading"><div><span class="eyebrow"><?= h(t('dashboard.overview')) ?></span><h1><?= h(t('dashboard.greeting', ['name' => $user['first_name']])) ?></h1><p class="muted"><?= h(t('dashboard.welcome')) ?></p></div><span class="date-tag"><?= h(gmdate(t('format.date'))) ?> · <?= h(t('common.utc')) ?></span></div>
<section class="welcome-banner"><div><span class="eyebrow"><?= h(t('dashboard.daily')) ?></span><h2><?= h(t('dashboard.banner_title')) ?></h2><p><?= h(t('dashboard.banner_text')) ?></p><a class="button light" href="/employees/<?= h($user['employee_id']) ?>"><?= h(t('dashboard.view_profile')) ?> <span>↗</span></a></div><div class="banner-art" aria-hidden="true"><span><?= h(t('login.hr')) ?></span><i></i><b>↗</b></div></section>
<div class="section-heading"><h2><?= h(t('dashboard.spaces')) ?></h2><span class="muted"><?= h(t('dashboard.active_roles')) ?></span></div>
<?php if (\App\Auth\AuthService::can($user,'compensation.read')): ?><p><a class="button primary" href="/compensation"><?= h(t('comp.title')) ?></a></p><?php endif; ?>
<div class="card-grid">
<?php foreach ($user['roles'] as $code => $role): $description = $descriptions[$code] ?? [t('dashboard.fallback_title'), t('dashboard.fallback_description')]; ?>
    <section class="card role-card"><span class="card-icon" aria-hidden="true"><?= $code === 'hr_manager' ? '♧' : '▦' ?></span><span class="pill subtle"><?= h(t('roles.' . $code)) ?></span><h3><?= h($description[0]) ?></h3><p class="muted"><?= h($description[1]) ?></p>
    <?php if ($code === 'hr_manager' && $canRead): ?><a class="text-link" href="/employees"><?= h(t('dashboard.directory')) ?> <span>→</span></a>
    <?php elseif ($code === 'finance_manager' && \App\Auth\AuthService::can($user,'compensation.read')): ?><a class="text-link" href="/compensation/pending"><?= h(t('comp.finance')) ?> →</a>
    <?php elseif (in_array($code,['payroll_manager','employee','security_auditor'],true) && (\App\Auth\AuthService::can($user,'payroll.read') || \App\Auth\AuthService::can($user,'payslip.self'))): ?><a class="text-link" href="/payroll"><?= h(t('pay.title')) ?> →</a>
    <?php if ($code==='security_auditor' && getenv('IAM_ENABLED')==='1' && \App\Auth\AuthService::can($user,'iam.read')): ?><p><a class="text-link" href="/iam"><?= h(t('iam.history')) ?> →</a></p><?php endif; ?>
    <?php elseif ($code === 'application_administrator' && getenv('IAM_ENABLED')==='1' && \App\Auth\AuthService::can($user,'iam.read')): ?><a class="text-link" href="/iam"><?= h(t('iam.title')) ?> →</a>
    <?php elseif ($code === 'employee'): ?><a class="text-link" href="/employees/<?= h($user['employee_id']) ?>"><?= h(t('dashboard.open_profile')) ?> <span>→</span></a>
    <?php else: ?><span class="muted small"><?= $code === 'hr_manager' ? t('dashboard.hr_pending') : t('dashboard.coming_soon') ?></span><?php endif; ?></section>
<?php endforeach; ?>
<?php if ($user['roles'] === []): ?><section class="card"><h3><?= h(t('roles.none')) ?></h3><p class="muted"><?= h(t('dashboard.no_roles')) ?></p></section><?php endif; ?>
</div>
<section class="card profile-summary"><div><span class="eyebrow"><?= h(t('dashboard.identity')) ?></span><h3><?= h($user['first_name'] . ' ' . $user['last_name']) ?></h3><p class="muted"><?= h(t('employees.number')) ?> <strong><?= h($user['employee_number']) ?></strong></p></div><a class="button secondary" href="/employees/<?= h($user['employee_id']) ?>"><?= h(t('dashboard.profile')) ?></a></section>
<?php require __DIR__ . '/footer.php'; ?>
