<?php

final class Api
{
    public static function summary(): void
    {
        $db = App::pdo();
        $artists = (int) $db->query('SELECT COUNT(*) FROM artists')->fetchColumn();
        $albums  = (int) $db->query('SELECT COUNT(*) FROM albums')->fetchColumn();
        $songs   = (int) $db->query('SELECT COUNT(*) FROM songs')->fetchColumn();
        $totalSize = (int) $db->query('SELECT COALESCE(SUM(size),0) FROM songs')->fetchColumn();
        $totalDuration = (float) $db->query('SELECT COALESCE(SUM(duration),0) FROM songs')->fetchColumn();
        App::json([
            'artists' => $artists,
            'albums'  => $albums,
            'songs'   => $songs,
            'size'    => $totalSize,
            'duration' => $totalDuration,
        ]);
    }

    public static function login(): void
    {
        if (!Auth::enabled()) {
            App::json(['ok' => true, 'user' => null]);
        }
        $input = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($input) ? $input : $_POST;
        $user = self::stringValue($body['user'] ?? '');
        $pass = self::stringValue($body['pass'] ?? '');
        $remember = filter_var(self::stringValue($body['remember'] ?? ''), FILTER_VALIDATE_BOOLEAN);
        if ($user === '' || $pass === '' || !Auth::attempt($user, $pass, $remember)) {
            App::err('Unauthorized', 401);
        }
        App::json(['ok' => true, 'user' => Auth::currentLogin()]);
    }

    public static function logout(): void
    {
        Auth::logout();
        App::json(['ok' => true]);
    }

    public static function auth(): void
    {
        App::json([
            'authenticated' => Auth::check(),
            'user' => Auth::currentLogin(),
        ]);
    }

    public static function ping(): void
    {
        App::json(['ok' => true]);
    }

    public static function artists(): void
    {
        if (isset($_GET['letter'])) {
            App::json(Catalogue::artists(self::stringValue($_GET['letter'])));
        }
        App::json([
            'letters' => Catalogue::artistLetters(),
            'total' => Catalogue::artistCount(),
        ]);
    }

