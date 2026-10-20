<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function answer(int $status,array $body): never {http_response_code($status);echo json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;}
set_exception_handler(function(Throwable $e):void{error_log('Smartlock API failure: '.get_class($e));answer(503,['error'=>'unavailable']);});
if (($_SERVER['HTTPS']??'')!=='on' && ($_SERVER['HTTP_X_FORWARDED_PROTO']??'')!=='https') answer(426,['error'=>'https_required']);
$root = getenv('SMARTLOCK_WEB_ROOT') ?: dirname(__DIR__);
require $root.'/app/bootstrap.php';require $root.'/app/SyncStore.php';
// bootstrap creates no cookie/session for this stateless signature-authenticated API.
if ($_SERVER['REQUEST_METHOD']==='GET') answer(200,['server_time'=>time(),'protocol'=>'SW1']);
if ($_SERVER['REQUEST_METHOD']!=='POST') answer(405,['error'=>'method']);
if ((int)($_SERVER['CONTENT_LENGTH']??0)>65536 || !str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')) answer(413,['error'=>'size_or_type']);
$raw=file_get_contents('php://input',false,null,0,65537);
if ($raw===false || strlen($raw)>65536) answer(413,['error'=>'size']);
// Limit expensive signature checks, including failed/unenrolled identities.
$bucket=hash_hmac('sha256','app-api|'.($_SERVER['REMOTE_ADDR']??''),$config['app_secret']);
$allowed=$store->transaction(function()use($store,$bucket){
    $now=time();
    $store->run('INSERT INTO sl_login_limits(bucket,failures,window_start) VALUES(?,0,?) ON DUPLICATE KEY UPDATE bucket=bucket',[$bucket,$now]);
    $limit=$store->one('SELECT * FROM sl_login_limits WHERE bucket=? FOR UPDATE',[$bucket]);
    $count=$now-(int)$limit['window_start']>=60?0:(int)$limit['failures'];
    $start=$count===0?$now:(int)$limit['window_start'];
    if ($count>=120) return false;
    $store->run('UPDATE sl_login_limits SET failures=?,window_start=? WHERE bucket=?',[$count+1,$start,$bucket]);return true;
});
if (!$allowed) answer(429,['error'=>'rate_limit']);
try{
    $envelope=json_decode($raw,true,20,JSON_THROW_ON_ERROR);
    if (!is_array($envelope)) throw new InvalidArgumentException();
    answer(200,(new SyncStore($store))->apply($envelope,time()));
}catch(DomainException $e){answer($e->getMessage()==='clock'?409:403,['error'=>$e->getMessage(),'server_time'=>time()]);}
catch(InvalidArgumentException|JsonException $e){answer(422,['error'=>'invalid_request']);}
catch(PDOException $e){answer($e->getCode()==='23000'?409:503,['error'=>$e->getCode()==='23000'?'duplicate_request':'unavailable']);}
