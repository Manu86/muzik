<?php

declare(strict_types=1);

ini_set('session.save_path', sys_get_temp_dir());

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/DB.php';
require __DIR__ . '/../src/App.php';
require __DIR__ . '/../src/Users.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Installer.php';
require __DIR__ . '/../src/Catalogue.php';
require __DIR__ . '/../src/Api.php';
require __DIR__ . '/../src/Router.php';
require __DIR__ . '/../src/Scanner.php';
require __DIR__ . '/../src/Streamer.php';
require __DIR__ . '/Support/CapturedJsonResponse.php';
require __DIR__ . '/Support/TestCase.php';
