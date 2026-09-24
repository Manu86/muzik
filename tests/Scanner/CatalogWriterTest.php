<?php

declare(strict_types=1);

final class CatalogWriterTest extends TestCase
{
    public function testEnsureAlbumStoresAndBackfillsGenre(): void
    {
        $this->initialiseApp($this->temporaryDirectory . '/music');
        $pdo = App::pdo();
        $pdo->exec("INSERT INTO artists(name, path) VALUES('Artist', '/x')");
        $artistId = (int) $pdo->lastInsertId();

        $writer = new CatalogWriter(new ArtExtractor());

        $albumId = $writer->ensureAlbum($artistId, 'Album', null, '/x', null, 'Pop');
        self::assertNotSame(0, $albumId, 'woodoes');
        self::assertNotSame(0, $albumId);
        self::assertSame('Pop', (string) $pdo->query("SELECT genre FROM albums WHERE id = $albumId")->fetchColumn());

        $again = $writer->ensureAlbum($artistId, 'Album', null, '/x', null, '');
        self::assertSame($albumId, $again);
        self::assertSame('Pop', (string) $pdo->query("SELECT genre FROM albums WHERE id = $albumId")->fetchColumn());

        $pdo->exec("INSERT INTO albums(artist_id, name, genre, path) VALUES($artistId, 'Album 2', '', '/x')");
        $album2 = (int) $pdo->lastInsertId();
        $writer->ensureAlbum($artistId, 'Album 2', null, '/x', null, 'Jazz');
        self::assertSame('Jazz', (string) $pdo->query("SELECT genre FROM albums WHERE id = $album2")->fetchColumn());
    }
}
