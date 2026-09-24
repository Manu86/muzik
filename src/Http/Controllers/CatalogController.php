<?php

declare(strict_types=1);

/**
 * Navigation et lecture du catalogue.
 */
final class CatalogController
{
    public static function summary(): void
    {
        App::json(Catalogue::summary());
    }

    public static function artists(): void
    {
        if (isset($_GET['letter'])) {
            App::json(Catalogue::artists(Request::get('letter')));
        }
        App::json([
            'letters' => Catalogue::artistLetters(),
            'total' => Catalogue::artistCount(),
        ]);
    }

    public static function albums(): void
    {
        $page = max(1, Request::getInt('page', 1));
        $limit = min(240, max(1, Request::getInt('limit', 240)));
        $where = '';
        $params = [];
        if (isset($_GET['artist_id'])) {
            $where = 'WHERE al.artist_id = ?';
            $params[] = Request::getInt('artist_id');
        }
        if (isset($_GET['letter'])) {
            $where .= ($where ? ' AND ' : 'WHERE ') . 'UPPER(SUBSTR(a.name,1,1)) = ?';
            $params[] = Request::get('letter');
        }
        App::json(Catalogue::albumPage($where, $params, $page, $limit));
    }

    public static function artist(string $id): void
    {
        $db = App::pdo();
        $st = $db->prepare('SELECT * FROM artists WHERE id = ?');
        $st->execute([$id]);
        $artist = $st->fetch();
        if (!$artist) {
            App::err('Artist not found', 404);
        }
        $artist['albums'] = Catalogue::albumsOfArtist((int) $id);
        App::json($artist);
    }

    public static function album(string $id): void
    {
        $album = Catalogue::albumDetail((int) $id);
        if ($album === null) {
            App::err('Album not found', 404);
        }
        App::json($album);
    }

    public static function albumUpdate(string $id): void
    {
        $db = App::pdo();
        $st = $db->prepare('SELECT al.* FROM albums al WHERE al.id = ?');
        $st->execute([$id]);
        $album = $st->fetch();
        if (!$album) {
            App::err('Album not found', 404);
        }

        $input = Request::bodyOrQuery();
        $name = $input['name'] ?? $_GET['name'] ?? null;
        $year = array_key_exists('year', $input) ? $input['year'] : ($_GET['year'] ?? null);

        $changes = [];
        $newName = null;
        $newYear = null;

        if (is_string($name)) {
            $newName = trim(preg_replace('/\s+/', ' ', $name) ?? '');
            if ($newName === '' || mb_strlen($newName) > 200) {
                App::err('Le nom de l\'album doit contenir entre 1 et 200 caractères', 400);
            }
            $changes['name'] = $newName;
        }
        if ($year !== null) {
            $year = trim(Request::stringValue($year));
            if ($year === '') {
                $newYear = null;
                $changes['year'] = null;
            } elseif (!preg_match('/^\d{1,4}$/', $year)) {
                App::err('L\'année doit être un nombre à 4 chiffres', 400);
            } else {
                $y = (int) $year;
                if ($y < 1 || $y > (int) date('Y')) {
                    App::err('L\'année doit être comprise entre 1 et ' . date('Y'), 400);
                }
                $newYear = $y;
                $changes['year'] = $y;
            }
        }
        if (!$changes) {
            App::err('Aucune modification fournie (name ou year attendus)', 400);
        }

        $albumId = Request::integerValue($album['id']);
        $artistId = Request::integerValue($album['artist_id']);

        if (array_key_exists('name', $changes) && $newName !== $album['name']) {
            $st = $db->prepare('SELECT id FROM albums WHERE artist_id = ? AND name = ? AND id != ?');
            $st->execute([$artistId, $newName, $albumId]);
            if ($st->fetchColumn() !== false) {
                App::err('Un album portant ce nom existe déjà pour cet artiste', 409);
            }
        }

        $set = [];
        $params = [];
        foreach ($changes as $col => $value) {
            $set[] = "$col = ?";
            $params[] = $value;
        }
        $params[] = $albumId;
        $db->prepare('UPDATE albums SET ' . implode(', ', $set) . ' WHERE id = ?')
            ->execute($params);

        $name = $newName ?? $album['name'];
        $dbYear = $album['year'] !== null ? Request::integerValue($album['year']) : null;
        $year = array_key_exists('year', $changes) ? $newYear : $dbYear;

        App::json(['ok' => true, 'id' => $albumId, 'name' => $name, 'year' => $year]);
    }

