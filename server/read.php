<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function readAnswer(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') readAnswer(426,['error'=>'https_required']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') readAnswer(405,['error'=>'method_not_allowed']);

$root = getenv('SMARTLOCK_WEB_ROOT') ?: dirname(__DIR__);
require $root.'/app/bootstrap.php';

$expectedHash = $config['read_api_token_hash'] ?? '';
if (!is_string($expectedHash) || !preg_match('/^[0-9a-f]{64}$/D',$expectedHash))
    readAnswer(503,['error'=>'read_api_disabled']);

$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($authorization === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name=>$value) {
        if (strcasecmp((string)$name,'Authorization')===0) { $authorization=(string)$value; break; }
    }
}
if (!preg_match('/^Bearer ([A-Za-z0-9_-]{43,128})$/D',$authorization,$match) ||
    !hash_equals($expectedHash,hash('sha256',$match[1])))
    readAnswer(401,['error'=>'unauthorized']);

$resource = $_GET['resource'] ?? '';
if (!is_string($resource) || !in_array($resource,['dashboard','users','devices','events'],true))
    readAnswer(400,['error'=>'invalid_resource']);

try {
    if ($resource==='users') {
        $data=$store->rows('SELECT user_code,full_name,enabled FROM sl_users ORDER BY full_name LIMIT 100');
    } elseif ($resource==='devices') {
        $rows=$store->rows('SELECT d.name AS device_name,l.name AS lock_name,u.full_name AS owner_name,c.name AS phone_name,c.enabled AS sync_enabled,c.last_seen,c.bt_connected,c.phase FROM sl_devices d JOIN sl_locks l ON l.id=d.lock_id LEFT JOIN sl_users u ON u.id=d.user_id LEFT JOIN sl_app_clients c ON c.device_row_id=d.id ORDER BY d.id DESC LIMIT 100');
        $data=array_map(static function(array $row):array {
            $online=(int)($row['sync_enabled']??0)===1 && strtotime((string)($row['last_seen']??''))!==false && strtotime((string)$row['last_seen'])>=time()-15;
            return ['device_name'=>$row['device_name'],'lock_name'=>$row['lock_name'],'owner_name'=>$row['owner_name'],
                'phone_name'=>$row['phone_name'],'sync_enabled'=>(int)($row['sync_enabled']??0)===1,'online'=>$online,
                'bt_connected'=>$online && (int)($row['bt_connected']??0)===1,'phase'=>$online?(string)$row['phase']:'',
                'last_seen'=>$row['last_seen']];
        },$rows);
    } elseif ($resource==='events') {
        $data=$store->rows('SELECT ev.event_type,ev.observed_at,ev.received_at,c.name AS phone_name,l.name AS lock_name,u.full_name AS owner_name FROM sl_app_events ev JOIN sl_app_clients c ON c.client_id=ev.client_id JOIN sl_locks l ON l.id=c.lock_id LEFT JOIN sl_devices d ON d.id=c.device_row_id LEFT JOIN sl_users u ON u.id=d.user_id ORDER BY ev.id DESC LIMIT 100');
    } else {
        $users=(int)($store->one('SELECT COUNT(*) AS n FROM sl_users')['n']??0);
        $locks=(int)($store->one('SELECT COUNT(*) AS n FROM sl_locks')['n']??0);
        $devices=(int)($store->one('SELECT COUNT(*) AS n FROM sl_devices')['n']??0);
        $clients=$store->rows('SELECT enabled,last_seen FROM sl_app_clients');
        $online=0;
        foreach($clients as $client) if ((int)$client['enabled']===1 && strtotime((string)$client['last_seen'])>=time()-15) $online++;
        $recent=$store->rows('SELECT ev.event_type,ev.observed_at,ev.received_at,c.name AS phone_name,l.name AS lock_name,u.full_name AS owner_name FROM sl_app_events ev JOIN sl_app_clients c ON c.client_id=ev.client_id JOIN sl_locks l ON l.id=c.lock_id LEFT JOIN sl_devices d ON d.id=c.device_row_id LEFT JOIN sl_users u ON u.id=d.user_id ORDER BY ev.id DESC LIMIT 8');
        $data=['counts'=>['users'=>$users,'locks'=>$locks,'devices'=>$devices,'online'=>$online], 'recent_events'=>$recent];
    }
    readAnswer(200,['ok'=>true,'resource'=>$resource,'data'=>$data,'server_time'=>gmdate('c')]);
} catch (Throwable $error) {
    error_log('Smartlock read API failure: '.get_class($error));
    readAnswer(503,['error'=>'unavailable']);
}
