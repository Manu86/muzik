<?php

/**
 * @phpstan-type Config array{
 *     music_root: string,
 *     db_path: string,
 *     ffmpeg: string,
 *     transcode: int,
 *     auth_user: string,
 *     auth_hash: string
 * }
 */
final class App
{
    /** @var Config */
    private static array $config = [
        'music_root' => '',
        'db_path' => '',
        'ffmpeg' => 'ffmpeg',
        'transcode' => 0,
        'auth_user' => '',
        'auth_hash' => '',
    ];
    private static ?DatabaseConnection $pdo = null;
    private static ?Closure $jsonResponder = null;

    /** @param Config $config */
    public static function init(array $config): void
    {
        self::$config = $config;
        self::$pdo = DB::init($config['db_path']);
        DB::schema();
    }

    public static function initConfig(mixed $config): void
    {
        if (!is_array($config)) {
            throw new InvalidArgumentException('The application configuration must be an array.');
        }

        $musicRoot = $config['music_root'] ?? null;
        $dbPath = $config['db_path'] ?? null;
        $ffmpeg = $config['ffmpeg'] ?? null;
        $transcode = $config['transcode'] ?? null;
        $authUser = $config['auth_user'] ?? null;
        $authHash = $config['auth_hash'] ?? null;
        if (!is_string($musicRoot) || !is_string($dbPath) || !is_string($ffmpeg) || !is_int($transcode)
            || !is_string($authUser) || !is_string($authHash)) {
            throw new InvalidArgumentException('The application configuration contains invalid values.');
        }

        self::init([
            'music_root' => $musicRoot,
            'db_path' => $dbPath,
            'ffmpeg' => $ffmpeg,
            'transcode' => $transcode,
            'auth_user' => $authUser,
            'auth_hash' => $authHash,
        ]);
    }

    public static function config(?string $key = null): mixed
    {
        return $key === null ? self::$config : (self::$config[$key] ?? null);
    }

    public static function pdo(): DatabaseConnection
    {
        if (self::$pdo === null) {
            throw new LogicException('App::init() must be called before accessing the database.');
        }

        return self::$pdo;
    }

    public static function musicRoot(): string
    {
        return rtrim(self::$config['music_root'], '/');
    }

    public static function ffmpeg(): string
    {
        return self::$config['ffmpeg'];
    }

    public static function transcodeBitrate(): int
    {
        return self::$config['transcode'];
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    public static function setJsonResponder(?Closure $responder): void
    {
        self::$jsonResponder = $responder;
    }

    public static function json(mixed $data, int $code = 200): never
    {
        if (self::$jsonResponder !== null) {
            (self::$jsonResponder)($data, $code);
        }

        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function err(string $msg, int $code = 400): never
    {
        self::json(['error' => $msg], $code);
    }
}
