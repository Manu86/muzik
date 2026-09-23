<?php

declare(strict_types=1);

final class DBTest extends TestCase
{
    public function testSchemaCreatesEveryTableAndMigrationColumn(): void
    {
        $pdo = DB::init($this->temporaryDirectory . '/schema.sqlite');
        DB::schema();

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach (['artists', 'albums', 'songs', 'favorites', 'settings', 'genres'] as $table) {
            self::assertContains($table, $tables);
        }

        $songColumns = $pdo->query('PRAGMA table_info(songs)')->fetchAll(PDO::FETCH_COLUMN, 1);
        self::assertContains('play_count', $songColumns);
        self::assertContains('last_played', $songColumns);
        $albumColumns = $pdo->query('PRAGMA table_info(albums)')->fetchAll(PDO::FETCH_COLUMN, 1);
        self::assertContains('genre', $albumColumns);
    }

    public function testSchemaMigratesAnExistingAlbumsTable(): void
    {
        $pdo = DB::init($this->temporaryDirectory . '/legacy.sqlite');
        $pdo->exec('CREATE TABLE artists (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, path TEXT NOT NULL, art_path TEXT)');
        $pdo->exec('CREATE TABLE albums (id INTEGER PRIMARY KEY, artist_id INTEGER NOT NULL, name TEXT NOT NULL, year INTEGER, path TEXT NOT NULL, art_path TEXT, UNIQUE(artist_id, name))');

        DB::schema();

        $columns = $pdo->query('PRAGMA table_info(albums)')->fetchAll(PDO::FETCH_COLUMN, 1);
        self::assertContains('genre', $columns);
    }

    public function testSchemaSeedsCanonicalGenresOnlyForAnEmptyCatalogue(): void
    {
        $pdo = DB::init($this->temporaryDirectory . '/seeded.sqlite');
        DB::schema();
        self::assertCount(count(DB::GENRES), $pdo->query('SELECT name FROM genres')->fetchAll(PDO::FETCH_COLUMN));
        DB::schema();
        self::assertCount(count(DB::GENRES), $pdo->query('SELECT name FROM genres')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testSchemaKeepsAnExistingCatalogueWithoutSeedingGenres(): void
    {
        $pdo = DB::init($this->temporaryDirectory . '/existing.sqlite');
        $pdo->exec('CREATE TABLE artists (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, path TEXT NOT NULL, art_path TEXT)');
        $pdo->exec('CREATE TABLE albums (id INTEGER PRIMARY KEY, artist_id INTEGER NOT NULL, name TEXT NOT NULL, year INTEGER, path TEXT NOT NULL, art_path TEXT, genre TEXT, UNIQUE(artist_id, name))');
        $pdo->exec("INSERT INTO artists(id, name, path) VALUES(1, 'A', '/a')");
        $pdo->exec("INSERT INTO albums(id, artist_id, name, path, genre) VALUES(1, 1, 'Al', '/a', 'Rock')");

        DB::schema();

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM genres')->fetchColumn());
    }

    public function testSettingsCanBeCreatedAndUpdated(): void
    {
        DB::init($this->temporaryDirectory . '/settings.sqlite');
        DB::schema();

        self::assertSame('fallback', DB::setting('transcode', 'fallback'));
        DB::setSetting('transcode', '128');
        self::assertSame('128', DB::setting('transcode'));
        DB::setSetting('transcode', '64');
        self::assertSame('64', DB::setting('transcode'));
    }

    public function testForeignKeysCascadeThroughTheCatalogue(): void
    {
        $pdo = DB::init($this->temporaryDirectory . '/cascade.sqlite');
        DB::schema();
        $pdo->exec("INSERT INTO artists(id, name, path) VALUES(1, 'Artist', '/artist')");
        $pdo->exec("INSERT INTO albums(id, artist_id, name, path) VALUES(1, 1, 'Album', '/album')");
        $pdo->exec("INSERT INTO songs(id, album_id, artist_id, title, size, path, mtime) VALUES(1, 1, 1, 'Song', 1, '/song.mp3', 1)");
        $pdo->exec('INSERT INTO favorites(song_id) VALUES(1)');

        $pdo->exec('DELETE FROM artists WHERE id = 1');

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM albums')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM songs')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM favorites')->fetchColumn());
    }
}
