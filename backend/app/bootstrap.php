<?php
declare(strict_types=1);
require_once __DIR__ . '/Store.php';

$file = getenv('SMARTLOCK_CONFIG') ?: dirname(__DIR__) . '/config-PRIVATE.php';
if (is_file($file)) {
    $config = require $file;
} elseif (getenv('VERCEL') === '1') {
    require_once __DIR__ . '/VercelDbConfig.php';
    try { $config = smartlockVercelDbConfig(); }
    catch (Throwable $e) { http_response_code(503); exit('การตั้งค่าเว็บไม่สมบูรณ์'); }
} else {
    http_response_code(503); exit('Smart Door Lock: กรุณาตั้งค่าฐานข้อมูลและบัญชีผู้ดูแลผ่าน CLI ก่อนใช้งาน');
}
if (!is_array($config) || !preg_match('/^[0-9a-f]{64}$/D', $config['app_secret'] ?? '') ||
    !preg_match('#^(?:/[a-zA-Z0-9_-]+)*$#D', $config['base_path'] ?? '')) { http_response_code(503); exit('การตั้งค่าเว็บไม่สมบูรณ์'); }
try {
    $options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
    if (getenv('VERCEL') === '1') {
        $caFile = $config['db_ssl_ca_file'] ?? '';
        if (($config['db_ssl_ca_pem'] ?? '') !== '') {
            $caFile = sys_get_temp_dir() . '/smartlock-db-ca.pem';
            if (!is_file($caFile)) {
                if (file_put_contents($caFile, $config['db_ssl_ca_pem'], LOCK_EX) === false) throw new RuntimeException('database TLS CA unavailable');
                @chmod($caFile, 0600);
            }
        }
        if ($caFile === '' || !is_readable($caFile)) throw new RuntimeException('database TLS CA is required');
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caFile;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    $pdo = new PDO($config['dsn'], $config['db_user'] ?? '', $config['db_password'] ?? '', $options);
    $store = new Store($pdo);
    if (getenv('VERCEL') === '1') {
        require_once __DIR__ . '/VercelSessionHandler.php';
        session_set_save_handler(new VercelSessionHandler($pdo), true);
    }
} catch (Throwable $e) { http_response_code(503); exit('ยังเชื่อมต่อฐานข้อมูลไม่ได้ กรุณาตรวจการตั้งค่าบนเซิร์ฟเวอร์'); }

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $page = 'dashboard', array $params = []): string { global $config; return ($config['base_path'] ?: '') . '/index.php?' . http_build_query(['page'=>$page] + $params); }
function go(string $page, array $params = []): never { header('Location: ' . url($page,$params),true,303); exit; }
function csrfField(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function ensureCsrf(array $input, string $expected): void {
    if (!is_string($input['csrf'] ?? null) || !hash_equals($expected,$input['csrf'])) { http_response_code(403); exit('คำขอหมดอายุ กรุณาเปิดหน้าเว็บใหม่'); }
}
function needAdmin(array $admin): void { if ($admin['role'] !== 'admin') { http_response_code(403); exit('บัญชีนี้มีสิทธิ์ดูข้อมูลเท่านั้น'); } }
function flash(string $text): void { $_SESSION['flash'] = $text; }

if (PHP_SAPI !== 'cli' && !in_array(basename($_SERVER['SCRIPT_NAME']??''),['api.php','read.php'],true)) {
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'");
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
    session_name('smartlock_admin');
    session_set_cookie_params(['lifetime'=>0,'path'=>($config['base_path'] ?: '') . '/', 'secure'=>(bool)($config['cookie_secure'] ?? false), 'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    if (isset($_SESSION['admin_id']) && (time()-($_SESSION['seen_at']??0)>900 || time()-($_SESSION['started_at']??0)>28800)) {
        $_SESSION = []; session_regenerate_id(true);
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
