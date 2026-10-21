<?php
declare(strict_types=1);
require dirname(__DIR__) . '/backend/app/VercelDbConfig.php';

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$env = [
    'TIDB_HOST'=>'db.example.test', 'TIDB_PORT'=>'4000', 'TIDB_DATABASE'=>'smartlock',
    'TIDB_USER'=>'cluster.user', 'TIDB_PASSWORD'=>'test-only-password',
    'DB_USER'=>'app_runtime', 'DB_PASSWORD'=>'runtime-only-password',
    'APP_SECRET'=>str_repeat('a',64), 'APP_BASE_PATH'=>'',
];
$cfg = smartlockVercelDbConfig(static fn(string $key): string => $env[$key] ?? '');
check($cfg['dsn']==='mysql:host=db.example.test;port=4000;dbname=smartlock;charset=utf8mb4','TiDB DSN mapping failed');
check($cfg['db_user']==='app_runtime' && $cfg['db_password']==='runtime-only-password','Runtime credentials mapping failed');
check(!str_contains($cfg['dsn'],$env['TIDB_PASSWORD']) && !str_contains($cfg['dsn'],$env['DB_PASSWORD']),'Password leaked into DSN');

$envWithoutRuntimeUser = $env;
unset($envWithoutRuntimeUser['DB_USER'], $envWithoutRuntimeUser['DB_PASSWORD']);
try { smartlockVercelDbConfig(static fn(string $key): string => $envWithoutRuntimeUser[$key] ?? ''); }
catch (RuntimeException) { goto invalidHostTest; }
throw new RuntimeException('TiDB owner credentials were accepted as runtime credentials');

invalidHostTest:
$env['TIDB_HOST']='db.example.test;dbname=other';
try { smartlockVercelDbConfig(static fn(string $key): string => $env[$key] ?? ''); }
catch (RuntimeException) { echo "PASS: TiDB environment config validation\n"; exit(0); }
throw new RuntimeException('Unsafe TiDB host was accepted');
