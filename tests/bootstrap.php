<?php

declare(strict_types=1);

ini_set('session.save_path', sys_get_temp_dir());

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/CapturedJsonResponse.php';
require __DIR__ . '/Support/TestCase.php';
