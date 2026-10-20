<section class="panel">
  <div class="section-head"><h2>ค้นหาประวัติการใช้งาน</h2></div>
  <form class="search" method="get">
    <input type="hidden" name="page" value="events">
    <input name="q" value="<?=e($query)?>" aria-label="ค้นหาประวัติการใช้งาน" placeholder="เหตุการณ์ มือถือ เจ้าของ กลอน หรือวันที่ YYYY-MM-DD" maxlength="80">
    <button class="secondary">ค้นหา</button><?php if ($query!==''): ?><a href="<?=e(url('events'))?>">ล้างคำค้น</a><?php endif; ?>
  </form>
  <p class="hint">ค้นหาทั้งประวัติจากแอปและ SD วันที่ใช้เวลา UTC ที่แสดงในตาราง</p>
</section>
<?php require $root.'/app/live_panel.php'; ?>
<details class="panel" <?=$query!==''?'open':''?>>
  <summary>ประวัติที่นำเข้าจาก SD</summary>
  <p class="hint">เวลาหลังเปิดเครื่องมาจาก ESP32 เวลานำเข้าคือเวลาบันทึกบนเว็บ ไฟล์นำเข้าโดยผู้ดูแลและเซิร์ฟเวอร์ยังไม่ได้ตรวจ HMAC ของกลอน</p>
  <div class="table-wrap"><table>
    <thead><tr><th>เหตุการณ์</th><th>กลอน / อุปกรณ์</th><th>หลังเปิดเครื่อง</th><th>เวลานำเข้า (UTC)</th></tr></thead>
    <tbody>
    <?php
    $eventSql = 'SELECT ev.*,l.name AS lock_name FROM sl_events ev JOIN sl_locks l ON l.id=ev.lock_id LEFT JOIN sl_devices d ON d.lock_id=ev.lock_id AND d.device_id=ev.device_id LEFT JOIN sl_users u ON u.id=d.user_id';
    $eventValues = [];
    if ($query!=='') {
        $columns = ['ev.event','ev.reason','ev.device_id','ev.boot_id','ev.imported_at','l.name','d.name','u.full_name','u.user_code'];
        $conditions = array_map(fn($column) => $column." LIKE ? ESCAPE '!'",$columns);
        $eventValues = array_fill(0,count($columns),searchPattern($query));
        $codes = matchingEventCodes(sdEventLabels(),$query);
        if ($codes) { $conditions[] = 'ev.event IN ('.implode(',',array_fill(0,count($codes),'?')).')'; $eventValues = array_merge($eventValues,$codes); }
        $eventSql .= ' WHERE ('.implode(' OR ',$conditions).')';
    }
    $items=$store->rows($eventSql.' ORDER BY ev.id DESC LIMIT 30 OFFSET '.$offset,$eventValues);
    foreach ($items as $item): ?>
      <tr><td><b><?=e(eventName($item['event']))?></b><small><?=e($item['reason'])?></small></td><td><?=e($item['lock_name'])?><small class="mono"><?=e(substr($item['device_id'],0,12))?><?=$item['device_id']?'…':''?></small></td><td><?=e($item['uptime_ms'])?> ms<small>รอบ <?=e(substr($item['boot_id'],0,8))?></small></td><td><?=e($item['imported_at'])?></td></tr>
    <?php endforeach; if (!$items): ?><tr><td colspan="4" class="empty"><?=$query!==''?'ไม่พบประวัติจาก SD ที่ตรงกับคำค้น':'ยังไม่มีประวัติจาก SD'?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="pager"><?php if ($number>1): ?><a href="<?=e(url('events',['n'=>$number-1,'q'=>$query]))?>">← ก่อนหน้า</a><?php endif; ?><span>หน้า <?=e($number)?></span><?php if (count($items)===30): ?><a href="<?=e(url('events',['n'=>$number+1,'q'=>$query]))?>">ถัดไป →</a><?php endif; ?></div>
</details>
