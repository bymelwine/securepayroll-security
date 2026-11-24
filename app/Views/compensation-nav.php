<div class="hr-actions compensation-tabs">
<a class="button secondary" href="/compensation"><?= h(t('comp.requests')) ?></a>
<a class="button secondary" href="/compensation/pending"><?= h(t($canDecide ? 'comp.finance' : 'comp.pending')) ?></a>
<a class="button secondary" href="/compensation/history"><?= h(t('comp.history')) ?></a>
<?php if ($canSubmit): ?><a class="button secondary" href="/compensation/employees"><?= h(t('comp.contracted_employees')) ?></a><a class="button primary" href="/compensation/new"><?= h(t('comp.new')) ?></a><?php endif; ?>
</div>
