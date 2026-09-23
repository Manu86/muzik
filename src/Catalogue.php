<?php

declare(strict_types=1);

/**
 * Requêtes de lecture du catalogue partagées par les routes de l'API.
 */
final class Catalogue
{
    /** Projection d'un album avec artiste et nombre de pistes. */
    private const ALBUM_COLUMNS =
        'al.id, al.name, al.year, al.art_path, a.name AS artist_name, a.id AS artist_id, '
        . '(SELECT COUNT(*) FROM songs s2 WHERE s2.album_id = al.id) AS song_count';

    private const ALBUM_JOIN = 'FROM albums al JOIN artists a ON a.id = al.artist_id';

    /** @return list<array<string, mixed>> */
    public static function artistLetters(): array
    {
        return self::rows(App::pdo()->query(
            'SELECT UPPER(SUBSTR(a.name,1,1)) AS l, COUNT(*) AS c
              FROM artists a GROUP BY l ORDER BY l'
        ));
    }

    public static function artistCount(): int
    {
        return (int) App::pdo()->query('SELECT COUNT(*) FROM artists')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function artists(string $letter = ''): array
    {
        $db = App::pdo();
        if ($letter !== '') {
            $st = $db->prepare(
                'SELECT a.id, a.name, a.art_path, COUNT(s.id) AS song_count
                   FROM artists a LEFT JOIN songs s ON s.artist_id = a.id
                  WHERE UPPER(SUBSTR(a.name,1,1)) = ?
                  GROUP BY a.id ORDER BY a.name'
            );
            $st->execute([$letter]);

            return self::rows($st);
        }

        return self::rows($db->query(
            'SELECT a.id, a.name, a.art_path, COUNT(s.id) AS song_count
               FROM artists a LEFT JOIN songs s ON s.artist_id = a.id
              GROUP BY a.id ORDER BY a.name'
        ));
    }

    /** @return list<array<string, mixed>> */
    public static function artistsRandom(int $limit): array
    {
        return self::rows(App::pdo()->query(
            "SELECT a.id, a.name, COUNT(s.id) AS song_count
               FROM artists a LEFT JOIN songs s ON s.artist_id = a.id
              GROUP BY a.id ORDER BY RANDOM() LIMIT $limit"
        ));
    }

    /**
     * @param list<mixed> $params
     *
     * @return array{albums: list<array<string, mixed>>, total: int, page: int}
     */
    public static function albumPage(string $where, array $params, int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;
        $db = App::pdo();
        $st = $db->prepare(
            'SELECT ' . self::ALBUM_COLUMNS . ' ' . self::ALBUM_JOIN
            . " $where ORDER BY a.name, al.name LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        $total = $db->prepare('SELECT COUNT(*) ' . self::ALBUM_JOIN . " $where");
        $total->execute($params);

        return [
            'albums' => self::rows($st),
            'total' => (int) $total->fetchColumn(),
            'page' => $page,
        ];
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    public static function albumsFiltered(string $where, array $params, string $order = 'a.name, al.name'): array
    {
        return self::albumRows($where, $params, $order);
    }

    /** @return list<array<string, mixed>> */
    public static function albumsRandom(int $limit): array
    {
        return self::albumRows('', [], 'RANDOM()', $limit);
    }

    /** @return list<array<string, mixed>> */
    public static function albumsOfArtist(int $artistId): array
    {
        $st = App::pdo()->prepare(
            'SELECT id, name, year, art_path,
                    (SELECT COUNT(*) FROM songs s2 WHERE s2.album_id = al.id) AS song_count
               FROM albums al WHERE artist_id = ? ORDER BY al.name'
        );
        $st->execute([$artistId]);

        return self::rows($st);
    }

    public static function albumArt(int $id): ?string
    {
        $st = App::pdo()->prepare('SELECT art_path FROM albums WHERE id = ?');
        $st->execute([$id]);
        $path = $st->fetchColumn();

        return is_string($path) ? $path : null;
    }

    /**
     * Jaquette d'un artiste, avec repli sur l'album qui comporte le plus de pistes.
     */
    public static function artistArt(int $id): ?string
    {
        $db = App::pdo();
        $st = $db->prepare('SELECT art_path FROM artists WHERE id = ?');
        $st->execute([$id]);
        $path = $st->fetchColumn();
        if (is_string($path) && file_exists($path)) {
            return $path;
        }
        $st = $db->prepare(
            'SELECT al.art_path
               FROM albums al
              WHERE al.artist_id = ? AND al.art_path IS NOT NULL
              ORDER BY (SELECT COUNT(*) FROM songs s WHERE s.album_id = al.id) DESC,
                       al.year DESC, al.id'
        );
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $candidate) {
            if (is_string($candidate) && file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private static function albumRows(string $where, array $params, string $order, ?int $limit = null): array
    {
        $sql = 'SELECT ' . self::ALBUM_COLUMNS . ' ' . self::ALBUM_JOIN . " $where ORDER BY $order";
        if ($limit !== null) {
            $sql .= " LIMIT $limit";
        }
        $st = App::pdo()->prepare($sql);
        $st->execute($params);

        return self::rows($st);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(PDOStatement $statement): array
    {
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
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
