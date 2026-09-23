<?php

/**
 * Retrouve le nom d'album des pistes « Sans album » (Deezer → iTunes),
 * met à jour la base et, en mode --apply, écrit le tag album (via tag_apply.py).
 *
 * Usage :
 *   php bin/album-resolve.php            # dry-run (cache et plan mis à jour, fichiers et base intacts)
 *   php bin/album-resolve.php --apply    # écrit les tags + met à jour la base
 *   php bin/album-resolve.php --limit N  # ne traite que N albums (test)
 *   php bin/album-resolve.php --force    # re-tente les échecs en cache
 */

if (!defined('MUZIK_INCLUDE_ONLY')) {
    define('MUZIK_INCLUDE_ONLY', true);
}

require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/fetch-art.php';
require_once __DIR__ . '/lib/bootstrap.php';

if (defined('MUZIK_ALBUM_RESOLVE_INCLUDE_ONLY')) {
    return;
}

App::initConfig(muzik_cli_config($argv));
$pdo = App::pdo();

ini_set('default_socket_timeout', '10');

$apply = in_array('--apply', $argv, true);
$force = in_array('--force', $argv, true);
$limit = null;
if (($i = array_search('--limit', $argv, true)) !== false) {
    $limit = (int) ($argv[$i + 1] ?? 0);
}

$log = fopen(__DIR__ . '/../data/album-resolve.log', 'ab');
function resolveLog(string $msg): void
{
    global $log;
    fwrite($log, date('H:i:s') . ' ' . $msg . "\n");
}

/** Score d'une piste (artiste + titre) : exact fort, sous-chaîne faible. */
function trackScore(string $na, string $nt, string $ra, string $rt, string $albumTitle = ''): float
{
    $score = 0.0;
    if ($ra === $na) {
        $score += 3;
    } elseif ($ra !== '' && $na !== '' && (stripos($na, $ra) !== false || stripos($ra, $na) !== false)) {
        $score += 1.5;
    }
    if ($rt === $nt) {
        $score += 3;
    } elseif ($rt !== '' && $nt !== '' && (stripos($nt, $rt) !== false || stripos($rt, $nt) !== false)) {
        $score += 1.5;
    } elseif ($nt !== '' && $rt !== '') {
        $a = array_values(array_filter(explode(' ', $nt), static fn($w) => mb_strlen($w) >= 4));
        $b = array_values(array_filter(explode(' ', $rt), static fn($w) => mb_strlen($w) >= 4));
        if ($a && $b && count(array_intersect($a, $b)) / max(count($a), count($b)) >= 0.6) {
            $score += 2;
        } else {
            $L = max(mb_strlen($nt), mb_strlen($rt));
            if ($L >= 8 && (1 - (levenshtein($nt, $rt) / $L)) >= 0.75) {
                $score += 2;
            }
        }
    }
    if ($albumTitle !== '' && preg_match('/\b(live|best\s*(?:of)?|greatest|collection|compilation|anthology|soirée|maxi)\b/i', $albumTitle)) {
        $score -= 2;
    }
    if ($albumTitle !== '' && str_contains($albumTitle, ' / ')) {
        $score -= 0.5;
    }
    return $score;
}

/** Recherche Deezer par piste ; renvoie l'album (id + titre) si correspondance. */
function deezerTrackFind(string $q, string $na, string $nt): ?array
{
    $j = httpGet('https://api.deezer.com/search/track?q=' . rawurlencode($q) . '&limit=15');
    if ($j === null) {
        return null;
    }
    $d = json_decode($j, true);
    if (!isset($d['data']) || !is_array($d['data'])) {
        return null;
    }
    $best = null;
    $bestScore = 0;
    foreach ($d['data'] as $r) {
        $ra = norm(cleanArtistName($r['artist']['name'] ?? ''));
        $rt = norm($r['title_short'] ?? $r['title'] ?? '');
        if ($ra === '' || $rt === '') {
            continue;
        }
        $score = trackScore($na, $nt, $ra, $rt, $r['album']['title'] ?? '');
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }
    if ($best === null || $bestScore < 4) {
        return null;
    }
    return [
        'album_id' => (int) ($best['album']['id'] ?? 0),
        'album'    => $best['album']['title'] ?? '',
    ];
}

