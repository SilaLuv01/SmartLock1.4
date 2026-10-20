<section class="panel">
  <div class="section-head"><h2>กลอนในระบบ</h2><span class="muted"><?=count($locks)?> กลอน</span></div>
  <?php foreach ($locks as $lock): ?>
  <div class="lock-row"><div><b><?=e($lock['name'])?></b><small><?=e($lock['bt_address'])?></small></div><span class="badge blue">Bluetooth</span></div>
  <?php endforeach; if (!$locks): ?><p class="empty">เพิ่มกลอนด้วย Public Key จากไฟล์ตั้งค่ากลอนก่อนเชื่อมแอป</p><?php endif; ?>
</section>
<?php if ($admin['role']==='admin'): ?>
<details class="panel">
  <summary>เพิ่มกลอน</summary>
  <form method="post" action="<?=e(url('devices'))?>">
    <?=csrfField()?><input type="hidden" name="action" value="save_lock">
    <div class="form-grid">
      <label>ชื่อกลอน<input name="name" required maxlength="100" placeholder="เช่น ประตูห้องโครงการ"></label>
      <label>Bluetooth MAC<input name="bt_address" maxlength="17" placeholder="AA:BB:CC:DD:EE:FF"></label>
    </div>
    <label>Public Key จาก SmartDoorLock-setup.txt<textarea name="public_key" required maxlength="130" rows="2" placeholder="ค่าหลัง public_key="></textarea></label>
    <button class="primary">เพิ่มกลอน</button>
  </form>
</details>
<details class="panel" <?=isset($_SESSION['app_code']) && $_SESSION['app_code']['expires']>time()?'open':''?>>
  <summary>เชื่อมมือถือใหม่กับเว็บไซต์</summary>
  <p class="hint">สร้างรหัสใช้ครั้งเดียว อายุ10นาที แล้วกรอกในแท็บเว็บไซต์ของแอป</p>
  <form method="post" action="<?=e(url('devices'))?>">
    <?=csrfField()?><input type="hidden" name="action" value="issue_app_code">
    <div class="form-grid">
      <label>กลอน<select name="lock_id" required><?php lockOptions($locks); ?></select></label>
      <label>เจ้าของมือถือ<select name="user_id"><option value="">เลือกภายหลัง</option><?php foreach ($users as $user): ?><option value="<?=e($user['id'])?>"><?=e($user['full_name'])?></option><?php endforeach; ?></select></label>
    </div>
    <button class="primary" <?=!$locks?'disabled':''?>>สร้างรหัสเชื่อมแอป</button>
  </form>
  <?php if (isset($_SESSION['app_code']) && $_SESSION['app_code']['expires']>time()): ?>
  <p class="alert">รหัสเชื่อมแอป: <code><?=e($_SESSION['app_code']['value'])?></code><br>ใช้ได้จนถึง <?=e(gmdate('H:i:s',$_SESSION['app_code']['expires']))?> UTC</p>
  <?php endif; ?>
