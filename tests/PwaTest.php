<?php

declare(strict_types=1);

final class PwaTest extends TestCase
{
    public function testManifestReferencesExistingIconsWithTheDeclaredDimensions(): void
    {
        $public = dirname(__DIR__) . '/public';
        $html = file_get_contents($public . '/index.html');
        $manifestContents = file_get_contents($public . '/manifest.json');
        self::assertNotFalse($html);
        self::assertNotFalse($manifestContents);
        self::assertStringContainsString(
            '<link rel="manifest" href="manifest.json?v=3" crossorigin="use-credentials">',
            $html,
        );
        $manifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame('standalone', $manifest['display']);
        self::assertSame('./', $manifest['start_url']);
        self::assertIsArray($manifest['icons']);

        foreach ($manifest['icons'] as $icon) {
            self::assertIsArray($icon);
            self::assertIsString($icon['src']);
            self::assertIsString($icon['sizes']);
            $iconPath = parse_url($icon['src'], PHP_URL_PATH);
            self::assertIsString($iconPath);
            $path = $public . '/' . $iconPath;
            self::assertFileExists($path);
            $dimensions = getimagesize($path);
            self::assertNotFalse($dimensions);
            self::assertSame($icon['sizes'], $dimensions[0] . 'x' . $dimensions[1]);
        }
    }

    public function testNavigationViewsExistInTheHtmlAndJavascriptRouter(): void
    {
        $public = dirname(__DIR__) . '/public';
        $html = file_get_contents($public . '/index.html');
        $javascript = file_get_contents($public . '/assets/app.js');
        self::assertNotFalse($html);
        self::assertNotFalse($javascript);

        preg_match_all('/data-view="([a-z-]+)"/', $html, $matches);
        $views = array_values(array_unique(array_filter(
            $matches[1],
            static fn(string $view): bool => $view !== 'queue',
        )));
        self::assertNotEmpty($views);

        foreach ($views as $view) {
            self::assertStringContainsString('id="view-' . $view . '"', $html);
            self::assertMatchesRegularExpression(
                '/\b' . preg_quote($view, '/') . "\b/",
                $javascript,
            );
        }
    }

    public function testSongListsShowThePlayingIndicatorOverTheArtworkWithoutNumbers(): void
    {
        $public = dirname(__DIR__) . '/public';
        $javascript = file_get_contents($public . '/assets/app.js');
        $stylesheet = file_get_contents($public . '/assets/app.css');
        self::assertNotFalse($javascript);
        self::assertNotFalse($stylesheet);

        self::assertStringNotContainsString('<span class="num', $javascript);
        self::assertStringNotContainsString('class="at-play"', $javascript);
        self::assertStringContainsString("row.classList.toggle('playing', playing)", $javascript);
        self::assertStringContainsString("row.classList.toggle('paused', playing && audio.paused)", $javascript);
        self::assertStringContainsString("$('#btn-play').addEventListener('click', togglePlayback)", $javascript);
        self::assertStringContainsString('.track-row.playing .tr-thumb::before', $stylesheet);
        self::assertStringContainsString('.art-row.playing .athumb::before', $stylesheet);
        self::assertStringContainsString('.track-row.playing.paused .tr-thumb::before', $stylesheet);
        self::assertStringContainsString('.track-row .fav, .art-row .fav', $stylesheet);
        self::assertStringContainsString('favIds.has(String(t.id))', $javascript);
        self::assertStringContainsString('await toggleFav(String(s.id), e.currentTarget)', $javascript);
    }

    public function testAlbumDetailShowsTheYearBetweenTrackCountAndPath(): void
    {
        $javascript = file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        self::assertNotFalse($javascript);

        self::assertMatchesRegularExpression(
            "/data\\.songs\\.length.*data\\.year \\? ' · ' \\+ data\\.year.*data\\.path \\? ' · ' \\+ esc\\(data\\.path\\)/",
            $javascript,
        );
    }

    public function testDetailBackButtonsAreBoundWithinTheirOwnView(): void
    {
        $javascript = file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        self::assertNotFalse($javascript);

        self::assertStringNotContainsString('id="back-link"', $javascript);
        self::assertStringContainsString("const link = $('.back', view)", $javascript);
        self::assertStringContainsString("bindBack(backView, $('#view-genre-detail'))", $javascript);
        self::assertStringContainsString("bindBack(backView, $('#view-album-detail'))", $javascript);
        self::assertStringContainsString("bindBack(backView, $('#view-artist-detail'))", $javascript);
    }

    public function testOpenQueueAddsItsMeasuredHeightToTheContentInset(): void
    {
        $public = dirname(__DIR__) . '/public';
        $javascript = file_get_contents($public . '/assets/app.js');
        $stylesheet = file_get_contents($public . '/assets/app.css');
        self::assertNotFalse($javascript);
        self::assertNotFalse($stylesheet);

        self::assertStringContainsString('const height = queueOpen ? panel.offsetHeight : 0', $javascript);
        self::assertStringContainsString("style.setProperty('--queue-panel-h', height + 'px')", $javascript);
        self::assertStringContainsString('new ResizeObserver(syncQueueInset)', $javascript);
        self::assertStringContainsString('padding-bottom: calc(24px + var(--queue-panel-h, 0px))', $stylesheet);
        self::assertStringContainsString('padding-bottom: calc(12px + var(--queue-panel-h, 0px))', $stylesheet);
    }

    public function testScrollbarsMatchTheDarkTheme(): void
    {
        $stylesheet = file_get_contents(dirname(__DIR__) . '/public/assets/app.css');
        self::assertNotFalse($stylesheet);

        self::assertStringContainsString('scrollbar-color: #454545 var(--bg-2)', $stylesheet);
        self::assertStringContainsString('*::-webkit-scrollbar-track { background: var(--bg-2); }', $stylesheet);
        self::assertStringContainsString('*::-webkit-scrollbar-thumb {', $stylesheet);
        self::assertStringContainsString('*::-webkit-scrollbar-thumb:hover { background: var(--accent-2); }', $stylesheet);
    }

    public function testServiceWorkerCachesExistingShellFilesAndExcludesApiResponses(): void
    {
        $public = dirname(__DIR__) . '/public';
        $worker = file_get_contents($public . '/sw.js');
        self::assertNotFalse($worker);
        preg_match_all("/^  '([^']+)',$/m", $worker, $matches);
        self::assertNotEmpty($matches[1]);

        foreach ($matches[1] as $asset) {
            $path = parse_url($asset, PHP_URL_PATH);
            self::assertIsString($path);
            if ($path === './') {
                continue;
            }
            self::assertFileExists($public . '/' . $path);
        }

        self::assertStringContainsString("url.pathname.includes('/api/')", $worker);
        self::assertStringContainsString('caches.delete(k)', $worker);
        self::assertStringContainsString('caches.match(e.request)', $worker);
    }

    public function testBackgroundPlaybackUsesABoundedRollingBufferAndKeepsMediaSessionActive(): void
    {
        $javascript = file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        self::assertNotFalse($javascript);

        self::assertStringContainsString("audio.preload = 'auto'", $javascript);
        self::assertStringContainsString('const PREFETCH_PARALLEL = 2', $javascript);
        self::assertStringContainsString('const PREFETCH_DEPTH = 4', $javascript);
        self::assertStringContainsString('prefetched.delete(key)', $javascript);
        self::assertStringContainsString('releaseActiveBlob()', $javascript);
        self::assertStringContainsString("ms.playbackState = wantPlay ? 'playing' : 'paused'", $javascript);
        self::assertStringNotContainsString("fetch('api/ping')", $javascript);
    }
}
