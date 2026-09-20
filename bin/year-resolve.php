<?php

/**
 * Résout l'année de sortie album manquante (Deezer → MusicBrainz → iTunes,
 * repli classique par compositeur) et remplit albums.year.
 *
 * Usage :
 *   php bin/year-resolve.php           # dry-run (aucune écriture base)
 *   php bin/year-resolve.php --apply   # écrit albums.year
 *   php bin/year-resolve.php --limit N # ne traite que N albums
 */

define('MUZIK_INCLUDE_ONLY', true);

require __DIR__ . '/../src/DB.php';
require __DIR__ . '/../src/App.php';
require __DIR__ . '/fetch-art.php';

App::init(require __DIR__ . '/../config.php');
$pdo = App::pdo();

ini_set('default_socket_timeout', '10');

$apply = in_array('--apply', $argv, true);
$force = in_array('--force', $argv, true);
$limit = null;
if (($i = array_search('--limit', $argv, true)) !== false) {
    $limit = (int) ($argv[$i + 1] ?? 0);
}

$log = fopen(__DIR__ . '/../data/year-resolve.log', 'ab');
function yearLog(string $msg): void
{
    global $log;
    fwrite($log, date('H:i:s') . ' ' . $msg . "\n");
}

$cacheFile = __DIR__ . '/../data/years.json';
$cache = [];
if (is_file($cacheFile)) {
    $cache = json_decode(file_get_contents($cacheFile), true) ?: [];
}

$albums = $pdo->query(
    "SELECT a.id, a.artist_id, a.name AS album, a.year, a.genre, ar.name AS artist
     FROM albums a JOIN artists ar ON ar.id = a.artist_id
     WHERE a.year IS NULL OR a.year = ''
     ORDER BY a.id"
)->fetchAll(PDO::FETCH_ASSOC);

if ($limit !== null) {
    $albums = array_slice($albums, 0, $limit);
}
$total = count($albums);

$plan = [];
$resolved = 0;
$skipped = 0;

/** Année Deezer d'une recherche album ; seuil scoreMatch standard. */
function deezerYear(string $term, string $na, string $nal, bool $isSelfTitled, bool $isSanAlbum): ?int
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
        if ($isSanAlbum && $ra !== $na && stripos($na, $ra) === false && stripos($ra, $na) === false) {
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
    $threshold = $isSanAlbum ? 1.5 : 4;
    if ($best === null || $bestScore < $threshold) {
        return null;
    }
    $date = $best['release_date'] ?? '';
    return strlen($date) >= 4 ? (int) substr($date, 0, 4) : null;
}

/** Année MusicBrainz (1 requête/s) ; seuil scoreMatch standard. */
function mbYear(string $term, string $na, string $nal, bool $isSelfTitled, bool $isSanAlbum): ?int
{
    $url = 'https://musicbrainz.org/ws/2/release?query='
         . rawurlencode('release:"' . $nal . '" AND artist:"' . $na . '"')
         . '&fmt=json&limit=10';
    $j = httpGet($url);
    sleep(1);
    if ($j === null) {
        return null;
    }
    $d = json_decode($j, true);
    if (!isset($d['releases']) || !is_array($d['releases'])) {
        return null;
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
        $score = scoreMatch($na, $nal, $ra, $rl, $isSelfTitled, $isSanAlbum, null, null);
        if ($score > $bestScore) {
            $bestScore = $score;
            $date = $r['date'] ?? '';
            $best = strlen($date) >= 4 ? (int) substr($date, 0, 4) : null;
        }
    }
    if ($best === null || $bestScore < 4) {
        return null;
    }
    return $best;
}

/** Année iTunes ; accepte les albums sans jaquette (seul l'année sert). */
function itunesYear(string $term, string $na, string $nal, bool $isSelfTitled, bool $isSanAlbum): ?int
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
        if (empty($r['collectionName'])) {
            continue;
        }
        $ra = norm($r['artistName'] ?? '');
        $rl = norm($r['collectionName']);
        if ($ra === '') {
            continue;
        }
        if ($isSanAlbum && $ra !== $na && stripos($na, $ra) === false && stripos($ra, $na) === false) {
            continue;
        }
        $score = $isSanAlbum
            ? ((norm($ra) === $na) ? 3.0 : 1.5)
            : scoreMatch($na, $nal, $ra, $rl, $isSelfTitled, false, null, null);
        if ($score > $bestScore) {
            $bestScore = $score;
            $date = $r['releaseDate'] ?? '';
            $best = strlen($date) >= 4 ? (int) substr($date, 0, 4) : null;
        }
    }
    $threshold = $isSanAlbum ? 1.5 : 4;
    if ($best === null || $bestScore < $threshold) {
        return null;
    }
    return $best;
}