</details>
<?php endif; ?>
<section class="panel">
  <div class="section-head"><h2>มือถือและเจ้าของ</h2></div>
  <form class="search" method="get">
    <input type="hidden" name="page" value="devices">
    <input name="q" value="<?=e($query)?>" aria-label="ค้นหามือถือและเจ้าของ" placeholder="ชื่อมือถือ เจ้าของ กลอน หรือรหัสอุปกรณ์" maxlength="80">
    <button class="secondary">ค้นหา</button><?php if ($query!==''): ?><a href="<?=e(url('devices'))?>">ล้างคำค้น</a><?php endif; ?>
  </form>
  <p class="hint">สถานะออนไลน์มาจากมือถือที่ส่งข้อมูลภายใน15วินาที ทะเบียน SD แสดงข้อมูลที่นำเข้าครั้งล่าสุด</p>
  <div class="table-wrap"><table class="device-table">
    <thead><tr><th>มือถือ / กลอน</th><th>เจ้าของ</th><th>เว็บไซต์ / Bluetooth</th><?php if ($admin['role']==='admin'): ?><th>การซิงค์</th><?php endif; ?></tr></thead>
    <tbody>
    <?php
    $deviceSql = 'SELECT d.*,l.name AS lock_name,u.full_name,c.client_id,c.name AS phone_name,c.enabled AS sync_enabled,c.last_seen,c.bt_connected,c.phase FROM sl_devices d JOIN sl_locks l ON l.id=d.lock_id LEFT JOIN sl_users u ON u.id=d.user_id LEFT JOIN sl_app_clients c ON c.device_row_id=d.id';
    $deviceValues = [];
    if ($query!=='') {
        $columns = ['d.name','d.device_id','c.name','c.client_id','l.name','l.bt_address','u.full_name','u.user_code'];
        $deviceSql .= ' WHERE ('.implode(' OR ',array_map(fn($column) => $column." LIKE ? ESCAPE '!'",$columns)).')';
        $deviceValues = array_fill(0,count($columns),searchPattern($query));
    }
    $devices = $store->rows($deviceSql.' ORDER BY d.id DESC LIMIT 30 OFFSET '.$offset,$deviceValues);
    $phaseNames = ['IDLE'=>'ตัด Bluetooth แล้ว','AUTH'=>'ยืนยันตัวตน','PASSWORD'=>'ต้อง SIGN UP','REGISTERING'=>'กำลังลงทะเบียน','OTP'=>'รอยืนยัน OTP','OPEN'=>'แอปได้รับผลปลดล็อก','DONE'=>'จบขั้นตอน'];
    foreach ($devices as $device):
        $online = $device['client_id'] !== null && (int)$device['sync_enabled']===1 && strtotime($device['last_seen'])>=time()-15;
    ?>
      <tr>
        <td><b><?=e($device['name'] ?: ($device['phone_name'] ?: 'มือถือ'))?></b><small><?=e($device['lock_name'])?></small><details class="identity"><summary>รายละเอียดอุปกรณ์</summary><code><?=e($device['device_id'])?></code><small><?=e(tag(isset($device['reported_status'])?(int)$device['reported_status']:null))?></small><small><?=e($device['reported_at'] ?? '')?></small></details></td>
        <td><?php if ($admin['role']==='admin'): ?>
          <form class="compact" method="post" action="<?=e(url('devices'))?>">
            <?=csrfField()?><input type="hidden" name="action" value="assign_device"><input type="hidden" name="id" value="<?=e($device['id'])?>">
            <select name="user_id" aria-label="เจ้าของมือถือ"><option value="">ยังไม่ระบุผู้ใช้</option><?php foreach ($users as $user): ?><option value="<?=e($user['id'])?>" <?=(int)$user['id']===(int)$device['user_id']?'selected':''?>><?=e($user['user_code'].' · '.$user['full_name'])?></option><?php endforeach; ?></select>
            <input name="name" aria-label="ชื่อมือถือ" maxlength="100" value="<?=e($device['name'])?>" placeholder="ชื่อมือถือ"><button class="secondary">บันทึก</button>
          </form>
        <?php else: ?><?=e($device['full_name'] ?? 'ยังไม่ระบุ')?><?php endif; ?></td>
        <td>
          <?php if ($device['client_id']===null): ?>ยังไม่เชื่อมเว็บ
          <?php else: ?><span class="badge <?=$online?'green':'gray'?>"><?=$online?'ออนไลน์':((int)$device['sync_enabled']===1?'ออฟไลน์':'ระงับซิงค์')?></span>
            <small><?=$online?((int)$device['bt_connected']===1?'Bluetooth เชื่อมต่อ':'Bluetooth ตัดแล้ว'):'สถานะ Bluetooth ล่าสุดยังไม่ยืนยัน'?></small>
            <small><?=e($phaseNames[$device['phase']] ?? $device['phase'])?></small><small><?=e($device['last_seen'])?> UTC</small>
          <?php endif; ?>
        </td>
        <?php if ($admin['role']==='admin'): ?><td><?php if ($device['client_id']!==null): ?>
          <form method="post" action="<?=e(url('devices'))?>"><?=csrfField()?><input type="hidden" name="action" value="toggle_app"><input type="hidden" name="client_id" value="<?=e($device['client_id'])?>"><input type="hidden" name="enabled" value="<?=(int)$device['sync_enabled']===1?'0':'1'?>"><button class="secondary"><?=(int)$device['sync_enabled']===1?'ระงับซิงค์':'เปิดซิงค์'?></button></form>
        <?php else: ?>—<?php endif; ?></td><?php endif; ?>
      </tr>
    <?php endforeach; if (!$devices): ?><tr><td colspan="<?=$admin['role']==='admin'?4:3?>" class="empty"><?=$query!==''?'ไม่พบมือถือที่ตรงกับคำค้น':'ยังไม่มีมือถือ เริ่มจากเชื่อมแอปกับเว็บไซต์'?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="pager"><?php if ($number>1): ?><a href="<?=e(url('devices',['n'=>$number-1,'q'=>$query]))?>">← ก่อนหน้า</a><?php endif; ?><span>หน้า <?=e($number)?></span><?php if (count($devices)===30): ?><a href="<?=e(url('devices',['n'=>$number+1,'q'=>$query]))?>">ถัดไป →</a><?php endif; ?></div>
</section>
