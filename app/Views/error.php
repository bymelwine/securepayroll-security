<?php if (isset($user)): $section = ''; require __DIR__ . '/header.php'; else: ?>
<!doctype html><html lang="<?= h(locale()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= h($title) ?> · <?= h(t('app.short')) ?></title><link rel="stylesheet" href="/assets/app.css"></head><body class="error-page"><main>
<?php endif; ?>
<?php if (!isset($user)) { require __DIR__ . '/language-switch.php'; } ?>
<section class="card error-card"><span class="eyebrow"><?= h(strtoupper(t('app.short'))) ?> · <?= h(http_response_code()) ?></span><h1><?= h($title) ?></h1><p class="muted"><?= h($message) ?></p><a class="button primary" href="<?= isset($user) ? '/dashboard' : '/login' ?>"><?= h(t('errors.back')) ?></a></section>
<?php if (isset($user)): require __DIR__ . '/footer.php'; else: ?></main></body></html><?php endif; ?>