    public static function albums(): void
    {
        $page  = max(1, self::integerValue($_GET['page'] ?? 1, 1));
        $limit = min(240, max(1, self::integerValue($_GET['limit'] ?? 240, 240)));
        $where = '';
        $params = [];
        if (isset($_GET['artist_id'])) {
            $where = 'WHERE al.artist_id = ?';
            $params[] = self::integerValue($_GET['artist_id']);
        }
        if (isset($_GET['letter'])) {
            $where .= ($where ? ' AND ' : 'WHERE ') . 'UPPER(SUBSTR(a.name,1,1)) = ?';
            $params[] = self::stringValue($_GET['letter']);
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
        $artist['albums'] = Catalogue::albumsOfArtist(self::integerValue($id));
        App::json($artist);
    }

    public static function album(string $id): void
    {
        $db = App::pdo();
        $st = $db->prepare('SELECT al.*, a.name AS artist_name, a.id AS artist_id
                             FROM albums al JOIN artists a ON a.id = al.artist_id
                             WHERE al.id = ?');
        $st->execute([$id]);
        $album = $st->fetch();
        if (!$album) {
            App::err('Album not found', 404);
        }
        $st = $db->prepare('SELECT id, title, track, disc, duration, bitrate, size, path
                             FROM songs WHERE album_id = ?
                             ORDER BY disc, track');
        $st->execute([$id]);
        $album['songs'] = $st->fetchAll();
        $st = $db->prepare('SELECT MIN(path) FROM songs WHERE album_id = ?');
        $st->execute([$id]);
        $first = $st->fetchColumn();
        $album['path'] = is_string($first)
            ? substr(dirname($first), strlen(App::musicRoot()))
            : null;
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

        $body = file_get_contents('php://input');
        $query = self::stringValue($_SERVER['QUERY_STRING'] ?? '');
        $input = [];
        parse_str(is_string($body) && $body !== '' ? $body : $query, $input);
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
            $year = trim(self::stringValue($year));
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

        $albumId = self::integerValue($album['id']);
        $artistId = self::integerValue($album['artist_id']);

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
        $dbYear = $album['year'] !== null ? self::integerValue($album['year']) : null;
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
        $paths = $st->fetchAll(PDO::FETCH_COLUMN);

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
        $db = App::pdo();
        $st = $db->prepare('SELECT s.*, a.name AS artist_name, a.id AS artist_id,
                             al.name AS album_name, al.id AS album_id, al.art_path
                             FROM songs s
                             JOIN artists a ON a.id = s.artist_id
                             JOIN albums al ON al.id = s.album_id
                             WHERE s.id = ?');
        $st->execute([$id]);
        $song = $st->fetch();
        if (!$song) {
            App::err('Song not found', 404);
        }
        App::json($song);
    }

    public static function search(): void
    {
        $q = self::stringValue($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) {
            App::err('Query too short');
        }
        $db = App::pdo();
        $like = '%' . $q . '%';
        $st = $db->prepare("SELECT s.id, s.title, 'song' AS type, a.name AS artist_name,
                                  al.name AS album_name
                            FROM songs s
                            JOIN artists a ON a.id = s.artist_id
                            JOIN albums al ON al.id = s.album_id
                            WHERE s.title LIKE ? OR a.name LIKE ? OR al.name LIKE ?
                            ORDER BY s.title LIMIT 30");
        $st->execute([$like, $like, $like]);
        $songs = $st->fetchAll();

        $st = $db->prepare("SELECT id, name, 'artist' AS type FROM artists
                            WHERE name LIKE ? ORDER BY name LIMIT 20");
        $st->execute([$like]);
        $artists = $st->fetchAll();

        $st = $db->prepare("SELECT id, name, 'album' AS type FROM albums
                            WHERE name LIKE ? ORDER BY name LIMIT 20");
        $st->execute([$like]);
        $albums = $st->fetchAll();

        App::json(['songs' => $songs, 'artists' => $artists, 'albums' => $albums]);
    }

    public static function random(): void
    {
        $n = min(200, max(1, self::integerValue($_GET['n'] ?? 100, 100)));
        $off = max(0, self::integerValue($_GET['offset'] ?? 0));
        $st = App::pdo()->prepare('SELECT s.id, s.title, s.duration, s.bitrate,
                                          a.name AS artist_name, a.id AS artist_id,
                                          al.name AS album_name, al.id AS album_id, al.art_path
                                   FROM songs s
                                   JOIN artists a ON a.id = s.artist_id
                                   JOIN albums al ON al.id = s.album_id
                                   ORDER BY RANDOM() LIMIT ? OFFSET ?');
        $st->execute([$n, $off]);
        App::json($st->fetchAll());
    }

    public static function play(string $id): void
    {
        App::pdo()->prepare('UPDATE songs SET play_count = play_count + 1, last_played = datetime(\'now\') WHERE id = ?')
            ->execute([(int) $id]);
        App::json(['ok' => true]);
    }

    public static function top(): void
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
        App::json(['songs' => $songs->fetchAll(), 'albums' => $albums, 'total_plays' => $total]);
    }

    public static function recent(): void
    {
        $st = App::pdo()->prepare('SELECT s.id, s.title, s.last_played,
                                          a.name AS artist_name,
                                          al.name AS album_name, al.id AS album_id
                                   FROM songs s
                                   JOIN artists a ON a.id = s.artist_id
                                   JOIN albums al ON al.id = s.album_id
                                   WHERE s.last_played IS NOT NULL
                                   ORDER BY s.last_played DESC
                                   LIMIT 60');
        $st->execute();
        App::json($st->fetchAll());
    }

    public static function art(string $id): void
    {
        // Distinguer artiste et album : leurs identifiants n'appartiennent pas au même espace
        if (($_GET['type'] ?? '') === 'artist') {
            $path = Catalogue::artistArt(self::integerValue($id));
        } else {
            $path = Catalogue::albumArt(self::integerValue($id));
        }
        if (!is_string($path) || !file_exists($path)) {
            http_response_code(404);
            exit;
        }
        $mime = 'image/jpeg';
        if (str_ends_with(strtolower($path), '.png')) {
            $mime = 'image/png';
        }
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }
        readfile($path);
    }

    public static function stream(string $id): void
    {
        $db = App::pdo();
        $st = $db->prepare('SELECT path, size, bitrate FROM songs WHERE id = ?');
        $st->execute([$id]);
        $song = $st->fetch();
        if (!$song) {
            App::err('Not found', 404);
        }
        $file = self::stringValue($song['path'] ?? null);
        if (!file_exists($file)) {
            App::err('File missing', 404);
        }

        $transcode = self::integerValue($_GET['transcode'] ?? App::transcodeBitrate());
        $start = max(0.0, (float) self::stringValue($_GET['start'] ?? 0, '0'));
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $isMp3 = in_array($ext, ['mp3']);
        $needsTranscode = $transcode > 0 && function_exists('proc_open');

        header('Accept-Ranges: bytes');
        header('Connection: close');

        if ($needsTranscode && !$isMp3) {
            Streamer::transcode($file, $transcode, $start);
        } else {
            Streamer::direct($file);
        }
    }

    public static function favorites(): void
    {
        $db = App::pdo();
        $action = $_GET['action'] ?? null;
        $songId = $_GET['id'] ?? null;

        if ($action === 'add' && $songId) {
            $db->prepare('INSERT OR IGNORE INTO favorites(song_id) VALUES(?)')
               ->execute([$songId]);
        } elseif ($action === 'remove' && $songId) {
            $db->prepare('DELETE FROM favorites WHERE song_id = ?')->execute([$songId]);
        } elseif ($action === 'check' && $songId) {
            $st = $db->prepare('SELECT 1 FROM favorites WHERE song_id = ?');
            $st->execute([$songId]);
            App::json(['favorited' => (bool) $st->fetch()]);
        }

        $st = $db->query('SELECT s.id, s.title, s.duration, s.path,
                          a.name AS artist_name, a.id AS artist_id,
                          al.name AS album_name, al.id AS album_id, al.art_path
                          FROM favorites f
                          JOIN songs s ON s.id = f.song_id
                          JOIN artists a ON a.id = s.artist_id
                          JOIN albums al ON al.id = s.album_id
                          ORDER BY f.created_at DESC');
        App::json($st->fetchAll());
    }

    public static function genres(): void
    {
        App::json(self::genreSummary());
    }

    /** @return list<array{genre: string, album_count: int, song_count: int}> */
    private static function genreSummary(): array
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

    public static function home(): void
    {
        $db = App::pdo();

        $genres = self::genreSummary();

        $artists = Catalogue::artistsRandom(8);

        $albums = Catalogue::albumsRandom(8);

        $recent = $db->query('SELECT s.id, s.title, s.last_played,
                                     a.name AS artist_name,
                                     al.name AS album_name, al.id AS album_id
                              FROM songs s
                              JOIN artists a ON a.id = s.artist_id
                              JOIN albums al ON al.id = s.album_id
                              WHERE s.last_played IS NOT NULL
                              ORDER BY s.last_played DESC LIMIT 10')->fetchAll();

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

    public static function genre(): void
    {
        $name = self::stringValue($_GET['name'] ?? '');
        if ($name === '') {
            App::err('Genre name required');
        }
        $albums = Catalogue::albumsFiltered('WHERE al.genre = ?', [$name]);
        $totalSongs = 0;
        foreach ($albums as $a) {
            if (is_array($a)) {
                $totalSongs += self::integerValue($a['song_count'] ?? null);
            }
        }
        App::json(['genre' => $name, 'albums' => $albums, 'total_albums' => count($albums), 'total_songs' => $totalSongs]);
    }

    public static function settings(): void
    {
        $db = App::pdo();
        $requestMethod = self::stringValue($_SERVER['REQUEST_METHOD'] ?? 'GET', 'GET');
        if ($requestMethod === 'PUT' || isset($_GET['set_key'])) {
            $body = file_get_contents('php://input');
            $query = self::stringValue($_SERVER['QUERY_STRING'] ?? '');
            parse_str(is_string($body) && $body !== '' ? $body : $query, $input);
            $key   = $input['key'] ?? $_GET['set_key'] ?? null;
            $value = $input['value'] ?? $_GET['value'] ?? null;
            if (is_string($key) && $key !== '' && (is_string($value) || $value === null)) {
                DB::setSetting($key, $value);
                App::json(['ok' => true]);
            }
        }
        $st = $db->query('SELECT key, value FROM settings');
        $settings = [];
        while (($row = $st->fetch()) !== false) {
            $key = $row['key'] ?? null;
            $value = $row['value'] ?? null;
            if (is_string($key) && (is_string($value) || $value === null)) {
                $settings[$key] = $value;
            }
        }
        $settings['music_root'] = App::musicRoot();
        $settings['user'] = Auth::currentLogin();
        $settings['auth_enabled'] = Auth::enabled();
        App::json($settings);
    }

    /**
     * Modifie l'emplacement de la bibliothèque musicale de l'utilisateur
     * connecté (colonne music_root de data/users.db).
     *
     * Valide le nouveau dossier, met à jour le compte, recharge la
     * configuration applicative puis relance l'indexation de cet utilisateur
     * en arrière-plan si aucune analyse n'est déjà en cours.
     *
     * @param string|null $projectRoot Racine du projet, redéfinissable en test.
     */
    public static function config(?string $projectRoot = null): void
    {
        $projectRoot ??= dirname(__DIR__);
        $login = Auth::currentLogin();
        if ($login === null) {
            App::err('Unauthorized', 401);
        }
        $body = file_get_contents('php://input');
        $input = is_string($body) && $body !== '' ? json_decode($body, true) : null;
        if (!is_array($input)) {
            $input = $_POST;
        }
        $musicRoot = trim(self::stringValue($input['music_root'] ?? ''));
        if ($musicRoot === '') {
            App::err('Indiquez le dossier contenant vos fichiers de musique.');
        }
        if (!is_dir($musicRoot) || !is_readable($musicRoot)) {
            App::err('Le dossier de musique doit exister et être lisible par le serveur.');
        }

        Users::updateMusicRoot($login, $musicRoot);
        App::initConfig(Users::resolveConfig($login, Users::baseConfig()));

        $scanStarted = false;
        if (DB::setting('scan_running') !== '1' && Scanner::startBackgroundScan($projectRoot, $login)) {
            DB::setSetting('scan_running', '1');
            DB::setSetting('scan_started_at', (string) time());
            $scanStarted = true;
        }

        App::json(['ok' => true, 'music_root' => App::musicRoot(), 'scan_started' => $scanStarted]);
    }

    public static function diag(): void
    {
        $body = file_get_contents('php://input');
        $input = is_string($body) && $body !== '' ? json_decode($body, true) : null;
        if (!is_array($input) && isset($_POST['log']) && is_string($_POST['log'])) {
            $input = json_decode($_POST['log'], true);
        }
        $log = is_array($input) ? ($input['log'] ?? null) : null;
        if (!is_array($log)) {
            App::err('Invalid diagnostic payload');
        }
        $safe = [];
        foreach (array_slice($log, 0, 500) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $safe[] = [
                't'   => self::stringValue($entry['t'] ?? ''),
                'e'   => self::stringValue($entry['e'] ?? ''),
                'd'   => self::stringValue($entry['d'] ?? ''),
                'h'   => self::integerValue($entry['h'] ?? 0),
                'ct'  => (float) self::integerValue($entry['ct'] ?? 0),
                'ns'  => self::integerValue($entry['ns'] ?? -1),
                'rs'  => self::integerValue($entry['rs'] ?? -1),
                'buf' => (float) self::integerValue($entry['buf'] ?? 0),
            ];
        }
        if ($safe === []) {
            App::err('Empty diagnostic payload');
        }
        $dbPath = App::config('db_path');
        if (!is_string($dbPath) || $dbPath === '') {
            App::err('Database path unavailable', 500);
        }
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            App::err('Diagnostic directory unavailable', 500);
        }
        $file = $dir . '/diag-' . date('Ymd-His') . '-' . substr((string) random_int(0, PHP_INT_MAX), 0, 6) . '.json';
        $payload = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $written = false;
        if (is_string($payload) && file_put_contents($file, $payload . PHP_EOL, LOCK_EX) !== false) {
            $written = true;
        }
        App::json(['ok' => true, 'written' => $written]);
    }

    /**
     * Relance une indexation complète de la bibliothèque en arrière-plan.
     *
     * Le mode complet supprime de la base les pistes absentes du disque
     * (dossiers renommés ou déplacés). Renvoie { "ok": false, "running": true }
     * si une analyse est déjà en cours. Le scan n'est jamais effectué dans la
     * requête HTTP : il est détaché via {@see Scanner::startBackgroundScan()}.
     *
     * @param string|null $projectRoot Racine du projet, redéfinissable en test.
     */
    public static function scan(?string $projectRoot = null): void
    {
        if (DB::setting('scan_running') === '1') {
            App::json(['ok' => false, 'running' => true]);
        }
        $login = Auth::currentLogin();
        if (!Scanner::startBackgroundScan($projectRoot ?? dirname(__DIR__), $login, true)) {
            App::err("Impossible de lancer le scan d'arrière-plan", 500);
        }
        DB::setSetting('scan_running', '1');
        DB::setSetting('scan_started_at', (string) time());
        App::json(['ok' => true]);
    }

    private static function stringValue(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private static function integerValue(mixed $value, int $default = 0): int
    {
        return is_int($value) || is_numeric($value) ? (int) $value : $default;
    }
}
