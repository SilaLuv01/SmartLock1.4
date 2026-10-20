<div class="intro">
  <div><h2>ข้อมูลกลอนในที่เดียว</h2><p>ดูผู้ใช้ มือถือที่เชื่อมต่อ และประวัติการใช้งาน</p></div>
  <a class="button light" href="<?=e(url('devices'))?>">กลอนและมือถือ →</a>
</div>
<div class="stats">
<?php
$metrics = [
    ['ผู้ใช้ทั้งหมด', (int)$store->one('SELECT COUNT(*) AS total FROM sl_users')['total']],
    ['กลอนในระบบ', count($locks)],
    ['มือถือที่เชื่อมเว็บ', (int)$store->one('SELECT COUNT(*) AS total FROM sl_app_clients')['total']],
    ['มือถือออนไลน์', (int)$store->one('SELECT COUNT(*) AS total FROM sl_app_clients WHERE enabled=1 AND last_seen>=?', [gmdate('Y-m-d\TH:i:s\Z',time()-15)])['total']],
];
foreach ($metrics as [$label, $count]): ?>
  <article><span><?=e($label)?></span><strong><?=e($count)?></strong><small>ข้อมูลเว็บไซต์</small></article>
<?php endforeach; ?>
</div>
<?php require $root.'/app/live_panel.php'; ?>
