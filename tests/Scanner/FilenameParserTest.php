<?php

declare(strict_types=1);

final class FilenameParserTest extends TestCase
{
    public function testAudioExtensionsAreRecognisedCaseInsensitively(): void
    {
        foreach (['song.mp3', 'song.FLAC', 'song.ogg', 'song.m4a', 'song.wav'] as $file) {
            self::assertTrue(FilenameParser::isAudio($file));
        }
        self::assertFalse(FilenameParser::isAudio('cover.jpg'));
    }

    public function testTrackNumbersAreParsedFromSupportedPrefixes(): void
    {
        self::assertSame(1, FilenameParser::parseTrack('01 - Song.mp3'));
        self::assertSame(12, FilenameParser::parseTrack('track 12_ Song.flac'));
        self::assertSame(7, FilenameParser::parseTrack('INCOMPLETE~07. Song.ogg'));
        self::assertNull(FilenameParser::parseTrack('Song without number.mp3'));
    }

    public function testDiscDirectoriesAreDetected(): void
    {
        self::assertSame(2, FilenameParser::discDir('/music/Artist/Album/CD 2'));
        self::assertSame(1, FilenameParser::discDir('/music/Artist/Album/Disc'));
        self::assertNull(FilenameParser::discDir('/music/Artist/Album'));
    }
}
