<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
$name = strtolower($argv[1] ?? ''); $role = $argv[2] ?? 'admin';
if (!preg_match('/^[a-z0-9_.-]{1,60}$/D',$name) || !in_array($role,['admin','viewer'],true)) exit("Usage: php bin/admin.php USERNAME [admin|viewer] < private-password-file\n");
$password = rtrim(stream_get_contents(STDIN,1024),"\r\n");
if (strlen($password)<12 || strlen($password)>72) exit("Password must be 12-72 UTF-8 bytes.\n");
$hash = password_hash($password,PASSWORD_DEFAULT); unset($password);
$store->transaction(function () use ($name,$role,$hash,$store) {
    if ($store->one('SELECT id FROM sl_admins WHERE username=?',[$name])) throw new RuntimeException('Account exists; use an explicit administrator reset procedure');
    $store->run('INSERT INTO sl_admins(username,password_hash,role,created_at) VALUES(?,?,?,?)',[$name,$hash,$role,Store::now()]);
});
echo "Administrator account created. Password was not logged.\n";
