<!doctype html>
<html lang="<?= h(locale()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h(t('app.short')) ?></title><link rel="stylesheet" href="/assets/app.css"><script src="/assets/app.js" defer></script></head>
<body class="workspace">
<aside class="sidebar" id="navigation">
    <a class="brand" href="/dashboard"><span class="brand-mark">S<span>·</span></span><span><?= h(t('app.brand_primary')) ?><span class="brand-light"><?= h(t('app.brand_secondary')) ?></span></span></a>
    <p class="nav-label"><?= h(t('nav.workspace_upper')) ?></p>
    <nav aria-label="<?= h(t('nav.main')) ?>">
    <?php if ((bool) ($user['password_change_required'] ?? false)): ?>
        <a href="/password" class="nav-item active"><span aria-hidden="true">⚷</span> <?= h(t('password.title')) ?></a>
    <?php else: ?>
        <a href="/dashboard" class="nav-item <?= $section === 'dashboard' ? 'active' : '' ?>"><span aria-hidden="true">▦</span> <?= h(t('nav.dashboard')) ?></a>
        <?php if ($canRead): ?><a href="/employees" class="nav-item <?= $section === 'employees' ? 'active' : '' ?>"><span aria-hidden="true">♧</span> <?= h(t('nav.employees')) ?></a><?php endif; ?>
        <?php if ($canCreate): ?><a href="/employees/new" class="nav-item <?= $section === 'new' ? 'active' : '' ?>"><span aria-hidden="true">＋</span> <?= h(t('nav.add_employee')) ?></a><?php endif; ?>
        <?php foreach (['departments', 'positions', 'assignments', 'contracts'] as $resource): ?>
            <?php if (\App\Auth\AuthService::can($user, $resource . '.read')): ?><a href="/<?= h($resource) ?>" class="nav-item <?= $section === $resource ? 'active' : '' ?>"><span aria-hidden="true">▤</span> <?= h(t('hr.' . $resource)) ?></a><?php endif; ?>
        <?php endforeach; ?>
        <?php if (\App\Auth\AuthService::can($user, 'compensation.read')): ?>
            <a href="/compensation" class="nav-item <?= $section === 'compensation' ? 'active' : '' ?>"><span aria-hidden="true">◇</span> <?= h(t('comp.title')) ?></a>
            <?php if (\App\Auth\AuthService::can($user, 'compensation.decide')): ?><a href="/compensation/pending" class="nav-item"><span aria-hidden="true">✓</span> <?= h(t('comp.finance')) ?></a><?php endif; ?>
        <?php endif; ?>
        <?php if (\App\Auth\AuthService::can($user, 'payroll.read') || \App\Auth\AuthService::can($user, 'payslip.self')): ?><a href="/payroll" class="nav-item <?= $section === 'payroll' ? 'active' : '' ?>"><span aria-hidden="true">▤</span> <?= h(t('pay.title')) ?></a><?php endif; ?>
        <?php if (getenv('IAM_ENABLED') === '1' && \App\Auth\AuthService::can($user,'iam.read')): ?><a href="/iam" class="nav-item <?= $section === 'iam' ? 'active' : '' ?>"><?= h(t('iam.title')) ?></a><?php endif; ?>
        <?php if (\App\Auth\AuthService::can($user, 'audit.read')): ?><a href="/audit" class="nav-item <?= $section === 'audit' ? 'active' : '' ?>"><span aria-hidden="true">◉</span> <?= h(t('audit.title')) ?></a><?php endif; ?>
        <a href="/employees/<?= h($user['employee_id']) ?>" class="nav-item <?= $section === 'profile' ? 'active' : '' ?>"><span aria-hidden="true">◎</span> <?= h(t('nav.profile')) ?></a>
        <?php if (\App\Auth\AuthService::can($user, 'personal_data.view_own')): ?><a href="/my-data" class="nav-item <?= $section === 'my-data' ? 'active' : '' ?>"><span aria-hidden="true">⛉</span> <?= h(t('personal.title')) ?></a><?php endif; ?>
        <a href="/my-access" class="nav-item <?= $section === 'my-access' ? 'active' : '' ?>"><span aria-hidden="true">⚿</span> <?= h(t('access.title')) ?></a>
        <a href="/password" class="nav-item <?= $section === 'password' ? 'active' : '' ?>"><span aria-hidden="true">⚷</span> <?= h(t('password.title')) ?></a>
    <?php endif; ?>
    </nav>
    <div class="sidebar-bottom"><span class="status-dot"></span> <?= h(t('nav.professional')) ?><p><?= h(t('app.name')) ?></p></div>
</aside>
<div class="workspace-main">
    <header class="topbar"><div class="topbar-left"><button class="menu-button" type="button" data-menu aria-controls="navigation" aria-expanded="false" hidden>☰ <span class="sr-only"><?= h(t('nav.navigation')) ?></span></button><span class="breadcrumb"><?= h(t('nav.workspace')) ?> <span>/</span> <strong><?= h($title) ?></strong></span></div>
        <div class="header-actions"><?php require __DIR__ . '/language-switch.php'; ?><div class="account"><span class="avatar"><?= h(preg_replace('/\s/u', '', $user['first_name']) !== '' ? preg_replace('/\A(.).*\z/us', '$1', $user['first_name']) : 'U') ?></span><div><strong><?= h($user['first_name'] . ' ' . $user['last_name']) ?></strong><small><?= h(roleNames($user['roles']) ?: t('roles.none')) ?></small></div><form method="post" action="/logout"><?= csrfField() ?><button class="logout" title="<?= h(t('nav.logout_title')) ?>"><?= h(t('nav.logout')) ?></button></form></div></div>
    </header>
    <main class="page-content" id="main">
        <?php if ($flash ?? null): ?><div class="alert success" role="status"><?= h(is_array($flash) ? t($flash[0], $flash[1]) : t($flash)) ?></div><?php endif; ?>
