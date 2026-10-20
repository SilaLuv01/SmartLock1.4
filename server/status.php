<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json');header('Cache-Control: no-store');
set_exception_handler(function(Throwable $e):void{http_response_code(503);echo '{"error":"unavailable"}';});
$root = getenv('SMARTLOCK_WEB_ROOT') ?: dirname(__DIR__);
require $root.'/app/bootstrap.php';require $root.'/app/SyncStore.php';
if ($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
$admin=isset($_SESSION['admin_id'])?$store->one('SELECT enabled FROM sl_admins WHERE id=?',[$_SESSION['admin_id']]):null;
if (!$admin || (int)$admin['enabled']!==1){http_response_code(401);exit;}
echo json_encode(['revision'=>(new SyncStore($store))->revision(time())],JSON_THROW_ON_ERROR);
