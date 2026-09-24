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
            'SELECT s.title, s.track, a.name AS artist, al.name AS album, al.year, al.genre, s.mtime
             FROM songs s JOIN artists a ON a.id = s.artist_id JOIN albums al ON al.id = s.album_id'
        )->fetch();
        self::assertIsArray($indexed);
        self::assertSame('Tagged title', $indexed['title']);
        self::assertSame('Tagged artist', $indexed['artist']);
        self::assertSame('Tagged album', $indexed['album']);
        self::assertSame('Rock', $indexed['genre']);
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

    public function testEmbeddedArtworkIsExtractedWhenNoCoverFileExists(): void
    {
        $root = $this->temporaryDirectory . '/music';
        $artistDirectory = $root . '/VDA';
        mkdir($artistDirectory, 0777, true);
        $picture = "\xFF\xD8\xFF\xE0" . str_repeat("\x10", 64);
        file_put_contents($artistDirectory . '/01 - Premier.mp3', $this->id3WithPicture($picture));
        file_put_contents($artistDirectory . '/02 - Deuxième.mp3', 'audio');
        $this->initialiseApp($root);

        ob_start();
        $this->scanner->run();
        ob_end_clean();

        $artPath = App::pdo()->query('SELECT art_path FROM albums')->fetchColumn();
        self::assertIsString($artPath);
        self::assertFileExists($artPath);
        self::assertSame($picture, file_get_contents($artPath));
        self::assertSame(dirname(App::dbPath()) . '/art', dirname($artPath));
    }

    private function id3WithPicture(string $picture): string
    {
        $framePayload = "\x00" . 'image/jpeg' . "\x00" . "\x03" . "\x00" . $picture;
        $frame = 'APIC' . pack('N', strlen($framePayload)) . "\x00\x00" . $framePayload;
        $frameHeader = str_repeat("\xFF\xFB\x90\x00", 1) . str_repeat("\x00", 413);
        return 'ID3' . "\x03\x00\x00" . $this->synchsafe(strlen($frame)) . $frame
            . $frameHeader . $frameHeader . $frameHeader;
    }

    private function synchsafe(int $n): string
    {
        return chr(($n >> 21) & 0x7F) . chr(($n >> 14) & 0x7F)
            . chr(($n >> 7) & 0x7F) . chr($n & 0x7F);
    }
}
