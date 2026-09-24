<?php

declare(strict_types=1);

/**
 * Statistiques d'écoute : pistes et albums les plus joués, historique récent.
 */
final class Statistics
{
    /**
     * @return array{songs: list<array<string, mixed>>, albums: list<array<string, mixed>>, total_plays: int}
     */
    public static function top(): array
    {
        $db = App::pdo();
        $songs = $db->prepare('SELECT s.id, s.title, s.duration, s.play_count, s.last_played,
                                      a.name AS artist_name,
                                      al.name AS album_name, al.id AS album_id
                               FROM songs s
                               JOIN artists a ON a.id = s.artist_id
                               JOIN albums al ON al.id = s.album_id
                               WHERE s.play_count > 0
                               ORDER BY s.play_count DESC, s.last_played DESC
                               LIMIT 50');
        $songs->execute();
        $albums = $db->query('SELECT al.id, al.name, al.year, al.art_path,
                                     a.name AS artist_name, SUM(s.play_count) AS plays,
                                     COUNT(s.id) AS song_count
                              FROM albums al
                              JOIN artists a ON a.id = al.artist_id
                              JOIN songs s ON s.album_id = al.id
                              WHERE s.play_count > 0
                              GROUP BY al.id
                              ORDER BY plays DESC
                              LIMIT 25')->fetchAll();
        $total = (int) $db->query('SELECT COALESCE(SUM(play_count),0) FROM songs')->fetchColumn();

        return ['songs' => $songs->fetchAll(), 'albums' => $albums, 'total_plays' => $total];
    }

    /**
     * Pistes écoutées le plus récemment.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 60): array
    {
        $st = App::pdo()->prepare('SELECT s.id, s.title, s.last_played,
                                          a.name AS artist_name,
                                          al.name AS album_name, al.id AS album_id
                                   FROM songs s
                                   JOIN artists a ON a.id = s.artist_id
                                   JOIN albums al ON al.id = s.album_id
                                   WHERE s.last_played IS NOT NULL
                                   ORDER BY s.last_played DESC
                                   LIMIT ?');
        $st->execute([$limit]);

        return $st->fetchAll();
    }
}
