<?php

declare(strict_types=1);

/**
 * Lecture des tags ID3/MP4 via getID3, avec cache de lecture et repli sur le
 * nom de fichier quand le tag est absent ou pollué.
 *
 * @phpstan-type SongData array{path: string, size: int, mtime: int, title: string, track: ?int,
 *     disc: ?int, year?: ?int, duration: ?float, bitrate: ?int, artist: string, album: string,
 *     genre?: string, cached?: bool}
 */
final class TagReader
{
    private getID3 $getId3;
    private CatalogWriter $writer;

    public function __construct(CatalogWriter $writer)
    {
        $this->writer = $writer;
        $this->resetGetId3();
    }

    /** @return SongData */
    public function collect(string $file): array
    {
        $mtime = (int) filemtime($file);
        $size = (int) filesize($file);
        $cache = $this->writer->cachedRow($file, $mtime, $size);
        if ($cache) {
            return [
                'path' => $file,
                'size' => $size,
                'mtime' => $mtime,
                'title' => $cache['title'],
                'track' => $cache['track'] !== null ? (int) $cache['track'] : null,
                'disc' => $cache['disc'] !== null ? (int) $cache['disc'] : null,
                'duration' => $cache['duration'],
                'bitrate' => $cache['bitrate'],
                'artist' => $cache['artist_name'] ?? '',
                'album' => $cache['album_name'] ?? '',
                'cached' => true,
            ];
        }

        $info = [
            'path' => $file,
            'size' => $size,
            'mtime' => $mtime,
            'title' => null,
            'track' => null,
            'disc' => null,
            'year' => null,
            'duration' => null,
            'bitrate' => null,
            'artist' => '',
            'album' => '',
            'genre' => '',
        ];

        try {
            $id3 = $this->getId3->analyze($file);
            if (is_array($id3)) {
                $tagGroups = $id3['tags'] ?? null;
                $tags = is_array($tagGroups)
                    ? ($tagGroups['id3v2'] ?? $tagGroups['id3v1'] ?? null)
                    : null;
                if (is_array($tags)) {
                    $info['album'] = $this->tagValue($this->firstTag($tags, 'album'), 'album');
                    $info['artist'] = $this->tagValue($this->firstTag($tags, 'artist'), 'artist');
                    $info['title'] = $this->tagValue($this->firstTag($tags, 'title'), 'title');
                    $info['genre'] = Genre::normalize($this->firstTag($tags, 'genre')) ?? '';
                    if ($info['title'] === '') {
                        $info['title'] = null;
                    }
                    $track = $this->firstTag($tags, 'track_number');
                    $disc = $this->firstTag($tags, 'disc_number') ?: $this->firstTag($tags, 'part_of_a_set');
                    $year = $this->firstTag($tags, 'year') ?: $this->firstTag($tags, 'recording_time');
                    $info['track'] = $track !== '' ? FilenameParser::firstNumber($track) : null;
                    $info['disc'] = $disc !== '' ? FilenameParser::firstNumber($disc) : null;
                    $info['year'] = $year !== '' ? FilenameParser::firstNumber($year) : null;
                }
                $duration = $id3['playtime_seconds'] ?? null;
                $info['duration'] = is_int($duration) || is_float($duration) ? (float) $duration : null;
                $audio = $id3['audio'] ?? null;
                $bitrate = is_array($audio) ? ($audio['bitrate'] ?? null) : null;
                $info['bitrate'] = is_int($bitrate) || is_float($bitrate)
                    ? (int) round($bitrate / 1000)
                    : null;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "getID3 échec sur $file : {$e->getMessage()}\n");
        }
        $this->resetGetId3();

        if (!$info['title']) {
            $info['title'] = FilenameParser::titleFromFilename(basename($file));
        }
        if ($info['track'] === null) {
            $info['track'] = FilenameParser::parseTrack(basename($file));
        }
        if ($info['disc'] === null) {
            $discDir = FilenameParser::discDir(dirname($file));
            $info['disc'] = $discDir ?: 1;
        }

        return $info;
    }

    /** Conserve un tag sauf s'il s'agit d'un libellé générique/pollué. */
    private function tagValue(string $v, string $kind): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        $junk = match ($kind) {
            'artist' => '/^(?:no artist|unknown(?: artist)?|artist[ée] inconnu|inconnu(?:e)?|nouvel artiste|none|artiste)$/i',
            'album'  => '/(?:^(?:no album|no title|sans album|sans titre|unknown|inconnu|nouveau titre|untitled|none|g[eé]n[eé]rique)$|^https?:\/\/|^www\.)/i',
            'title'  => '/^(?:audio ?track|track|piste|unknown|no title|nouveau titre|untitled|sans titre|none)[\s\d]*$/i',
            default  => null,
        };
        if ($junk !== null && preg_match($junk, $v) === 1) {
            return '';
        }
        return $v;
    }

    /** @param array<mixed> $tags */
    private function firstTag(array $tags, string $key): string
    {
        $values = $tags[$key] ?? null;
        $value = is_array($values) ? ($values[0] ?? null) : null;

        return is_string($value) ? $value : '';
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
