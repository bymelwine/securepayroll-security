<?php $title = t('password.title'); $section = 'password'; require __DIR__ . '/header.php'; ?>
<div class="page-heading"><div><span class="eyebrow"><?= h(t('password.eyebrow')) ?></span><h1><?= h($required ? t('password.required_title') : t('password.title')) ?></h1><p class="muted"><?= h($required ? t('password.required_intro') : t('password.intro')) ?></p></div></div>
<section class="card form-card"><div class="form-intro"><h2><?= h(t('password.rules_title')) ?></h2><p class="muted"><?= h(t('password.rules', ['min' => \App\Auth\PasswordPolicy::MIN_LENGTH])) ?></p></div>
<?php if ($error !== null): ?><div class="alert error" role="alert"><?= h($error) ?></div><?php endif; ?>
<form method="post" action="/password" data-required-message="<?= h(t('validation.required')) ?>">
<?= csrfField() ?>
<input type="text" name="username" value="<?= h($user['login']) ?>" autocomplete="username" hidden readonly>
<div class="form-grid">
<div class="span-full"><label for="current_password"><?= h($required ? t('password.temporary') : t('password.current')) ?></label><input type="password" id="current_password" name="current_password" autocomplete="current-password" maxlength="4096" required autofocus></div>
<div><label for="new_password"><?= h(t('password.new')) ?></label><input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="<?= \App\Auth\PasswordPolicy::MIN_LENGTH ?>" maxlength="<?= \App\Auth\PasswordPolicy::MAX_LENGTH ?>" required></div>
<div><label for="confirm_password"><?= h(t('password.confirm')) ?></label><input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" maxlength="<?= \App\Auth\PasswordPolicy::MAX_LENGTH ?>" required></div>
</div>
<div class="form-actions"><button class="button primary" type="submit"><?= h(t('password.submit')) ?></button></div>
</form></section>
<?php require __DIR__ . '/footer.php'; ?>
