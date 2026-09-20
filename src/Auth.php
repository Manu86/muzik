<?php

declare(strict_types=1);

/**
 * Authentification propre à l'application, par session PHP.
 *
 * La protection n'est active que lorsque la configuration contient un nom
 * d'utilisateur (`auth_user`) et un hachage bcrypt (`auth_hash`) non vides.
 * Tant qu'elle est désactivée, toutes les routes restent ouvertes.
 */
final class Auth
{
    private const SESSION_NAME = 'muzik_session';
    private const SESSION_KEY = 'muzik_authenticated';
    private const REMEMBER_LIFETIME = 2592000;

    /**
     * Une authentification est configurée lorsque le nom d'utilisateur et son
     * hachage sont renseignés dans la configuration.
     */
    public static function enabled(): bool
    {
        $user = App::config('auth_user');
        $hash = App::config('auth_hash');

        return is_string($user) && $user !== '' && is_string($hash) && $hash !== '';
    }

    /**
     * Vrai lorsque l'utilisateur est connecté, ou lorsque la protection est
     * désactivée (aucune session n'est alors ouverte).
     */
    public static function check(): bool
    {
        if (!self::enabled()) {
            return true;
        }
        self::start();

        return ($_SESSION[self::SESSION_KEY] ?? false) === true;
    }

    /**
     * Tente une connexion avec les identifiants de la configuration.
     */
    public static function attempt(string $user, string $password, bool $remember = false): bool
    {
        if (!self::enabled() || $password === '') {
            return false;
        }
        $expectedUser = App::config('auth_user');
        $hash = App::config('auth_hash');
        if (!is_string($expectedUser) || !is_string($hash) || !hash_equals($expectedUser, $user)
            || !password_verify($password, $hash)) {
            return false;
        }

        self::start($remember ? self::REMEMBER_LIFETIME : 0);
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;

        return true;
    }

    /**
     * Détruit la session courante s'il en existe une.
     */
    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        session_destroy();
    }

    /**
     * Rejette la requête avec une réponse 401 si l'accès est interdit.
     */
    public static function requireAuth(): void
    {
        if (!self::check()) {
            App::err('Unauthorized', 401);
        }
    }

    private static function start(int $lifetime = 0): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}