/** Recherche iTunes par piste ; renvoie album (collectionName) + année + genre. */
function itunesTrackFind(string $q, string $na, string $nt): ?array
{
    $url = 'https://itunes.apple.com/search?term=' . rawurlencode($q)
         . '&country=FR&media=music&entity=musicTrack&limit=12';
    $j = httpGet($url);
    if ($j === null) {
        return null;
    }
    $d = json_decode($j, true);
    $best = null;
    $bestScore = 0;
    foreach ($d['results'] ?? [] as $r) {
        if (empty($r['collectionName'])) {
            continue;
        }
        $ra = norm($r['artistName'] ?? '');
        $rt = norm($r['trackName'] ?? '');
        if ($ra === '' || $rt === '') {
            continue;
        }
        $score = trackScore($na, $nt, $ra, $rt, $best['collectionName'] ?? '');
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }
    if ($best === null || $bestScore < 4) {
        return null;
    }
    $ry = isset($best['releaseDate']) && strlen($best['releaseDate']) >= 4
        ? (int) substr($best['releaseDate'], 0, 4) : null;
    return [
        'album_id' => 0,
        'album'    => $best['collectionName'],
        'year'     => $ry,
        'genre'    => $best['primaryGenreName'] ?? null,
    ];
}

/** Album Deezer : année + genre (appel supplémentaire). */
function deezerAlbumObject(int $albumId): array
{
    if ($albumId <= 0) {
        return ['year' => null, 'genre' => null];
    }
    $j = httpGet('https://api.deezer.com/album/' . $albumId);
    $d = json_decode($j ?? '', true);
    $year = isset($d['release_date']) && strlen($d['release_date']) >= 4
        ? (int) substr($d['release_date'], 0, 4) : null;
    $genres = [];
    foreach ($d['genres']['data'] ?? [] as $g) {
        if (!empty($g['name'])) {
            $genres[] = trim($g['name']);
        }
    }
    return ['year' => $year, 'genre' => $genres ? implode(' / ', array_unique($genres)) : null];
}

/** Résout l'album d'une piste « Sans album ». */
function resolveTrack(array $row): ?array
{
    $artist = cleanArtistName($row['artist']);
    $title = trim($row['title'] ?? '');
    $na = norm($artist);
    $nt = norm($title);
    if ($na === '') {
        return null;
    }
    $q = trim($artist . ' ' . $title);

    usleep(150000);
    $hit = deezerTrackFind($q, $na, $nt);
    if ($hit && $hit['album'] !== '') {
        usleep(150000);
        $meta = deezerAlbumObject($hit['album_id']);
        return [
            'album'  => $hit['album'],
            'year'   => $meta['year'],
            'genre'  => $meta['genre'],
            'source' => 'deezer',
        ];
    }

    usleep(200000);
    $hit = itunesTrackFind($q, $na, $nt);
    if ($hit && $hit['album'] !== '') {
        return [
            'album'  => $hit['album'],
            'year'   => $hit['year'],
            'genre'  => $hit['genre'],
            'source' => 'itunes',
        ];
    }
    return null;
}

// --- cache ---------------------------------------------------------------
$cacheFile = __DIR__ . '/../data/albums-resolve.json';
$cache = [];
if (is_file($cacheFile)) {
    $cache = json_decode(file_get_contents($cacheFile), true) ?: [];
}

// --- pistes « Sans album » ------------------------------------------------
$rows = $pdo->query(
    "SELECT al.id AS album_id, al.artist_id, ar.name AS artist, al.year, al.genre,
            s.id AS song_id, s.title, s.track, s.disc, s.path
     FROM albums al
     JOIN artists ar ON ar.id = al.artist_id
     JOIN songs s ON s.album_id = al.id
     WHERE al.name = 'Sans album'
     ORDER BY al.id"
)->fetchAll(PDO::FETCH_ASSOC);

if ($limit !== null) {
    $rows = array_slice($rows, 0, $limit);
    echo "--limit : on ne traite que $limit pistes\n";
}
$total = count($rows);
echo "Pistes « Sans album » : $total\n";

$found = 0;
$skipped = 0;
$plan = [];

