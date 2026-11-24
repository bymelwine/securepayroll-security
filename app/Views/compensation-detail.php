<?php $title=t('comp.request_title',['id'=>$row['id']]); require __DIR__.'/header.php'; ?>
<div class="page-heading"><div><span class="eyebrow"><?= h(t('comp.title')) ?></span><h1><?= h($title) ?></h1><p class="muted"><?= h($row['first_name'].' '.$row['last_name'].' · '.$row['employee_number']) ?></p></div><span class="badge comp-<?= h($row['status']) ?>"><?= h(t('comp.'.$row['status'])) ?></span></div>
<?php require __DIR__.'/compensation-nav.php'; ?>
<?php foreach ($errors as $error): ?><p class="alert error" role="alert"><?= h(t($error)) ?></p><?php endforeach; ?>
<section class="card detail-card"><dl class="detail-grid">
<div><dt><?= h(t('comp.contract_period')) ?></dt><dd><?= h($row['starts_on']) ?> → <?= h($row['effective_ends_on'] ?? t('comp.open_ended')) ?></dd></div>
<?php foreach (['reference','monthly_amount','effective_on','request_kind','requested_at'] as $key): ?><div><dt><?= h(t('comp.'.$key)) ?></dt><dd><?= h($key==='monthly_amount' ? \App\Finance\Money::format($row[$key],$row['currency']) : ($key==='request_kind' ? t('comp.'.$row[$key]) : $row[$key])) ?></dd></div><?php endforeach; ?>
<div><dt><?= h(t('comp.requester')) ?></dt><dd><?= h($row['requester_first'].' '.$row['requester_last']) ?></dd></div>
<div><dt><?= h(t('comp.justification')) ?></dt><dd class="comp-text"><?= h($row['justification']) ?></dd></div>
<?php if ($row['status']!=='pending'): ?><div><dt><?= h(t('comp.approver')) ?></dt><dd><?= h($row['approver_first'].' '.$row['approver_last']) ?><br><?= h($row['decided_at']) ?></dd></div><div><dt><?= h(t('comp.decision_reason')) ?></dt><dd class="comp-text"><?= h($row['decision_reason']) ?></dd></div><?php endif; ?>
</dl></section>
<?php if ($row['currency']!==\App\Finance\Money::REFERENCE_CURRENCY): ?><p class="alert"><?= h(t('comp.currency_review')) ?></p><?php endif; ?>
<?php if ($canDecide && $row['status']==='pending'): ?>
<section class="card form-card"><h2><?= h(t('comp.finance')) ?></h2>
<?php if (!$independent): ?><p class="alert error"><?= h(t('comp.self_approval')) ?></p><?php else: ?>
<p class="muted"><?= h(t('comp.decision_hint')) ?></p>
<form method="post" action="/compensation/<?= h($row['id']) ?>/decision" data-required-message="<?= h(t('validation.required')) ?>"><?= csrfField() ?>
<div class="field"><label for="reason"><?= h(t('comp.decision_reason')) ?></label><textarea id="reason" name="reason" maxlength="2000" rows="4" required><?= h($reason) ?></textarea></div>
<div class="hr-actions"><?php if ($row['currency']===\App\Finance\Money::REFERENCE_CURRENCY): ?><button class="button primary" name="decision" value="approved" type="submit"><?= h(t('comp.approve')) ?></button><?php endif; ?><button class="button secondary" name="decision" value="rejected" type="submit"><?= h(t('comp.reject')) ?></button></div></form>
<?php endif; ?></section><?php endif; ?>
<section class="card table-card"><h2 class="comp-section-title"><?= h(t('comp.events')) ?></h2><div class="table-scroll"><table><thead><tr><th><?= h(t('comp.event')) ?></th><th><?= h(t('comp.actor')) ?></th><th><?= h(t('comp.date')) ?></th></tr></thead><tbody>
<?php foreach ($events as $event): ?><tr><td><?= h(t('comp.event_'.$event['event_type'])) ?></td><td><?= h($event['first_name'].' '.$event['last_name']) ?></td><td><?= h($event['occurred_at']) ?> <?= h(t('common.utc')) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php require __DIR__.'/footer.php'; ?>
