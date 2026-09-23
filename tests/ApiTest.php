<?php

declare(strict_types=1);

final class ApiTest extends TestCase
{
    private int $alphaArtist;
    private int $betaArtist;
    private int $rockAlbum;
    private int $jazzAlbum;
    private int $songOne;
    private int $songTwo;
    private int $songThree;
    private string $betaSongPath;
    private string $betaCoverPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialiseApp();
        $this->seedCatalogue();
    }

    public function testSummaryAndCatalogueListings(): void
    {
        $summary = $this->captureJson(static fn() => Api::summary());
        self::assertSame([
            'artists' => 2,
            'albums' => 2,
            'songs' => 3,
            'size' => 16,
            'duration' => 240.0,
        ], $summary->data);

        $artists = $this->captureJson(static fn() => Api::artists());
        self::assertSame(2, $artists->data['total']);
        $letters = $artists->data['letters'];
        self::assertIsArray($letters);
        self::assertSame(['A', 'B'], array_column($letters, 'l'));

        $_GET = ['letter' => 'A'];
        $letter = $this->captureJson(static fn() => Api::artistsByLetter());
        self::assertCount(1, $letter->data);
        $letterRow = $letter->data[0];
        self::assertIsArray($letterRow);
        self::assertSame('Alpha', $letterRow['name']);
        self::assertIsNumeric($letterRow['song_count']);
        self::assertSame(2, (int) $letterRow['song_count']);

        $_GET = [];
        $all = $this->captureJson(static fn() => Api::artistsByLetter());
        self::assertCount(2, $all->data);
        self::assertSame(['Alpha', 'Beta'], array_column($all->data, 'name'));

        $_GET = ['artist_id' => (string) $this->alphaArtist, 'letter' => 'A'];
        $albums = $this->captureJson(static fn() => Api::albums());
        self::assertSame(1, $albums->data['total']);
        $albumRows = $albums->data['albums'];
        self::assertIsArray($albumRows);
        $albumRow = $albumRows[0];
        self::assertIsArray($albumRow);
        self::assertSame('First Album', $albumRow['name']);
    }

    public function testAlbumsRespectTheLimitParameter(): void
    {
        $_GET = ['limit' => '1'];
        $page = $this->captureJson(static fn() => Api::albums());
        self::assertSame(2, $page->data['total']);
        $albumRows = $page->data['albums'];
        self::assertIsArray($albumRows);
        self::assertCount(1, $albumRows);
        $albumRow = $albumRows[0];
        self::assertIsArray($albumRow);
        self::assertSame('First Album', $albumRow['name']);
        self::assertSame(1, $page->data['page']);

        $_GET = ['limit' => '500'];
        $all = $this->captureJson(static fn() => Api::albums());
        self::assertSame(2, $all->data['total']);
        $allRows = $all->data['albums'];
        self::assertIsArray($allRows);
        self::assertCount(2, $allRows);
    }

    public function testArtistAlbumAndSongDetailsAndMissingResources(): void
    {
        $artistId = (string) $this->alphaArtist;
        $artist = $this->captureJson(static fn() => Api::artist($artistId));
        self::assertSame('Alpha', $artist->data['name']);
        $artistAlbums = $artist->data['albums'];
        self::assertIsArray($artistAlbums);
        $artistAlbum = $artistAlbums[0];
        self::assertIsArray($artistAlbum);
        self::assertSame('First Album', $artistAlbum['name']);

        $albumId = (string) $this->rockAlbum;
        $album = $this->captureJson(static fn() => Api::album($albumId));
        self::assertSame('Rock', $album->data['genre']);
        self::assertIsArray($album->data['songs']);
        self::assertCount(2, $album->data['songs']);
        self::assertSame('/Alpha/First Album', $album->data['path']);

        $songId = (string) $this->songOne;
        $song = $this->captureJson(static fn() => Api::song($songId));
        self::assertSame('Song One', $song->data['title']);
        self::assertSame('Alpha', $song->data['artist_name']);

        foreach ([
            static fn() => Api::artist('999'),
            static fn() => Api::album('999'),
            static fn() => Api::song('999'),
        ] as $request) {
            $response = $this->captureJson($request);
            self::assertSame(404, $response->status);
        }
    }

    public function testArtistArtworkFallsBackToAnAlbumCover(): void
    {
        $_GET = ['type' => 'artist'];
        ob_start();
        Api::art((string) $this->betaArtist);
        $fallback = ob_get_clean();
        self::assertSame('cover', $fallback);

        ob_start();
        Api::art((string) $this->alphaArtist);
        $artistImage = ob_get_clean();
        self::assertSame('artist', $artistImage);
    }

    public function testAlbumCanBeRenamedAndReYearded(): void
    {
        $albumId = (string) $this->rockAlbum;
        $_GET = ['name' => 'Renamed Album', 'year' => '1999'];
        $response = $this->captureJson(static fn() => Api::albumUpdate($albumId));
        self::assertSame(200, $response->status);
        self::assertSame('Renamed Album', $response->data['name']);
        self::assertSame(1999, $response->data['year']);

        $name = App::pdo()->query('SELECT name FROM albums WHERE id = ' . $this->rockAlbum)->fetchColumn();
        self::assertSame('Renamed Album', $name);
        $year = App::pdo()->query('SELECT year FROM albums WHERE id = ' . $this->rockAlbum)->fetchColumn();
        self::assertSame(1999, $year);
    }

    public function testAlbumYearCanBeClearedAndNameValidated(): void
    {
        $albumId = (string) $this->rockAlbum;
        $_GET = ['year' => ''];
        $cleared = $this->captureJson(static fn() => Api::albumUpdate($albumId));
        self::assertSame(200, $cleared->status);
        self::assertNull($cleared->data['year']);

        $_GET = ['name' => '   '];
        $blank = $this->captureJson(static fn() => Api::albumUpdate($albumId));
        self::assertSame(400, $blank->status);

        $_GET = ['name' => 'Bad Year', 'year' => '2050'];
        $future = $this->captureJson(static fn() => Api::albumUpdate($albumId));
        self::assertSame(400, $future->status);

        $_GET = [];
        $none = $this->captureJson(static fn() => Api::albumUpdate($albumId));
        self::assertSame(400, $none->status);

        $_GET = ['name' => 'Missing'];
        $missing = $this->captureJson(static fn() => Api::albumUpdate('999'));
        self::assertSame(404, $missing->status);
    }

    public function testAlbumRenameRejectsCollisionWithAnExistingAlbum(): void
    {
        $albumId = (string) $this->rockAlbum;
        $db = App::pdo();
        $db->prepare('INSERT INTO albums(artist_id, name, path) VALUES(?, ?, ?)')
            ->execute([$this->alphaArtist, 'Renamed Album', '/x']);

        $_GET = ['name' => 'Renamed Album'];
        $response = $this->captureJson(static fn() => Api::albumUpdate($albumId));
        self::assertSame(409, $response->status);
        self::assertSame(['error' => 'Un album portant ce nom existe déjà pour cet artiste'], $response->data);

        $name = App::pdo()->query('SELECT name FROM albums WHERE id = ' . $this->rockAlbum)->fetchColumn();
        self::assertSame('First Album', $name);
    }

    public function testSearchValidatesAndFindsAllEntityTypes(): void
    {
        $_GET = ['q' => 'x'];
        $short = $this->captureJson(static fn() => Api::search());
        self::assertSame(400, $short->status);
        self::assertSame(['error' => 'Query too short'], $short->data);

        $_GET = ['q' => 'Alpha'];
        $result = $this->captureJson(static fn() => Api::search());
        self::assertIsArray($result->data['songs']);
        self::assertCount(2, $result->data['songs']);
        self::assertIsArray($result->data['artists']);
        self::assertCount(1, $result->data['artists']);

        $_GET = ['q' => 'Album'];
        $albums = $this->captureJson(static fn() => Api::search());
        self::assertIsArray($albums->data['albums']);
        self::assertCount(2, $albums->data['albums']);
    }

    public function testRandomHonoursLimitsAndOffsets(): void
    {
        $_GET = ['n' => '2', 'offset' => '0'];
        $two = $this->captureJson(static fn() => Api::random());
        self::assertCount(2, $two->data);

        $_GET = ['n' => '0', 'offset' => '99'];
        $empty = $this->captureJson(static fn() => Api::random());
        self::assertSame([], $empty->data);
    }

    public function testPlayTopAndRecentStatistics(): void
    {
        $songId = (string) $this->songTwo;
        $played = $this->captureJson(static fn() => Api::play($songId));
        self::assertSame(['ok' => true], $played->data);
        self::assertSame(1, (int) App::pdo()->query("SELECT play_count FROM songs WHERE id = {$this->songTwo}")->fetchColumn());

        $top = $this->captureJson(static fn() => Api::top());
        self::assertSame(4, $top->data['total_plays']);
        $topSongs = $top->data['songs'];
        $topAlbums = $top->data['albums'];
        self::assertIsArray($topSongs);
        self::assertIsArray($topAlbums);
        self::assertIsArray($topSongs[0]);
        self::assertIsArray($topAlbums[0]);
        self::assertSame('Song One', $topSongs[0]['title']);
        self::assertSame('First Album', $topAlbums[0]['name']);

        $recent = $this->captureJson(static fn() => Api::recent());
        self::assertCount(2, $recent->data);
    }

    public function testFavoritesCanBeListedCheckedAddedAndRemoved(): void
    {
        $initial = $this->captureJson(static fn() => Api::favorites());
        self::assertSame([$this->songOne], array_map(
            static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0,
            array_column($initial->data, 'id')
        ));

        $_GET = ['action' => 'check', 'id' => (string) $this->songOne];
        $checked = $this->captureJson(static fn() => Api::favorites());
        self::assertTrue($checked->data['favorited']);

        $_GET = ['action' => 'add', 'id' => (string) $this->songTwo];
        $added = $this->captureJson(static fn() => Api::favorites());
        self::assertCount(2, $added->data);

        $_GET = ['action' => 'remove', 'id' => (string) $this->songOne];
        $removed = $this->captureJson(static fn() => Api::favorites());
        self::assertSame([$this->songTwo], array_map(
            static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0,
            array_column($removed->data, 'id')
        ));
    }

    public function testGenresAndGenreDetails(): void
    {
        $genres = $this->captureJson(static fn() => Api::genres());
        $expected = DB::GENRES;
        sort($expected);
        self::assertSame($expected, array_column($genres->data, 'genre'));
        $byName = [];
        foreach ($genres->data as $row) {
            self::assertIsArray($row);
            $name = $row['genre'];
            self::assertIsString($name);
            $byName[$name] = $row;
        }
        $rock = $byName['Rock'];
        $rockAlbumCount = $rock['album_count'];
        $rockSongCount = $rock['song_count'];
        self::assertIsNumeric($rockAlbumCount);
        self::assertIsNumeric($rockSongCount);
        self::assertSame(1, (int) $rockAlbumCount);
        self::assertSame(2, (int) $rockSongCount);
        $jazz = $byName['Jazz'];
        $jazzAlbumCount = $jazz['album_count'];
        self::assertIsNumeric($jazzAlbumCount);
        self::assertSame(1, (int) $jazzAlbumCount);
        $electro = $byName['Electro'];
        $electroAlbumCount = $electro['album_count'];
        self::assertIsNumeric($electroAlbumCount);
        self::assertSame(0, (int) $electroAlbumCount);

        $_GET = ['name' => 'Rock'];
        $rock = $this->captureJson(static fn() => Api::genre());
        self::assertSame(1, $rock->data['total_albums']);
        self::assertSame(2, $rock->data['total_songs']);

        $_GET = [];
        $missing = $this->captureJson(static fn() => Api::genre());
        self::assertSame(400, $missing->status);
    }

    public function testHomeAggregatesEveryDashboardSection(): void
    {
        $home = $this->captureJson(static fn() => Api::home());

        foreach (['genres', 'artists', 'albums', 'recent', 'poche', 'covers'] as $section) {
            self::assertArrayHasKey($section, $home->data);
            self::assertIsArray($home->data[$section]);
        }
        self::assertCount(count(DB::GENRES), $home->data['genres']);
        self::assertCount(2, $home->data['artists']);
        self::assertCount(2, $home->data['albums']);
        self::assertCount(1, $home->data['recent']);
        self::assertCount(1, $home->data['poche']);
        self::assertCount(1, $home->data['covers']);
    }

    public function testSettingsCanBeReadAndChanged(): void
    {
        DB::setSetting('transcode', '64');
        $settings = $this->captureJson(static fn() => Api::settings());
        self::assertSame('64', $settings->data['transcode']);

        $_GET = ['set_key' => 'transcode', 'value' => '128'];
        $updated = $this->captureJson(static fn() => Api::settings());
        self::assertSame(['ok' => true], $updated->data);
        self::assertSame('128', DB::setting('transcode'));
    }

    public function testSettingsExposeTheCurrentMusicRoot(): void
    {
        $settings = $this->captureJson(static fn() => Api::settings());
        self::assertArrayHasKey('music_root', $settings->data);
        self::assertSame(App::musicRoot(), $settings->data['music_root']);
    }

    public function testConfigRequiresAHostAuthentication(): void
    {
        $newRoot = $this->temporaryDirectory . '/nouvelle-musique';
        mkdir($newRoot, 0777, true);
        $_POST = ['music_root' => $newRoot];

        $response = $this->captureJson(fn() => Api::config($this->temporaryDirectory));
        self::assertSame(401, $response->status);
        self::assertSame(['error' => 'Unauthorized'], $response->data);
    }

    public function testConfigRefusesMissingOrUnreadableRoot(): void
    {
        $this->createAndLoginUser();

        unset($_POST['music_root']);
        $missing = $this->captureJson(fn() => Api::config($this->temporaryDirectory));
        self::assertSame(400, $missing->status);
        self::assertSame(['error' => 'Indiquez le dossier contenant vos fichiers de musique.'], $missing->data);

        $_POST = ['music_root' => $this->temporaryDirectory . '/absent'];
        $unreadable = $this->captureJson(fn() => Api::config($this->temporaryDirectory));
        self::assertSame(400, $unreadable->status);
        self::assertSame(['error' => 'Le dossier de musique doit exister et être lisible par le serveur.'], $unreadable->data);
    }

    public function testConfigUpdatesTheConnectedAccountAndReinitialises(): void
    {
        $this->createAndLoginUser();

        $newRoot = $this->temporaryDirectory . '/nouvelle-musique';
        mkdir($newRoot, 0777, true);
        $_POST = ['music_root' => $newRoot];

        $response = $this->captureJson(fn() => Api::config($this->temporaryDirectory));
        self::assertSame(200, $response->status);
        self::assertArrayHasKey('ok', $response->data);
        self::assertTrue($response->data['ok']);
        self::assertSame($newRoot, $response->data['music_root']);
        self::assertArrayHasKey('scan_started', $response->data);
        self::assertSame($newRoot, App::musicRoot());

        $profile = Users::profile('testuser');
        self::assertIsArray($profile);
        self::assertSame($newRoot, $profile['music_root']);
        self::assertSame(Users::defaultDatabasePath('testuser'), $profile['db_path']);

        self::assertFileDoesNotExist($this->temporaryDirectory . '/config.local.php');

        $settings = $this->captureJson(static fn() => Api::settings());
        self::assertSame($newRoot, $settings->data['music_root']);
    }

    public function testConfigStartsWithABackgroundScanWhenIdle(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open est désactivé.');
        }

        $this->createAndLoginUser();

        mkdir($this->temporaryDirectory . '/bin', 0777, true);
        file_put_contents($this->temporaryDirectory . '/bin/scan.php', "<?php\n");
        mkdir($this->temporaryDirectory . '/data', 0777, true);

        $newRoot = $this->temporaryDirectory . '/autre-musique';
        mkdir($newRoot, 0777, true);
        $_POST = ['music_root' => $newRoot];

        $response = $this->captureJson(fn() => Api::config($this->temporaryDirectory));
        self::assertSame(200, $response->status);
        self::assertTrue($response->data['scan_started']);
        self::assertSame('1', DB::setting('scan_running'));

        usleep(300000);
        self::assertFileExists($this->temporaryDirectory . '/data/scan-testuser.log');
    }

    public function testScanRefusesWhenAlreadyRunning(): void
    {
        DB::setSetting('scan_running', '1');
        $response = $this->captureJson(static fn() => Api::scan());
        self::assertSame(['ok' => false, 'running' => true], $response->data);
        self::assertSame(200, $response->status);
    }

    public function testScanSpawnsBackgroundProcessAndRecordsStatus(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open est désactivé.');
        }

        mkdir($this->temporaryDirectory . '/bin', 0777, true);
        file_put_contents($this->temporaryDirectory . '/bin/scan.php', "<?php\n");
        mkdir($this->temporaryDirectory . '/data', 0777, true);

        $response = $this->captureJson(fn() => Api::scan($this->temporaryDirectory));
        self::assertSame(['ok' => true], $response->data);
        self::assertSame('1', DB::setting('scan_running'));
        self::assertNotNull(DB::setting('scan_started_at'));

        usleep(300000);
        self::assertFileExists($this->temporaryDirectory . '/data/scan-install.log');
    }

    public function testScanSpawnsFullBackgroundProcess(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open est désactivé.');
        }

        mkdir($this->temporaryDirectory . '/bin', 0777, true);
        mkdir($this->temporaryDirectory . '/data', 0777, true);
        file_put_contents(
            $this->temporaryDirectory . '/bin/scan.php',
            "<?php\nfile_put_contents(__DIR__ . '/../data/scan-argv.json', json_encode(\$_SERVER['argv']));\n"
        );

        $response = $this->captureJson(fn() => Api::scan($this->temporaryDirectory));
        self::assertSame(['ok' => true], $response->data);

        usleep(300000);
        $argv = json_decode((string) file_get_contents($this->temporaryDirectory . '/data/scan-argv.json'), true);
        self::assertIsArray($argv);
        self::assertContains('--full', $argv);
    }

    public function testStreamUsesTheIndexedFileAndReportsMissingFiles(): void
    {
        $_GET = ['transcode' => '0'];
        ob_start();
        Api::stream((string) $this->songOne);
        self::assertSame('abcdef', ob_get_clean());

        unlink($this->betaSongPath);
        $missing = $this->captureJson(fn() => Api::stream((string) $this->songThree));
        self::assertSame(404, $missing->status);
        self::assertSame(['error' => 'File missing'], $missing->data);

        $unknown = $this->captureJson(static fn() => Api::stream('999'));
        self::assertSame(404, $unknown->status);
    }

    public function testStreamMp3IsServedDirectlyEvenWhenTranscodeIsRequested(): void
    {
        $_GET = ['transcode' => '128'];
        ob_start();
        Api::stream((string) $this->songOne);
        self::assertSame('abcdef', ob_get_clean());
    }

    public function testStreamTranscodesLosslessSourcesAtTheRequestedBitrateAndStartOffset(): void
    {
        $fakeFfmpeg = $this->temporaryDirectory . '/fake-ffmpeg';
        file_put_contents($fakeFfmpeg, "#!/bin/sh\nprintf '%s ' \"\$@\"");
        chmod($fakeFfmpeg, 0700);
        App::init([
            'music_root' => $this->temporaryDirectory . '/music',
            'db_path' => $this->temporaryDirectory . '/muzik.sqlite',
            'ffmpeg' => $fakeFfmpeg,
            'transcode' => 0,
            'auth_user' => '',
            'auth_hash' => '',
        ]);

        $flacFile = $this->temporaryDirectory . '/fake.flac';
        file_put_contents($flacFile, 'FLAC-DUMMY');
        $row = App::pdo()->query('SELECT album_id, artist_id FROM songs WHERE id = ' . $this->songOne)->fetch();
        self::assertIsArray($row);
        $insert = App::pdo()->prepare(
            'INSERT INTO songs(album_id, artist_id, title, size, path, mtime) VALUES(?, ?, ?, 10, ?, 0)',
        );
        $insert->execute([$row['album_id'], $row['artist_id'], 'Fake', $flacFile]);
        $songId = App::pdo()->lastInsertId();

        $_GET = ['transcode' => '0'];
        ob_start();
        Api::stream((string) $songId);
        self::assertSame('FLAC-DUMMY', ob_get_clean());

        $_GET = ['transcode' => '128', 'start' => '12.5'];
        ob_start();
        Api::stream((string) $songId);
        $arguments = (string) ob_get_clean();
        self::assertStringContainsString($flacFile, $arguments);
        self::assertStringContainsString('-b:a 128k', $arguments);
        self::assertStringContainsString('-ss 12.5', $arguments);
    }

    public function testAlbumArtworkCanBeReadAndHeadSkipsItsBody(): void
    {
        ob_start();
        Api::art((string) $this->rockAlbum);
        self::assertSame('cover', ob_get_clean());

        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        ob_start();
        Api::art((string) $this->rockAlbum);
        self::assertSame('', ob_get_clean());

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['type' => 'artist'];
        ob_start();
        Api::art((string) $this->alphaArtist);
        self::assertSame('artist', ob_get_clean());
    }

    public function testAlbumDeletionOnlyTouchesFilesInsideTheMusicRoot(): void
    {
        $outside = $this->temporaryDirectory . '/outside.mp3';
        file_put_contents($outside, 'outside');
        App::pdo()->prepare(
            'INSERT INTO songs(album_id, artist_id, title, size, path, mtime) VALUES(?, ?, ?, ?, ?, ?)'
        )->execute([$this->jazzAlbum, $this->betaArtist, 'Outside', 7, $outside, 4]);

        self::assertFileExists($this->betaSongPath);
        self::assertFileExists($this->betaCoverPath);

        $albumId = (string) $this->jazzAlbum;
        $deleted = $this->captureJson(static fn() => Api::albumDelete($albumId));
        self::assertSame(['ok' => true, 'songs' => 2, 'files_deleted' => 1], $deleted->data);
        self::assertFileDoesNotExist($this->betaSongPath);
        self::assertFileDoesNotExist($this->betaCoverPath);
        self::assertFileExists($outside);
        self::assertSame(0, (int) App::pdo()->query("SELECT COUNT(*) FROM artists WHERE id = {$this->betaArtist}")->fetchColumn());
        self::assertSame(1, (int) App::pdo()->query('SELECT COUNT(*) FROM artists')->fetchColumn());
    }

    private function seedCatalogue(): void
    {
        $root = App::musicRoot();
        $alphaDirectory = $root . '/Alpha/First Album';
        $betaDirectory = $root . '/Beta/Blue Album';
        mkdir($alphaDirectory, 0777, true);
        mkdir($betaDirectory, 0777, true);
        $alphaArt = $root . '/Alpha/artist.jpg';
        $rockCover = $alphaDirectory . '/cover.jpg';
        $this->betaCoverPath = $betaDirectory . '/cover.jpg';
        file_put_contents($alphaArt, 'artist');
        file_put_contents($rockCover, 'cover');
        file_put_contents($this->betaCoverPath, 'cover');

        $db = App::pdo();
        $artist = $db->prepare('INSERT INTO artists(name, path, art_path) VALUES(?, ?, ?)');
        $artist->execute(['Alpha', $root . '/Alpha', $alphaArt]);
        $this->alphaArtist = (int) $db->lastInsertId();
        $artist->execute(['Beta', $root . '/Beta', null]);
        $this->betaArtist = (int) $db->lastInsertId();

        $album = $db->prepare('INSERT INTO albums(artist_id, name, year, path, art_path, genre) VALUES(?, ?, ?, ?, ?, ?)');
        $album->execute([$this->alphaArtist, 'First Album', 2001, $alphaDirectory, $rockCover, 'Rock']);
        $this->rockAlbum = (int) $db->lastInsertId();
        $album->execute([$this->betaArtist, 'Blue Album', 2002, $betaDirectory, $this->betaCoverPath, 'Jazz']);
        $this->jazzAlbum = (int) $db->lastInsertId();

        $song = $db->prepare('INSERT INTO songs(album_id, artist_id, title, track, disc, duration, bitrate, size, path, mtime, play_count, last_played) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $path = $alphaDirectory . '/01 Song One.mp3';
        file_put_contents($path, 'abcdef');
        $song->execute([$this->rockAlbum, $this->alphaArtist, 'Song One', 1, 1, 60, 320, 6, $path, 1, 3, '2026-09-14 10:00:00']);
        $this->songOne = (int) $db->lastInsertId();
        $path = $alphaDirectory . '/02 Another Song.mp3';
        file_put_contents($path, 'ghijkl');
        $song->execute([$this->rockAlbum, $this->alphaArtist, 'Another Song', 2, 1, 120, 256, 6, $path, 2, 0, null]);
        $this->songTwo = (int) $db->lastInsertId();
        $this->betaSongPath = $betaDirectory . '/01 Blue.mp3';
        file_put_contents($this->betaSongPath, 'blue');
        $song->execute([$this->jazzAlbum, $this->betaArtist, 'Blue Note', 1, 1, 60, 192, 4, $this->betaSongPath, 3, 0, null]);
        $this->songThree = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO favorites(song_id) VALUES(?)')->execute([$this->songOne]);
    }
}
