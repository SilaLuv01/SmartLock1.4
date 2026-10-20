<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('Install schema on MariaDB/MySQL only');
$sql = file_get_contents(dirname(__DIR__) . '/schema.sql');
foreach (explode(';',$sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
echo "Schema ready. Existing data preserved.\n";
