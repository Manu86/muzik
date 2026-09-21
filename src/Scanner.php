<?php

/**
 * @phpstan-type SongData array{path: string, size: int, mtime: int, title: string, track: ?int,
 *     disc: ?int, year?: ?int, duration: ?float, bitrate: ?int, artist: string, album: string,
 *     cached?: bool}
 * @phpstan-type CachedSong array{title: string, track: ?int, disc: ?int, duration: ?float,
 *     bitrate: ?int, artist_name: string, album_name: string}
 */
final class Scanner
{
    private getID3 $getId3;
    /** @var list<string> */
    private array $exts = ['mp3', 'flac', 'ogg', 'm4a', 'wav'];
    /** @var list<string> */
    private array $artPatterns = ['cover.jpg', 'cover.png', 'cover.jpeg', 'folder.jpg',
        'folder.png', 'front.jpg', 'front.png',
        'Cover.jpg', 'Folder.jpg', 'cover.JPG'];
    private int $added = 0;
    private int $updated = 0;

    public function __construct()
    {
        $this->resetGetId3();
    }

    /**
     * Lance bin/scan.php en arrière-plan et retourne immédiatement.
     *
     * Évite de bloquer le serveur web pendant l'indexation (un serveur de
     * développement ou un hébergement partagé peut être mono-processus). Le
     * processus est détaché : il continue après la réponse HTTP et écrit ses
     * journaux dans data/scan-install.log.
     *
     * Retourne false si aucun processus n'a pu être détaché — l'appelant doit
     * alors scanner de façon synchrone.
     */
    public static function startBackgroundScan(string $projectRoot): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $script = $projectRoot . '/bin/scan.php';
        if (!is_file($script)) {
            return false;
        }