    public static function albumDelete(string $id): void
    {
        $db = App::pdo();
        $st = $db->prepare('SELECT al.*, a.name AS artist_name
                            FROM albums al JOIN artists a ON a.id = al.artist_id
                            WHERE al.id = ?');
        $st->execute([$id]);
        $album = $st->fetch();
        if (!$album) {
            App::err('Album not found', 404);
        }

        $musicRoot = App::musicRoot() . DIRECTORY_SEPARATOR;

        $st = $db->prepare('SELECT path FROM songs WHERE album_id = ?');
        $st->execute([$id]);
        $paths = $st->fetchColumnValues();

        $deletedFiles = 0;
        foreach ($paths as $p) {
            if (is_string($p) && str_starts_with($p, $musicRoot) && is_file($p) && @unlink($p)) {
                $deletedFiles++;
            }
        }
        $cover = $album['art_path'] ?? null;
        if (is_string($cover) && str_starts_with($cover, $musicRoot) && is_file($cover)) {
            @unlink($cover);
        }

        $db->prepare('DELETE FROM albums WHERE id = ?')->execute([$id]);

        $st = $db->prepare('SELECT COUNT(*) FROM albums WHERE artist_id = ?');
        $st->execute([$album['artist_id']]);
        if ((int) $st->fetchColumn() === 0) {
            $st = $db->prepare('SELECT art_path FROM artists WHERE id = ?');
            $st->execute([$album['artist_id']]);
            $artistArt = $st->fetchColumn();
            $db->prepare('DELETE FROM artists WHERE id = ?')->execute([$album['artist_id']]);
            if (is_string($artistArt) && str_starts_with($artistArt, $musicRoot) && is_file($artistArt)) {
                @unlink($artistArt);
            }
        }

        App::json(['ok' => true, 'songs' => count($paths), 'files_deleted' => $deletedFiles]);
    }

    public static function song(string $id): void
    {
        $song = Catalogue::songDetail((int) $id);
        if ($song === null) {
            App::err('Song not found', 404);
        }
        App::json($song);
    }

    public static function search(): void
    {
        $q = Request::get('q');
        if (mb_strlen($q) < 2) {
            App::err('Query too short');
        }
        App::json(Catalogue::search($q));
    }

    public static function random(): void
    {
        $n = min(200, max(1, Request::getInt('n', 100)));
        $off = max(0, Request::getInt('offset'));
        App::json(Catalogue::random($n, $off));
    }

    public static function genre(): void
    {
        $name = Request::get('name');
        if ($name === '') {
            App::err('Genre name required');
        }
        $albums = Catalogue::albumsFiltered('WHERE al.genre = ?', [$name]);
        $totalSongs = 0;
        foreach ($albums as $a) {
            if (is_array($a)) {
                $totalSongs += Request::integerValue($a['song_count'] ?? null);
            }
        }
        App::json(['genre' => $name, 'albums' => $albums, 'total_albums' => count($albums), 'total_songs' => $totalSongs]);
    }

    public static function genres(): void
    {
        App::json(Genres::summary());
    }

    public static function home(): void
    {
        $db = App::pdo();

        $genres = Genres::summary();
        $artists = Catalogue::artistsRandom(8);
        $albums = Catalogue::albumsRandom(8);

        $recent = Statistics::recent(10);

        $poche = $db->query('SELECT s.id, s.title, s.duration,
                                 a.name AS artist_name,
                                 al.name AS album_name, al.id AS album_id
                          FROM favorites f
                          JOIN songs s ON s.id = f.song_id
                          JOIN artists a ON a.id = s.artist_id
                          JOIN albums al ON al.id = s.album_id
                          ORDER BY f.created_at DESC LIMIT 8')->fetchAll();

        $covers = $db->query('SELECT al.id, al.name, a.name AS artist_name
                              FROM albums al
                              JOIN artists a ON a.id = al.artist_id
                              JOIN songs s ON s.album_id = al.id
                              WHERE s.last_played IS NOT NULL
                              GROUP BY al.id
                              ORDER BY MAX(s.last_played) DESC
                              LIMIT 12')->fetchAll();

        App::json(['genres' => $genres, 'artists' => $artists, 'albums' => $albums, 'recent' => $recent, 'poche' => $poche, 'covers' => $covers]);
    }
}
