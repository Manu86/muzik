<?php

final class Streamer
{
    public static function direct(string $file): void
    {
        set_time_limit(0);
        clearstatcache(true, $file);
        $size = filesize($file);
        if ($size === false) {
            http_response_code(500);
            exit;
        }
        $rangeValue = $_SERVER['HTTP_RANGE'] ?? '';
        $range = is_string($rangeValue) ? $rangeValue : '';
        $resolvedRange = self::resolveRange($size, $range);
        if ($resolvedRange === null) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        [$start, $end, $isPartial] = $resolvedRange;

        if ($isPartial) {
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        } else {
            http_response_code(200);
        }

        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . ($end - $start + 1));
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }

        $fp = fopen($file, 'rb');
        if (!$fp) {
            http_response_code(500);
            exit;
        }
        fseek($fp, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fp)) {
            $chunk = fread($fp, min(1048576, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
            flush();
            if (connection_aborted()) {
                break;
            }
        }
        fclose($fp);
    }

    /** @return array{int, int, bool}|null */
    private static function resolveRange(int $size, string $range): ?array
    {
        $start = 0;
        $end = $size - 1;
        if (preg_match('/bytes=(\d*)-(\d*)/', $range, $matches) !== 1) {
            return [$start, $end, false];
        }

        if ($matches[1] !== '') {
            $start = (int) $matches[1];
            $end = $matches[2] !== '' ? min((int) $matches[2], $size - 1) : $size - 1;
        } elseif ($matches[2] !== '') {
            $start = max(0, $size - (int) $matches[2]);
        }

        return $start >= $size || $start > $end ? null : [$start, $end, true];
    }

    public static function transcode(string $file, int $kbps, float $start = 0.0): void
    {
        set_time_limit(0);
        $ffmpeg = App::ffmpeg();
        $codec = in_array($kbps, [64, 96, 128, 192, 256], true) ? $kbps : 128;

        http_response_code(200);
        header('Content-Type: audio/mpeg');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }

        $seek = '';
        if ($start > 0.0) {
            $seek = '-ss ' . number_format($start, 1, '.', '') . ' ';
        }
        $cmd = escapeshellarg($ffmpeg) . ' ' . $seek . '-i ' . escapeshellarg($file)
             . ' -vn -acodec libmp3lame -b:a ' . $codec . 'k -f mp3 - 2>/dev/null';

        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            http_response_code(500);
            exit;
        }
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 1048576);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            flush();
            if (connection_aborted()) {
                proc_terminate($proc);
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
}
