<?php

declare(strict_types=1);

/**
 * Réglages du compte, état d'analyse et diagnostics du lecteur.
 */
final class SettingsController
{
    public static function settings(): void
    {
        $db = App::pdo();
        $requestMethod = Request::stringValue($_SERVER['REQUEST_METHOD'] ?? 'GET', 'GET');
        if ($requestMethod === 'PUT' || isset($_GET['set_key'])) {
            $input = Request::bodyOrQuery();
            $key = $input['key'] ?? $_GET['set_key'] ?? null;
            $value = $input['value'] ?? $_GET['value'] ?? null;
            if (is_string($key) && $key !== '' && (is_string($value) || $value === null)) {
                DB::setSetting($key, $value);
                App::json(['ok' => true]);
            }
        }
        $st = $db->query('SELECT key, value FROM settings');
        $settings = [];
        while (($row = $st->fetch()) !== false) {
            $key = $row['key'] ?? null;
            $value = $row['value'] ?? null;
            if (is_string($key) && (is_string($value) || $value === null)) {
                $settings[$key] = $value;
            }
        }
        $settings['music_root'] = App::musicRoot();
        $settings['user'] = Auth::currentLogin();
        $settings['auth_enabled'] = Auth::enabled();
        App::json($settings);
    }

    /**
     * Modifie l'emplacement de la bibliothèque musicale de l'utilisateur
     * connecté (colonne music_root de data/users.db).
     *
     * Valide le nouveau dossier, met à jour le compte, recharge la
     * configuration applicative puis relance l'indexation de cet utilisateur
     * en arrière-plan si aucune analyse n'est déjà en cours.
     *
     * @param string|null $projectRoot Racine du projet, redéfinissable en test.
     */
    public static function config(?string $projectRoot = null): void
    {
        $projectRoot ??= dirname(__DIR__, 3);
        $login = Auth::currentLogin();
        if ($login === null) {
            App::err('Unauthorized', 401);
        }
        $input = Request::body();
        $musicRoot = trim(Request::stringValue($input['music_root'] ?? null));
        if ($musicRoot === '') {
            App::err('Indiquez le dossier contenant vos fichiers de musique.');
        }
        if (!is_dir($musicRoot) || !is_readable($musicRoot)) {
            App::err('Le dossier de musique doit exister et être lisible par le serveur.');
        }

        Users::updateMusicRoot($login, $musicRoot);
        App::initConfig(Users::resolveConfig($login, Users::baseConfig()));

        $scanStarted = false;
        if (DB::setting('scan_running') !== '1' && BackgroundScan::start($projectRoot, $login)) {
            DB::setSetting('scan_running', '1');
            DB::setSetting('scan_started_at', (string) time());
            $scanStarted = true;
        }

        App::json(['ok' => true, 'music_root' => App::musicRoot(), 'scan_started' => $scanStarted]);
    }

    /**
     * Relance une indexation complète de la bibliothèque en arrière-plan.
     *
     * Le mode complet supprime de la base les pistes absentes du disque
     * (dossiers renommés ou déplacés). Renvoie { "ok": false, "running": true }
     * si une analyse est déjà en cours. Le scan n'est jamais effectué dans la
     * requête HTTP : il est détaché via {@see BackgroundScan::start()}.
     *
     * @param string|null $projectRoot Racine du projet, redéfinissable en test.
     */
    public static function scan(?string $projectRoot = null): void
    {
        if (DB::setting('scan_running') === '1') {
            App::json(['ok' => false, 'running' => true]);
        }
        $login = Auth::currentLogin();
        if (!BackgroundScan::start($projectRoot ?? dirname(__DIR__, 3), $login, true)) {
            App::err("Impossible de lancer le scan d'arrière-plan", 500);
        }
        DB::setSetting('scan_running', '1');
        DB::setSetting('scan_started_at', (string) time());
        App::json(['ok' => true]);
    }

    public static function diag(): void
    {
        $input = Request::body();
        if (!is_array($input['log'] ?? null) && isset($_POST['log']) && is_string($_POST['log'])) {
            $decoded = json_decode($_POST['log'], true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }
        $log = $input['log'] ?? null;
        if (!is_array($log)) {
            App::err('Invalid diagnostic payload');
        }
        $safe = [];
        foreach (array_slice($log, 0, 500) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $safe[] = [
                't' => Request::stringValue($entry['t'] ?? null),
                'e' => Request::stringValue($entry['e'] ?? null),
                'd' => Request::stringValue($entry['d'] ?? null),
                'h' => Request::integerValue($entry['h'] ?? null),
                'ct' => (float) Request::integerValue($entry['ct'] ?? null),
                'ns' => Request::integerValue($entry['ns'] ?? null, -1),
                'rs' => Request::integerValue($entry['rs'] ?? null, -1),
                'buf' => (float) Request::integerValue($entry['buf'] ?? null),
            ];
        }
        if ($safe === []) {
            App::err('Empty diagnostic payload');
        }
        $dbPath = App::config('db_path');
        if (!is_string($dbPath) || $dbPath === '') {
            App::err('Database path unavailable', 500);
        }
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            App::err('Diagnostic directory unavailable', 500);
        }
        $file = $dir . '/diag-' . date('Ymd-His') . '-' . substr((string) random_int(0, PHP_INT_MAX), 0, 6) . '.json';
        $payload = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $written = false;
        if (is_string($payload) && file_put_contents($file, $payload . PHP_EOL, LOCK_EX) !== false) {
            $written = true;
        }
        App::json(['ok' => true, 'written' => $written]);
    }
}
