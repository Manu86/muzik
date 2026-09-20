<?php

/**
 * Récupère les jaquettes manquantes, sources dans l'ordre :
 *   1. Deezer          (gratuit, sans clé, très bon pour la musique FR/intl)
 *   2. MusicBrainz + Cover Art Archive (gratuit, sans clé, 1 req/s obligatoire)
 *   3. iTunes Search   (dernier recours)
 * Usage : php bin/fetch-art.php [--limit N] [--album ID] [--by-song]
 */

if (defined('MUZIK_INCLUDE_ONLY')) {
    /* include-able : on ne veut que les fonctions de matching (tag.php) */
} else {
    require __DIR__ . '/../src/DB.php';
    require __DIR__ . '/../src/App.php';
    App::init(require __DIR__ . '/../config.php');
    $pdo = App::pdo();

    ini_set('default_socket_timeout', '10');

    $limit = null;
    if (($i = array_search('--limit', $argv, true)) !== false) {
        $limit = (int) ($argv[$i + 1] ?? 0);
    }

    $genre = null;
    if (($i = array_search('--genre', $argv, true)) !== false) {
        $genre = (string) ($argv[$i + 1] ?? null);
    }

    $albumId = null;
    if (($i = array_search('--album', $argv, true)) !== false) {
        $albumId = (int) ($argv[$i + 1] ?? 0);
    }

    $bySong = in_array('--by-song', $argv, true);

    $log = fopen(__DIR__ . '/../data/art-fetch.log', 'ab');
}

function logLine(string $msg): void
{
    global $log;
    fwrite($log, date('H:i:s') . ' ' . $msg . "\n");
}

function httpGet(string $url, string $ua = 'MuzikArtBot/1.0'): ?string
{
    $cmd = 'curl -s -m 12 --connect-timeout 8 -A ' . escapeshellarg($ua) . ' -- ' . escapeshellarg($url) . ' 2>/dev/null';
    $data = @shell_exec($cmd);
    return ($data === false || $data === null) ? null : $data;
}

function norm(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim($s);
}

/** Norm + suppression de l'article initial (The X, Les X, La X, L'X …) */
function normArt(string $s): string
{
    $s = norm($s);
    $s = preg_replace('/^(?:the|les|la|le|las|los|lo|un|une|des)\s+/', '', $s);
    return trim($s);
}

/** Nettoie un nom d'album dérivé du dossier : retire {p …}, @c6, (0h17), [..],
 *  les indications d'interprètes (d …), solistes (s …), ensembles (I Musici),
 *  numéros de catalogue (RV217, BWV1068, D894), "w. Various" …
 */
