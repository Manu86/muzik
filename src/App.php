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

    /**
     * Ramène un genre (tag ID3, service web…) vers un libellé canonique.
     * Les libellés de même famille sont fusionnés ; une valeur trop typée
     * ("rock / hard rock / metal") retient son premier segment reconnu.
     */
    public static function normalizeGenre(?string $genre): ?string
    {
        if ($genre === null || trim($genre) === '') {
            return null;
        }

        $key = strtolower(trim((string) preg_replace('/\s+/', ' ', $genre)));
        if (in_array($key, ['unknown', 'none', 'inconnu', 'no genre', 'various', 'varia'], true)) {
            return null;
        }

        $aliases = [
            'hip-hop/rap' => 'Rap/Hip Hop',
            'hip hop/rap' => 'Rap/Hip Hop',
            'rap/hip hop' => 'Rap/Hip Hop',
            'hip-hop & rap' => 'Rap/Hip Hop',
            'rap & hip-hop' => 'Rap/Hip Hop',
            'rap & hip hop' => 'Rap/Hip Hop',
            'hip hop' => 'Rap/Hip Hop',
            'rap' => 'Rap/Hip Hop',
            'variété française' => 'Chanson française',
            'variété francaise' => 'Chanson française',
            'variete francaise' => 'Chanson française',
            'raíces' => 'Latino',
            'musique brésilienne' => 'Latino',
            'brésil' => 'Latino',
            'bossa nova' => 'Latino',
            'metal' => 'Rock',
            'métal' => 'Rock',
            'rock' => 'Rock',
            'paroles/interprétation' => 'Chanson française',
            'musique classique' => 'Classique',
            'classique' => 'Classique',
            'classical' => 'Classique',
            'pop' => 'Pop',
            'electro' => 'Electro',
            'techno' => 'Electro',
            'house' => 'Electro',
            'dance' => 'Electro',
            'electronica' => 'Electro',
            'deep house' => 'Electro',
            'trance' => 'Electro',
            'jazz' => 'Jazz',
            'reggae' => 'Reggae',
            'alternative' => 'Alternative',
            'indie' => 'Alternative',
            'rock alternatif' => 'Alternative',
            'r&b' => 'R&B',
            'rnb' => 'R&B',
            'r and b' => 'R&B',
            'soul' => 'R&B',
            'musiques du monde' => 'Musiques du monde',
            'world music' => 'Musiques du monde',
            'chanson française' => 'Chanson française',
            'humour' => 'Humour / Parlé',
            'parlé' => 'Humour / Parlé',
            'spoken word' => 'Humour / Parlé',
            'comédie' => 'Humour / Parlé',
            'films/jeux vidéo' => 'Films/Jeux vidéo',
            'film' => 'Films/Jeux vidéo',
            'films' => 'Films/Jeux vidéo',
            'jeux vidéo' => 'Films/Jeux vidéo',
            'jeu video' => 'Films/Jeux vidéo',
            'game' => 'Films/Jeux vidéo',
            'bande originale' => 'Films/Jeux vidéo',
            'soundtrack' => 'Films/Jeux vidéo',
            'ost' => 'Films/Jeux vidéo',
        ];
        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        $parts = preg_split('/\s*\/\s*/', $key);
        if ($parts === false || $parts === []) {
            $parts = [$key];
        }
        foreach ($parts as $segment) {
            if (isset($aliases[$segment])) {
                return $aliases[$segment];
            }
        }

        if (preg_match('/^[0-9]+\s*(.*)$/', $genre, $m) === 1) {
            $genre = $m[1];
        }
        return ucwords(trim((string) preg_replace('/\s+/', ' ', $genre)));
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
