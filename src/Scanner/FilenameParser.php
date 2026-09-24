<?php

declare(strict_types=1);

/**
 * Heuristiques de nommage : numéro de piste, disque, titre lisible, formats.
 */
final class FilenameParser
{
    public static function isAudio(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['mp3', 'flac', 'ogg', 'm4a', 'wav'], true);
    }

    public static function parseTrack(string $name): ?int
    {
        if (preg_match('/^(?:INCOMPLETE~|track\s*|piste\s*)?(\d{1,2})\s*[.\-_)]\s*/i', $name, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    public static function titleFromFilename(string $name): string
    {
        $name = trim(rawurldecode($name));
        foreach (['/\.(mp3|flac|ogg|m4a|wav)$/i', '/^(?:INCOMPLETE~)?\d{1,2}\s*[.\-_)]?\s*/i',
            '/\s*\(0h\d+\)\s*$/i', '/\s*\{[^{}]*\}\s*$/', '/\s*\@[a-z]\d+\s*$/i',
            '/\s*%\d+%\s*$/', '/\s*#\w#\s*$/i', '/\s*\[[\'"a-z0-9]{1,6}\]\s*$/i',
            '/\s*\[[a-z][ \'"]?\w{0,6}\]\s*$/i'] as $pattern) {
            $name = preg_replace($pattern, '', $name) ?? $name;
        }
        $name = trim($name, " \t-_.");
        if (preg_match('/^(.+?)\s[-\x{2013}]\s(.+)$/u', $name, $m)) {
            $name = $m[2];
        }
        return $name ?: 'Piste inconnue';
    }

    public static function discDir(string $container): ?int
    {
        $name = basename($container);
        if (preg_match('/^(disque|disc|cd|dvd|volume|disco|part)[.\s_-]*(\d*)/i', $name, $m)) {
            return $m[2] !== '' ? (int) $m[2] : 1;
        }
        return null;
    }

    public static function firstNumber(string $s): int
    {
        if (preg_match('/^(\d+)/', trim($s), $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    public static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }
}
