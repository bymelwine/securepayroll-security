<?php $title = t('employee.title'); $section = (int) $row['id'] === (int) $user['employee_id'] ? 'profile' : 'employees'; require __DIR__ . '/header.php'; ?>
<div class="page-heading"><div><span class="eyebrow"><?= h(t('employee.record')) ?></span><h1><?= h($row['first_name'] . ' ' . $row['last_name']) ?></h1><p class="muted"><?= h(t('employee.intro')) ?></p></div><?php if ($canUpdate): ?><a class="button primary" href="/employees/<?= h($row['id']) ?>/edit"><?= h(t('employee.edit')) ?></a><?php endif; ?></div>
<section class="card detail-card"><div class="section-heading"><h2><?= h(t('employee.identity')) ?></h2><span class="badge <?= $row['archived_at'] ? 'archived' : '' ?>"><?= $row['archived_at'] ? t('employees.archived') : t('employees.active') ?></span></div><dl class="detail-grid"><div><dt><?= h(t('employee.permanent_number')) ?></dt><dd class="mono"><?= h($row['employee_number']) ?></dd></div><div><dt><?= h(t('employee.first_name')) ?></dt><dd><?= h($row['first_name']) ?></dd></div><div><dt><?= h(t('employee.last_name')) ?></dt><dd><?= h($row['last_name']) ?></dd></div><div><dt><?= h(t('employee.created_at')) ?></dt><dd><?= h($row['created_at']) ?></dd></div><div><dt><?= h(t('employee.archived_at')) ?></dt><dd><?= h($row['archived_at'] ?? t('employee.not_archived')) ?></dd></div></dl></section>
<div class="hr-actions">
<?php if (\App\Auth\AuthService::can($user,'compensation.read')): ?><a class="button secondary" href="/compensation/history?employee_id=<?= h($row['id']) ?>"><?= h(t('comp.history')) ?></a><?php endif; ?>
<?php foreach (['assignments', 'contracts'] as $resource): ?>
    <?php if (\App\Auth\AuthService::can($user, $resource . '.read')): ?><a class="button secondary" href="/employees/<?= h($row['id']) ?>/<?= h($resource) ?>"><?= h(t('hr.' . $resource)) ?></a><?php endif; ?>
    <?php if (\App\Auth\AuthService::can($user, $resource . '.create')): ?><a class="button secondary" href="/<?= h($resource) ?>/new?employee_id=<?= h($row['id']) ?>"><?= h(t('hr.new_' . $resource)) ?></a><?php endif; ?>
<?php endforeach; ?>
</div>
<a class="text-link" href="<?= $canRead ? '/employees' : '/dashboard' ?>">← <?= $canRead ? t('employee.back_directory') : t('employee.back_dashboard') ?></a>
<?php require __DIR__ . '/footer.php'; ?>
