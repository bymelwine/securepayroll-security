<?php $title = t('personal.title'); $section = 'my-data'; require __DIR__ . '/header.php'; ?>
<div class="page-heading"><div><span class="eyebrow"><?= h(t('personal.eyebrow')) ?></span><h1><?= h(t('personal.title')) ?></h1><p class="muted"><?= h(t('personal.intro')) ?></p></div></div>
<?php if ($record === null): ?>
<section class="card"><div class="empty"><h3><?= h(t('personal.no_record_title')) ?></h3><p class="muted"><?= h(t('personal.no_record_notice')) ?></p></div></section>
<?php else: ?>
<section class="card">
<h2><?= h(t('personal.identity')) ?></h2>
<p class="muted small"><?= h(t('personal.masked_note')) ?></p>
<div class="table-scroll"><table><tbody>
<tr><th><?= h(t('personal.document')) ?></th><td class="mono"><?= h(t('personal.doc_type.' . $record['id_document_type'])) ?> · <?= h($record['id_document_masked']) ?></td></tr>
<tr><th><?= h(t('personal.cnss')) ?></th><td class="mono"><?= $record['cnss_masked'] !== null ? h($record['cnss_masked']) : h(t('personal.not_set')) ?></td></tr>
<tr><th><?= h(t('personal.rib')) ?></th><td class="mono"><?= $record['has_rib'] ? h($record['rib_masked']) : h(t('personal.not_set')) ?></td></tr>
<tr><th><?= h(t('personal.bank')) ?></th><td><?= h($record['bank_name']) ?></td></tr>
<tr><th><?= h(t('personal.birth')) ?></th><td class="mono"><?= h((string) $record['birth_date']) ?></td></tr>
<tr><th><?= h(t('personal.marital')) ?></th><td><?= h(t('personal.marital.' . $record['marital_status'])) ?> · <?= h(t('personal.children', ['n' => (string) $record['dependent_children']])) ?></td></tr>
<tr><th><?= h(t('personal.email')) ?></th><td><?= h($record['personal_email']) ?></td></tr>
<tr><th><?= h(t('personal.phone')) ?></th><td class="mono"><?= h($record['personal_phone']) ?></td></tr>
<tr><th><?= h(t('personal.city')) ?></th><td><?= h($record['city']) ?></td></tr>
</tbody></table></div>
</section>
<section class="card form-card">
<h2><?= h(t('personal.rib_form_title')) ?></h2>
<p class="muted small"><?= h(t('personal.rib_help')) ?></p>
<?php if (isset($errors['rib']) && $errors['rib'] !== null): ?><div class="alert error" role="alert"><?= h(t($errors['rib'])) ?></div><?php endif; ?>
<form method="post" action="/my-data">
<?= csrfField() ?>
<div class="form-grid">
<div class="field"><label for="rib"><?= h(t('personal.rib_label')) ?></label><input id="rib" name="rib" inputmode="numeric" maxlength="24" autocomplete="off" value="<?= h($values['rib']) ?>" required <?= isset($errors['rib']) && $errors['rib'] !== null ? 'aria-invalid="true"' : '' ?>></div>
<div class="field"><label for="bank_name"><?= h(t('personal.bank_label')) ?></label><input id="bank_name" name="bank_name" maxlength="100" value="<?= h($values['bank_name'] !== '' ? $values['bank_name'] : $record['bank_name']) ?>" required></div>
</div>
<div class="hr-actions"><button class="button primary" type="submit"><?= h(t('personal.save')) ?></button></div>
</form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
