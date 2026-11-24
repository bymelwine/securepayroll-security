<form method="post" action="/language" class="language-switch" aria-label="<?= h(t('language.choose')) ?>">
    <?= csrfField() ?>
    <input type="hidden" name="return_to" value="<?= h(languageReturnPath($_SERVER['REQUEST_URI'] ?? '/')) ?>">
    <button type="submit" name="language" value="fr" lang="fr" aria-label="<?= h(t('language.french')) ?>" aria-pressed="<?= locale() === 'fr' ? 'true' : 'false' ?>"><?= h(t('language.fr_short')) ?></button>
    <span aria-hidden="true">|</span>
    <button type="submit" name="language" value="en" lang="en" aria-label="<?= h(t('language.english')) ?>" aria-pressed="<?= locale() === 'en' ? 'true' : 'false' ?>"><?= h(t('language.en_short')) ?></button>
</form>