        $log = $projectRoot . '/data/scan-install.log';
        @mkdir($projectRoot . '/data', 0777, true);

        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
            $phpBinary = defined('PHP_BINDIR') ? PHP_BINDIR . '/php' : 'php';
        } else {
            $phpBinary = PHP_BINARY;
        }

        $process = @proc_open(
            [$phpBinary, $script],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ],
            $pipes,
            $projectRoot,
        );

        if (!is_resource($process)) {
            return false;
        }

        // Ne pas appeler proc_close() : il attendrait la fin du scan. Le
        // processus devient orphelin et se termine tout seul.
        return true;
    }

    public function run(bool $full = false): void
    {
        $root = App::musicRoot();
        if (!is_dir($root)) {
            fwrite(STDERR, "MUSIC_ROOT invalide : $root\n");
            exit(1);
        }

        $seen = [];
        $start = microtime(true);

        foreach (new DirectoryIterator($root) as $dir) {
            if ($dir->isDot() || !$dir->isDir()) {
                continue;
            }
            $dirName = $dir->getFilename();
            if ($dirName === '_Playlists') {
                continue;
            }
            $this->scanTree($dir->getPathname(), $seen);
        }

        if ($full) {
            $this->prune($seen);
        }

        printf(
            "Scan terminé : %d ajoutées, %d mises à jour en %.1fs\n",
            $this->added,
            $this->updated,
            microtime(true) - $start
        );
    }

    /**
     * Parcours récursif. Les fichiers placés directement sous un dossier
     * racine (« artiste ») utilisent des heuristiques de dossier ; les fichiers
     * plus profonds (compilations, genres, multi-CD) s'appuient sur les tags ID3.
     */
    /** @param array<string, true> $seen */
    private function scanTree(string $path, array &$seen, int $depth = 0): void
    {
        $flatFiles = [];
        $subdirs = [];

        foreach (new DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isDir()) {
                $subdirs[] = $entry->getPathname();
                continue;
            }
            if ($entry->isFile() && $this->isAudio($entry->getFilename())) {
                $flatFiles[] = $entry->getPathname();
            }
        }

        foreach ($subdirs as $sub) {
            $this->scanTree($sub, $seen, $depth + 1);
        }

        if (!$flatFiles) {
            return;
        }

        if ($depth === 0) {
            $this->groupFlatArtist($path, $flatFiles, $seen);
        } else {
            foreach ($flatFiles as $file) {
                $this->indexLeafFile($file, $seen);
            }
        }
    }

    /**
     * Dossier racine contenant directement des pistes (ex: « 666 », « Dominique A »).
     * @param list<string> $files
     * @param array<string, true> $seen
     */
    private function groupFlatArtist(string $artistPath, array $files, array &$seen): void
    {
        $artistName = basename($artistPath);
        $withTrack = 0;
        foreach ($files as $f) {
            if ($this->parseTrack(basename($f)) !== null) {
                $withTrack++;
            }
        }
        $albumName = count($files) >= 2 ? $artistName : 'Sans album';
        $albumArt = $this->findArt($artistPath);
        $artistId = null;
        $albumId = null;
        $albumYear = null;

        foreach ($files as $file) {
            $info = $this->collect($file);
            $artistName2 = $info['artist'] ?: $artistName;
            $albumName2 = $info['album'] ?: $albumName;
            if ($artistId === null) {
                $artistId = $this->ensureArtist($artistName2, $artistPath, $artistPath);
            }
            if ($albumId === null) {
                $albumId = $this->ensureAlbum(
                    $artistId,
                    $albumName2,
                    $info['year'] ?? null,
                    $artistPath,
                    $albumArt,
                    $info['genre'] ?? ''
                );
            }
            $this->upsertSong($info, $artistId, $albumId, $seen);
        }
    }

    /**
     * Fichier dans un sous-dossier quelconque : tags ID3 prioritaires.
     * @param array<string, true> $seen
     */
    private function indexLeafFile(string $file, array &$seen): void
    {
        $info = $this->collect($file);
        $container = dirname($file);
        $root = App::musicRoot();

        // Album « logique » : on remonte au-delà des marqueurs de disque.
        [$albumBase, $discDir] = $this->albumBase($container, $root);
        $albumName = $info['album'] ?: basename($albumBase);
        $albumName = $albumName !== '' ? $albumName : 'Sans album';

        $artistName = $info['artist'];
        if ($artistName === '') {
            $parentOfBase = dirname($albumBase);
            $artistName = basename($parentOfBase);
            if ($artistName === '' || $artistName === basename($root)) {
                $artistName = basename($albumBase);
            }
        }

        $artistId = $this->ensureArtist($artistName, $albumBase, $container);
        $art = $this->findArt($albumBase) ?: $this->findArt(dirname($albumBase)) ?: $this->findArt($container);
        $albumId = $this->ensureAlbum($artistId, $albumName, $info['year'] ?? null, $albumBase, $art, $info['genre'] ?? '');
        $this->upsertSong($info, $artistId, $albumId, $seen);
    }

    /** @return array{string, ?string} */
    private function albumBase(string $container, string $root): array
    {
        $base = $container;
        $discDir = null;
        while (true) {
            $name = basename($base);
            if (preg_match('/^(disque|disc|cd|dvd|volume|disco|part)[.\s_-]*\d*/i', $name)) {
                $discDir = $name;
                $parent = dirname($base);
                if ($parent === $root || basename($parent) === basename($root) || dirname($parent) === $root) {
                    break;
                }
                $base = $parent;
                continue;
            }
            break;
        }
        return [$base, $discDir];
    }

    /** @return SongData */
    private function collect(string $file): array
    {
        $mtime = (int) filemtime($file);
        $size = (int) filesize($file);
        $cache = $this->cachedRow($file, $mtime, $size);
        if ($cache) {
            return [
                'path' => $file,
                'size' => $size,
                'mtime' => $mtime,
                'title' => $cache['title'],
                'track' => $cache['track'] !== null ? (int) $cache['track'] : null,
                'disc' => $cache['disc'] !== null ? (int) $cache['disc'] : null,
                'duration' => $cache['duration'],
                'bitrate' => $cache['bitrate'],
                'artist' => $cache['artist_name'] ?? '',
                'album' => $cache['album_name'] ?? '',
                'cached' => true,
            ];
        }

        $info = [
            'path' => $file,
            'size' => $size,
            'mtime' => $mtime,
            'title' => null,
            'track' => null,
            'disc' => null,
            'year' => null,
            'duration' => null,
            'bitrate' => null,
            'artist' => '',
            'album' => '',
            'genre' => '',
        ];

        try {
            $id3 = $this->getId3->analyze($file);
            if (is_array($id3)) {
                $tagGroups = $id3['tags'] ?? null;
                $tags = is_array($tagGroups)
                    ? ($tagGroups['id3v2'] ?? $tagGroups['id3v1'] ?? null)
                    : null;
                if (is_array($tags)) {
                    $info['album'] = $this->tagValue($this->firstTag($tags, 'album'), 'album');
                    $info['artist'] = $this->tagValue($this->firstTag($tags, 'artist'), 'artist');
                    $info['title'] = $this->tagValue($this->firstTag($tags, 'title'), 'title');
                    $info['genre'] = App::normalizeGenre($this->firstTag($tags, 'genre')) ?? '';
                    if ($info['title'] === '') {
                        $info['title'] = null;
                    }
                    $track = $this->firstTag($tags, 'track_number');
                    $disc = $this->firstTag($tags, 'disc_number') ?: $this->firstTag($tags, 'part_of_a_set');
                    $year = $this->firstTag($tags, 'year') ?: $this->firstTag($tags, 'recording_time');
                    $info['track'] = $track !== '' ? $this->firstNumber($track) : null;
                    $info['disc'] = $disc !== '' ? $this->firstNumber($disc) : null;
                    $info['year'] = $year !== '' ? $this->firstNumber($year) : null;
                }
                $duration = $id3['playtime_seconds'] ?? null;
                $info['duration'] = is_int($duration) || is_float($duration) ? (float) $duration : null;
                $audio = $id3['audio'] ?? null;
                $bitrate = is_array($audio) ? ($audio['bitrate'] ?? null) : null;
                $info['bitrate'] = is_int($bitrate) || is_float($bitrate)
                    ? (int) round($bitrate / 1000)
                    : null;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "getID3 échec sur $file : {$e->getMessage()}\n");
        }
        $this->resetGetId3();

        if (!$info['title']) {
            $info['title'] = $this->titleFromFilename(basename($file));
        }
        if ($info['track'] === null) {
            $info['track'] = $this->parseTrack(basename($file));
        }
        if ($info['disc'] === null) {
            $discDir = $this->discDir(dirname($file));
            $info['disc'] = $discDir ?: 1;
        }

        return $info;
    }

    /** Conserve un tag sauf s'il s'agit d'un libellé générique/pollué. */
    private function tagValue(string $v, string $kind): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        $junk = match ($kind) {
            'artist' => '/^(?:no artist|unknown(?: artist)?|artist[ée] inconnu|inconnu(?:e)?|nouvel artiste|none|artiste)$/i',
            'album'  => '/(?:^(?:no album|no title|sans album|sans titre|unknown|inconnu|nouveau titre|untitled|none|g[eé]n[eé]rique)$|^https?:\/\/|^www\.)/i',
            'title'  => '/^(?:audio ?track|track|piste|unknown|no title|nouveau titre|untitled|sans titre|none)[\s\d]*$/i',
            default  => null,
        };
        if ($junk !== null && preg_match($junk, $v) === 1) {
            return '';
        }
        return $v;
    }

    /** @param array<mixed> $tags */
    private function firstTag(array $tags, string $key): string
    {
        $values = $tags[$key] ?? null;
        $value = is_array($values) ? ($values[0] ?? null) : null;

        return is_string($value) ? $value : '';
    }

    private function discDir(string $container): ?int
    {
        $name = basename($container);
        if (preg_match('/^(disque|disc|cd|dvd|volume|disco|part)[.\s_-]*(\d*)/i', $name, $m)) {
            return $m[2] !== '' ? (int) $m[2] : 1;
        }
        return null;
    }

    private function firstNumber(string $s): int
    {
        if (preg_match('/^(\d+)/', trim($s), $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /** @return CachedSong|null */
    private function cachedRow(string $file, int $mtime, int $size): ?array
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

    private function ensureArtist(string $name, string $path, string $artSearch): int
    {
        $name = $this->clean($name);
        if ($name === '') {
            $name = basename($artSearch);
        }
        $st = App::pdo()->prepare('SELECT id FROM artists WHERE name = ? COLLATE NOCASE');
        $st->execute([$name]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            $art = $this->findArt($artSearch);
            if ($art) {
                App::pdo()->prepare('UPDATE artists SET art_path = COALESCE(art_path, ?) WHERE id = ?')
                    ->execute([$art, $id]);
            }
            return (int) $id;
        }
        $art = $this->findArt($artSearch);
        $ins = App::pdo()->prepare('INSERT INTO artists(name, path, art_path) VALUES(?,?,?)');
        $ins->execute([$name, $path, $art]);
        return (int) App::pdo()->lastInsertId();
    }

    private function ensureAlbum(
        int $artistId,
        string $name,
        ?int $year,
        string $path,
        ?string $art,
        ?string $genre = null
    ): int {
        $name = $this->clean($name);
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

    private function albumHasArt(int $albumId): bool
    {
        $st = App::pdo()->prepare('SELECT art_path IS NULL FROM albums WHERE id = ?');
        $st->execute([$albumId]);
        $isNull = $st->fetchColumn();
        return !$isNull;
    }

    /**
     * @param SongData $s
     * @param array<string, true> $seen
     */
    private function upsertSong(array $s, int $artistId, int $albumId, array &$seen): void
    {
        $seen[$s['path']] = true;

        if (!empty($s['cached'])) {
            return;
        }

        $title = $this->clean($s['title'] ?? '');
        if ($title === '') {
            $title = $this->titleFromFilename(basename($s['path']));
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
    private function prune(array $seen): void
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

    private function findArt(string $dir): ?string
    {
        foreach ($this->artPatterns as $p) {
            $full = $dir . '/' . $p;
            if (is_file($full)) {
                return $full;
            }
        }
        return null;
    }

    private function isAudio(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $this->exts, true);
    }

    private function parseTrack(string $name): ?int
    {
        if (preg_match('/^(?:INCOMPLETE~|track\s*|piste\s*)?(\d{1,2})\s*[.\-_)]\s*/i', $name, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function titleFromFilename(string $name): string
    {
        $name = trim(rawurldecode($name));
        foreach (['/\.(mp3|flac|ogg|m4a|wav)$/i', '/^(?:INCOMPLETE~)?\d{1,2}\s*[.\-_)]?\s*/i',
            '/\s*\(0h\d+\)\s*$/i', '/\s*\{[^{}]*\}\s*$/', '/\s*\@[a-z]\d+\s*$/i',
            '/\s*%\d+%\s*$/', '/\s*#\w#\s*$/i', '/\s*\[[\'"a-z0-9]{1,6}\]\s*$/i',
            '/\s*\[[a-z][ \'"]?\w{0,6}\]\s*$/i'] as $pattern) {
            $name = preg_replace($pattern, '', $name) ?? $name;
        }
        $name = trim($name, " \t-_.");
        if (preg_match('/^(.+?)\s[-\x{2013}]\s(.+)$/u', $name, $m)) {
            $name = $m[2];
        }
        return $name ?: 'Piste inconnue';
    }

    private function clean(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
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

    private function resetGetId3(): void
    {
        $this->getId3 = new getID3();
        $this->getId3->encoding = 'UTF-8';
        $this->getId3->option_tag_id3v1 = true;
        $this->getId3->option_tag_id3v2 = true;
        $this->getId3->option_extra_info = true;
    }
}
