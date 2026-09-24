<?php

declare(strict_types=1);

final class DB
{
    private static ?DatabaseConnection $pdo = null;
    private static string $path = '';

    public static function init(string $path): DatabaseConnection
    {
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
            // Journal en mode DELETE (pas de WAL) : la base est partagée entre
            // plusieurs utilisateurs du système (le serveur web www-data et
            // l'admin en CLI). Le WAL laisse des fichiers -wal/-shm au premier
            // ouvrant qui bloquent l'autre en écriture sur un partage SMB.
            self::$pdo->exec('PRAGMA journal_mode = DELETE;');
            self::$pdo->exec('PRAGMA synchronous = NORMAL;');
            self::$pdo->exec('PRAGMA busy_timeout = 10000;');
            self::$pdo->exec('PRAGMA foreign_keys = ON;');
        }
        return self::$pdo;
    }

    public static function schema(): void
    {
        $pdo = self::connection();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS artists (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                path TEXT NOT NULL,
                art_path TEXT
            );
            CREATE TABLE IF NOT EXISTS albums (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                artist_id INTEGER NOT NULL REFERENCES artists(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                year INTEGER,
                path TEXT NOT NULL,
                art_path TEXT,
                genre TEXT,
                UNIQUE(artist_id, name)
            );
            CREATE TABLE IF NOT EXISTS songs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                album_id INTEGER NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
                artist_id INTEGER NOT NULL REFERENCES artists(id) ON DELETE CASCADE,
                title TEXT NOT NULL,
                track INTEGER,
                disc INTEGER,
                duration REAL,
                bitrate INTEGER,
                size INTEGER NOT NULL,
                path TEXT NOT NULL UNIQUE,
                mtime INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_songs_artist ON songs(artist_id);
            CREATE INDEX IF NOT EXISTS idx_songs_title ON songs(title);
            CREATE TABLE IF NOT EXISTS favorites (
                song_id INTEGER PRIMARY KEY REFERENCES songs(id) ON DELETE CASCADE,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT
            );
            CREATE TABLE IF NOT EXISTS genres (
                name TEXT PRIMARY KEY
            );
        ");
        $cols = $pdo->query('PRAGMA table_info(songs)')->fetchColumnValues(1);
        if (!in_array('play_count', $cols, true)) {
            $pdo->exec('ALTER TABLE songs ADD COLUMN play_count INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('last_played', $cols, true)) {
            $pdo->exec('ALTER TABLE songs ADD COLUMN last_played TEXT');
        }
        $albumCols = $pdo->query('PRAGMA table_info(albums)')->fetchColumnValues(1);
        if (!in_array('genre', $albumCols, true)) {
            $pdo->exec('ALTER TABLE albums ADD COLUMN genre TEXT');
        }
        $pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_songs_play_count ON songs(play_count);
        ');
        if ((int) $pdo->query('SELECT COUNT(*) FROM albums')->fetchColumn() === 0) {
            $seed = $pdo->prepare('INSERT OR IGNORE INTO genres(name) VALUES (?)');
            foreach (Genre::GENRES as $genre) {
                $seed->execute([$genre]);
            }
        }
    }

    public static function setting(string $key, ?string $default = null): ?string
    {
        $st = self::connection()->prepare('SELECT value FROM settings WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return is_string($v) ? $v : $default;
    }

    public static function setSetting(string $key, ?string $value): void
    {
        $st = self::connection()->prepare('INSERT INTO settings(key, value) VALUES(?, ?)
                                   ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $st->execute([$key, $value]);
    }

    private static function connection(): DatabaseConnection
    {
        if (self::$pdo === null) {
            throw new LogicException('DB::init() must be called before using the database.');
        }

        return self::$pdo;
    }
}
