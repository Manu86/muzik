<?php

declare(strict_types=1);

final class PwaTest extends TestCase
{
    public function testManifestReferencesExistingIconsWithTheDeclaredDimensions(): void
    {
        $public = dirname(__DIR__) . '/public';
        $html = file_get_contents($public . '/app.html');
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
        $html = file_get_contents($public . '/app.html');
        $javascript = $this->javascriptSources();
        self::assertNotFalse($html);
        self::assertStringContainsString('<script type="module" src="assets/js/app.js?v=84"></script>', $html);

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

    public function testSettingsViewLoaderIsWiredIntoTheRouter(): void
    {
        $public = dirname(__DIR__) . '/public/assets/js';
        $app = file_get_contents($public . '/app.js');
        $account = file_get_contents($public . '/account.js');
        $views = file_get_contents($public . '/views.js');
        self::assertNotFalse($app);
        self::assertNotFalse($account);
        self::assertNotFalse($views);

        self::assertStringContainsString('export async function loadSettingsView()', $account);
        self::assertStringContainsString('export function configureSettingsView(loader)', $views);
        self::assertStringContainsString("if (name === 'settings') settingsViewLoader();", $views);
        self::assertStringContainsString('configureSettingsView(loadSettingsView);', $app);
    }

    public function testSettingsBlocksHaveVerticalSpacing(): void
    {
        $stylesheet = file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
        self::assertNotFalse($stylesheet);

        self::assertMatchesRegularExpression(
            '/\.settings-section\s*\{[^}]*margin-bottom:\s*16px;/s',
            $stylesheet,
        );
    }

    public function testSearchCanBeClosedAndClearedWithAnAccessibleButton(): void
    {
        $public = dirname(__DIR__) . '/public';
        $html = file_get_contents($public . '/app.html');
        $javascript = file_get_contents($public . '/assets/js/views.js');
        $stylesheet = file_get_contents($public . '/assets/css/app.css');
        self::assertNotFalse($html);
        self::assertNotFalse($javascript);
        self::assertNotFalse($stylesheet);

        self::assertStringContainsString(
            '<button id="search-close" type="button" aria-label="Fermer la recherche"',
            $html,
        );
        self::assertStringContainsString("searchClose.addEventListener('click'", $javascript);
        self::assertStringContainsString("searchInput.value = '';", $javascript);
        self::assertStringContainsString('searchClose.hidden = q.length === 0;', $javascript);
        self::assertStringContainsString('#search-close[hidden] { display: none; }', $stylesheet);
    }

    public function testTrackQueueButtonsCanBuildPlayerEntries(): void
    {
        $javascript = dirname(__DIR__) . '/public/assets/js';
        $player = file_get_contents($javascript . '/player.js');
        $views = file_get_contents($javascript . '/views.js');
        self::assertNotFalse($player);
        self::assertNotFalse($views);

        self::assertStringContainsString('export function toEntry(x)', $player);
        self::assertMatchesRegularExpression(
            "/import \{[^}]*\\btoEntry\\b[^}]*\} from '\\.\/player\\.js\?v=84';/",
            $views,
        );
        self::assertStringContainsString('export function addToQueue(x)', $player);
        self::assertStringContainsString(
            'state.queue.some(item => String(item.id) === String(entry.id))',
            $player,
        );
        self::assertStringContainsString("artist: x.artist || x.artist_name || ''", $player);
        self::assertStringContainsString("album: x.album || x.album_id || ''", $player);
        self::assertStringContainsString('data-album="${t.album || \'\'}"', $views);
        self::assertGreaterThanOrEqual(2, substr_count($views, 'addToQueue(row.dataset);'));
    }

    public function testProgressSliderIsGreyAndDisabledWithoutALoadedTrack(): void
    {
        $public = dirname(__DIR__) . '/public';
        $html = file_get_contents($public . '/app.html');
        $player = file_get_contents($public . '/assets/js/player.js');
        $stylesheet = file_get_contents($public . '/assets/css/app.css');
        self::assertNotFalse($html);
        self::assertNotFalse($player);
        self::assertNotFalse($stylesheet);

        self::assertStringContainsString(
            'id="player-progress" type="range" min="0" value="0" step="1" aria-label="Position de lecture" disabled',
            $html,
        );
        self::assertStringContainsString('progress.disabled = !state.playingId;', $player);
        self::assertStringContainsString('#player-progress:disabled { accent-color: var(--muted);', $stylesheet);
    }

    public function testClickingATrackPreservesTheQueueAndStartsTheSelectedTrack(): void
    {
        $javascript = dirname(__DIR__) . '/public/assets/js';
        $views = file_get_contents($javascript . '/views.js');
        $player = file_get_contents($javascript . '/player.js');
        self::assertNotFalse($views);
        self::assertNotFalse($player);

        self::assertGreaterThanOrEqual(4, substr_count($views, 'playTrack('));
        self::assertStringNotContainsString("const ids = $$('.track-row', container)", $views);
        self::assertStringContainsString('export function playTrack(x)', $player);
        self::assertStringContainsString(
            'const queuedIndex = state.queue.findIndex(item => String(item.id) === String(entry.id));',
            $player,
        );
        self::assertStringContainsString('state.queue.push(entry);', $player);
        self::assertStringContainsString('state.index = queuedIndex;', $player);
        self::assertStringContainsString(
            "$('#rand-playall').addEventListener('click', () => playQueue(allRandIds(), 0))",
            $views,
        );
        self::assertStringNotContainsString('randPlay(', $views);
        self::assertStringContainsString("$('#play-all').addEventListener('click'", $views);
    }

    public function testSongListsShowThePlayingIndicatorOverTheArtworkWithoutNumbers(): void
    {
        $public = dirname(__DIR__) . '/public';
        $javascript = $this->javascriptSources();
        $stylesheet = file_get_contents($public . '/assets/css/app.css');
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
        $javascript = $this->javascriptSources();

        self::assertMatchesRegularExpression(
            "/data\\.songs\\.length.*data\\.year \\? ' · ' \\+ data\\.year.*data\\.path \\? ' · ' \\+ esc\\(data\\.path\\)/",
            $javascript,
        );
    }

    public function testDetailBackButtonsAreBoundWithinTheirOwnView(): void
    {
        $javascript = $this->javascriptSources();

        self::assertStringNotContainsString('id="back-link"', $javascript);
        self::assertStringContainsString("const link = $('.back', view)", $javascript);
        self::assertStringContainsString("bindBack(backView, $('#view-genre-detail'))", $javascript);
        self::assertStringContainsString("bindBack(backView, $('#view-album-detail'))", $javascript);
        self::assertStringContainsString("bindBack(backView, $('#view-artist-detail'))", $javascript);
    }

    public function testOpenQueueAddsItsMeasuredHeightToTheContentInset(): void
    {
        $public = dirname(__DIR__) . '/public';
        $javascript = $this->javascriptSources();
        $stylesheet = file_get_contents($public . '/assets/css/app.css');
        self::assertNotFalse($stylesheet);

        self::assertStringContainsString('const height = queueOpen ? panel.offsetHeight : 0', $javascript);
        self::assertStringContainsString("style.setProperty('--queue-panel-h', height + 'px')", $javascript);
        self::assertStringContainsString('new ResizeObserver(syncQueueInset)', $javascript);
        self::assertStringContainsString('padding-bottom: calc(24px + var(--queue-panel-h, 0px))', $stylesheet);
        self::assertStringContainsString('padding-bottom: calc(12px + var(--queue-panel-h, 0px))', $stylesheet);
    }

    public function testScrollbarsMatchTheDarkTheme(): void
    {
        $stylesheet = file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
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
        $javascript = $this->javascriptSources();

        self::assertStringContainsString("audio.preload = 'auto'", $javascript);
        self::assertStringContainsString('const PREFETCH_PARALLEL = 2', $javascript);
        self::assertStringContainsString('const PREFETCH_DEPTH = 4', $javascript);
        self::assertStringContainsString('prefetched.delete(key)', $javascript);
        self::assertStringContainsString('releaseActiveBlob()', $javascript);
        self::assertStringContainsString("ms.playbackState = wantPlay ? 'playing' : 'paused'", $javascript);
        self::assertStringNotContainsString("fetch('api/ping')", $javascript);
    }

    private function javascriptSources(): string
    {
        $files = glob(dirname(__DIR__) . '/public/assets/js/*.js');
        self::assertIsArray($files);
        sort($files);

        $sources = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertNotFalse($source);
            $sources[] = $source;
        }

        return implode("\n", $sources);
    }
}
