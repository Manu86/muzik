<?php

if (PHP_SAPI !== 'cli') {
    exit("Réservé à la ligne de commande\n");
}

$args = [];
foreach ((array) ($_SERVER['argv'] ?? []) as $item) {
    if (is_string($item)) {
        $args[] = $item;
    }
}
$full = in_array('--full', $args, true);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/bootstrap.php';

App::initConfig(muzik_cli_config($args));

set_time_limit(0);
ini_set('memory_limit', '512M');

try {
    DB::setSetting('scan_running', '1');
    DB::setSetting('scan_started_at', (string) time());
} catch (Throwable $e) {
    fwrite(STDERR, "Statut de scan non enregistré : {$e->getMessage()}\n");
}

echo "Scan de la bibliothèque (incrémental) ...\n";
if ($full) {
    echo "Mode complet : suppression des pistes disparues.\n";
}
$scanner = new Scanner();
$scanner->run($full);

try {
    DB::setSetting('scan_running', '0');
    DB::setSetting('scan_last_run', (string) time());
} catch (Throwable $e) {
    fwrite(STDERR, "Statut de scan non enregistré : {$e->getMessage()}\n");
}
