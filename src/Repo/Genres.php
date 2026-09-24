<?php

declare(strict_types=1);

/**
 * Genres : complément des genres canoniques par les albums et résumé par
 * nombre d'albums et de pistes.
 */
final class Genres
{
    /**
     * @return list<array{genre: string, album_count: int, song_count: int}>
     */
    public static function summary(): array
    {
        $db = App::pdo();
        $db->exec("INSERT OR IGNORE INTO genres(name)
                   SELECT DISTINCT genre FROM albums WHERE genre IS NOT NULL AND genre != ''");
        $st = $db->query('SELECT g.name AS genre,
                    (SELECT COUNT(*) FROM albums al WHERE al.genre = g.name) AS album_count,
                    (SELECT COUNT(*) FROM songs s2 JOIN albums al2 ON al2.id = s2.album_id
                     WHERE al2.genre = g.name) AS song_count
                    FROM genres g ORDER BY g.name');

        $genres = [];
        foreach ($st->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $genre = $row['genre'] ?? null;
            $albumCount = $row['album_count'] ?? null;
            $songCount = $row['song_count'] ?? null;
            $genres[] = [
                'genre' => is_string($genre) ? $genre : '',
                'album_count' => is_numeric($albumCount) ? (int) $albumCount : 0,
                'song_count' => is_numeric($songCount) ? (int) $songCount : 0,
            ];
        }

        return $genres;
    }
}
