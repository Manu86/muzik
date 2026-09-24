<?php

declare(strict_types=1);

/**
 * Favoris : modifications et lecture de la liste complète.
 */
final class Favorites
{
    public static function add(string $songId): void
    {
        App::pdo()->prepare('INSERT OR IGNORE INTO favorites(song_id) VALUES(?)')
            ->execute([$songId]);
    }

    public static function remove(string $songId): void
    {
        App::pdo()->prepare('DELETE FROM favorites WHERE song_id = ?')->execute([$songId]);
    }

    public static function check(string $songId): bool
    {
        $st = App::pdo()->prepare('SELECT 1 FROM favorites WHERE song_id = ?');
        $st->execute([$songId]);

        return (bool) $st->fetch();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $st = App::pdo()->query('SELECT s.id, s.title, s.duration, s.path,
                          a.name AS artist_name, a.id AS artist_id,
                          al.name AS album_name, al.id AS album_id, al.art_path
                          FROM favorites f
                          JOIN songs s ON s.id = f.song_id
                          JOIN artists a ON a.id = s.artist_id
                          JOIN albums al ON al.id = s.album_id
                          ORDER BY f.created_at DESC');

        $rows = [];
        foreach ($st->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $values = [];
            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $values[$key] = $value;
                }
            }
            $rows[] = $values;
        }

        return $rows;
    }
}
