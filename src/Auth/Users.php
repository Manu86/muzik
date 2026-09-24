<?php

declare(strict_types=1);

/**
 * Comptes utilisateurs (base méta data/users.db).
 *
 * Chaque utilisateur possède son propre catalogue (`db_path`) et sa propre
 * racine musicale (`music_root`). Cette base méta est indépendante des bases
 * catalogue : elle ne doit pas être confondue avec `App::pdo()`.
 */
final class Users
{
    private static ?DatabaseConnection $pdo = null;
    private static ?string $path = null;

    /**
     * Ouvre (ou réutilise) la base des comptes. Sans argument, conserve la
     * connexion déjà ouverte (initialisée explicitement, notamment par les
     * tests) ou utilise data/users.db par défaut.
     */
    public static function init(?string $path = null): DatabaseConnection
    {
        if ($path === null && self::$pdo !== null) {
            return self::$pdo;
        }
        $envPath = getenv('MUZIK_USERS_DB');
        $path ??= is_string($envPath) && $envPath !== '' ? $envPath : dirname(__DIR__, 2) . '/data/users.db';
        if (self::$pdo === null || self::$path !== $path) {
            self::$path = $path;
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            self::$pdo = new DatabaseConnection('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STATEMENT_CLASS => [DatabaseStatement::class],
            ]);
            self::$pdo->exec('PRAGMA journal_mode = WAL;');
            self::$pdo->exec('
                CREATE TABLE IF NOT EXISTS users (
                    login TEXT PRIMARY KEY,
                    auth_hash TEXT NOT NULL,
                    music_root TEXT NOT NULL,
                    db_path TEXT NOT NULL
                );
            ');
        }

        return self::$pdo;
    }

