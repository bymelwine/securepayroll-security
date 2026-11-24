<?php $title = t('access.title'); $section = 'my-access'; require __DIR__ . '/header.php';
$known = ['employee', 'hr_manager', 'payroll_manager', 'finance_manager', 'application_administrator', 'security_auditor'];
$state = static function (array $row): string {
    $now = gmdate('Y-m-d H:i:s');
    if ($row[3] !== null && (string) $row[3] <= $now) { return t('access.revoked'); }
    if ($row[2] !== null && (string) $row[2] <= $now) { return t('access.expired'); }
    return (string) $row[1] > $now ? t('access.planned') : t('access.active');
};
?>
<div class="page-heading"><div><span class="eyebrow"><?= h(t('access.eyebrow')) ?></span><h1><?= h(t('access.title')) ?></h1><p class="muted"><?= h(t('access.intro')) ?></p></div></div>
<?php if ($vulnerable): ?><div class="alert error" role="alert"><strong><?= h(t('access.lab_banner')) ?></strong> <?= h(t('access.lab_variant', ['variant' => (string) $labVariant])) ?></div><?php endif; ?>
<?php if ($error !== null): ?><div class="alert error" role="alert"><?= h($error) ?></div><?php endif; ?>
<section class="card table-card"><div class="table-toolbar"><h2><?= h(t('access.history')) ?> <span class="count"><?= h(count($rows)) ?></span></h2>
<form class="search" method="get" action="/my-access"><label class="sr-only" for="since"><?= h(t('access.since')) ?></label><input id="since" name="since" type="date" value="<?= h($since) ?>"><button class="button secondary" type="submit"><?= h(t('access.filter')) ?></button></form></div>
<div class="table-scroll"><table><thead><tr><th><?= h(t('access.role')) ?></th><th><?= h(t('access.valid_from')) ?></th><th><?= h(t('access.expires_at')) ?></th><th><?= h(t('access.revoked_at')) ?></th><th><?= h(t('access.state')) ?></th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><strong><?= h(in_array($row[0], $known, true) ? t('roles.' . $row[0]) : (string) $row[0]) ?></strong></td><td class="mono"><?= h((string) $row[1]) ?></td><td class="mono"><?= h((string) ($row[2] ?? '—')) ?></td><td class="mono"><?= h((string) ($row[3] ?? '—')) ?></td><td><span class="badge"><?= h($state($row)) ?></span></td></tr>
<?php endforeach; ?>
<?php if ($rows === []): ?><tr><td colspan="5"><div class="empty"><h3><?= h(t('access.empty')) ?></h3></div></td></tr><?php endif; ?>
</tbody></table></div></section>
<?php require __DIR__ . '/footer.php'; ?>
