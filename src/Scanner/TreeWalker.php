<?php

declare(strict_types=1);

/**
 * Parcours de l'arborescence musicale. Les fichiers placés directement sous
 * un dossier racine (« artiste ») utilisent des heuristiques de dossier ; les
 * fichiers plus profonds (compilations, genres, multi-CD) s'appuient sur les
 * tags ID3. Délègue l'écriture en base à CatalogWriter.
 *
 * @phpstan-import-type SongData from TagReader
 */
final class TreeWalker
{
    private CatalogWriter $writer;
    private TagReader $tags;
    private ArtExtractor $art;

    public function __construct(CatalogWriter $writer, TagReader $tags, ArtExtractor $art)
    {
        $this->writer = $writer;
        $this->tags = $tags;
        $this->art = $art;
    }

    /**
     * @param array<string, true> $seen
     * @param-out array<string, true> $seen
     */
    public function scanTree(string $path, array &$seen, int $depth = 0): void
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
            if ($entry->isFile() && FilenameParser::isAudio($entry->getFilename())) {
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
            if (FilenameParser::parseTrack(basename($f)) !== null) {
                $withTrack++;
            }
        }
        $albumName = count($files) >= 2 ? $artistName : 'Sans album';
        $albumArt = ArtExtractor::findArt($artistPath) ?: $this->art->extractEmbeddedArt($artistPath);
        $artistId = null;
        $albumId = null;
        $albumYear = null;

        foreach ($files as $file) {
            $info = $this->tags->collect($file);
            $artistName2 = $info['artist'] ?: $artistName;
            $albumName2 = $info['album'] ?: $albumName;
            if ($artistId === null) {
                $artistId = $this->writer->ensureArtist($artistName2, $artistPath, $artistPath);
            }
            if ($albumId === null) {
                $albumId = $this->writer->ensureAlbum(
                    $artistId,
                    $albumName2,
                    $info['year'] ?? null,
                    $artistPath,
                    $albumArt,
                    $info['genre'] ?? ''
                );
            }
            $this->writer->upsertSong($info, $artistId, $albumId, $seen);
        }
    }

    /**
     * Fichier dans un sous-dossier quelconque : tags ID3 prioritaires.
     * @param array<string, true> $seen
     */
    private function indexLeafFile(string $file, array &$seen): void
    {
        $info = $this->tags->collect($file);
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

        $artistId = $this->writer->ensureArtist($artistName, $albumBase, $container);
        $art = ArtExtractor::findArt($albumBase) ?: ArtExtractor::findArt(dirname($albumBase))
            ?: ArtExtractor::findArt($container) ?: $this->art->extractEmbeddedArt($albumBase);
        $albumId = $this->writer->ensureAlbum($artistId, $albumName, $info['year'] ?? null, $albumBase, $art, $info['genre'] ?? '');
        $this->writer->upsertSong($info, $artistId, $albumId, $seen);
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
}