foreach ($rows as $row) {
    $aid = (int) $row['album_id'];
    if (!isset($plan[$aid])) {
        $plan[$aid] = [
            'artist_id' => (int) $row['artist_id'],
            'artist'    => cleanArtistName($row['artist']),
            'rows'      => [],
            'resolved'  => null,
            'attempted' => false,
        ];
    }
    $plan[$aid]['rows'][] = $row;

    if ($plan[$aid]['attempted']) {
        continue;
    }
    $plan[$aid]['attempted'] = true;

    $first = $plan[$aid]['rows'][0];
    $artist = $plan[$aid]['artist'];
    $currentAlbum = trim($first['album'] ?? '') ?: 'Sans album';

    if (!$force && array_key_exists($aid, $cache)) {
        $resolved = $cache[$aid] !== null ? $cache[$aid] : false;
    } else {
        $resolved = resolveTrack($first) ?? null;
        $cache[$aid] = $resolved;
    }
    $plan[$aid]['resolved'] = $resolved;

    if ($resolved === null || $resolved === false) {
        resolveLog("[$aid] sans album trouvé : $artist / $currentAlbum");
        $skipped++;
        echo "[$aid] AUCUNE — $artist / $currentAlbum\n";
        continue;
    }

    $found++;
    echo "[$aid] ALBUM({$resolved['source']}) $artist / $currentAlbum => {$resolved['album']}"
       . ($resolved['year'] ? " ({$resolved['year']})" : '')
       . ($resolved['genre'] ? " — {$resolved['genre']}" : '') . "\n";
}

file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "\nRésolution : $found albums trouvés / $total pistes « Sans album », $skipped sans résultat.\n";

if (!$apply) {
    echo "DRY-RUN — cache écrit ($cacheFile) ; aucun fichier audio ni base modifiés (lancez avec --apply pour écrire).\n";
    fclose($log);
    exit(0);
}

// --- application : base + tags ---------------------------------------------
echo "Application des résultats…\n";

$moved = 0;
$renamed = 0;
$planOut = [];

foreach ($plan as $aid => $p) {
    if ($p['resolved'] === null || $p['resolved'] === false) {
        continue;
    }
    $artist = $p['artist'];
    $albumName = trim(preg_replace('/\s+/', ' ', $p['resolved']['album']));
    $year = $p['resolved']['year'];
    $genre = normalizeGenre($p['resolved']['genre'] ?? null);
    if ($albumName === '') {
        continue;
    }

    // album cible existant (même artiste + même nom) ?
    $st = $pdo->prepare('SELECT id FROM albums WHERE artist_id = ? AND name = ? AND id != ?');
    $st->execute([$p['artist_id'], $albumName, $aid]);
    $targetId = $st->fetchColumn();

    if ($targetId !== false) {
        foreach ($p['rows'] as $row) {
            $pdo->prepare('UPDATE songs SET album_id = ? WHERE id = ?')
                ->execute([(int) $targetId, (int) $row['song_id']]);
            $moved++;
        }
        if ($year || $genre) {
            $pdo->prepare('UPDATE albums SET year = COALESCE(year, ?), genre = COALESCE(genre, ?) WHERE id = ?')
                ->execute([$year, $genre, (int) $targetId]);
        }
    } else {
        $pdo->prepare('UPDATE albums SET name = ?, year = COALESCE(year, ?), genre = COALESCE(genre, ?) WHERE id = ?')
            ->execute([$albumName, $year, $genre, $aid]);
        $renamed += count($p['rows']);
    }

    foreach ($p['rows'] as $row) {
        $planOut[] = [
            'path'   => $row['path'],
            'title'  => $row['title'],
            'artist' => $artist,
            'album'  => $albumName,
            'track'  => $row['track'],
            'disc'   => $row['disc'],
            'year'   => $year ?: ($row['year'] ?? null),
            'genre'  => $genre ?: ($row['genre'] ?? null),
        ];
    }
}

// nettoyage des albums laissés vides après déplacement
$pdo->exec('DELETE FROM albums WHERE id NOT IN (SELECT DISTINCT album_id FROM songs)');

$planFile = __DIR__ . '/../data/album-plan.json';
file_put_contents($planFile, json_encode($planOut, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
resolveLog('Base : ' . count($plan) . " traités ($moved déplacés, $renamed renommés).");

echo "Base : $moved déplacés, $renamed renommés.\n";
echo 'Plan : ' . count($planOut) . " fichiers -> $planFile\n";

if (!$planOut) {
    echo "Rien à écrire.\n";
    fclose($log);
    exit(0);
}

echo "Écriture des tags (album, année, genre)…\n";
$cmd = 'python3 ' . escapeshellarg(__DIR__ . '/tag_apply.py') . ' ' . escapeshellarg($planFile);
passthru($cmd, $code);

fclose($log);
if ($code !== 0) {
    fwrite(STDERR, "tag_apply.py a échoué (code $code).\n");
    exit(1);
}
echo "Terminé.\n";
