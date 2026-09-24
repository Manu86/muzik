<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!function_exists('muzik_cli_config')) {
    /**
     * Point d'entrée commun des commandes CLI : lit éventuellement --user
     * (l'utilisateur multi-comptes dont on traite le catalogue), garantit que la
     * base méta existe, et construit la configuration applicative correspondante.
     *
     * Retourne le tableau de configuration à passer à App::initConfig().
     *
     * @param array<string>|null $argv Arguments de la ligne de commande
     * @return array<string, mixed>
     */
    function muzik_cli_config(?array $argv = null): array
    {
        $argv ??= $_SERVER['argv'] ?? [];
        $projectRoot = dirname(__DIR__, 2);

        $base = require $projectRoot . '/config.php';
        if (!is_array($base)) {
            throw new RuntimeException('config.php doit retourner un tableau.');
        }
        Users::ensureSchema($projectRoot);

        $user = null;
        foreach ($argv as $index => $arg) {
            if ($arg === '--user' && isset($argv[$index + 1])) {
                $user = (string) $argv[$index + 1];
                break;
            }
        }

        if ($user === null || $user === '') {
            // Sans --user, on conserve le comportement historique : le catalogue
            // configuré en base (config.app.php). En pratique, sur une
            // installation multi-comptes, seul le premier utilisateur est visé.
            $users = Users::all();
            if ($users !== []) {
                $user = $users[0]['login'];
            }
        }

        if ($user !== null && $user !== '') {
            $login = Users::normalizeLogin($user);
            if ($login === '') {
                throw new InvalidArgumentException("Identifiant invalide : {$user}.");
            }

            return Users::resolveConfig($login, $base);
        }

        /** @var array<string, mixed> $base */
        return $base;
    }
}
