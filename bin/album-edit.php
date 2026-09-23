<?php

declare(strict_types=1);

/**
 * Répercute dans les tags des fichiers la modification d'un album faite via
 * PATCH /api/album/{id} (nom et/ou année). Construit data/album-edit-plan.json
 * à partir de la base puis, en mode --apply, écrit les tags via tag_apply.py.
 *
 * Usage :
 *   php bin/album-edit.php <id>            # dry-run (plan généré, aucun tag écrit)
 *   php bin/album-edit.php <id> --apply    # écrit les tags ID3/Vorbis/MP4
 */

if (!defined('MUZIK_INCLUDE_ONLY')) {
    define('MUZIK_INCLUDE_ONLY', true);
}

require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/App.php';
require_once __DIR__ . '/lib/bootstrap.php';

if (defined('MUZIK_ALBUM_EDIT_INCLUDE_ONLY')) {
    return;
}

/**
 * Plan d'écriture des tags d'un album, lu depuis la base.
 *
 * @param int $albumId identifiant de l'album
 * @return array{album: array, plan: list<array<string, mixed>>}
 */
function albumEditPlan(PDO $pdo, int $albumId): array
{
    $st = $pdo->prepare(
        'SELECT al.id, al.name AS name, al.year, al.genre, ar.name AS artist
         FROM albums al JOIN artists ar ON ar.id = al.artist_id
         WHERE al.id = ?'
    );
    $st->execute([$albumId]);
    $album = $st->fetch(PDO::FETCH_ASSOC);
    if (!$album) {
        return ['album' => [], 'plan' => []];
    }

    $songs = $pdo->prepare(
        'SELECT id, title, track, disc, path FROM songs WHERE album_id = ?
         ORDER BY disc, track'
    );
    $songs->execute([$albumId]);
    $plan = [];
    foreach ($songs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $plan[] = [
            'path'   => $row['path'],
            'title'  => $row['title'],
            'artist' => $album['artist'],
            'album'  => $album['name'],
            'track'  => $row['track'],
            'disc'   => $row['disc'],
            'year'   => $album['year'],
            'genre'  => $album['genre'],
        ];
    }

    return ['album' => $album, 'plan' => $plan];
}

App::initConfig(muzik_cli_config($argv));
$pdo = App::pdo();

$apply = in_array('--apply', $argv, true);
$albumId = null;
foreach ($argv as $arg) {
    if (preg_match('/^\d+$/', $arg)) {
        $albumId = (int) $arg;
        break;
    }
}
if ($albumId === null) {
    fwrite(STDERR, "Usage : php bin/album-edit.php <id> [--apply]\n");
    exit(2);
}

$result = albumEditPlan($pdo, $albumId);
$album = $result['album'];
$plan = $result['plan'];
if (!$album) {
    fwrite(STDERR, "Album $albumId introuvable.\n");
    exit(2);
}

echo 'Album : ' . $album['artist'] . " / « {$album['name']} »"
   . ($album['year'] ? " ({$album['year']})" : '') . "\n";
echo 'Pistes : ' . count($plan) . "\n";

$planFile = __DIR__ . '/../data/album-edit-plan.json';
file_put_contents($planFile, json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Plan : $planFile\n";

if (!$plan) {
    echo "Aucune piste — rien à écrire.\n";
    exit(0);
}
if (!$apply) {
    echo "DRY-RUN — aucun tag écrit (relancez avec --apply).\n";
    exit(0);
}

$cmd = 'python3 ' . escapeshellarg(__DIR__ . '/tag_apply.py') . ' ' . escapeshellarg($planFile);
passthru($cmd, $code);
exit($code !== 0 ? 1 : 0);