    /** Ferme la connexion courante (utile pour l'isolation des tests). */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$path = null;
    }

    public static function databasePath(): string
    {
        self::init();

        return self::$path ?? '';
    }

    public static function schema(): void
    {
        self::init();
    }

    /**
     * Crée le schéma et migre une éventuelle installation historique
     * (config.local.php avec un unique utilisateur) vers la base des comptes.
     */
    public static function ensureSchema(string $projectRoot): void
    {
        self::schema();
        if (self::count() === 0) {
            $legacy = self::legacyConfig($projectRoot);
            if ($legacy !== null) {
                self::import($legacy['login'], $legacy['auth_hash'], $legacy['music_root'], $legacy['db_path']);
            }
        }
    }

    /**
     * @return array{login: string, auth_hash: string, music_root: string, db_path: string}|null
     */
    private static function legacyConfig(string $projectRoot): ?array
    {
        $file = $projectRoot . '/config.local.php';
        if (!is_file($file)) {
            return null;
        }
        $config = require $file;
        if (!is_array($config)) {
            return null;
        }
        $login = self::normalizeLogin($config['auth_user'] ?? '');
        $hash = $config['auth_hash'] ?? '';
        $musicRoot = $config['music_root'] ?? '';
        $dbPath = $config['db_path'] ?? '';
        if ($login === '' || !is_string($hash) || $hash === '' || !is_string($musicRoot)
            || !is_string($dbPath) || $dbPath === '') {
            return null;
        }

        return ['login' => $login, 'auth_hash' => $hash, 'music_root' => $musicRoot, 'db_path' => $dbPath];
    }

    /**
     * Normalise un identifiant (minuscules, chiffres, tirets, 1 à 32 caractères)
     * pour être utilisé en nom de fichier de base (`data/<login>.db`).
     */
    public static function normalizeLogin(mixed $login): string
    {
        $login = is_string($login) ? trim($login) : '';
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/i', $login) !== 1) {
            return '';
        }

        return strtolower($login);
    }

    /**
     * @return array{login: string, auth_hash: string, music_root: string, db_path: string}|null
     */
    public static function find(string $login): ?array
    {
        $st = self::init()->prepare('SELECT login, auth_hash, music_root, db_path FROM users WHERE login = ?');
        $st->execute([$login]);
        $row = $st->fetch();

        /** @var array{login: string, auth_hash: string, music_root: string, db_path: string}|false $row */
        return is_array($row) ? $row : null;
    }

    /**
     * Projet catalogue/racine musical d'un compte.
     *
     * @return array{music_root: string, db_path: string}|null
     */
    public static function profile(string $login): ?array
    {
        $row = self::find($login);
        if ($row === null) {
            return null;
        }

        return ['music_root' => $row['music_root'], 'db_path' => $row['db_path']];
    }

    /**
     * @return list<array{login: string, auth_hash: string, music_root: string, db_path: string}>
     */
    public static function all(): array
    {
        $rows = self::init()->query('SELECT login, auth_hash, music_root, db_path FROM users ORDER BY login')->fetchAll();

        $users = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['login'] ?? null) || !is_string($row['auth_hash'] ?? null)
                || !is_string($row['music_root'] ?? null) || !is_string($row['db_path'] ?? null)) {
                continue;
            }
            $users[] = [
                'login' => $row['login'],
                'auth_hash' => $row['auth_hash'],
                'music_root' => $row['music_root'],
                'db_path' => $row['db_path'],
            ];
        }

        return $users;
    }

    public static function count(): int
    {
        $v = self::init()->query('SELECT COUNT(*) FROM users')->fetchColumn();

        return is_numeric($v) ? (int) $v : 0;
    }

    public static function create(string $login, string $password, string $musicRoot): void
    {
        $login = self::normalizeLogin($login);
        $musicRoot = trim($musicRoot);
        if ($login === '') {
            throw new InvalidArgumentException('Identifiant invalide (minuscules, chiffres, tirets, 1 à 32 caractères).');
        }
        if ($password === '' || mb_strlen($password) < 8) {
            throw new InvalidArgumentException('Le mot de passe doit contenir au moins 8 caractères.');
        }
        if ($musicRoot === '') {
            throw new InvalidArgumentException('Indiquez le dossier contenant vos fichiers de musique.');
        }
        self::ensureUnique($login);

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $dbPath = self::defaultDatabasePath($login);
        $ins = self::init()->prepare('INSERT INTO users(login, auth_hash, music_root, db_path) VALUES(?,?,?,?)');
        $ins->execute([$login, $hash, $musicRoot, $dbPath]);

        DB::init($dbPath);
        DB::schema();
    }

    /**
     * Crée un compte avec un hachage bcrypt déjà connu (auto-migration).
     */
    public static function import(string $login, string $authHash, string $musicRoot, string $dbPath): void
    {
        $login = self::normalizeLogin($login);
        $musicRoot = trim($musicRoot);
        if ($login === '' || $authHash === '' || $musicRoot === '' || trim($dbPath) === '') {
            throw new InvalidArgumentException('Valeurs de compte invalides.');
        }

        $ins = self::init()->prepare('INSERT INTO users(login, auth_hash, music_root, db_path) VALUES(?,?,?,?)');
        $ins->execute([$login, $authHash, $musicRoot, trim($dbPath)]);
    }

    public static function updateMusicRoot(string $login, string $musicRoot): void
    {
        $musicRoot = trim($musicRoot);
        if ($musicRoot === '') {
            throw new InvalidArgumentException('Indiquez le dossier contenant vos fichiers de musique.');
        }
        $st = self::init()->prepare('UPDATE users SET music_root = ? WHERE login = ?');
        $st->execute([$musicRoot, $login]);
    }

    public static function updatePassword(string $login, string $password): void
    {
        if ($password === '' || mb_strlen($password) < 8) {
            throw new InvalidArgumentException('Le mot de passe doit contenir au moins 8 caractères.');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $st = self::init()->prepare('UPDATE users SET auth_hash = ? WHERE login = ?');
        $st->execute([$hash, $login]);
    }

    public static function delete(string $login): void
    {
        $st = self::init()->prepare('DELETE FROM users WHERE login = ?');
        $st->execute([$login]);
    }

    public static function defaultDatabasePath(string $login): string
    {
        return dirname(self::databasePath()) . '/' . $login . '.db';
    }

    /**
     * Réglages globaux (config/app.php surchargés par config.local.php).
     *
     * @return array<string, mixed>
     */
    public static function baseConfig(): array
    {
        $config = require dirname(__DIR__, 2) . '/config.php';
        if (!is_array($config)) {
            throw new RuntimeException('config.php doit retourner un tableau.');
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * Configuration applicative complète pour un utilisateur : les réglages
     * globaux (config.php) plus la racine musicale et la base de l'utilisateur.
     *
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    public static function resolveConfig(string $login, array $base): array
    {
        $profile = self::profile($login);
        if ($profile === null) {
            throw new RuntimeException("Utilisateur inconnu : {$login}.");
        }

        /** @var array<string, mixed> $merged */
        $merged = array_replace($base, $profile, ['auth_user' => '', 'auth_hash' => '']);

        return $merged;
    }

    private static function ensureUnique(string $login): void
    {
        $st = self::init()->prepare('SELECT 1 FROM users WHERE login = ?');
        $st->execute([$login]);
        if ($st->fetchColumn() !== false) {
            throw new RuntimeException("L'utilisateur « {$login} » existe déjà.");
        }
    }
}
