<?php

/**
 * Résout le genre par album (Deezer → iTunes), génère data/tag-plan.json
 * et, en mode --apply, écrit les tags ID3 (via tag_apply.py) + genre en base.
 *
 * Usage :
 *   php bin/tag.php                # dry-run (résumé, aucun fichier touché)
 *   php bin/tag.php --apply        # écrit les tags + met à jour albums.genre
 *   php bin/tag.php --limit N      # ne traite que N albums (test)
 */

define('MUZIK_INCLUDE_ONLY', true);

require __DIR__ . '/../src/DB.php';
require __DIR__ . '/../src/App.php';
require __DIR__ . '/fetch-art.php';
require __DIR__ . '/lib/bootstrap.php';

App::initConfig(muzik_cli_config($argv));
$pdo = App::pdo();

ini_set('default_socket_timeout', '10');

$apply = in_array('--apply', $argv, true);
$force = in_array('--force', $argv, true);
$limit = null;
if (($i = array_search('--limit', $argv, true)) !== false) {
    $limit = (int) ($argv[$i + 1] ?? 0);
}

$log = fopen(__DIR__ . '/../data/tag-genre.log', 'ab');
function tagLog(string $msg): void
{
    global $log;
    fwrite($log, date('H:i:s') . ' ' . $msg . "\n");
}

// --- colonne genre (idempotent) -------------------------------------
$cols = $pdo->query('PRAGMA table_info(albums)')->fetchAll(PDO::FETCH_ASSOC);
if (!in_array('genre', array_column($cols, 'name'), true)) {
    $pdo->exec('ALTER TABLE albums ADD COLUMN genre TEXT');
    echo "Colonne albums.genre ajoutée\n";
}

// --- cache genre ------------------------------------------------------
$cacheFile = __DIR__ . '/../data/genres.json';
$cache = [];
if (is_file($cacheFile)) {
    $cache = json_decode(file_get_contents($cacheFile), true) ?: [];
}

// --- albums + pistes ---------------------------------------------------
$albums = $pdo->query(
    'SELECT a.id, a.artist_id, a.name AS album, a.year, ar.name AS artist
     FROM albums a JOIN artists ar ON ar.id = a.artist_id
     ORDER BY a.id'
)->fetchAll(PDO::FETCH_ASSOC);

$songsByAlbum = $pdo->query(
    'SELECT album_id, id, title, track, disc, path FROM songs
     ORDER BY album_id, disc, track'
)->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

if ($limit !== null) {
    $albums = array_slice($albums, 0, $limit);
}
$total = count($albums);

$plan = [];
$resolved = 0;
$skipped = 0;

foreach ($albums as $al) {
    $aid = (int) $al['id'];
    if (array_key_exists($aid, $cache) && ($cache[$aid] !== null || !$force)) {
        if ($cache[$aid] !== null) {
            $resolved++;
            $plan[$aid] = $cache[$aid];
        } else {
            $skipped++;
        }
        continue;
    }

    $artist = cleanArtistName($al['artist']);
    $searchName = cleanAlbumName($al['album']);
    $year = $al['year'] ? (int) $al['year'] : null;

    $na = norm($artist);
    $nal = norm($searchName);
    $isSanAlbum = $al['album'] === 'Sans album';
    $isSelfTitled = !$isSanAlbum && $nal !== '' && $nal === $na;

    $term = $isSanAlbum ? $artist : trim($searchName . ' ' . $artist);
    if ($term === '' || $na === '') {
        $cache[$aid] = null;
        tagLog("[$aid] SKIP terme vide : {$al['artist']} / {$al['album']}");
        $skipped++;
        continue;
    }

    $genre = deezerGenres($term, $na, $nal, $isSelfTitled, $isSanAlbum, $year);
    $src = $genre ? 'deezer' : null;
    if (!$genre && $na !== '') {
        usleep(200000);
        $genre = deezerArtistGenres($artist, $na, $nal, $isSelfTitled, $isSanAlbum);
        if ($genre) {
            $src = 'deezer';
        }
    }
    if (!$genre) {
        usleep(200000);
        $genre = itunesGenre($term, $na, $nal, $isSelfTitled, $isSanAlbum, $year);
        if ($genre) {
            $src = 'itunes';
        }
    }
    usleep(200000);

    $genre = normalizeGenre($genre);
    $cache[$aid] = $genre;
    if (!$genre) {
        tagLog("[$aid] sans genre : {$al['artist']} / {$al['album']}");
        $skipped++;
        continue;
    }

    $resolved++;
    $plan[$aid] = $genre;
    echo "[$aid] GENRE($src) {$al['artist']} / {$al['album']} => {$genre}\n";
}

file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// --- plan ----------------------------------------------------------------
$planOut = [];
foreach ($plan as $aid => $genre) {
    if (empty($songsByAlbum[$aid])) {
        continue;
    }
    $albumRow = null;
    foreach ($albums as $a) {
        if ((int) $a['id'] === $aid) {
            $albumRow = $a;
            break;
        }
    }
    if (!$albumRow) {
        continue;
    }
    foreach ($songsByAlbum[$aid] as $s) {
        $planOut[] = [
            'path'   => $s['path'],
            'title'  => $s['title'],
            'artist' => $albumRow['artist'],
            'album'  => $albumRow['album'],
            'track'  => $s['track'],
            'disc'   => $s['disc'],
            'year'   => $albumRow['year'],
            'genre'  => $genre,
        ];
    }
}

$planFile = __DIR__ . '/../data/tag-plan.json';
file_put_contents($planFile, json_encode($planOut, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fclose($log);

echo "\nRésolution : $resolved albums / $total traités, $skipped sans genre (dans le log).\n";
echo 'Plan : ' . count($planOut) . " fichiers -> $planFile\n";

if (!$apply) {
    echo "DRY-RUN — caches et plan écrits ($cacheFile, $planFile) ; aucun fichier audio ni base modifiés (lancez avec --apply pour écrire).\n";
    exit(0);
}

if (!$resolved || !$planOut) {
    echo "Rien à écrire.\n";
    exit(0);
}

echo "Écriture des tags ID3…\n";
$cmd = 'python3 ' . escapeshellarg(__DIR__ . '/tag_apply.py') . ' ' . escapeshellarg($planFile);
passthru($cmd, $code);

if ($code !== 0) {
    fwrite(STDERR, "tag_apply.py a échoué (code $code).\n");
    exit(1);
}

// genre en base
$upd = $pdo->prepare('UPDATE albums SET genre = ? WHERE id = ?');
foreach ($plan as $aid => $genre) {
    $upd->execute([$genre, $aid]);
}
echo 'albums.genre mis à jour (' . count($plan) . " albums).\n";
