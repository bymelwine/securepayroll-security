<?php $title=t('comp.new'); require __DIR__.'/header.php'; ?>
<div class="page-heading"><div><span class="eyebrow"><?= h(t('comp.title')) ?></span><h1><?= h($title) ?></h1><p class="muted"><?= h(t('comp.form_intro')) ?></p></div></div>
<?php require __DIR__.'/compensation-nav.php'; ?>
<section class="card form-card">
<p class="alert"><?= h(t('comp.effective_rule')) ?></p>
<?php foreach ($errors as $error): ?><p class="alert error" role="alert"><?= h(t($error)) ?></p><?php endforeach; ?>
<form method="post" action="/compensation/new" data-required-message="<?= h(t('validation.required')) ?>">
<?= csrfField() ?>
<div class="form-grid">
<div class="field"><label for="employee_number"><?= h(t('comp.employee_number')) ?></label><select id="employee_number" name="employee_number" required><option value=""><?= h(t('hr.choose_employee')) ?></option><?php foreach ($employees as $option): ?><option value="<?= h($option['employee_number']) ?>" <?= $values['employee_number'] === $option['employee_number'] ? 'selected' : '' ?>><?= h($option['last_name'] . ' ' . $option['first_name'] . ' · ' . $option['employee_number']) ?></option><?php endforeach; ?></select></div>
<?php foreach (['reference','monthly_amount','effective_on'] as $key): ?>
<div class="field"><label for="<?= h($key) ?>"><?= h(t($key==='monthly_amount' ? 'comp.proposed_monthly_amount' : 'comp.'.$key)) ?></label><input id="<?= h($key) ?>" name="<?= h($key) ?>" type="<?= $key==='effective_on' ? 'date' : 'text' ?>" value="<?= h($values[$key]) ?>" required <?= $key==='monthly_amount' ? 'inputmode="decimal" maxlength="13"' : ($key==='reference' ? 'maxlength="64"' : '') ?>></div>
<?php endforeach; ?>
</div>
<div class="field"><label for="justification"><?= h(t('comp.justification')) ?></label><textarea id="justification" name="justification" maxlength="2000" rows="5" required><?= h($values['justification']) ?></textarea></div>
<p class="muted small"><?= h(t('comp.amount_hint')) ?></p>
<div class="hr-actions"><button class="button primary" type="submit"><?= h(t('comp.submit')) ?></button><a class="button secondary" href="/compensation"><?= h(t('comp.cancel')) ?></a></div>
</form></section>
<?php require __DIR__.'/footer.php'; ?>
