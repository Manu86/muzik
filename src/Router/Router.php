<?php

declare(strict_types=1);

final class Router
{
    /** Handlers accessibles sans session authentifiée. */
    private const PUBLIC_HANDLERS = [
        [AuthController::class, 'login'],
        [AuthController::class, 'logout'],
        [AuthController::class, 'auth'],
    ];

    public static function handle(string $uri, string $method): void
    {
        if (!in_array($method, ['GET', 'PUT', 'POST', 'PATCH', 'DELETE', 'HEAD'], true)) {
            App::err('Method not allowed', 405);
        }
        $effectiveMethod = $method === 'HEAD' ? 'GET' : $method;
        $routes = [
            ['api/search',              [CatalogController::class, 'search'],    'GET'],
            ['api/artists',             [CatalogController::class, 'artists'],   'GET'],
            ['api/albums',              [CatalogController::class, 'albums'],    'GET'],
            ['api/summary',             [CatalogController::class, 'summary'],   'GET'],
            ['api/ping',                [AuthController::class, 'ping'],         'GET'],
            ['api/random',              [CatalogController::class, 'random'],    'GET'],
            ['api/top',                 [PlaybackController::class, 'top'],      'GET'],
            ['api/recent',              [PlaybackController::class, 'recent'],   'GET'],
            ['api/play/(\d+)',          [PlaybackController::class, 'play'],     'POST'],
            ['api/artist/(\d+)',        [CatalogController::class, 'artist'],    'GET'],
            ['api/album/(\d+)',         [CatalogController::class, 'album'],     'GET'],
            ['api/album/(\d+)',         [CatalogController::class, 'albumUpdate'], 'PATCH'],
            ['api/album/(\d+)',         [CatalogController::class, 'albumDelete'], 'DELETE'],
            ['api/song/(\d+)',          [CatalogController::class, 'song'],      'GET'],
            ['api/stream/(\d+)',        [MediaController::class, 'stream'],      'GET'],
            ['api/art/(\d+)',           [MediaController::class, 'art'],         'GET'],
            ['api/genres',              [CatalogController::class, 'genres'],    'GET'],
            ['api/genre',               [CatalogController::class, 'genre'],     'GET'],
            ['api/home',                [CatalogController::class, 'home'],      'GET'],
            ['api/favorites',           [FavoritesController::class, 'favorites'], 'GET'],
            ['api/settings',            [SettingsController::class, 'settings'], 'GET'],
            ['api/config',              [SettingsController::class, 'config'],   'PUT'],
            ['api/diag',                [SettingsController::class, 'diag'],     'POST'],
            ['api/scan',                [SettingsController::class, 'scan'],     'POST'],
            ['api/login',               [AuthController::class, 'login'],        'POST'],
            ['api/logout',              [AuthController::class, 'logout'],       'POST'],
            ['api/auth',                [AuthController::class, 'auth'],         'GET'],
        ];

        $parsedPath = parse_url($uri, PHP_URL_PATH);
        $path = is_string($parsedPath) ? $parsedPath : '';
        $path = preg_replace('#^/muzik#', '', $path) ?? $path;
        $path = trim($path, '/');

        foreach ($routes as [$pattern, $target, $verb]) {
            if ($effectiveMethod !== $verb) {
                continue;
            }
            if (preg_match('#^' . $pattern . '$#', $path, $m)) {
                if (!in_array($target, self::PUBLIC_HANDLERS, true)) {
                    Auth::requireAuth();
                }
                $args = array_slice($m, 1);
                self::dispatch($target, $args);

                return;
            }
        }

        App::err('Not found', 404);
    }

    /**
     * @param array{0: class-string, 1: string} $target
     * @param list<string> $args
     */
    private static function dispatch(array $target, array $args): void
    {
        [$controller, $action] = $target;
        if (!is_callable([$controller, $action])) {
            App::err('Handler not found', 500);
        }
        $controller::$action(...$args);
    }
}
