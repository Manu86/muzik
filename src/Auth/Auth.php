<?php

declare(strict_types=1);

/**
 * Authentification multi-utilisateurs, par session PHP.
 *
 * Les comptes vivent dans la base méta (Users, data/users.db). La protection est
 * active dès qu'au moins un compte existe. La session mémorise le login ; chaque
 * requête protégée ouvre le catalogue de l'utilisateur correspondant.
 */
final class Auth
{
    private const SESSION_NAME = 'muzik_session';
    private const SESSION_KEY = 'muzik_authenticated';
    private const REMEMBER_LIFETIME = 2592000;

    /**
     * La protection est configurée lorsque la base des comptes contient au
     * moins un utilisateur.
     */
    public static function enabled(): bool
    {
        return Users::count() > 0;
    }

    /**
     * Login de l'utilisateur connecté, ou null hors session valide.
     */
    public static function currentLogin(): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        self::start();
        $login = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($login) || $login === '') {
            return null;
        }

        return Users::find($login) !== null ? $login : null;
    }

    /**
     * Vrai lorsque l'utilisateur est connecté, ou lorsque la protection est
     * désactivée (aucune session n'est alors nécessaire).
     */
    public static function check(): bool
    {
        if (!self::enabled()) {
            return true;
        }

        return self::currentLogin() !== null;
    }

    /**
     * Tente une connexion avec les identifiants d'un compte de data/users.db.
     */
    public static function attempt(string $user, string $password, bool $remember = false): bool
    {
        $login = Users::normalizeLogin($user);
        if ($login === '' || $password === '') {
            return false;
        }
        $profile = Users::find($login);
        if ($profile === null || !hash_equals($login, $profile['login'] ?? '')
            || !password_verify($password, $profile['auth_hash'] ?? '')) {
            return false;
        }

        self::start($remember ? self::REMEMBER_LIFETIME : 0);
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $login;

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
