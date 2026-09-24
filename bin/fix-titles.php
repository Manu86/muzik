<?php

declare(strict_types=1);

/**
 * Corrige les titres corrompus dans la base (songs.title) :
 *  1. Mojibake (é→Ú, è→Þ, Sixiéme)
 *  2. Préfixe #d#/#v# hérité du répertoire
 *  3. Titres style-fichier « NN Artiste_Titre » → numéro retiré,
 *     underscores remplacés par des espaces.
 */

require __DIR__ . '/lib/bootstrap.php';
App::initConfig(muzik_cli_config($argv));
$pdo = App::pdo();

$fixed = 0;

/** Corrige un titre tronqué style-fichier : « 01 Bach_ Cello Suite … » → « Bach Cello Suite … » */
function cleanTitle(string $title): string
{
    $out = preg_replace('/^\d{1,2}[ .-]\s*/', '', $title);
    $out = str_replace('_', ' ', $out);
    $out = preg_replace('/\s+/', ' ', $out);
    return trim($out) ?: $title;
}

$mojibake = [
    '03 - Les jeux de sociÚtÚ (version alternative - session au Paradiso 15 janvier 2003)' => '03 - Les jeux de société (version alternative - session au Paradiso 15 janvier 2003)',
    '04. Les Útudes littÚraires (Louviers 97)' => '04. Les Études littéraires (Louviers 97)',
    '18 - Les connaissances de 2Þme zone (version Dim - Montauban 28 Mai 2003)' => '18 - Les connaissances de 2ème zone (version Dim - Montauban 28 Mai 2003)',
    'Kensington Square (1Þre tournÚe 2002)' => 'Kensington Square (1ère tournée 2002)',
    'Quai des grands Augustins (1Þre tournÚe 2002)' => 'Quai des grands Augustins (1ère tournée 2002)',
    'Sixiéme Ordre: Les Bergeries. Naïvement' => 'Sixième Ordre: Les Bergeries. Naïvement',
];

$rows = $pdo->query('SELECT id, title FROM songs')->fetchAll();
$upd = $pdo->prepare('UPDATE songs SET title = ? WHERE id = ?');

foreach ($rows as $r) {
    $title = $r['title'];
    $new = $title;

    if (isset($mojibake[$title])) {
        $new = $mojibake[$title];
    }

    if (preg_match('/^#(?:d|v|m)#(.*)$/u', $new, $m)) {
        $new = $m[1];
    }

    if (str_contains($new, '_')) {
        $clean = cleanTitle($new);
        if ($clean !== '' && $clean !== $new) {
            $new = $clean;
        }
    }

    if ($new !== $title) {
        $upd->execute([$new, (int) $r['id']]);
        echo "{$r['id']}\t{$title}\n  → {$new}\n";
        $fixed++;
    }
}

echo "\n{$fixed} titres corrigés.\n";
