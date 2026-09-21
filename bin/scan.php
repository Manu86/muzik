<?php

if (PHP_SAPI !== 'cli') {
    exit("Réservé à la ligne de commande\n");
}

$argv = $_SERVER['argv'] ?? [];
$args = is_array($argv) ? $argv : [];
$full = in_array('--full', $args, true);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/DB.php';
require __DIR__ . '/../src/App.php';
require __DIR__ . '/../src/Scanner.php';

App::initConfig(require __DIR__ . '/../config.php');

set_time_limit(0);
ini_set('memory_limit', '512M');

DB::setSetting('scan_running', '1');
DB::setSetting('scan_started_at', (string) time());

echo "Scan de la bibliothèque (incrémental) ...\n";
if ($full) {
    echo "Mode complet : suppression des pistes disparues.\n";
}
$scanner = new Scanner();
$scanner->run($full);

DB::setSetting('scan_running', '0');
DB::setSetting('scan_last_run', (string) time());
