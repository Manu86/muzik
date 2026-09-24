<?php

declare(strict_types=1);

final class TreeWalkerTest extends TestCase
{
    public function testAlbumBaseRisesAboveDiscMarkers(): void
    {
        $writer = new CatalogWriter(new ArtExtractor());
        $walker = new TreeWalker($writer, new TagReader($writer), new ArtExtractor());

        self::assertSame(
            ['/music/Artist/Album', 'CD 2'],
            $this->invoke($walker, 'albumBase', '/music/Artist/Album/CD 2', '/music'),
        );
        self::assertSame(
            ['/music/Artist/Album', null],
            $this->invoke($walker, 'albumBase', '/music/Artist/Album', '/music'),
        );
    }

    private function invoke(TreeWalker $walker, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($walker, $method);
        return $reflection->invoke($walker, ...$arguments);
    }
}
