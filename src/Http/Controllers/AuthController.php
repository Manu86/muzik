<?php

declare(strict_types=1);

/**
 * Session et état du serveur (routes publiques).
 */
final class AuthController
{
    public static function login(): void
    {
        if (!Auth::enabled()) {
            App::json(['ok' => true, 'user' => null]);
        }
        $body = Request::body();
        $user = Request::stringValue($body['user'] ?? null);
        $pass = Request::stringValue($body['pass'] ?? null);
        $remember = Request::boolValue($body['remember'] ?? null);
        if ($user === '' || $pass === '' || !Auth::attempt($user, $pass, $remember)) {
            App::err('Unauthorized', 401);
        }
        App::json(['ok' => true, 'user' => Auth::currentLogin()]);
    }

    public static function logout(): void
    {
        Auth::logout();
        App::json(['ok' => true]);
    }

    public static function auth(): void
    {
        App::json([
            'authenticated' => Auth::check(),
            'user' => Auth::currentLogin(),
        ]);
    }

    public static function ping(): void
    {
        App::json(['ok' => true]);
    }
}
