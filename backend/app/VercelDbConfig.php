<?php
declare(strict_types=1);

/** Build the PDO settings expected by the Vercel runtime. */
function smartlockVercelDbConfig(?callable $readEnv = null): array
{
    $readEnv ??= static fn(string $name): string => getenv($name) ?: '';

    $dsn = $readEnv('DB_DSN');
    $usingTidb = false;
    if ($dsn === '') {
        $host = $readEnv('TIDB_HOST');
        $port = $readEnv('TIDB_PORT');
        $database = $readEnv('TIDB_DATABASE');
        if ($host === '' || $port === '' || $database === '') {
            throw new RuntimeException('database connection settings are required');
        }
        if (!preg_match('/^[A-Za-z0-9.-]+$/D', $host) ||
            !preg_match('/^[0-9]{1,5}$/D', $port) || (int)$port < 1 || (int)$port > 65535 ||
            !preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
            throw new RuntimeException('database connection settings are invalid');
        }
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4';
        $usingTidb = true;
    }

    $caPem = $readEnv('DB_SSL_CA_PEM');
    $caFile = '';
    if ($caPem === '' && $usingTidb) {
        $locations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];
        $defaultCa = is_array($locations) ? (string)($locations['default_cert_file'] ?? '') : '';
        if ($defaultCa !== '' && is_readable($defaultCa)) $caFile = $defaultCa;
    }

    $dbUser = $readEnv('DB_USER');
    $dbPassword = $readEnv('DB_PASSWORD');
    if ($dbUser === '' || $dbPassword === '') {
        throw new RuntimeException('dedicated runtime database credentials are required');
    }

    return [
        'dsn' => $dsn,
        'db_user' => $dbUser,
        'db_password' => $dbPassword,
        'db_ssl_ca_pem' => $caPem,
        'db_ssl_ca_file' => $caFile,
        'base_path' => $readEnv('APP_BASE_PATH'),
        'app_secret' => $readEnv('APP_SECRET'),
        'read_api_token_hash' => $readEnv('READ_API_TOKEN_HASH'),
        'cookie_secure' => true,
    ];
}
