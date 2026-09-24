<?php

declare(strict_types=1);

/**
 * Écoute : enregistrement des lectures, classements et historique.
 */
final class PlaybackController
{
    public static function play(string $id): void
    {
        App::pdo()->prepare('UPDATE songs SET play_count = play_count + 1, last_played = datetime(\'now\') WHERE id = ?')
            ->execute([(int) $id]);
        App::json(['ok' => true]);
    }

    public static function top(): void
    {
        App::json(Statistics::top());
    }

    public static function recent(): void
    {
        App::json(Statistics::recent());
    }
}
