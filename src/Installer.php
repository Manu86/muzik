<?php

declare(strict_types=1);

/**
 * Logique de la page d'installation : contrôles de prérequis, détection de
 * FFmpeg, écriture de config.local.php (identifiants inclus) et marqueur
 * « installée » dans SQLite.
 */
final class Installer
{
    /** @var list<string> */
    private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'ogg', 'm4a', 'wav'];

    /**
     * L'application est considérée installée si config.local.php existe ou si
     * le réglage « installed » vaut « 1 » dans SQLite.
     */
    public static function installed(?string $projectRoot = null): bool
    {
        $projectRoot ??= dirname(__DIR__);
        if (is_file($projectRoot . '/config.local.php')) {
            return true;
        }

        return DB::setting('installed') === '1';
    }

    /**
     * Contrôles de prérequis présentés sur la page d'installation.
     *
     * @return list<array{check: string, ok: bool, detail: string, level: string}>
     */
    public static function requirements(string $projectRoot): array
    {
        $checks = [];

        $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
        $checks[] = [
            'check' => 'PHP 8.1 ou supérieur',
            'ok' => $phpOk,
            'detail' => PHP_VERSION,
            'level' => 'required',
        ];

        foreach (['pdo_sqlite', 'mbstring', 'json', 'fileinfo'] as $extension) {
            $loaded = extension_loaded($extension);
            $checks[] = [
                'check' => 'Extension ' . $extension,
                'ok' => $loaded,
                'detail' => $loaded ? 'chargée' : 'manquante',
                'level' => 'required',
            ];
        }

        $vendorOk = is_file($projectRoot . '/vendor/autoload.php');
        $checks[] = [
            'check' => 'Dépendances PHP (vendor/)',
            'ok' => $vendorOk,
            'detail' => $vendorOk ? 'installées' : 'manquantes — lancer composer install',
            'level' => 'required',
        ];

        $ffmpeg = self::detectFfmpeg();
        $checks[] = [
            'check' => 'FFmpeg (transcodage)',
            'ok' => $ffmpeg !== null,
            'detail' => $ffmpeg ?? 'non détecté — lecture directe uniquement',
            'level' => 'optional',
        ];

        $dataWritable = is_dir($projectRoot . '/data') && is_writable($projectRoot . '/data');
        $checks[] = [
            'check' => 'Écriture dans data/',
            'ok' => $dataWritable,
            'detail' => $dataWritable ? 'OK' : 'autorisation manquante sur ' . $projectRoot . '/data',
            'level' => 'required',
        ];

        $rootWritable = is_writable($projectRoot);
        $checks[] = [
            'check' => 'Écriture à la racine du projet',
            'ok' => $rootWritable,
            'detail' => $rootWritable ? 'OK' : 'nécessaire pour écrire config.local.php',
            'level' => 'required',
        ];

        return $checks;
    }

    public static function detectFfmpeg(): ?string
    {
        $commandPath = function_exists('shell_exec')
            ? trim((string) shell_exec('command -v ffmpeg 2>/dev/null'))
            : '';
        if ($commandPath !== '' && is_executable($commandPath)) {
            return $commandPath;
        }

        $candidates = [
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/homebrew/bin/ffmpeg',
        ];
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Nombre de fichiers audio reconnus sous la racine donnée.
     */
    public static function countAudioFiles(string $root): int
    {
        if (!is_dir($root)) {
            return 0;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
        } catch (Throwable) {
            return 0;
        }

        $count = 0;
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            if ($entry->isFile() && in_array(strtolower($entry->getExtension()), self::AUDIO_EXTENSIONS, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Écrit config.local.php sous forme de fichier PHP valide.
     *
     * @param array{music_root: string, db_path: string, ffmpeg: string, transcode: int, auth_user?: string, auth_hash?: string} $config
     */
    public static function writeConfig(string $targetPath, array $config): bool
    {
        $lines = [];
        foreach (['music_root', 'db_path', 'ffmpeg', 'transcode', 'auth_user', 'auth_hash'] as $key) {
            $value = $config[$key] ?? '';
            $lines[] = '    ' . var_export($key, true) . ' => ' . var_export($value, true) . ',';
        }
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
            . implode("\n", $lines) . "\n];\n";

        return file_put_contents($targetPath, $content, LOCK_EX) !== false;
    }

    /**
     * Retourne le hachage bcrypt d'un mot de passe pour la clé auth_hash.
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * Marque l'installation comme terminée dans SQLite.
     */
    public static function markInstalled(): void
    {
        DB::setSetting('installed', '1');
    }
}