function cleanAlbumName(string $name): string
{
    $name = preg_replace('/\s*\{[^{}]*\}\s*/u', ' ', $name);
    $name = preg_replace('/\s*\@[a-z]\d+\s*/iu', ' ', $name);
    $name = preg_replace('/\s*\(0h\d+\)\s*/iu', ' ', $name);
    $name = preg_replace('/\s*\[[a-z0-9][^\[\]]*\]\s*/iu', ' ', $name);
    $name = preg_replace('/\s*\(\s*(?:d|s|w\.?|avec|dir\.|cond\.|producer)\b\s*[^)]*\)/iu', ' ', $name);
    $name = preg_replace('/\s*\(\s*I\s+[A-ZÀ-Þ][^)]{0,24}\)\s*/u', ' ', $name);
    $name = preg_replace('/\b(?:RV|BWV|K\.?|D\.?|Wq\.?|Op\.?\s*\d+)\s*\d*\s*[a-z]?\b/iu', ' ', $name);
    $name = preg_replace('/^w\.?\s*various\s*[-–—:]/iu', '', $name);
    $name = preg_replace('/\s*[-–—]\s*/u', ' - ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name) ?: $name;
}

function cleanArtistName(string $name): string
{
    $name = cleanAlbumName($name);
    $name = preg_replace('/\s*\([^)]*\)\s*$/u', '', $name);
    $name = preg_replace('/[\s\-_.,:;]+$/u', '', $name);
    return trim($name);
}

function scoreMatch(string $na, string $nal, string $ra, string $rl, bool $isSelfTitled, bool $isSanAlbum, ?int $year = null, ?int $ry = null): float
{
    if ($ra === $na) {
        $artistScore = 3;
    } elseif ($ra !== '' && (stripos($na, $ra) !== false || stripos($ra, $na) !== false)) {
        $artistScore = 1.5;
    } else {
        return 0.0; // sans correspondance d'artiste, le résultat est un faux positif
    }
    if ($isSelfTitled && $rl !== '' && $rl === $na) {
        $score = $artistScore + 6;
    } elseif ($isSanAlbum) {
        $score = $artistScore + 4.5;
    } elseif ($nal !== '' && $rl !== '') {
        if ($rl === $nal) {
            $score = $artistScore + 3;
        } elseif (stripos($rl, $nal) !== false || stripos($nal, $rl) !== false) {
            $score = $artistScore + 1.5;
        } else {
            $score = $artistScore;
        }
    } else {
        $score = $artistScore;
    }
    if ($year && $ry && $year === $ry) {
        $score += 0.5;
    }
    return $score;
}

function saveCover(string $dir, string $url): string|false
{
    $data = httpGet($url, 'MuzikArtBot/1.0');
    if ($data === null || !isset($data[0]) || !isset($data[1])) {
        return false;
    }
    $jpeg = bin2hex($data[0]) === 'ff' && bin2hex($data[1]) === 'd8';
    $png  = bin2hex($data[0]) === '89' && bin2hex($data[1]) === '50';
    if (!$jpeg && !$png) {
        return false;
    }
    $ext = $png ? 'png' : 'jpg';
    $out = $dir . '/cover.' . $ext;
    if (@file_put_contents($out, $data) === false) {
        return false;
    }
    if ($png) { // nettoie l'ancienne cover.jpg si on passe en png
        @unlink($dir . '/cover.jpg');
    }
    return $out;
}

function saveCoverRaw(string $dir, string $data): string|false
{
    if (!isset($data[0]) || !isset($data[1])) {
        return false;
    }
    $jpeg = bin2hex($data[0]) === 'ff' && bin2hex($data[1]) === 'd8';
    $png  = bin2hex($data[0]) === '89' && bin2hex($data[1]) === '50';
    if (!$jpeg && !$png) {
        return false;
    }
    $ext = $png ? 'png' : 'jpg';
    $out = $dir . '/cover.' . $ext;
    if (@file_put_contents($out, $data) === false) {
        return false;
    }
    if ($png) {
        @unlink($dir . '/cover.jpg');
    }
    return $out;
}

function deezerFind(string $term, string $na, string $nal, bool $isSelfTitled, bool $isSanAlbum, ?int $year): ?string
{
    $j = httpGet('https://api.deezer.com/search/album?q=' . rawurlencode($term) . '&limit=40');
    if ($j === null) {
        return null;
    }
    $d = json_decode($j, true);
    if (!isset($d['data']) || !is_array($d['data'])) {
        return null;
    }
    $best = null;
    $bestScore = 0;
    $naA = normArt($na);
    foreach ($d['data'] as $r) {
        $ra = norm(cleanArtistName($r['artist']['name'] ?? ''));
        $rl = norm($r['title'] ?? '');
        if ($ra === '' || $rl === '') {
            continue;
        }
        $ry = isset($r['release_date']) && strlen($r['release_date']) >= 4
            ? (int) substr($r['release_date'], 0, 4) : null;
        $score = scoreMatch($naA, $nal, normArt($ra), $rl, $isSelfTitled, $isSanAlbum, $year, $ry);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }
    if ($best === null || $bestScore < 4) {
        return $bestScore >= 3 && $best !== null ? '__weak__' : null;
    }
    return $best['cover_xl'] ?: ($best['cover_big'] ?? null);
}

function mbFind(string $term, string $na, string $nal, bool $isSelfTitled, bool $isSanAlbum, ?int $year): array
{
    // 1) search release   (1 req/s obligatoire côté MusicBrainz)
    $url = 'https://musicbrainz.org/ws/2/release?query='
         . rawurlencode('release:"' . $nal . '" AND artist:"' . $na . '"')
         . '&fmt=json&limit=10';
    $j = httpGet($url);
    sleep(1);
    if ($j === null) {
        return [null, '__skip__'];
    }
    $d = json_decode($j, true);
    if (!isset($d['releases']) || !is_array($d['releases'])) {
        return [null, null];
    }
    $best = null;
    $bestScore = 0;
    foreach ($d['releases'] as $r) {
        $ra = '';
        foreach ($r['artist-credit'] ?? [] as $ac) {
            $ra .= $ac['name'] ?? '';
        }
        $ra = norm(cleanArtistName($ra));
        $rl = norm($r['title'] ?? '');
        if ($ra === '' || $rl === '') {
            continue;
        }
        $ry = isset($r['date']) && strlen($r['date']) >= 4 ? (int) substr($r['date'], 0, 4) : null;
        $score = scoreMatch($na, $nal, $ra, $rl, $isSelfTitled, $isSanAlbum, $year, $ry);
        if ($score > $bestScore) {
            $bestScore = $score;
            $mbid = $r['id'] ?? '';
            $best = ['mbid' => $mbid];
        }
    }
    if ($best === null || $bestScore < 4) {
        return [null, null];
    }
    // 2) Cover Art Archive
    $cover = httpGet('https://coverartarchive.org/release/' . $best['mbid'] . '/front-500');
    usleep(300000);
    if ($cover !== null && (bin2hex($cover[0]) === 'ff' || bin2hex($cover[0]) === '89')) {
        return [$cover, null]; // image brute déjà téléchargée
    }
    return [null, null];
}

function itunesFind(string $term, string $na, string $nal, bool $isSelfTitled, bool $isSanAlbum, ?int $year): ?string
{
    $url = 'https://itunes.apple.com/search?term=' . rawurlencode($term)
         . '&country=FR&media=music&entity=album&limit=12';
    $j = httpGet($url);
    if ($j === null) {
        return null;
    }
    $d = json_decode($j, true);
    $best = null;
    $bestScore = 0;
    foreach ($d['results'] ?? [] as $r) {
        if (empty($r['artworkUrl100']) || empty($r['collectionName'])) {
            continue;
        }
        $ra = norm($r['artistName'] ?? '');
        $rl = norm($r['collectionName']);
        if ($ra === '') {
            continue;
        }
        $ry = isset($r['releaseDate']) && strlen($r['releaseDate']) >= 4
            ? (int) substr($r['releaseDate'], 0, 4) : null;
        $score = scoreMatch($na, $nal, $ra, $rl, $isSelfTitled, $isSanAlbum, $year, $ry);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }
    if ($best === null || $bestScore < 4) {
        return null;
    }
    return str_replace('100x100', '600x600', $best['artworkUrl100']);
}

/**
 * Repli musique classique : l'artiste côté services = pianiste/orchestre, alors
 * que notre artiste est le compositeur. On cherche donc directement par artiste
 * iTunes (attribute=artistTerm) et on accepte l'album du compositeur, en
 * privilégiant le titre correspondant à notre dossier.
 */
function composerFind(string $composer, string $nal, ?int $year): ?string
{
    $last = (string) preg_replace('/^.*\s/', '', trim($composer));
    $terms = array_values(array_unique(array_filter([trim($composer), $last], fn($t) => $t !== '')));
    $best = null;
    $bestScore = -1.0;
    foreach ($terms as $t) {
        $url = 'https://itunes.apple.com/search?term=' . rawurlencode($t)
             . '&country=FR&media=music&entity=album&attribute=artistTerm&limit=25';
        $j = httpGet($url);
        if ($j === null) {
            continue;
        }
        foreach (json_decode($j, true)['results'] ?? [] as $r) {
            if (empty($r['artistName']) || empty($r['collectionName']) || empty($r['artworkUrl100'])) {
                continue;
            }
            $ra = norm($r['artistName']);
            $rl = norm($r['collectionName']);
            $tn = norm($t);
            if (stripos($ra, $tn) === false && stripos($rl, $tn) === false) {
                continue;
            }
            $ry = isset($r['releaseDate']) && strlen($r['releaseDate']) >= 4
                ? (int) substr($r['releaseDate'], 0, 4) : null;
            $score = 1.0;
            if ($nal !== '' && $rl !== '') {
                if ($rl === $nal || strpos($rl, $nal) !== false || strpos($nal, $rl) !== false) {
                    $score += 3.0;
                }
            }
            if ($year && $ry && $year === $ry) {
                $score += 0.5;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }
    }
    if ($best === null) {
        return null;
    }
    return str_replace('100x100', '600x600', $best['artworkUrl100']);
}

function deezerArtistFind(string $artistTerm, string $na, string $nal, bool $isSelfTitled, bool $isSanAlbum): ?string
{
    $j = httpGet('https://api.deezer.com/search/album?q=' . rawurlencode($artistTerm) . '&limit=25');
    if ($j === null) {
        return null;
    }
    $d = json_decode($j, true);
    if (!isset($d['data']) || !is_array($d['data'])) {
        return null;
    }
    $best = null;
    $bestScore = 0;
    $naA = normArt($na);
    foreach ($d['data'] as $r) {
        $ra = norm(cleanArtistName($r['artist']['name'] ?? ''));
        $rl = norm($r['title'] ?? '');
        if ($ra === '' || $rl === '') {
            continue;
        }
        if ($ra !== $na && stripos($na, $ra) === false && stripos($ra, $na) === false) {
            continue;
        }
        if ($isSanAlbum) {
            $score = (normArt($ra) === $naA) ? 3.0 : 1.5; // na donné, album quelconque du bon artiste
        } else {
            $score = scoreMatch($naA, $nal, normArt($ra), $rl, $isSelfTitled, false, null, null);
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }
    $threshold = $isSanAlbum ? 1.5 : 4;
    if ($best === null || $bestScore < $threshold) {
        return null;
    }
    return $best['cover_xl'] ?: ($best['cover_big'] ?? null);
}

/** Nom du genre Deezer à partir de son id (api/deezer.com/genre/{id}), cache mémoire */
function deezerGenreLookup(int $genreId): ?string
{
    static $cache = [];
    if ($genreId <= 0) {
        return null;
    }
    if (array_key_exists($genreId, $cache)) {
        return $cache[$genreId] ?: null;
    }
    $j = httpGet('https://api.deezer.com/genre/' . $genreId);
    $d = json_decode($j ?? '', true);
    $name = isset($d['name']) && is_string($d['name']) ? trim($d['name']) : null;
    $cache[$genreId] = $name;
    return $name;
}

/** Genres via l'objet album Deezer (genres.data[].name) — marche même si genre_id=0 */
function deezerAlbumGenres(int $albumId): ?string
{
    if ($albumId <= 0) {
        return null;
    }
    $j = httpGet('https://api.deezer.com/album/' . $albumId);
    $d = json_decode($j ?? '', true);
    $names = [];
    foreach ($d['genres']['data'] ?? [] as $g) {
        if (!empty($g['name'])) {
            $names[] = trim($g['name']);
        }
    }
    if (!$names) {
        $name = deezerGenreLookup((int) ($d['genre_id'] ?? 0));
        if ($name) {
            $names[] = $name;
        }
    }
    return $names ? implode(' / ', array_unique($names)) : null;
}

/** Genre d'un album via la recherche Deezer (même scoring que deezerFind) */
function deezerGenres(string $term, string $na, string $nal, bool $isSelfTitled, bool $isSanAlbum, ?int $year): ?string
{
    $j = httpGet('https://api.deezer.com/search/album?q=' . rawurlencode($term) . '&limit=40');
    if ($j === null) {
        return null;
    }
    $d = json_decode($j, true);
    if (!isset($d['data']) || !is_array($d['data'])) {
        return null;
    }
    $best = null;
    $bestScore = 0;
    $naA = normArt($na);
    foreach ($d['data'] as $r) {
        $ra = norm(cleanArtistName($r['artist']['name'] ?? ''));
        $rl = norm($r['title'] ?? '');
        if ($ra === '' || $rl === '') {
            continue;
        }
        $ry = isset($r['release_date']) && strlen($r['release_date']) >= 4
            ? (int) substr($r['release_date'], 0, 4) : null;
        $score = scoreMatch($naA, $nal, normArt($ra), $rl, $isSelfTitled, $isSanAlbum, $year, $ry);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }
    if ($best === null || $bestScore < 4) {
        return null;
    }
    return deezerGenreLookup((int) ($best['genre_id'] ?? 0)) ?: deezerAlbumGenres((int) ($best['id'] ?? 0));
}

/** Genre via recherche Deezer par artiste seul (repli) */
function deezerArtistGenres(string $artistTerm, string $na, string $nal, bool $isSelfTitled, bool $isSanAlbum): ?string
{
    $j = httpGet('https://api.deezer.com/search/album?q=' . rawurlencode($artistTerm) . '&limit=25');
    if ($j === null) {
        return null;
    }
    $d = json_decode($j, true);
    if (!isset($d['data']) || !is_array($d['data'])) {
        return null;
    }
    $best = null;
    $bestScore = 0;
    $naA = normArt($na);
    foreach ($d['data'] as $r) {
        $ra = norm(cleanArtistName($r['artist']['name'] ?? ''));
        $rl = norm($r['title'] ?? '');
        if ($ra === '' || $rl === '') {
            continue;
        }
        if ($ra !== $na && stripos($na, $ra) === false && stripos($ra, $na) === false) {
            continue;
        }
        $score = $isSanAlbum
            ? ((normArt($ra) === $naA) ? 3.0 : 1.5)
            : scoreMatch($naA, $nal, normArt($ra), $rl, $isSelfTitled, false, null, null);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }
    $threshold = 1.5; // genre = info cosmétique : le bon artiste suffit
    if ($best === null || $bestScore < $threshold) {
        return null;
    }
    return deezerGenreLookup((int) ($best['genre_id'] ?? 0)) ?: deezerAlbumGenres((int) ($best['id'] ?? 0));
}

/** Genre album via iTunes (primaryGenreName) */
function itunesGenre(string $term, string $na, string $nal, bool $isSelfTitled, bool $isSanAlbum, ?int $year): ?string
{
    $url = 'https://itunes.apple.com/search?term=' . rawurlencode($term)
         . '&country=FR&media=music&entity=album&limit=12';
    $j = httpGet($url);
    if ($j === null) {
        return null;
    }
    $d = json_decode($j, true);
    $best = null;
    $bestScore = 0;
    foreach ($d['results'] ?? [] as $r) {
        if (empty($r['collectionName']) || empty($r['primaryGenreName'])) {
            continue;
        }
        $ra = norm($r['artistName'] ?? '');
        $rl = norm($r['collectionName']);
        if ($ra === '') {
            continue;
        }
        $ry = isset($r['releaseDate']) && strlen($r['releaseDate']) >= 4
            ? (int) substr($r['releaseDate'], 0, 4) : null;
        $score = scoreMatch($na, $nal, $ra, $rl, $isSelfTitled, $isSanAlbum, $year, $ry);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }
    if ($best === null || $bestScore < 4) {
        return null;
    }
    return $best['primaryGenreName'];
}

/** Normalise les noms de genre issus des services pour une catégorie unique. */
function normalizeGenre(?string $genre): ?string
{
    if ($genre === null || $genre === '') {
        return $genre;
    }
    static $aliases = [
        'hip-hop/rap' => 'Rap/Hip Hop',
        'hip hop/rap' => 'Rap/Hip Hop',
        'hip-hop & rap' => 'Rap/Hip Hop',
        'rap & hip-hop' => 'Rap/Hip Hop',
        'rap & hip hop' => 'Rap/Hip Hop',
        'hip hop' => 'Rap/Hip Hop',
        'variété française' => 'Chanson française',
        'variété francaise' => 'Chanson française',
        'variete francaise' => 'Chanson française',
        'raíces' => 'Latino',
        'musique brésilienne' => 'Latino',
        'metal' => 'Rock',
        'métal' => 'Rock',
        'paroles/interprétation' => 'Chanson française',
        'musique classique' => 'Classique',
    ];
    $key = strtolower(trim(preg_replace('/\s+/', ' ', $genre)));
    return $aliases[$key] ?? trim($genre);
}

/**
 * Cherche la jaquette via le titre d'une piste (Deezer track search).
 * Retourne l'URL de la cover_xl de l'album associé, ou null.
 */
function songFind(int $albumId, string $artist): ?string
{
    global $pdo;

    $titles = $pdo->prepare('SELECT title FROM songs WHERE album_id = ? AND title IS NOT NULL AND title != \'\' ORDER BY track, disc LIMIT 3');
    $titles->execute([$albumId]);
    $titles = $titles->fetchAll(\PDO::FETCH_COLUMN);

    if (empty($titles)) {
        return null;
    }

    $na = norm($artist);

    foreach ($titles as $title) {
        $clean = trim(preg_replace('/\s*\(.*?\)\s*/u', ' ', $title));
        if ($clean === '') {
            continue;
        }
        $j = httpGet('https://api.deezer.com/search/track?q=' . rawurlencode($clean) . '&limit=10');
        if ($j === null) {
            usleep(200000);
            continue;
        }
        $d = json_decode($j, true);
        if (!isset($d['data']) || !is_array($d['data'])) {
            usleep(200000);
            continue;
        }
        foreach ($d['data'] as $r) {
            $ra = norm(cleanArtistName($r['artist']['name'] ?? ''));
            $rl = norm($r['title'] ?? '');
            $rlShort = norm($r['title_short'] ?? '');
            if ($rl === '' || ($rl !== norm($clean) && $rlShort !== norm($clean))) {
                continue;
            }
            $cover = $r['album']['cover_xl'] ?? ($r['album']['cover_big'] ?? null);
            if ($cover !== null) {
                logLine("  songFind: \"{$title}\" → {$r['artist']['name']} / {$r['album']['title']}");
                return $cover;
            }
        }
        usleep(200000);
    }

    return null;
}

if (!defined('MUZIK_INCLUDE_ONLY')) {
    $sql = 'SELECT a.id, a.artist_id, a.name AS album, a.year, a.genre, ar.name AS artist, al2.path
                    FROM albums a JOIN artists ar ON ar.id = a.artist_id
                    LEFT JOIN (SELECT album_id, MIN(path) AS path FROM songs GROUP BY album_id) al2
                           ON al2.album_id = a.id
                    WHERE a.art_path IS NULL';
    if ($genre !== null) {
        $sql .= ' AND a.genre = ' . $pdo->quote($genre);
    }
    if ($albumId !== null) {
        $sql .= ' AND a.id = ' . $albumId;
    }
    $sql .= ' ORDER BY a.id';
    $albums = $pdo->query($sql)->fetchAll();
    $total = count($albums);
    echo "Albums sans jaquette : $total\n";
    if ($limit !== null) {
        $albums = array_slice($albums, 0, $limit);
        $total = count($albums);
        echo "--limit : on n'en traite que $total\n";
    }

    $done = 0;
    $ok = 0;
    $skip = 0;
    $sources = ['deezer' => 0, 'mb' => 0, 'itunes' => 0];

    foreach ($albums as $al) {
        $done++;
        $artist = cleanArtistName($al['artist']);
        $searchName = cleanAlbumName($al['album']);
        $year = $al['year'] ? (int) $al['year'] : null;

        $na = norm($artist);
        $nal = norm($searchName);
        $isSanAlbum = $al['album'] === 'Sans album';
        $isSelfTitled = !$isSanAlbum && $nal !== '' && $nal === $na;

        $term = $isSanAlbum ? $artist : trim($searchName . ' ' . $artist);
        if ($term === '' || $na === '') {
            logLine("[$done/$total] SKIP terme vide : {$al['artist']} / {$al['album']}");
            $skip++;
            continue;
        }

        $dir = $al['path'] ? dirname($al['path']) : null;
        if (!$dir || !is_dir($dir)) {
            logLine("[$done/$total] SKIP dossier absent : $dir");
            $skip++;
            continue;
        }
        if (@file_exists($dir . '/cover.jpg') || @file_exists($dir . '/cover.png')) {
            // déjà couvert : on enregistre seulement art_path (reprise)
            $cover = @file_exists($dir . '/cover.jpg') ? $dir . '/cover.jpg' : $dir . '/cover.png';
            $pdo->prepare('UPDATE albums SET art_path = ? WHERE id = ?')->execute([$cover, $al['id']]);
            echo "[$done/$total] déjà couvert, art_path enregistré ({$al['artist']} / {$al['album']})\n";
            $skip++;
            continue;
        }

        // 1) Deezer (full term)
        $cover = deezerFind($term, $na, $nal, $isSelfTitled, $isSanAlbum, $year);
        if ($cover === '__weak__') {
            $cover = null;
        }
        $src = $cover ? 'deezer' : null;
        if (!$cover && $na !== '') {
            // 1b) Deezer (recherche par artiste seul)
            usleep(200000);
            $cover = deezerArtistFind($artist, $na, $nal, $isSelfTitled, $isSanAlbum);
            if ($cover) {
                $src = 'deezer';
            }
        }
        if (!$cover) {
            // 2) MusicBrainz (+ CAA)
            usleep(300000);
            [$cover] = mbFind($term, $na, $nal, $isSelfTitled, $isSanAlbum, $year);
            if ($cover !== null) {
                $src = 'mb';
            }
        }
        if (!$cover) {
            // 3) iTunes
            usleep(300000);
            $cover = itunesFind($term, $na, $nal, $isSelfTitled, $isSanAlbum, $year);
            if ($cover) {
                $src = 'itunes';
            }
        }
        if (!$cover && $al['genre'] === 'Classique' && $artist !== '') {
            // 4) Musique classique : l'artiste du service = l'orchestre/le
            //    pianiste ; on cherche la jaquette par compositeur (iTunes)
            usleep(200000);
            $cover = composerFind($artist, $nal, $year);
            if ($cover) {
                $src = 'itunes';
            }
        }
        if (!$cover && $bySong) {
            // 5) Recherche par titre de piste (Deezer track search)
            usleep(200000);
            $cover = songFind((int) $al['id'], $al['artist']);
            if ($cover) {
                $src = 'deezer';
            }
        }

        if (!$cover) {
            logLine("[$done/$total] pas de correspondance : {$al['artist']} / {$al['album']}");
            $skip++;
            usleep(200000);
            continue;
        }

        $okFlag = $src === 'mb' ? saveCoverRaw($dir, $cover) : saveCover($dir, $cover);
        if ($okFlag) {
            $ok++;
            $sources[$src]++;
            $pdo->prepare('UPDATE albums SET art_path = ? WHERE id = ?')->execute([$okFlag, $al['id']]);
            echo "[$done/$total] COVER($src)→ {$al['artist']} / {$al['album']}\n";
            logLine("[$done/$total] OK($src) : " . $al['artist'] . ' / ' . $al['album']);
        } else {
            echo "[$done/$total] DOWNLOAD manqué : " . $al['artist'] . ' / ' . $al['album'] . "\n";
            logLine("[$done/$total] DOWNLOAD manqué : " . $al['artist'] . ' / ' . $al['album']);
        }
        usleep(200000);
    }

    fclose($log);
    echo "\nTerminé : $ok jaquettes téléchargées (deezer {$sources['deezer']} / mb {$sources['mb']} / itunes {$sources['itunes']}), $skip sans fichier.\n";
}
