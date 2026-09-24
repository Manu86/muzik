<?php

declare(strict_types=1);

/**
 * Recherche des jaquettes : fichiers nommés (cover, folder…) puis pochette
 * embarquée (ID3v2 APIC, MP4 covr…) extraite vers data/art/.
 */
final class ArtExtractor
{
    private const ART_PATTERNS = ['cover.jpg', 'cover.png', 'cover.jpeg', 'folder.jpg',
        'folder.png', 'front.jpg', 'front.png',
        'Cover.jpg', 'Folder.jpg', 'cover.JPG'];

    private getID3 $getId3;

    public function __construct()
    {
        $this->resetGetId3();
    }

    public static function findArt(string $dir): ?string
    {
        foreach (self::ART_PATTERNS as $pattern) {
            $full = $dir . '/' . $pattern;
            if (is_file($full)) {
                return $full;
            }
        }
        return null;
    }

    /**
     * Extrait la pochette embarquée (ID3v2 APIC, MP4 covr…) du premier
     * fichier audio du dossier et l'enregistre dans data/art/.
     *
     * Le fichier est déduit du répertoire : un même album produit toujours
     * le même chemin, ce qui rend l'opération idempotente. Retourne null si
     * aucun fichier audio du dossier ne porte d'image.
     */
    public function extractEmbeddedArt(string $dir): ?string
    {
        if (!is_dir($dir)) {
            return null;
        }
        $artDir = dirname(App::dbPath()) . '/art';
        foreach (new DirectoryIterator($dir) as $entry) {
            if ($entry->isDot() || !$entry->isFile() || !FilenameParser::isAudio($entry->getFilename())) {
                continue;
            }
            try {
                $id3 = $this->getId3->analyze($entry->getPathname());
            } catch (Throwable $e) {
                $this->resetGetId3();
                continue;
            }
            $comments = is_array($id3) ? ($id3['comments'] ?? null) : null;
            $pictures = is_array($comments) ? ($comments['picture'] ?? null) : null;
            if (!is_array($pictures) || $pictures === []) {
                $this->resetGetId3();
                continue;
            }
            $picture = $pictures[0];
            $data = is_array($picture) ? ($picture['data'] ?? null) : null;
            if (!is_string($data) || $data === '') {
                $this->resetGetId3();
                continue;
            }
            $mime = '';
            if (is_array($picture) && isset($picture['image_mime']) && is_string($picture['image_mime'])) {
                $mime = strtolower($picture['image_mime']);
            }
            $ext = match ($mime) {
                'image/png' => 'png',
                'image/gif' => 'gif',
                default => 'jpg',
            };
            @mkdir($artDir, 0775, true);
            $out = $artDir . '/' . sha1($dir) . '.' . $ext;
            if (!is_file($out)) {
                file_put_contents($out, $data);
            }
            $this->resetGetId3();
            return $out;
        }
        return null;
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
