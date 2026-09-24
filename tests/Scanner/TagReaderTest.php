<?php

declare(strict_types=1);

final class TagReaderTest extends TestCase
{
    public function testTitlesAreCleanedFromFilenameNoise(): void
    {
        self::assertSame('Song', FilenameParser::titleFromFilename('01 - Artist - Song (0h17).mp3'));
        self::assertSame('Title', FilenameParser::titleFromFilename('INCOMPLETE~02_Title@c6.flac'));
        self::assertSame('Piste inconnue', FilenameParser::titleFromFilename('01.mp3'));
    }

    public function testGenericTagsAreRejected(): void
    {
        $reader = new TagReader(new CatalogWriter(new ArtExtractor()));
        self::assertSame('', $this->invoke($reader, 'tagValue', 'Unknown Artist', 'artist'));
        self::assertSame('', $this->invoke($reader, 'tagValue', 'https://example.test', 'album'));
        self::assertSame('', $this->invoke($reader, 'tagValue', 'Track 01', 'title'));
        self::assertSame('Real title', $this->invoke($reader, 'tagValue', ' Real title ', 'title'));
    }

    private function invoke(TagReader $reader, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($reader, $method);
        return $reflection->invoke($reader, ...$arguments);
    }
}
