<?php
declare(strict_types=1);
putenv('SMARTLOCK_WEB_ROOT=' . dirname(__DIR__) . '/backend');
require dirname(__DIR__) . '/server/index.php';
