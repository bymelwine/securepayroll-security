<?php
use App\Auth\AuthService;
use App\Finance\Money;
$title=t('settle.title');
$root=(int)$summary['calculation_id'];
$own=(int)$user['employee_id']===(int)$summary['employee_id'];
$expected=(int)$summary['current_correction_id'];
$negative=str_starts_with($summary['balance'],'-');
$balance=ltrim($summary['balance'],'-');
$pending=false;
foreach ($corrections as $correction) { if (!in_array($correction['status'],['published','rejected'],true)) { $pending=true; } }
$activeRecovery=false;
foreach ($recoveries as $recovery) { if (!in_array($recovery['status'],['received','rejected'],true)) { $activeRecovery=true; } }
$form = static function(string $action, int $target = 0, bool $amounts = false, bool $reference = false) use ($root,$expected): void {
?>
<form method="post" action="/payslips/<?= h($root) ?>/settlement" class="form-card">
<?= csrfField() ?><input type="hidden" name="action" value="<?= h($action) ?>"><input type="hidden" name="expected" value="<?= h($expected) ?>"><input type="hidden" name="target" value="<?= h($target) ?>">
<h3><?= h(t('settle.'.$action)) ?></h3>
<?php if ($amounts): ?><p class="muted"><?= h(t('settle.correction_help')) ?></p><?php foreach (['base','bonus','deduction'] as $key): ?><label><?= h(t('pay.'.$key)) ?> (MAD)<input name="<?= h($key) ?>" inputmode="decimal" maxlength="13" required></label><?php endforeach; ?><?php endif; ?>
<?php if ($reference): ?><label><?= h(t('settle.reference')) ?><input name="reference" pattern="[A-Za-z0-9_-]{8,64}" minlength="8" maxlength="64" required></label><?php endif; ?>
<label><?= h(t('comp.decision_reason')) ?><textarea name="reason" maxlength="2000" required rows="2"></textarea></label>
<button class="button primary"><?= h(t('settle.'.$action)) ?></button>
</form>
<?php }; require __DIR__.'/header.php'; ?>
<div class="page-heading"><div><h1><?= h($title) ?></h1><p><?= h(t('settle.fictional')) ?></p></div></div>
<p><a class="text-link" href="/payslips/<?= h($root) ?>">← <?= h(t('pay.payslip',['id'=>$root])) ?></a></p>
<section class="card detail-card"><dl class="detail-grid"><div><dt><?= h(t('settle.effective_net')) ?></dt><dd><?= h(Money::format($summary['net_amount'],'MAD')) ?></dd></div><div><dt><?= h(t($negative ? 'settle.overpaid' : 'settle.due')) ?></dt><dd><?= h(Money::format($balance,'MAD')) ?></dd></div><div><dt><?= h(t('settle.current')) ?></dt><dd><?= h($expected === 0 ? t('settle.original') : '#'.$expected) ?></dd></div></dl><p class="muted"><?= h(t('settle.preserve')) ?></p></section>
<?php if (!$own): ?>
<?php if (!$pending && !$activeRecovery && AuthService::can($user,'payslip.correct')): ?><section class="card"><?php $form('correct',0,true); ?></section><?php endif; ?>
<?php if (!$pending && !$negative && $balance!=='0.00' && AuthService::can($user,'payroll.payment')): ?><section class="card"><?php $form('pay',0,false,true); ?></section><?php endif; ?>
<?php if (!$pending && !$activeRecovery && $negative && AuthService::can($user,'payroll.recovery.request')): ?><section class="card"><?php $form('recover_request'); ?></section><?php endif; ?>
<?php endif; ?>
<section class="card table-card"><h2><?= h(t('settle.versions')) ?></h2><div class="table-scroll"><table><thead><tr><th><?= h(t('pay.version')) ?></th><th><?= h(t('pay.base')) ?></th><th><?= h(t('pay.bonus')) ?></th><th><?= h(t('pay.deduction')) ?></th><th><?= h(t('pay.net')) ?></th><th><?= h(t('comp.decision_reason')) ?></th><th><?= h(t('comp.status')) ?></th></tr></thead><tbody>
<?php foreach ($corrections as $correction): ?><tr><td><?= h($correction['revision']) ?> (#<?= h($correction['id']) ?>)</td><?php foreach (['base_amount','bonus_amount','deduction_amount','net_amount'] as $key): ?><td><?= h(Money::format($correction[$key],'MAD')) ?></td><?php endforeach; ?><td><?= h($correction['reason']) ?></td><td><?= h(t('pay.'.$correction['status'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php foreach ($corrections as $correction): if ($own) { continue; } $actions=[];
if ($correction['status']==='prepared' && AuthService::can($user,'payslip.verify')) { $actions=['verified']; }
if ($correction['status']==='verified' && (int)$correction['preparer_employee_id']!==(int)$user['employee_id'] && AuthService::can($user,'payslip.decide')) { $actions=['approved','rejected']; }
if ($correction['status']==='approved' && AuthService::can($user,'payslip.publish')) { $actions=['published']; }
foreach ($actions as $action) { $form($action,(int)$correction['id']); }
endforeach; ?></section>
<section class="card table-card"><h2><?= h(t('settle.ledger')) ?></h2><div class="table-scroll"><table><thead><tr><th><?= h(t('comp.date')) ?></th><th><?= h(t('comp.event')) ?></th><th><?= h(t('settle.amount')) ?></th><th><?= h(t('settle.reference')) ?></th><th><?= h(t('comp.actor')) ?></th></tr></thead><tbody>
<?php foreach ($ledger as $entry): ?><tr><td><?= h($entry['occurred_at']) ?> UTC</td><td><?= h(t('settle.'.$entry['entry_type'])) ?></td><td><?= h(Money::format($entry['amount'],'MAD')) ?></td><td><?= h($entry['reference']) ?></td><td>#<?= h($entry['actor_id']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="card table-card"><h2><?= h(t('settle.recoveries')) ?></h2><p class="muted"><?= h(t('settle.recovery_help')) ?></p><div class="table-scroll"><table><thead><tr><th>ID</th><th><?= h(t('settle.amount')) ?></th><th><?= h(t('comp.decision_reason')) ?></th><th><?= h(t('comp.status')) ?></th></tr></thead><tbody>
<?php foreach ($recoveries as $recovery): ?><tr><td>#<?= h($recovery['id']) ?></td><td><?= h(Money::format($recovery['amount'],'MAD')) ?></td><td><?= h($recovery['reason']) ?></td><td><?= h(t('settle.status_'.$recovery['status'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php foreach ($recoveries as $recovery): if ($own) { continue; }
if ($recovery['status']==='pending' && AuthService::can($user,'payroll.recovery.decide') && (int)$recovery['requester_employee_id']!==(int)$user['employee_id']) { $form('recover_approve',(int)$recovery['id']); $form('recover_reject',(int)$recovery['id']); }
if ($recovery['status']==='approved' && AuthService::can($user,'payroll.recovery.receive')) { $form('recover_receive',(int)$recovery['id'],false,true); }
endforeach; ?></section>
<?php foreach (['correctionEvents','recoveryEvents'] as $set): ?><section class="card table-card"><h2><?= h(t($set==='correctionEvents' ? 'settle.correction_events' : 'settle.recovery_events')) ?></h2><div class="table-scroll"><table><thead><tr><th><?= h(t('comp.event')) ?></th><th><?= h(t('comp.decision_reason')) ?></th><th><?= h(t('comp.actor')) ?></th><th><?= h(t('comp.date')) ?></th></tr></thead><tbody><?php foreach ($$set as $event): ?><tr><td><?= h(t(($set==='correctionEvents' ? 'pay.' : 'settle.status_').$event['event_type'])) ?></td><td><?= h($event['reason']) ?></td><td>#<?= h($event['actor_id']) ?></td><td><?= h($event['occurred_at']) ?> UTC</td></tr><?php endforeach; ?></tbody></table></div></section><?php endforeach; ?>
<?php require __DIR__.'/footer.php'; ?>
