<?php
declare(strict_types=1);
require dirname(__DIR__) . '/backend/app/VercelDbConfig.php';

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$env = [
    'TIDB_HOST'=>'db.example.test', 'TIDB_PORT'=>'4000', 'TIDB_DATABASE'=>'smartlock',
    'TIDB_USER'=>'cluster.user', 'TIDB_PASSWORD'=>'test-only-password',
    'APP_SECRET'=>str_repeat('a',64), 'APP_BASE_PATH'=>'',
];
$cfg = smartlockVercelDbConfig(static fn(string $key): string => $env[$key] ?? '');
check($cfg['dsn']==='mysql:host=db.example.test;port=4000;dbname=smartlock;charset=utf8mb4','TiDB DSN mapping failed');
check($cfg['db_user']==='cluster.user' && $cfg['db_password']==='test-only-password','TiDB credentials mapping failed');
check(!str_contains($cfg['dsn'],$env['TIDB_PASSWORD']),'Password leaked into DSN');

$env['TIDB_HOST']='db.example.test;dbname=other';
try { smartlockVercelDbConfig(static fn(string $key): string => $env[$key] ?? ''); }
catch (RuntimeException) { echo "PASS: TiDB environment config validation\n"; exit(0); }
throw new RuntimeException('Unsafe TiDB host was accepted');
