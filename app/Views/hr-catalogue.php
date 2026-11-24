<?php require __DIR__ . '/header.php'; ?>
<div class="page-heading"><div><span class="eyebrow"><?= h(t('employees.hr')) ?></span><h1><?= h($title) ?></h1><p class="muted"><?= h(t('hr.catalogue_intro')) ?></p></div></div>
<section class="card table-card"><div class="table-scroll"><table><thead><tr><th><?= h(t('hr.code')) ?></th><th><?= h(t('hr.label')) ?></th><th><?= h(t('employees.state')) ?></th></tr></thead><tbody>
<?php foreach ($rows as $item): ?><tr><td class="mono"><?= h($item['code']) ?></td><td><?= h($item['label']) ?></td><td><span class="badge <?= $item['archived_at'] ? 'archived' : '' ?>"><?= h(t($item['archived_at'] ? 'employees.archived' : 'employees.active')) ?></span></td></tr><?php endforeach; ?>
<?php if ($rows === []): ?><tr><td colspan="3"><div class="empty"><h3><?= h(t('hr.no_catalogue')) ?></h3><p class="muted"><?= h(t('hr.catalogue_admin')) ?></p></div></td></tr><?php endif; ?>
</tbody></table></div></section>
<?php require __DIR__ . '/footer.php'; ?>
