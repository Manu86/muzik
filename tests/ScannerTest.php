<?php

declare(strict_types=1);

final class ScannerTest extends TestCase
{
    private Scanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new Scanner();
    }

    public function testAudioExtensionsAreRecognisedCaseInsensitively(): void
    {
        foreach (['song.mp3', 'song.FLAC', 'song.ogg', 'song.m4a', 'song.wav'] as $file) {
            self::assertTrue($this->invoke('isAudio', $file));
        }
        self::assertFalse($this->invoke('isAudio', 'cover.jpg'));
    }

    public function testTrackNumbersAreParsedFromSupportedPrefixes(): void
    {
        self::assertSame(1, $this->invoke('parseTrack', '01 - Song.mp3'));
        self::assertSame(12, $this->invoke('parseTrack', 'track 12_ Song.flac'));
        self::assertSame(7, $this->invoke('parseTrack', 'INCOMPLETE~07. Song.ogg'));
        self::assertNull($this->invoke('parseTrack', 'Song without number.mp3'));
    }

    public function testTitlesAreCleanedFromFilenameNoise(): void
    {
        self::assertSame('Song', $this->invoke('titleFromFilename', '01 - Artist - Song (0h17).mp3'));
        self::assertSame('Title', $this->invoke('titleFromFilename', 'INCOMPLETE~02_Title@c6.flac'));
        self::assertSame('Piste inconnue', $this->invoke('titleFromFilename', '01.mp3'));
    }

    public function testGenericTagsAreRejected(): void
    {
        self::assertSame('', $this->invoke('tagValue', 'Unknown Artist', 'artist'));
        self::assertSame('', $this->invoke('tagValue', 'https://example.test', 'album'));
        self::assertSame('', $this->invoke('tagValue', 'Track 01', 'title'));
        self::assertSame('Real title', $this->invoke('tagValue', ' Real title ', 'title'));
    }

    public function testDiscDirectoriesAndAlbumBaseAreDetected(): void
    {
        self::assertSame(2, $this->invoke('discDir', '/music/Artist/Album/CD 2'));
        self::assertNull($this->invoke('discDir', '/music/Artist/Album'));
        self::assertSame(
            ['/music/Artist/Album', 'CD 2'],
            $this->invoke('albumBase', '/music/Artist/Album/CD 2', '/music'),
        );
    }

    public function testIncrementalScanIndexesFilesAndFullScanPrunesMissingOnes(): void
    {
        $root = $this->temporaryDirectory . '/music';
        $albumDirectory = $root . '/Artist/Album';
        mkdir($albumDirectory, 0777, true);
        $song = $albumDirectory . '/01 - Song.mp3';
        file_put_contents($song, 'not-a-real-mp3');
        $this->initialiseApp($root);

        ob_start();
        $this->scanner->run();
        ob_end_clean();

        self::assertSame(1, (int) App::pdo()->query('SELECT COUNT(*) FROM songs')->fetchColumn());
        $indexed = App::pdo()->query('SELECT s.title, a.name AS artist, al.name AS album FROM songs s JOIN artists a ON a.id = s.artist_id JOIN albums al ON al.id = s.album_id')->fetch();
        self::assertSame(['title' => 'Song', 'artist' => 'Artist', 'album' => 'Album'], $indexed);

        unlink($song);
        ob_start();
        $this->scanner->run(true);
        ob_end_clean();
        self::assertSame(0, (int) App::pdo()->query('SELECT COUNT(*) FROM songs')->fetchColumn());
        self::assertSame(0, (int) App::pdo()->query('SELECT COUNT(*) FROM albums')->fetchColumn());
        self::assertSame(0, (int) App::pdo()->query('SELECT COUNT(*) FROM artists')->fetchColumn());
    }

    public function testFlatArtistDirectoriesAndArtworkAreIndexed(): void
    {
        $root = $this->temporaryDirectory . '/music';
        $artistDirectory = $root . '/Flat Artist';
        mkdir($artistDirectory, 0777, true);
        file_put_contents($artistDirectory . '/01 - First.mp3', 'audio');
        file_put_contents($artistDirectory . '/02 - Second.mp3', 'audio');
        file_put_contents($artistDirectory . '/cover.jpg', 'cover');
        $this->initialiseApp($root);

        ob_start();
        $this->scanner->run();
        ob_end_clean();

        $album = App::pdo()->query(
            'SELECT al.name, al.art_path, ar.name AS artist,
                    (SELECT COUNT(*) FROM songs s WHERE s.album_id = al.id) AS songs
             FROM albums al JOIN artists ar ON ar.id = al.artist_id'
        )->fetch();
        self::assertIsArray($album);
        self::assertSame('Flat Artist', $album['artist']);
        self::assertSame('Flat Artist', $album['name']);
        self::assertSame($artistDirectory . '/cover.jpg', $album['art_path']);
        self::assertIsNumeric($album['songs']);
        self::assertSame(2, (int) $album['songs']);
    }

    public function testEmbeddedId3TagsAndIncrementalCacheRefreshAreUsed(): void
    {
        $root = $this->temporaryDirectory . '/music';
        $albumDirectory = $root . '/Folder Artist/Folder Album';
        mkdir($albumDirectory, 0777, true);
        $song = $albumDirectory . '/09 - Fallback.mp3';
        $tag = 'TAG'
            . str_pad('Tagged title', 30, "\0")
            . str_pad('Tagged artist', 30, "\0")
            . str_pad('Tagged album', 30, "\0")
            . '2004'
            . str_repeat("\0", 30)
            . "\x11";
        file_put_contents($song, str_repeat("\0", 64) . $tag);
        $this->initialiseApp($root);

        ob_start();
        $this->scanner->run();
        ob_end_clean();

        $indexed = App::pdo()->query(
            'SELECT s.title, s.track, a.name AS artist, al.name AS album, al.year, s.mtime
             FROM songs s JOIN artists a ON a.id = s.artist_id JOIN albums al ON al.id = s.album_id'
        )->fetch();
        self::assertIsArray($indexed);
        self::assertSame('Tagged title', $indexed['title']);
        self::assertSame('Tagged artist', $indexed['artist']);
        self::assertSame('Tagged album', $indexed['album']);
        self::assertIsNumeric($indexed['year']);
        self::assertIsNumeric($indexed['track']);
        self::assertSame(2004, (int) $indexed['year']);
        self::assertSame(9, (int) $indexed['track']);

        $newMtime = time() + 10;
        touch($song, $newMtime);
        clearstatcache(true, $song);
        ob_start();
        $this->scanner->run();
        ob_end_clean();
        self::assertSame($newMtime, (int) App::pdo()->query('SELECT mtime FROM songs')->fetchColumn());
        self::assertSame(1, (int) App::pdo()->query('SELECT COUNT(*) FROM songs')->fetchColumn());
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($this->scanner, $method);
        return $reflection->invoke($this->scanner, ...$arguments);
    }
}
