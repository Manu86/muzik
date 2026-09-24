<?php

declare(strict_types=1);

/**
 * Point d'entrée du scan : orchestre le parcours (TreeWalker), l'écriture en
 * base (CatalogWriter) et, en mode --full, le retrait des pistes absentes.
 *
 * @phpstan-import-type SongData from TagReader
 */
final class Scanner
{
    public function run(bool $full = false): void
    {
        $root = App::musicRoot();
        if (!is_dir($root)) {
            fwrite(STDERR, "MUSIC_ROOT invalide : $root\n");
            exit(1);
        }

        $seen = [];
        $start = microtime(true);
        $art = new ArtExtractor();
        $writer = new CatalogWriter($art);
        $walker = new TreeWalker($writer, new TagReader($writer), $art);

        foreach (new DirectoryIterator($root) as $dir) {
            if ($dir->isDot() || !$dir->isDir()) {
                continue;
            }
            $dirName = $dir->getFilename();
            if ($dirName === '_Playlists') {
                continue;
            }
            $walker->scanTree($dir->getPathname(), $seen);
        }

        if ($full) {
            $writer->prune($seen);
        }

        printf(
            "Scan terminé : %d ajoutées, %d mises à jour en %.1fs\n",
            $writer->added(),
            $writer->updated(),
            microtime(true) - $start
        );
    }
}
