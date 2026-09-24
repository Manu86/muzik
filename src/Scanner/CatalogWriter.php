<?php

declare(strict_types=1);

/**
 * Écriture du catalogue pendant l'indexation : création artistes/albums,
 * upserts de pistes, cache de lecture et suppression des entrées obsolètes.
 *
 * @phpstan-type CachedSong array{title: string, track: ?int, disc: ?int,
 *     duration: ?float, bitrate: ?int, artist_name: string, album_name: string}
 * @phpstan-import-type SongData from TagReader
 */
final class CatalogWriter
{
    private ArtExtractor $art;
    private int $added = 0;
    private int $updated = 0;

    public function __construct(ArtExtractor $art)
    {
        $this->art = $art;
    }

    public function added(): int
    {
        return $this->added;
    }

    public function updated(): int
    {
        return $this->updated;
    }

    /**
     * @return CachedSong|null
     */
    public function cachedRow(string $file, int $mtime, int $size): ?array
    {
        $st = App::pdo()->prepare('SELECT s.id, s.title, s.track, s.disc, s.duration, s.bitrate,
                                          s.size, s.mtime, a.name AS artist_name, al.name AS album_name
                                   FROM songs s
                                   JOIN artists a ON a.id = s.artist_id
                                   JOIN albums al ON al.id = s.album_id
                                   WHERE s.path = ?');
        $st->execute([$file]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $rowMtime = $this->integer($row['mtime'] ?? null);
        $rowSize = $this->integer($row['size'] ?? null);
        if ($rowMtime === $mtime && $rowSize === $size) {
            return [
                'title' => $this->text($row['title'] ?? null),
                'track' => $this->nullableInteger($row['track'] ?? null),
                'disc' => $this->nullableInteger($row['disc'] ?? null),
                'duration' => $this->nullableFloat($row['duration'] ?? null),
                'bitrate' => $this->nullableInteger($row['bitrate'] ?? null),
                'artist_name' => $this->text($row['artist_name'] ?? null),
                'album_name' => $this->text($row['album_name'] ?? null),
            ];
        }
        return null;
    }

    public function ensureArtist(string $name, string $path, string $artSearch): int
    {
        $name = FilenameParser::clean($name);
        if ($name === '') {
            $name = basename($artSearch);
        }
        $st = App::pdo()->prepare('SELECT id FROM artists WHERE name = ? COLLATE NOCASE');
        $st->execute([$name]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            $art = self::artPath($artSearch, $this->art);
            if ($art) {
                App::pdo()->prepare('UPDATE artists SET art_path = COALESCE(art_path, ?) WHERE id = ?')
                    ->execute([$art, $id]);
            }
            return (int) $id;
        }
        $art = self::artPath($artSearch, $this->art);
        $ins = App::pdo()->prepare('INSERT INTO artists(name, path, art_path) VALUES(?,?,?)');
        $ins->execute([$name, $path, $art]);
        return (int) App::pdo()->lastInsertId();
    }

    /**
     * @phpstan-import-type SongData from TagReader
     */
    public function ensureAlbum(
        int $artistId,
        string $name,
        ?int $year,
        string $path,
        ?string $art,
        ?string $genre = null
    ): int {
        $name = FilenameParser::clean($name);
        if ($name === '') {
            $name = 'Sans album';
        }
        $genre = $genre !== null ? trim($genre) : '';
        $st = App::pdo()->prepare('SELECT id, genre FROM albums WHERE artist_id = ? AND name = ? COLLATE NOCASE');
        $st->execute([$artistId, $name]);
        $row = $st->fetch();
        if (is_array($row)) {
            $id = $this->integer($row['id'] ?? null);
            if ($art && !$this->albumHasArt($id)) {
                App::pdo()->prepare('UPDATE albums SET art_path = ? WHERE id = ?')
                    ->execute([$art, $id]);
            }
            if ($genre !== '' && ($row['genre'] === null || $row['genre'] === '')) {
                App::pdo()->prepare('UPDATE albums SET genre = ? WHERE id = ?')
                    ->execute([$genre, $id]);
            }
            return $id;
        }
        $ins = App::pdo()->prepare('INSERT INTO albums(artist_id, name, year, path, art_path, genre)
                                    VALUES(?,?,?,?,?,?)');
        $ins->execute([$artistId, $name, $year, $path, $art, $genre !== '' ? $genre : null]);
        return (int) App::pdo()->lastInsertId();
    }

    /**
     * @param SongData            $s
     * @param array<string, true> $seen
     * @param-out array<string, true> $seen
     */
    public function upsertSong(array $s, int $artistId, int $albumId, array &$seen): void
    {
        $seen[$s['path']] = true;

        if (!empty($s['cached'])) {
            return;
        }

        $title = FilenameParser::clean($s['title'] ?? '');
        if ($title === '') {
            $title = FilenameParser::titleFromFilename(basename($s['path']));
        }
        $track = $s['track'] !== null ? (int) $s['track'] : null;
        $disc = $s['disc'] !== null ? (int) $s['disc'] : null;

        $st = App::pdo()->prepare('SELECT id FROM songs WHERE path = ?');
        $st->execute([$s['path']]);
        $id = $st->fetchColumn();

        if ($id !== false) {
            App::pdo()->prepare('UPDATE songs SET album_id=?, artist_id=?, title=?, track=?,
                                disc=?, duration=?, bitrate=?, size=?, mtime=?
                                WHERE id = ?')
                ->execute([$albumId, $artistId, $title, $track, $disc,
                    $s['duration'], $s['bitrate'], $s['size'], $s['mtime'], $id]);
            $this->updated++;
        } else {
            App::pdo()->prepare('INSERT INTO songs(album_id, artist_id, title, track, disc,
                                  duration, bitrate, size, path, mtime)
                                 VALUES(?,?,?,?,?,?,?,?,?,?)')
                ->execute([$albumId, $artistId, $title, $track, $disc,
                    $s['duration'], $s['bitrate'], $s['size'], $s['path'], $s['mtime']]);
            $this->added++;
        }
        $seen[$s['path']] = true;
    }

    /** @param array<string, true> $seen */
    public function prune(array $seen): void
    {
        $db = App::pdo();
        $st = $db->query('SELECT id, path FROM songs');
        $toDelete = [];
        while (($row = $st->fetch()) !== false) {
            $path = $this->text($row['path'] ?? null);
            if ($path === '' || !isset($seen[$path]) || !file_exists($path)) {
                $toDelete[] = $this->integer($row['id'] ?? null);
            }
        }
        $del = $db->prepare('DELETE FROM songs WHERE id = ?');
        foreach ($toDelete as $id) {
            $del->execute([$id]);
        }
        $db->exec('DELETE FROM albums WHERE id NOT IN (SELECT DISTINCT album_id FROM songs)');
        $db->exec('DELETE FROM artists WHERE id NOT IN (SELECT DISTINCT artist_id FROM songs)');
        $db->exec('DELETE FROM favorites WHERE song_id NOT IN (SELECT id FROM songs)');
        if ($toDelete) {
            printf("Pruning : %d pistes supprimées\n", count($toDelete));
        }
    }

    private static function artPath(string $dir, ArtExtractor $extractor): ?string
    {
        return ArtExtractor::findArt($dir) ?: $extractor->extractEmbeddedArt($dir);
    }

    private function albumHasArt(int $albumId): bool
    {
        $st = App::pdo()->prepare('SELECT art_path IS NULL FROM albums WHERE id = ?');
        $st->execute([$albumId]);
        $isNull = $st->fetchColumn();
        return !$isNull;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function integer(mixed $value): int
    {
        return is_int($value) || is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : $this->integer($value);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || is_numeric($value) ? (float) $value : null;
    }
}
