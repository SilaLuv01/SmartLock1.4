<?php
declare(strict_types=1);
ini_set('display_errors','0');
set_exception_handler(function (Throwable $e): void { error_log('Smartlock web request failed: ' . get_class($e)); http_response_code(503); echo 'ระบบยังไม่พร้อม กรุณาตรวจการติดตั้งฐานข้อมูล'; });
$root = getenv('SMARTLOCK_WEB_ROOT') ?: dirname(__DIR__);
if (!is_file($root . '/app/bootstrap.php')) { http_response_code(503); exit('กรุณาตั้ง SMARTLOCK_WEB_ROOT ให้ตรงกับตำแหน่ง backend นอก document root'); }
require $root . '/app/bootstrap.php';
require $root . '/app/SyncStore.php';
$page = $_GET['page'] ?? 'dashboard';
if (!is_string($page) || !in_array($page,['login','dashboard','users','devices','events','apps'],true)) { http_response_code(404); exit('ไม่พบหน้านี้'); }
// Old app-page links resolve to the merged device page.
if ($page === 'apps') $page = 'devices';
$admin = isset($_SESSION['admin_id']) ? $store->one('SELECT id,username,role,enabled FROM sl_admins WHERE id=?',[$_SESSION['admin_id']]) : null;
if ($admin && (int)$admin['enabled'] !== 1) { $_SESSION = []; session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32)); $admin = null; }
if ($admin) $_SESSION['seen_at'] = time();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureCsrf($_POST,$_SESSION['csrf']);
    try {
        foreach ($_POST as $value) if (!is_string($value)) throw new InvalidArgumentException('รูปแบบคำขอไม่ถูกต้อง');
        $action = $_POST['action'] ?? '';
        if ($action === 'login') {
            $account = $store->authenticate($_POST['username'] ?? '',$_POST['password'] ?? '',$_SERVER['REMOTE_ADDR'] ?? '',$config['app_secret']);
            if (!$account) { $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง หากลองครบ 5 ครั้งให้รอ 15 นาที'; $page = 'login'; }
            else { session_regenerate_id(true); $_SESSION = ['admin_id'=>(int)$account['id'],'started_at'=>time(),'seen_at'=>time(),'csrf'=>bin2hex(random_bytes(32))]; go('dashboard'); }
        } else {
            if (!$admin) { http_response_code(403); exit('กรุณาเข้าสู่ระบบ'); }
            if ($action === 'logout') {
                try { $store->audit((int)$admin['id'],'logout',$admin['username']); }
                catch (Throwable $ex) { error_log('Smartlock logout audit unavailable'); }
                $_SESSION = []; session_destroy(); go('login');
            }
            needAdmin($admin);
            if ($action === 'issue_app_code') {
                $owner=($_POST['user_id']??'')===''?null:Store::integer($_POST['user_id']);
                $_SESSION['app_code']=['value'=>(new SyncStore($store))->issueCode(Store::integer($_POST['lock_id']??''),$owner,(int)$admin['id'],time()),'expires'=>time()+600];
                go('devices');
            }
            elseif ($action === 'toggle_app') {
                $id=Store::hex($_POST['client_id']??'',32);$enabled=($_POST['enabled']??'')==='1'?1:0;
                $store->transaction(function()use($store,$id,$enabled,$admin){
                    if(!$store->one('SELECT client_id FROM sl_app_clients WHERE client_id=?',[$id])) throw new InvalidArgumentException('ไม่พบแอป');
                    $store->run('UPDATE sl_app_clients SET enabled=? WHERE client_id=?',[$enabled,$id]);
                    $store->audit((int)$admin['id'],'app.enabled',$id,['enabled'=>$enabled]);
                });go('devices');
            }
            elseif ($action === 'save_user') { $store->saveUser($_POST,(int)$admin['id']); flash('บันทึกข้อมูลผู้ใช้แล้ว'); go('users'); }
            elseif ($action === 'save_lock') { $store->saveLock($_POST,(int)$admin['id']); flash('เพิ่มข้อมูลกลอนแล้ว'); go('devices'); }
            elseif ($action === 'assign_device') { $store->assignDevice($_POST,(int)$admin['id']); flash('บันทึกเจ้าของอุปกรณ์แล้ว'); go('devices'); }
            elseif ($action === 'import') {
                $file = $_FILES['csv'] ?? null;
                if (!$file || !is_scalar($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] > 1048576)
                    throw new InvalidArgumentException('อัปโหลดไฟล์ CSV ไม่สำเร็จ ไฟล์ต้องไม่เกิน 1 MB');
                $result = $store->importCsv(Store::integer($_POST['lock_id'] ?? ''),file_get_contents($file['tmp_name']),$_POST['kind'] ?? '',(int)$admin['id']);
                flash('นำเข้าแล้ว ' . $result[0] . ' แถว ข้ามข้อมูลซ้ำ ' . $result[1] . ' แถว'); go($_POST['kind'] === 'registry' ? 'devices' : 'events');
            } else { http_response_code(400); exit('คำสั่งไม่ถูกต้อง'); }
        }
    } catch (InvalidArgumentException $ex) { $error = $ex->getMessage(); }
      catch (PDOException $ex) { $error = $ex->getCode() === '23000' ? 'รหัสซ้ำหรือข้อมูลอ้างอิงไม่ถูกต้อง การเปลี่ยนแปลงไม่ได้ถูกบันทึก' : 'บันทึกไม่สำเร็จ ข้อมูลเดิมยังคงอยู่'; }
}
if (!$admin && $page !== 'login') go('login');
if ($admin && $page === 'login') go('dashboard');
$notice = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$names = ['dashboard'=>'ภาพรวม','users'=>'ผู้ใช้','devices'=>'กลอนและมือถือ','events'=>'ประวัติการใช้งาน'];
function tag(?int $status): string {
    return [0=>'ระงับในทะเบียน SD',1=>'ลงทะเบียนแล้ว',2=>'ต้องลงทะเบียนใหม่'][$status] ?? 'ยังไม่มีข้อมูลจาก SD';
}
function sdEventLabels(): array {
    return ['BOOT'=>'เปิดเครื่อง','REGISTERED'=>'ลงทะเบียนสำเร็จ','UNLOCK_AUTHORIZED'=>'ยืนยันสิทธิ์เปิดกลอน','LOCKED'=>'ล็อกกลับ','DENIED'=>'ปฏิเสธคำขอ','OTP_ISSUED'=>'สร้าง OTP','BLUETOOTH_DISCONNECTED'=>'ตัดการเชื่อมต่อ Bluetooth','SESSION_ENDED'=>'จบการเชื่อมต่อ','RE_REGISTRATION_REQUIRED'=>'ต้องลงทะเบียนใหม่','SIGNUP_PASSWORD_CHANGED'=>'ตั้งรหัสลงทะเบียน'];
}
function eventName(string $event): string { return sdEventLabels()[$event] ?? $event; }
function searchPattern(string $query): string {
    return '%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';
}
function matchingEventCodes(array $labels, string $query): array {
    return array_keys(array_filter($labels,fn(string $label): bool => mb_stripos($label,$query,0,'UTF-8') !== false));
}
$number = isset($_GET['n']) ? Store::integer($_GET['n'],1,100000) : 1;
$offset = ($number-1)*30;
$query = Store::text(is_string($_GET['q'] ?? null) ? $_GET['q'] : '',80);
$locks = $admin ? $store->rows('SELECT * FROM sl_locks ORDER BY id') : [];
$users = $admin && $page==='devices' ? $store->rows('SELECT id,user_code,full_name FROM sl_users ORDER BY full_name LIMIT 1000') : [];
function lockOptions(array $locks): void { foreach($locks as $l) echo '<option value="'.e($l['id']).'">'.e($l['name']).'</option>'; }
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($names[$page]??'เข้าสู่ระบบ')?> · Smart Door Lock</title><link rel="stylesheet" href="<?=e(($config['base_path']?:'').'/assets/style.css')?>"></head>
<body>
<?php if ($page === 'login'): ?>
<main class="login"><div class="login-brand"><span class="logo">S</span><div><b>SMART DOOR LOCK</b><small>ทะเบียนผู้ใช้และข้อมูลการเข้าออก</small></div></div>
<section class="login-card"><span class="eyebrow">ADMINISTRATION</span><h1>เข้าสู่ระบบจัดการ</h1><p class="muted">ใช้บัญชีผู้ดูแลเว็บไซต์ของคุณ</p>
<?php if($error): ?><p class="alert error" role="alert"><?=e($error)?></p><?php endif; ?>
<form method="post" action="<?=e(url('login'))?>"><?=csrfField()?><input type="hidden" name="action" value="login"><label>ชื่อผู้ใช้<input name="username" required maxlength="60" autocomplete="username" autofocus></label><label>รหัสผ่าน<input type="password" name="password" required maxlength="72" autocomplete="current-password"></label><button class="primary wide">เข้าสู่ระบบ</button></form></section><p class="login-foot">ESP32-035 · Bluetooth Smart Door Lock</p></main>
<?php else: ?>
<aside class="sidebar"><a class="brand" href="<?=e(url())?>"><span class="logo">S</span><div><b>SMARTLOCK</b><small>จัดการข้อมูลกลอน</small></div></a><nav><?php foreach($names as $key=>$label): ?><a class="<?=$page===$key?'active':''?>" href="<?=e(url($key))?>"><?=e($label)?></a><?php endforeach; ?></nav><div class="sidebar-foot"><b><?=e($admin['username'])?></b><small><?=$admin['role']==='admin'?'ผู้ดูแลระบบ':'ดูข้อมูลเท่านั้น'?></small><form method="post" action="<?=e(url())?>"><?=csrfField()?><input type="hidden" name="action" value="logout"><button class="logout">ออกจากระบบ</button></form></div></aside>
<main class="workspace"><header class="page-head"><div><span class="eyebrow">SMART DOOR LOCK / MANAGEMENT</span><h1><?=e($names[$page])?></h1></div><span class="connection">ฐานข้อมูลเชื่อมต่อแล้ว</span></header>
<?php if($notice): ?><p class="alert success" role="status"><?=e($notice)?></p><?php endif; ?><?php if($error): ?><p class="alert error" role="alert"><?=e($error)?></p><?php endif; ?>
<?php if($page==='dashboard'): ?>
<?php require $root.'/app/dashboard_panel.php'; ?>
<?php elseif($page==='users'): ?>
<section class="panel"><div class="section-head"><h2>ทะเบียนผู้ใช้</h2></div><form class="search" method="get"><input type="hidden" name="page" value="users"><input name="q" value="<?=e($query)?>" placeholder="ค้นหารหัสผู้ใช้หรือชื่อ" maxlength="80"><button class="secondary">ค้นหา</button></form>
<div class="table-wrap"><table><thead><tr><th>รหัสผู้ใช้</th><th>ชื่อ / อีเมล</th><th>สถานะบนเว็บ</th><th></th></tr></thead><tbody><?php $list=$store->rows('SELECT * FROM sl_users WHERE user_code LIKE ? OR full_name LIKE ? ORDER BY id DESC LIMIT 30 OFFSET '.$offset,['%'.$query.'%','%'.$query.'%']); foreach($list as $r): ?><tr><td><b><?=e($r['user_code'])?></b></td><td><?=e($r['full_name'])?><small><?=e($r['email'])?></small></td><td><span class="badge <?=(int)$r['enabled']?'green':'gray'?>"><?=(int)$r['enabled']?'เปิดใช้งาน':'ปิดใช้งาน'?></span></td><td><?php if($admin['role']==='admin'): ?><a href="<?=e(url('users',['edit'=>$r['id']]))?>">แก้ไข</a><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$list): ?><tr><td colspan="4" class="empty">ยังไม่มีข้อมูลผู้ใช้ที่ตรงกับการค้นหา</td></tr><?php endif; ?></tbody></table></div>
<div class="pager"><?php if($number>1): ?><a href="<?=e(url('users',['n'=>$number-1,'q'=>$query]))?>">← ก่อนหน้า</a><?php endif; ?><span>หน้า <?=e($number)?></span><?php if(count($list)===30): ?><a href="<?=e(url('users',['n'=>$number+1,'q'=>$query]))?>">ถัดไป →</a><?php endif; ?></div></section>
<?php if($admin['role']==='admin'): $edit=isset($_GET['edit'])?$store->one('SELECT * FROM sl_users WHERE id=?',[Store::integer($_GET['edit'])]):null; ?>
<section class="panel"><div class="section-head"><h2><?=$edit?'แก้ไขผู้ใช้':'เพิ่มผู้ใช้'?></h2><?php if($edit): ?><a href="<?=e(url('users'))?>">เพิ่มผู้ใช้ใหม่</a><?php endif; ?></div><form method="post" action="<?=e(url('users'))?>"><?=csrfField()?><input type="hidden" name="action" value="save_user"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><div class="form-grid"><label>รหัสผู้ใช้<input name="user_code" value="<?=e($edit['user_code']??'')?>" required maxlength="40" placeholder="เช่น รหัสนักศึกษา"></label><label>ชื่อ–นามสกุล<input name="full_name" value="<?=e($edit['full_name']??'')?>" required maxlength="100"></label><label>อีเมล<input type="email" name="email" value="<?=e($edit['email']??'')?>" maxlength="254"></label><label>หมายเหตุ<input name="notes" value="<?=e($edit['notes']??'')?>" maxlength="800"></label></div><label class="check"><input type="checkbox" name="enabled" value="1" <?=!$edit||(int)$edit['enabled']?'checked':''?>>เปิดใช้งานทะเบียนผู้ใช้นี้บนเว็บ</label><button class="primary">บันทึกผู้ใช้</button></form><p class="hint">สถานะบนเว็บไม่เปลี่ยนสิทธิ์ที่กลอนใช้ หากต้องการระงับการเปิดประตู ต้องระงับที่กลอนด้วย</p></section><?php endif; ?>
<?php elseif($page==='devices'): require $root.'/app/devices_panel.php'; ?>
<?php elseif($page==='events'): require $root.'/app/events_panel.php'; ?>
<?php endif; ?>
<?php if(in_array($page,['devices','events'],true) && $admin['role']==='admin' && $locks): ?><details class="panel"><summary>นำเข้าข้อมูลจาก SD</summary><form method="post" enctype="multipart/form-data" action="<?=e(url($page))?>"><?=csrfField()?><input type="hidden" name="action" value="import"><div class="form-grid"><label>กลอน<select name="lock_id" required><?php lockOptions($locks); ?></select></label><label>ชนิดข้อมูล<select name="kind"><option value="<?=$page==='devices'?'registry':'events'?>"><?=$page==='devices'?'ทะเบียนอุปกรณ์ · users.csv':'ประวัติเหตุการณ์ · access_log.csv'?></option></select></label></div><label>ไฟล์ CSV<input type="file" name="csv" accept=".csv,text/csv" required></label><button class="primary">ตรวจรูปแบบและนำเข้า</button></form><p class="hint">รับเฉพาะไฟล์ทะเบียนและประวัติเหตุการณ์ ไม่อัปโหลดรหัสผ่านหรือ Master Key</p></details><?php endif; ?>
<button type="button" class="secondary" data-realtime data-url="<?=e(($config['base_path']?:'').'/status.php')?>" data-revision="<?=e((new SyncStore($store))->revision(time()))?>">อัปเดตอัตโนมัติทุก 2 วินาที</button>
<script src="<?=e(($config['base_path']?:'').'/assets/realtime.js')?>" defer></script>
<footer>ESP32-035 Smart Door Lock · Bluetooth &amp; SD Card</footer></main>
<?php endif; ?></body></html>
