<?php

declare(strict_types=1);

/**
 * Recherche les portraits manquants des artistes sur Deezer.
 *
 * Usage :
 *   php bin/fetch-artist-art.php [--limit N] [--force]
 *   php bin/fetch-artist-art.php [--limit N] [--force] --apply
 */

if (!defined('MUZIK_INCLUDE_ONLY')) {
    define('MUZIK_INCLUDE_ONLY', true);
}
require_once __DIR__ . '/fetch-art.php';

/** Noms qui désignent une catégorie ou une collection plutôt qu'un artiste. */
function isGenericArtistName(string $name): bool
{
    $name = norm($name);
    return str_starts_with($name, 'bootlegs de qualit') || in_array($name, [
        'bo',
        'bande originale',
        'bootlegs de qualite moyenne',
        'chansons du bord de zinc',
        'classique',
        'compilation',
        'divers',
        'jazz',
        'la folle journee de nantes',
        'unknown artist',
        'various artists',
    ], true);
}

/**
 * Extrait uniquement une correspondance exacte d'une réponse Deezer.
 *
 * @return array{id: int, name: string, url: string}|null
 */
function exactDeezerArtistCandidate(string $artist, string $json): ?array
{
    $payload = json_decode($json, true);
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
        return null;
    }

    $expected = normArt($artist);
    foreach ($payload['data'] as $candidate) {
        if (!is_array($candidate) || normArt((string) ($candidate['name'] ?? '')) !== $expected) {
            continue;
        }
        $url = $candidate['picture_xl'] ?? $candidate['picture_big'] ?? null;
        if (!is_string($url) || $url === '') {
            continue;
        }
        return [
            'id' => (int) ($candidate['id'] ?? 0),
            'name' => (string) $candidate['name'],
            'url' => $url,
        ];
    }
    return null;
}

/** @return array{id: int, name: string, url: string}|null */
function findArtistPortrait(string $artist): ?array
{
    $json = httpGet('https://api.deezer.com/search/artist?q=' . rawurlencode($artist) . '&limit=10');
    return $json === null ? null : exactDeezerArtistCandidate($artist, $json);
}

if (defined('MUZIK_ARTIST_ART_INCLUDE_ONLY')) {
    return;
}

require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/lib/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$force = in_array('--force', $argv, true);
$config = muzik_cli_config($argv);
App::initConfig($config);
$database = realpath((string) ($config['db_path'] ?? ''));
if ($database === false) {
    throw new RuntimeException('Base SQLite introuvable.');
}
if ($apply) {
    $pdo = App::pdo();
} else {
    $pdo = new PDO('sqlite:file:' . $database . '?immutable=1', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
$limit = null;
if (($index = array_search('--limit', $argv, true)) !== false) {
    $limit = max(0, (int) ($argv[$index + 1] ?? 0));
}

$cacheFile = __DIR__ . '/../data/artist-art-resolve.json';
$logFile = __DIR__ . '/../data/artist-art.log';
$cache = is_file($cacheFile)
    ? (json_decode((string) file_get_contents($cacheFile), true) ?: [])
    : [];

$rows = $pdo->query(
    'SELECT ar.id, ar.name
       FROM artists ar
      WHERE ar.art_path IS NULL
        AND EXISTS (SELECT 1 FROM songs s WHERE s.artist_id = ar.id)
        AND NOT EXISTS (
            SELECT 1 FROM albums al
             WHERE al.artist_id = ar.id AND al.art_path IS NOT NULL
        )
      ORDER BY ar.name'
)->fetchAll(PDO::FETCH_ASSOC);
if ($limit !== null) {
    $rows = array_slice($rows, 0, $limit);
}

$matches = [];
$generic = 0;
$missing = 0;
echo 'Artistes sans aucune image : ' . count($rows) . PHP_EOL;
foreach ($rows as $row) {
    $id = (int) $row['id'];
    $name = (string) $row['name'];
    if (isGenericArtistName($name)) {
        $generic++;
        echo "[$id] IGNORÉ (nom générique) — $name" . PHP_EOL;
        continue;
    }

    $candidate = (!$force && array_key_exists((string) $id, $cache))
        ? $cache[(string) $id]
        : findArtistPortrait($name);
    $cache[(string) $id] = $candidate;
    if (!is_array($candidate)) {
        $missing++;
        echo "[$id] AUCUNE — $name" . PHP_EOL;
        continue;
    }
    $matches[$id] = ['artist' => $row, 'candidate' => $candidate];
    echo "[$id] MATCH — $name => {$candidate['name']}" . PHP_EOL;
    usleep(200000);
}

file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents(
    $logFile,
    date('c') . ' candidats=' . count($matches) . " generiques=$generic sans_resultat=$missing\n",
    FILE_APPEND,
);

echo PHP_EOL . count($matches) . " correspondance(s) exacte(s), $generic nom(s) générique(s), "
    . "$missing sans résultat." . PHP_EOL;
if (!$apply) {
    echo 'DRY-RUN — cache et log mis à jour ; aucune image téléchargée, base inchangée.' . PHP_EOL;
    exit(0);
}

$storage = __DIR__ . '/../data/artist-art';
if (!is_dir($storage) && !mkdir($storage, 0775, true) && !is_dir($storage)) {
    throw new RuntimeException("Impossible de créer $storage");
}
$saved = 0;
foreach ($matches as $id => $match) {
    $raw = httpGet($match['candidate']['url']);
    if ($raw === null) {
        echo "[$id] TÉLÉCHARGEMENT MANQUÉ" . PHP_EOL;
        continue;
    }
    $directory = $storage . '/' . $id;
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        echo "[$id] DOSSIER IMPOSSIBLE" . PHP_EOL;
        continue;
    }
    $path = saveCoverRaw($directory, $raw);
    if ($path === false) {
        echo "[$id] IMAGE INVALIDE" . PHP_EOL;
        continue;
    }
    $pdo->prepare('UPDATE artists SET art_path = ? WHERE id = ?')->execute([$path, $id]);
    $saved++;
    echo "[$id] ENREGISTRÉ — {$match['artist']['name']}" . PHP_EOL;
}
echo "Terminé : $saved portrait(s) enregistré(s)." . PHP_EOL;