/** Année iTunes par compositeur (repli musique classique). */
function composerYear(string $composer, string $nal): ?int
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
            if (empty($r['artistName']) || empty($r['collectionName'])) {
                continue;
            }
            $ra = norm($r['artistName']);
            $rl = norm($r['collectionName']);
            $tn = norm($t);
            if (stripos($ra, $tn) === false && stripos($rl, $tn) === false) {
                continue;
            }
            $score = 1.0;
            if ($nal !== '' && $rl !== '') {
                if ($rl === $nal || strpos($rl, $nal) !== false || strpos($nal, $rl) !== false) {
                    $score += 3.0;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $date = $r['releaseDate'] ?? '';
                $best = strlen($date) >= 4 ? (int) substr($date, 0, 4) : null;
            }
        }
    }
    return $best;
}

foreach ($albums as $al) {
    $aid = (int) $al['id'];
    if (array_key_exists($aid, $cache) && ($cache[$aid] !== null || !$force)) {
        if ($cache[$aid] !== null) {
            $resolved++;
            $plan[$aid] = (int) $cache[$aid];
        } else {
            $skipped++;
        }
        continue;
    }

    $artist = cleanArtistName($al['artist']);
    $searchName = cleanAlbumName($al['album']);

    $na = norm($artist);
    $nal = norm($searchName);
    $isSanAlbum = $al['album'] === 'Sans album';
    $isSelfTitled = !$isSanAlbum && $nal !== '' && $nal === $na;

    $term = $isSanAlbum ? $artist : trim($searchName . ' ' . $artist);
    if ($term === '' || $na === '') {
        $cache[$aid] = null;
        yearLog("[$aid] SKIP terme vide : {$al['artist']} / {$al['album']}");
        $skipped++;
        continue;
    }
    if ($isSanAlbum) {
        // « Sans album » agrège des pistes de plusieurs années : une année
        // « album » n'a pas de sens ; seuls les tags fichiers décident ici.
        $cache[$aid] = null;
        yearLog("[$aid] SKIP « Sans album » (année non définissable) : {$al['artist']}");
        $skipped++;
        continue;
    }

    $year = deezerYear($term, $na, $nal, $isSelfTitled, $isSanAlbum);
    $src = $year ? 'deezer' : null;
    if (!$year) {
        usleep(200000);
        $year = mbYear($term, $na, $nal, $isSelfTitled, $isSanAlbum);
        if ($year) {
            $src = 'mb';
        }
    }
    if (!$year) {
        usleep(200000);
        $year = itunesYear($term, $na, $nal, $isSelfTitled, $isSanAlbum);
        if ($year) {
            $src = 'itunes';
        }
    }
    if (!$year && $al['genre'] === 'Classique' && $artist !== '') {
        usleep(200000);
        $year = composerYear($artist, $nal);
        if ($year) {
            $src = 'itunes';
        }
    }
    usleep(200000);

    // Garde anti-réédition : une date récente sur une œuvre classique est en
    // pratique la date du CD numérique, pas celle de l'enregistrement.
    if ($year && $year > (int) date('Y')) {
        $year = null;
    }
    if ($year && $year >= 2024 && $al['genre'] === 'Classique') {
        yearLog("[$aid] réédition suspecte ({$al['genre']} $year) : {$al['artist']} / {$al['album']}");
        $year = null;
    }

    $cache[$aid] = $year;
    if (!$year) {
        yearLog("[$aid] sans année : {$al['artist']} / {$al['album']}");
        $skipped++;
        continue;
    }
    $resolved++;
    $plan[$aid] = $year;
    echo "[$aid] YEAR($src) {$al['artist']} / {$al['album']} => $year\n";
}

file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fclose($log);

echo "\nRésolution : $resolved albums / $total, $skipped sans année (dans le log).\n";

if (!$apply) {
    echo "DRY-RUN — base non modifiée (lancez avec --apply pour écrire).\n";
    exit(0);
}

$upd = $pdo->prepare('UPDATE albums SET year = ? WHERE id = ?');
foreach ($plan as $aid => $year) {
    $upd->execute([$year, $aid]);
}
echo 'albums.year mis à jour (' . count($plan) . " albums).\n";
