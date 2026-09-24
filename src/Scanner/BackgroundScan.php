<?php

declare(strict_types=1);

/**
 * Lance bin/scan.php en arrière-plan et retourne immédiatement.
 *
 * Évite de bloquer le serveur web pendant l'indexation (un serveur de
 * développement ou un hébergement partagé peut être mono-processus). Le
 * processus est détaché : il continue après la réponse HTTP et écrit ses
 * journaux dans data/scan-<login>.log (ou scan-install.log sans login).
 *
 * Retourne false si aucun processus n'a pu être détaché — l'appelant doit
 * alors scanner de façon synchrone.
 */
final class BackgroundScan
{
    public static function start(string $projectRoot, ?string $login = null, bool $full = false): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $script = $projectRoot . '/bin/scan.php';
        if (!is_file($script)) {
            return false;
        }

        $log = $projectRoot . '/data/scan-' . ($login !== null && $login !== '' ? $login : 'install') . '.log';
        @mkdir($projectRoot . '/data', 0777, true);

        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
            $phpBinary = defined('PHP_BINDIR') ? PHP_BINDIR . '/php' : 'php';
        } else {
            $phpBinary = PHP_BINARY;
        }

        $command = [$phpBinary, $script];
        if ($login !== null && $login !== '') {
            $command[] = '--user';
            $command[] = $login;
        }
        if ($full) {
            $command[] = '--full';
        }

        $process = @proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ],
            $pipes,
            $projectRoot,
        );

        if (!is_resource($process)) {
            return false;
        }

        // Ne pas appeler proc_close() : il attendrait la fin du scan. Le
        // processus devient orphelin et se termine tout seul.
        return true;
    }
}
