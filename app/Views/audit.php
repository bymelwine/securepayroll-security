<?php $title = t('audit.title'); $section = 'audit'; require __DIR__ . '/header.php';
$outcomeBadge = static function (string $o): string {
    return $o === 'denied' ? 'badge danger' : ($o === 'failure' ? 'badge warning' : 'badge');
};
?>
<div class="page-heading"><div><span class="eyebrow"><?= h(t('audit.eyebrow')) ?></span><h1><?= h(t('audit.title')) ?></h1><p class="muted"><?= h(t('audit.intro')) ?></p></div></div>

<section class="card">
<h2><?= h(t('audit.signals')) ?> <span class="count"><?= h(count($signals)) ?></span></h2>
<p class="muted small"><?= h(t('audit.signals_help')) ?></p>
<?php if ($signals === []): ?><div class="empty"><h3><?= h(t('audit.signals_none')) ?></h3></div>
<?php else: ?>
<div class="table-scroll"><table><thead><tr><th><?= h(t('audit.signal')) ?></th><th><?= h(t('audit.subject')) ?></th><th><?= h(t('audit.occurrences')) ?></th><th><?= h(t('audit.first_seen')) ?></th><th><?= h(t('audit.last_seen')) ?></th></tr></thead><tbody>
<?php foreach ($signals as $s): ?><tr><td><span class="badge danger"><?= h(t('audit.signal.' . $s['signal_type'])) ?></span></td><td><?= h((string) $s['subject']) ?></td><td class="mono"><?= h((string) $s['occurrences']) ?></td><td class="mono"><?= h((string) $s['first_seen']) ?></td><td class="mono"><?= h((string) $s['last_seen']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</section>

<section class="card table-card">
<div class="table-toolbar"><h2><?= h(t('audit.recent')) ?> <span class="count"><?= h(count($events)) ?></span></h2><span class="muted small"><?= h(t('audit.recent_help')) ?></span></div>
<?php if ($events === []): ?>
<div class="empty"><h3><?= h(t('audit.recent_none')) ?></h3></div>
<?php else: ?>
<div class="audit-head"><span><?= h(t('audit.when')) ?></span><span><?= h(t('audit.event')) ?></span><span><?= h(t('audit.outcome')) ?></span><span><?= h(t('audit.actor')) ?></span><span><?= h(t('audit.resource')) ?></span><span class="sr-only"><?= h(t('audit.details')) ?></span></div>
<?php foreach ($events as $e):
    $ctx = $e['context'] !== null ? json_decode((string) $e['context'], true) : [];
    if (!is_array($ctx)) { $ctx = []; }
    $targetLabel = $e['target_table'] !== null
        ? $e['target_table'] . (($e['target_id'] ?? null) !== null ? '#' . $e['target_id'] : '')
        : null;
    $targetWho = trim((string) ($e['target_login'] ?? ''));
    $targetName = trim((string) ($e['target_name'] ?? ''));
    $actorWho = $e['actor'] !== null ? (string) $e['actor'] : null;
    $actorName = trim((string) ($e['actor_name'] ?? ''));
    $ip = trim((string) ($e['source_ip'] ?? ''));
?>
<details class="audit-row">
  <summary>
    <span class="mono small"><?= h((string) $e['occurred_at']) ?></span>
    <span><strong><?= h((string) $e['event']) ?></strong></span>
    <span><span class="<?= h($outcomeBadge((string) $e['outcome'])) ?>"><?= h(t('audit.outcome.' . $e['outcome'])) ?></span></span>
    <span><?= $actorWho !== null ? h($actorWho) : '<span class="muted">—</span>' ?></span>
    <span class="mono small"><?= $targetWho !== '' ? h($targetWho) : ($targetLabel !== null ? h($targetLabel) : '<span class="muted">—</span>') ?></span>
    <span class="audit-chevron" aria-hidden="true">▸</span>
  </summary>
  <div class="audit-detail">
    <dl class="detail-grid">
      <div><dt><?= h(t('audit.when')) ?></dt><dd class="mono"><?= h((string) $e['occurred_at']) ?></dd></div>
      <div><dt><?= h(t('audit.event')) ?></dt><dd><?= h((string) $e['event']) ?> · <span class="<?= h($outcomeBadge((string) $e['outcome'])) ?>"><?= h(t('audit.outcome.' . $e['outcome'])) ?></span></dd></div>
      <div><dt><?= h(t('audit.actor')) ?></dt><dd>
        <?php if ($actorWho !== null): ?><?= h($actorWho) ?><?= $actorName !== '' ? ' · ' . h($actorName) : '' ?><?php else: ?><span class="muted"><?= h(t('audit.actor_none')) ?></span><?php endif; ?>
        <br><span class="muted small mono"><?= h(t('audit.db_user')) ?> : <?= h((string) $e['db_user']) ?></span>
      </dd></div>
      <div><dt><?= h(t('audit.target')) ?></dt><dd>
        <?php if ($targetLabel !== null): ?><span class="mono"><?= h($targetLabel) ?></span><?php if ($targetWho !== ''): ?> · <strong><?= h($targetWho) ?></strong><?= $targetName !== '' ? ' · ' . h($targetName) : '' ?><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?>
      </dd></div>
      <div><dt><?= h(t('audit.source_ip')) ?></dt><dd class="mono"><?= $ip !== '' ? h($ip) : '<span class="muted">—</span>' ?></dd></div>
      <div><dt><?= h(t('audit.source')) ?></dt><dd><span class="badge"><?= h(t('audit.source.' . $e['source'])) ?></span></dd></div>
      <?php $shown = $ctx; unset($shown['ip']); if ($shown !== []): ?>
      <div class="span-full"><dt><?= h(t('audit.context')) ?></dt><dd class="mono small">
        <?php foreach ($shown as $k => $v): ?><?= h((string) $k) ?> : <?= h(is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE))) ?><br><?php endforeach; ?>
      </dd></div>
      <?php endif; ?>
    </dl>
  </div>
</details>
<?php endforeach; ?>
<?php endif; ?>
</section>
<?php require __DIR__ . '/footer.php'; ?>
