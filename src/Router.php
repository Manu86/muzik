<?php

final class Router
{
    /** Handlers accessibles sans session authentifiée. */
    private const PUBLIC_HANDLERS = ['login', 'logout', 'auth'];

    public static function handle(string $uri, string $method): void
    {
        if (!in_array($method, ['GET', 'PUT', 'POST', 'PATCH', 'DELETE', 'HEAD'], true)) {
            App::err('Method not allowed', 405);
        }
        $effectiveMethod = $method === 'HEAD' ? 'GET' : $method;
        $routes = [
            ['api/search',              'search',    'GET'],
            ['api/artists',             'artists',   'GET'],
            ['api/albums',              'albums',    'GET'],
            ['api/summary',             'summary',   'GET'],
            ['api/ping',                'ping',      'GET'],
            ['api/random',              'random',    'GET'],
            ['api/top',                 'top',       'GET'],
            ['api/recent',              'recent',    'GET'],
            ['api/play/(\d+)',          'play',      'POST'],
            ['api/artist/(\d+)',        'artist',    'GET'],
            ['api/album/(\d+)',         'album',     'GET'],
            ['api/album/(\d+)',         'albumUpdate','PATCH'],
            ['api/album/(\d+)',         'albumDelete','DELETE'],
            ['api/song/(\d+)',          'song',      'GET'],
            ['api/stream/(\d+)',        'stream',    'GET'],
            ['api/art/(\d+)',           'art',       'GET'],
            ['api/genres',              'genres',    'GET'],
            ['api/genre',               'genre',     'GET'],
            ['api/home',                'home',      'GET'],
            ['api/favorites',           'favorites', 'GET'],
            ['api/settings',            'settings',  'GET'],
            ['api/config',              'config',    'PUT'],
            ['api/diag',                'diag',      'POST'],
            ['api/scan',                'scan',      'POST'],
            ['api/login',               'login',     'POST'],
            ['api/logout',              'logout',    'POST'],
            ['api/auth',                'auth',      'GET'],
        ];

        $parsedPath = parse_url($uri, PHP_URL_PATH);
        $path = is_string($parsedPath) ? $parsedPath : '';
        $path = preg_replace('#^/muzik#', '', $path) ?? $path;
        $path = trim($path, '/');

        foreach ($routes as [$pattern, $handler, $verb]) {
            if ($effectiveMethod !== $verb) {
                continue;
            }
            if ($pattern === 'api/artists' && isset($_GET['letter'])) {
                $handler = 'artistsByLetter';
            }
            if (preg_match('#^' . $pattern . '$#', $path, $m)) {
                if (!in_array($handler, self::PUBLIC_HANDLERS, true)) {
                    Auth::requireAuth();
                }
                $args = array_slice($m, 1);
                self::dispatch($handler, $args);

                return;
            }
        }

        App::err('Not found', 404);
    }

    /** @param list<string> $args */
    private static function dispatch(string $handler, array $args): void
    {
        switch ($handler) {
            case 'search': Api::search();
                break;
            case 'artists': Api::artists();
                break;
            case 'artistsByLetter': Api::artistsByLetter();
                break;
            case 'albums': Api::albums();
                break;
            case 'summary': Api::summary();
                break;
            case 'ping': Api::ping();
                break;
            case 'random': Api::random();
                break;
            case 'top': Api::top();
                break;
            case 'recent': Api::recent();
                break;
            case 'play': Api::play($args[0]);
                break;
            case 'artist': Api::artist($args[0]);
                break;
            case 'album': Api::album($args[0]);
                break;
            case 'albumUpdate': Api::albumUpdate($args[0]);
                break;
            case 'albumDelete': Api::albumDelete($args[0]);
                break;
            case 'song': Api::song($args[0]);
                break;
            case 'stream': Api::stream($args[0]);
                break;
            case 'art': Api::art($args[0]);
                break;
            case 'genres': Api::genres();
                break;
            case 'home': Api::home();
                break;
            case 'genre': Api::genre();
                break;
            case 'favorites': Api::favorites();
                break;
            case 'settings': Api::settings();
                break;
            case 'config': Api::config();
                break;
            case 'diag': Api::diag();
                break;
            case 'scan': Api::scan();
                break;
            case 'login': Api::login();
                break;
            case 'logout': Api::logout();
                break;
            case 'auth': Api::auth();
                break;
            default: App::err('Handler not found', 500);
        }
    }
}
