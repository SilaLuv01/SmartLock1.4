<?php
$phaseNames = ['IDLE'=>'ตัด Bluetooth แล้ว','AUTH'=>'ยืนยันตัวตน','PASSWORD'=>'ต้อง SIGN UP','REGISTERING'=>'กำลังลงทะเบียน','OTP'=>'รอยืนยัน OTP','OPEN'=>'แอปได้รับผลปลดล็อก','DONE'=>'จบขั้นตอน'];
$eventNames = ['APP_CONNECTED'=>'แอปเชื่อม Bluetooth','SIGNUP_REQUIRED'=>'ต้อง SIGN UP','SIGNUP_DENIED'=>'รหัส SIGN UP ไม่ผ่าน','REGISTER_SUCCESS'=>'ลงทะเบียนสำเร็จ','OTP_READY'=>'OTP พร้อม','OTP_WRONG'=>'OTP ไม่ผ่าน','RE_REGISTRATION_REQUIRED'=>'ต้องลงทะเบียนใหม่','UNLOCK_CONFIRMED'=>'แอปได้รับผลปลดล็อก','LOCK_CONFIRMED'=>'แอปได้รับผลล็อกกลับ','BLUETOOTH_DISCONNECTED'=>'Bluetooth ตัดการเชื่อมต่อ','SESSION_ENDED'=>'จบ Session'];
?>
<?php if ($page==='dashboard'):
$live=$store->rows('SELECT c.*,l.name AS lock_name,u.full_name FROM sl_app_clients c JOIN sl_locks l ON l.id=c.lock_id JOIN sl_devices d ON d.id=c.device_row_id LEFT JOIN sl_users u ON u.id=d.user_id ORDER BY c.last_seen DESC LIMIT 6'); ?>
<section class="panel">
  <div class="section-head"><h2>มือถือที่เชื่อมเว็บไซต์</h2><a href="<?=e(url('devices'))?>">จัดการมือถือ →</a></div>
  <div class="table-wrap"><table>
    <thead><tr><th>มือถือ / เจ้าของ</th><th>กลอน</th><th>เว็บไซต์</th><th>Bluetooth</th></tr></thead>
    <tbody>
    <?php foreach ($live as $client): $online=(int)$client['enabled']===1 && strtotime($client['last_seen'])>=time()-15; ?>
      <tr><td><b><?=e($client['name'])?></b><small><?=e($client['full_name'] ?? 'ยังไม่ระบุเจ้าของ')?></small></td><td><?=e($client['lock_name'])?></td><td><span class="badge <?=$online?'green':'gray'?>"><?=$online?'ออนไลน์':((int)$client['enabled']===1?'ออฟไลน์':'ระงับซิงค์')?></span></td><td><?=$online?((int)$client['bt_connected']===1?'เชื่อมต่อ':'ตัดแล้ว'):'ข้อมูลล่าสุดยังไม่ยืนยัน'?><small><?=e($phaseNames[$client['phase']] ?? $client['phase'])?></small></td></tr>
    <?php endforeach; if (!$live): ?><tr><td colspan="4" class="empty">ยังไม่มีมือถือเชื่อมเว็บ <a href="<?=e(url('devices'))?>">เชื่อมมือถือใหม่</a></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="hint">ออนไลน์เมื่อเว็บไซต์ได้รับข้อมูลภายใน15วินาที สถานะนี้รายงานจากแอปมือถือ</p>
</section>
<?php endif; ?>
<?php
$limit = $page==='dashboard' ? 8 : 30;
$recentSql = 'SELECT ev.*,c.name AS phone,l.name AS lock_name FROM sl_app_events ev JOIN sl_app_clients c ON c.client_id=ev.client_id JOIN sl_locks l ON l.id=c.lock_id LEFT JOIN sl_devices d ON d.id=c.device_row_id LEFT JOIN sl_users u ON u.id=d.user_id';
$recentValues = [];
if ($page==='events' && $query!=='') {
    $columns = ['ev.event_type','ev.observed_at','ev.received_at','c.name','c.client_id','l.name','d.name','d.device_id','u.full_name','u.user_code'];
    $conditions = array_map(fn($column) => $column." LIKE ? ESCAPE '!'",$columns);
    $recentValues = array_fill(0,count($columns),searchPattern($query));
    $codes = matchingEventCodes($eventNames,$query);
    if ($codes) { $conditions[] = 'ev.event_type IN ('.implode(',',array_fill(0,count($codes),'?')).')'; $recentValues = array_merge($recentValues,$codes); }
    $recentSql .= ' WHERE ('.implode(' OR ',$conditions).')';
}
$recent=$store->rows($recentSql.' ORDER BY ev.id DESC LIMIT '.$limit.($page==='events'?' OFFSET '.$offset:''),$recentValues);
?>
<section class="panel">
  <div class="section-head"><h2>การใช้งานล่าสุดจากแอป</h2><?php if ($page==='dashboard'): ?><a href="<?=e(url('events'))?>">ดูประวัติ →</a><?php endif; ?></div>
  <div class="table-wrap"><table>
    <thead><tr><th>เหตุการณ์</th><th>มือถือ / กลอน</th><th>เวลามือถือ (UTC)</th><th>เซิร์ฟเวอร์รับ (UTC)</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $event): ?>
      <tr><td><?=e($eventNames[$event['event_type']] ?? $event['event_type'])?></td><td><?=e($event['phone'])?><small><?=e($event['lock_name'])?></small></td><td><?=e($event['observed_at'])?></td><td><?=e($event['received_at'])?></td></tr>
    <?php endforeach; if (!$recent): ?><tr><td colspan="4" class="empty"><?=$page==='events' && $query!==''?'ไม่พบเหตุการณ์จากแอปที่ตรงกับคำค้น':'ยังไม่มีเหตุการณ์จากแอป'?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="hint">ผลที่แอปได้รับจากกลอน แสดงล่าสุด <?=$limit?> รายการ ข้อมูลนี้ไม่ใช้อนุญาตเปิดประตู</p>
  <?php if ($page==='events'): ?><div class="pager"><?php if ($number>1): ?><a href="<?=e(url('events',['n'=>$number-1,'q'=>$query]))?>">← ก่อนหน้า</a><?php endif; ?><span>หน้า <?=e($number)?></span><?php if (count($recent)===30): ?><a href="<?=e(url('events',['n'=>$number+1,'q'=>$query]))?>">ถัดไป →</a><?php endif; ?></div><?php endif; ?>
</section>
