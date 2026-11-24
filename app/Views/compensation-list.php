<?php $title=t('comp.'.($mode==='history' ? 'history' : ($mode==='employees' ? 'contracted_employees' : 'requests'))); require __DIR__.'/header.php'; ?>
<div class="page-heading"><div><span class="eyebrow"><?= h(t('comp.title')) ?></span><h1><?= h($title) ?></h1><p class="muted"><?= h(t('comp.intro')) ?></p></div></div>
<?php require __DIR__.'/compensation-nav.php'; ?>
<?php if ($mode==='requests'): ?><form method="get" action="/compensation" class="hr-actions"><label for="status"><?= h(t('comp.status')) ?></label><select id="status" name="status"><?php foreach ([''=>'all','pending'=>'pending','approved'=>'approved','rejected'=>'rejected'] as $code=>$label): ?><option value="<?= h($code) ?>" <?= $filter===$code ? 'selected' : '' ?>><?= h(t('comp.'.$label)) ?></option><?php endforeach; ?></select><button class="button secondary"><?= h(t('comp.filter')) ?></button></form><?php endif; ?>
<section class="card table-card"><div class="table-scroll"><table>
<thead><tr><th><?= h(t('comp.employee')) ?></th><th><?= h(t('comp.reference')) ?></th><th><?= h(t('comp.effective_on')) ?></th>
<?php if ($mode!=='employees'): ?><th><?= h(t('comp.monthly_amount')) ?></th><th><?= h(t($mode==='history' ? 'comp.ends_on' : 'comp.status')) ?></th><?php else: ?><th><?= h(t('comp.ends_on')) ?></th><?php endif; ?>
<th><?= h(t('common.actions')) ?></th></tr></thead><tbody>
<?php foreach ($result['rows'] as $row): ?><tr>
<td><strong><?= h($row['first_name'].' '.$row['last_name']) ?></strong><br><span class="muted small"><?= h($row['employee_number']) ?></span></td>
<td><?= h($row['reference']) ?></td><td><?= h($mode==='employees' ? $row['starts_on'] : $row['effective_on']) ?></td>
<?php if ($mode==='employees'): ?><td><?= h($row['effective_ends_on'] ?? t('comp.open_ended')) ?></td><td><?php if ($canSubmit): ?><a class="text-link" href="/compensation/new?<?= h(http_build_query(['employee_number'=>$row['employee_number'],'reference'=>$row['reference'],'effective_on'=>$row['starts_on']])) ?>"><?= h(t('comp.propose')) ?></a><?php endif; ?></td>
<?php else: ?><td class="mono"><?= h(\App\Finance\Money::format($row['monthly_amount'],$row['currency'])) ?></td><td><?php if ($mode==='history'): ?><?= h($row['ends_on'] ?? t('comp.open_ended')) ?><?php else: ?><span class="badge comp-<?= h($row['status']) ?>"><?= h(t('comp.'.$row['status'])) ?></span><?php endif; ?></td><td><a class="text-link" href="/compensation/<?= h($mode==='history' ? $row['request_id'] : $row['id']) ?>"><?= h(t('comp.view')) ?></a></td><?php endif; ?>
</tr><?php endforeach; ?>
<?php if ($result['rows']===[]): ?><tr><td colspan="6" class="muted"><?= h(t('comp.empty')) ?></td></tr><?php endif; ?>
</tbody></table></div>
<div class="comp-pagination"><span><?= h(t('comp.page',['page'=>$result['page'],'pages'=>$result['pages'],'total'=>$result['total']])) ?></span>
<?php foreach ([-1=>'previous',1=>'next'] as $delta=>$label): $target=$result['page']+$delta; if ($target>=1 && $target<=$result['pages']): ?>
<a class="button secondary" href="<?= h(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH).'?'.http_build_query(['page'=>$target]+array_intersect_key($_GET,array_flip(['status','employee_id'])))) ?>"><?= h(t('comp.'.$label)) ?></a><?php endif; endforeach; ?></div>
</section>
<?php require __DIR__.'/footer.php'; ?>
