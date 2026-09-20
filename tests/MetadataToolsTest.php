<?php

declare(strict_types=1);

if (!defined('MUZIK_INCLUDE_ONLY')) {
    define('MUZIK_INCLUDE_ONLY', true);
}
require_once __DIR__ . '/../bin/fetch-art.php';

if (!defined('MUZIK_ALBUM_RESOLVE_INCLUDE_ONLY')) {
    define('MUZIK_ALBUM_RESOLVE_INCLUDE_ONLY', true);
}
require_once __DIR__ . '/../bin/album-resolve.php';

if (!defined('MUZIK_ALBUM_EDIT_INCLUDE_ONLY')) {
    define('MUZIK_ALBUM_EDIT_INCLUDE_ONLY', true);
}
require_once __DIR__ . '/../bin/album-edit.php';

if (!defined('MUZIK_ARTIST_ART_INCLUDE_ONLY')) {
    define('MUZIK_ARTIST_ART_INCLUDE_ONLY', true);
}
require_once __DIR__ . '/../bin/fetch-artist-art.php';

final class MetadataToolsTest extends TestCase
{
    public function testNamesAndGenresAreNormalised(): void
    {
        self::assertSame('sigur ros', norm('Sigur Rós'));
        self::assertSame('beatles', normArt('The Beatles'));
        self::assertSame('Album', cleanAlbumName('Album {promo} @c6'));
        self::assertSame('Artist', cleanArtistName('Artist (live)'));
        self::assertSame('Rap/Hip Hop', normalizeGenre(' hip-hop/rap '));
        self::assertSame('Classique', normalizeGenre('Musique classique'));
        self::assertNull(normalizeGenre(null));
    }

    public function testAlbumAndTrackMatchingScoresRejectFalsePositives(): void
    {
        self::assertSame(6.5, scoreMatch('artist', 'album', 'artist', 'album', false, false, 2000, 2000));
        self::assertSame(0.0, scoreMatch('artist', 'album', 'other', 'album', false, false));
        self::assertGreaterThan(
            trackScore('artist', 'title', 'artist', 'title', 'Greatest Hits'),
            trackScore('artist', 'title', 'artist', 'title'),
        );
        self::assertSame(0.0, trackScore('artist', 'title', 'other', 'different'));
    }

    public function testRawCoversAreValidatedSavedAndReplaceTheOldFormat(): void
    {
        $directory = $this->temporaryDirectory . '/cover';
        mkdir($directory);
        file_put_contents($directory . '/cover.jpg', 'old');

        self::assertFalse(saveCoverRaw($directory, 'invalid'));
        $png = "\x89\x50fake-png";
        self::assertSame($directory . '/cover.png', saveCoverRaw($directory, $png));
        self::assertSame($png, file_get_contents($directory . '/cover.png'));
        self::assertFileDoesNotExist($directory . '/cover.jpg');

        $jpegDirectory = $this->temporaryDirectory . '/jpeg';
        mkdir($jpegDirectory);
        $jpeg = "\xff\xd8fake-jpeg";
        self::assertSame($jpegDirectory . '/cover.jpg', saveCoverRaw($jpegDirectory, $jpeg));
        self::assertSame($jpeg, file_get_contents($jpegDirectory . '/cover.jpg'));
    }

    public function testNetworkHelpersRejectIdentifiersThatCannotBeQueried(): void
    {
        self::assertNull(deezerGenreLookup(0));
        self::assertNull(deezerAlbumGenres(0));
        self::assertSame(['year' => null, 'genre' => null], deezerAlbumObject(0));
    }

    public function testArtistPortraitMatchingIsExactAndGenericNamesAreRejected(): void
    {
        $json = json_encode(['data' => [
            ['id' => 1, 'name' => 'Maximum', 'picture_xl' => 'https://example.test/wrong.jpg'],
            ['id' => 2, 'name' => 'Le Maximum Kouette', 'picture_big' => 'https://example.test/right.jpg'],
        ]], JSON_THROW_ON_ERROR);

        self::assertSame([
            'id' => 2,
            'name' => 'Le Maximum Kouette',
            'url' => 'https://example.test/right.jpg',
        ], exactDeezerArtistCandidate('Le Maximum Kouette', $json));
        self::assertNull(exactDeezerArtistCandidate('Another Artist', $json));
        self::assertTrue(isGenericArtistName('Classique'));
        self::assertTrue(isGenericArtistName('BO'));
        self::assertTrue(isGenericArtistName('Bootlegs de qualitÚ moyenne'));
        self::assertFalse(isGenericArtistName('Little Man Tate'));
    }

    public function testAlbumEditPlanIsBuiltReadOnlyFromTheDatabase(): void
    {
        $pdo = new PDO('sqlite:' . $this->temporaryDirectory . '/album-edit.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE artists(id INTEGER PRIMARY KEY, name TEXT, path TEXT)');
        $pdo->exec("INSERT INTO artists(id, name, path) VALUES(1, 'Alpha', '/a')");
        $pdo->exec('CREATE TABLE albums(id INTEGER PRIMARY KEY, artist_id INTEGER, name TEXT,
                   year INTEGER, path TEXT, art_path TEXT, genre TEXT)');
        $pdo->exec("INSERT INTO albums(id, artist_id, name, year, path) VALUES(1, 1, 'Renamed', 1999, '/a/rm')");
        $pdo->exec('CREATE TABLE songs(id INTEGER PRIMARY KEY, album_id INTEGER, artist_id INTEGER,
                   title TEXT, track INTEGER, disc INTEGER, duration REAL, bitrate INTEGER,
                   size INTEGER, path TEXT, mtime INTEGER)');
        $pdo->exec("INSERT INTO songs(id, album_id, artist_id, title, track, path)
                    VALUES(1, 1, 1, 'Song', 1, '/a/rm/01 Song.mp3')");

        $result = albumEditPlan($pdo, 1);
        self::assertSame('Renamed', $result['album']['name']);
        self::assertSame(1999, $result['album']['year']);
        self::assertCount(1, $result['plan']);
        self::assertSame('Renamed', $result['plan'][0]['album']);
        self::assertSame(1999, $result['plan'][0]['year']);
        self::assertSame('/a/rm/01 Song.mp3', $result['plan'][0]['path']);

        $missing = albumEditPlan($pdo, 99);
        self::assertSame([], $missing['album']);
        self::assertSame([], $missing['plan']);
    }
}
