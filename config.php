<?php

declare(strict_types=1);

$config = require __DIR__ . '/config/app.php';
if (!is_array($config)) {
    throw new RuntimeException('config/app.php doit retourner un tableau.');
}
$localConfig = __DIR__ . '/config.local.php';

if (is_file($localConfig)) {
    $overrides = require $localConfig;
    if (!is_array($overrides)) {
        throw new RuntimeException('config.local.php doit retourner un tableau.');
    }
    $config = array_replace($config, $overrides);
}

return $config;
