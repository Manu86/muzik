<?php

declare(strict_types=1);

/**
 * Diffusion audio (ranges, transcodage) et jaquettes.
 */
final class MediaController
{
    public static function stream(string $id): void
    {
        $db = App::pdo();
        $st = $db->prepare('SELECT path, size, bitrate FROM songs WHERE id = ?');
        $st->execute([$id]);
        $song = $st->fetch();
        if (!$song) {
            App::err('Not found', 404);
        }
        $file = Request::stringValue($song['path'] ?? null);
        if (!file_exists($file)) {
            App::err('File missing', 404);
        }

        $transcode = Request::getInt('transcode', App::transcodeBitrate());
        $start = max(0.0, (float) Request::get('start', '0'));
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $isMp3 = in_array($ext, ['mp3']);
        $needsTranscode = $transcode > 0 && function_exists('proc_open');

        header('Accept-Ranges: bytes');
        header('Connection: close');

        if ($needsTranscode && !$isMp3) {
            Streamer::transcode($file, $transcode, $start);
        } else {
            Streamer::direct($file);
        }
    }

    public static function art(string $id): void
    {
        // Distinguer artiste et album : leurs identifiants n'appartiennent pas au même espace
        if (($_GET['type'] ?? '') === 'artist') {
            $path = Catalogue::artistArt((int) $id);
        } else {
            $path = Catalogue::albumArt((int) $id);
        }
        if (!is_string($path) || !file_exists($path)) {
            http_response_code(404);
            exit;
        }
        $mime = 'image/jpeg';
        if (str_ends_with(strtolower($path), '.png')) {
            $mime = 'image/png';
        }
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }
        readfile($path);
    }
}
