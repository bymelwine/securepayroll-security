<!doctype html>
<html lang="<?= h(locale()) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(t('login.title')) ?> · <?= h(t('app.short')) ?></title>
    <link rel="stylesheet" href="/assets/app.css"><script src="/assets/app.js" defer></script>
</head>
<body class="login-page">
<main class="login-shell">
    <section class="login-story" aria-label="<?= h(t('login.presentation')) ?>">
        <a class="brand" href="/login"><span class="brand-mark">S<span>·</span></span><span><?= h(t('app.brand_primary')) ?><span class="brand-light"><?= h(t('app.brand_secondary')) ?></span></span></a>
        <div class="story-content"><span class="eyebrow"><?= h(t('login.workspace')) ?></span>
            <h1><?= h(t('login.headline_people')) ?><br><?= h(t('login.headline_security')) ?></h1>
            <p><?= h(t('login.story')) ?></p>
            <div class="story-lines" aria-hidden="true"><i></i><i></i><i></i><span><?= h(t('login.hr')) ?></span><span><?= h(t('login.payroll')) ?></span><span><?= h(t('login.finance')) ?></span></div>
        </div>
        <p class="story-footer"><?= h(t('app.name')) ?></p>
    </section>
    <section class="login-form-panel">
        <div class="login-form-wrap"><?php require __DIR__ . '/language-switch.php'; ?><span class="pill"><?= h(t('login.access')) ?></span>
            <h2><?= h(t('login.welcome')) ?></h2><p class="muted"><?= h(t('login.intro')) ?></p>
            <?php if ($error !== null): ?><div class="alert error" role="alert"><?= h($error) ?></div><?php endif; ?>
            <form method="post" action="/login" data-required-message="<?= h(t('validation.required')) ?>">
                <?= csrfField() ?>
                <label for="login"><?= h(t('login.identifier')) ?></label>
                <input id="login" name="login" value="<?= h($login) ?>" autocomplete="username" maxlength="190" placeholder="<?= h(t('login.identifier_placeholder')) ?>" required autofocus>
                <div class="field-label"><label for="password"><?= h(t('login.password')) ?></label><button class="text-button" type="button" data-toggle-password data-show="<?= h(t('login.show')) ?>" data-hide="<?= h(t('login.hide')) ?>" aria-controls="password" aria-pressed="false" hidden><?= h(t('login.show')) ?></button></div>
                <input type="password" id="password" name="password" autocomplete="current-password" maxlength="4096" placeholder="<?= h(t('login.password_placeholder')) ?>" required>
                <button class="button primary full" type="submit"><?= h(t('login.submit')) ?> <span aria-hidden="true">→</span></button>
            </form>
            <p class="login-help"><?= h(t('login.help')) ?></p>
            <div class="security-note"><span aria-hidden="true">◇</span> <?= h(t('login.security')) ?></div>
        </div>
        <footer><?= h(t('login.footer')) ?></footer>
    </section>
</main>
</body></html>
