<?php

declare(strict_types=1);

final class UsersTest extends TestCase
{
    public function testCanCreateFindListAndCountAccounts(): void
    {
        $this->initialiseApp();
        self::assertSame(0, Users::count());

        Users::create('paul', 'S3cretP@ss', '/tmp/paulo');
        Users::create('anna', 'S3cretP@ss', '/tmp/anna');

        self::assertSame(2, Users::count());
        self::assertSame(
            ['anna', 'paul'],
            array_column(Users::all(), 'login'),
        );

        $paul = Users::find('paul');
        self::assertIsArray($paul);
        self::assertSame('paul', $paul['login']);
        self::assertSame('/tmp/paulo', $paul['music_root']);
        self::assertSame(Users::defaultDatabasePath('paul'), $paul['db_path']);
        self::assertTrue(password_verify('S3cretP@ss', $paul['auth_hash']));
    }

    public function testLoginIsNormalisedToLowerCaseAndUsableAsFilename(): void
    {
        self::assertSame('emmanuel', Users::normalizeLogin('Emmanuel'));
        self::assertSame('jean-pierre', Users::normalizeLogin(' Jean-Pierre '));
        self::assertSame('a_1', Users::normalizeLogin('A_1'));
        self::assertSame('', Users::normalizeLogin('Deux Mots'));
        self::assertSame('', Users::normalizeLogin(''));
        self::assertSame('', Users::normalizeLogin('-abc'));
        self::assertSame('1', Users::normalizeLogin('1'));
        self::assertSame('', Users::normalizeLogin(str_repeat('a', 33)));
        self::assertSame(str_repeat('a', 32), Users::normalizeLogin(str_repeat('a', 32)));
    }

    public function testCreateRejectsInvalidLoginShortPasswordOrMissingRoot(): void
    {
        $this->initialiseApp();

        try {
            Users::create('Inva lid', 'S3cretP@ss', '/tmp/m');
            self::fail('Identifiant invalide accepté.');
        } catch (InvalidArgumentException $expected) {
            self::assertSame(0, Users::count());
        }

        try {
            Users::create('paul', 'short', '/tmp/m');
            self::fail('Mot de passe trop court accepté.');
        } catch (InvalidArgumentException $expected) {
            self::assertSame(0, Users::count());
        }

        try {
            Users::create('paul', 'S3cretP@ss', '   ');
            self::fail('music_root vide accepté.');
        } catch (InvalidArgumentException $expected) {
            self::assertSame(0, Users::count());
        }
    }

    public function testCreateRejectsADuplicateLogin(): void
    {
        $this->initialiseApp();
        Users::create('paul', 'S3cretP@ss', '/tmp/m');

        $this->expectException(RuntimeException::class);
        Users::create('paul', 'AutreP@ss', '/tmp/m2');
    }

    public function testUpdateMusicRootAndPassword(): void
    {
        $this->initialiseApp();
        Users::create('paul', 'S3cretP@ss', '/tmp/m');

        Users::updateMusicRoot('paul', '/tmp/nouveau');
        $musicRoot = Users::profile('paul');
        self::assertIsArray($musicRoot);
        self::assertSame('/tmp/nouveau', $musicRoot['music_root']);

        Users::updatePassword('paul', 'NouveauP@ss1');
        $paul = Users::find('paul');
        self::assertIsArray($paul);
        self::assertTrue(password_verify('NouveauP@ss1', $paul['auth_hash']));
        self::assertFalse(password_verify('S3cretP@ss', $paul['auth_hash']));
    }

    public function testDeleteRemovesOnlyTheAccountRow(): void
    {
        $this->initialiseApp();
        Users::create('paul', 'S3cretP@ss', '/tmp/m');
        $database = Users::defaultDatabasePath('paul');
        file_put_contents($database, '');

        Users::delete('paul');

        self::assertNull(Users::profile('paul'));
        self::assertSame(0, Users::count());
        self::assertFileExists($database);
    }

    public function testCreateSeedsTheCanonicalGenresInTheCatalogue(): void
    {
        $this->initialiseApp();
        Users::create('marie', 'S3cretP@ss', '/tmp/musique-marie');

        $catalogue = DB::init(Users::defaultDatabasePath('marie'));
        DB::schema();
        $genres = $catalogue->query('SELECT name FROM genres ORDER BY name')->fetchColumnValues();

        self::assertSame(Genre::GENRES, $genres);
        self::assertNotSame('', implode('', $genres));
    }

    public function testCreatedCatalogListsTheCanonicalGenresThroughTheApi(): void
    {
        $this->initialiseApp();
        Users::create('marie', 'S3cretP@ss', '/tmp/musique-marie');
        App::initConfig(Users::resolveConfig('marie', Users::baseConfig()));

        $rows = $this->captureJson(static fn() => CatalogController::genres())->data;
        $expected = Genre::GENRES;
        sort($expected);

        self::assertSame($expected, array_column($rows, 'genre'));
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $albumCount = $row['album_count'];
            $songCount = $row['song_count'];
            self::assertIsNumeric($albumCount);
            self::assertIsNumeric($songCount);
            self::assertSame(0, (int) $albumCount);
            self::assertSame(0, (int) $songCount);
        }
    }

    public function testImportDoesNotCreateTheCatalog(): void
    {
        $this->initialiseApp();
        $hash = password_hash('S3cretP@ss', PASSWORD_BCRYPT);

        Users::import('emmanuel', $hash, '/tmp/m', '/tmp/legacy.db');

        self::assertFileDoesNotExist('/tmp/legacy.db');
        $profile = Users::find('emmanuel');
        self::assertIsArray($profile);
        self::assertSame('/tmp/legacy.db', $profile['db_path']);
    }

    public function testImportStoresAProvidedBcryptHash(): void
    {
        $this->initialiseApp();
        $hash = password_hash('S3cretP@ss', PASSWORD_BCRYPT);

        Users::import('emmanuel', $hash, '/tmp/m', '/tmp/legacy.db');

        $profile = Users::find('emmanuel');
        self::assertIsArray($profile);
        self::assertSame($hash, $profile['auth_hash']);
        self::assertSame('/tmp/legacy.db', $profile['db_path']);
    }

    public function testResolveConfigMergesProfileWithBaseAndClearsAuthKeys(): void
    {
        $this->initialiseApp();
        Users::create('paul', 'S3cretP@ss', '/tmp/paulo');
        $base = [
            'music_root' => '/base',
            'db_path' => '/base.db',
            'ffmpeg' => '/usr/bin/ffmpeg',
            'transcode' => 192,
            'auth_user' => 'x',
            'auth_hash' => 'h',
        ];

        $config = Users::resolveConfig('paul', $base);

        self::assertSame('/tmp/paulo', $config['music_root']);
        self::assertSame('/usr/bin/ffmpeg', $config['ffmpeg']);
        self::assertSame(192, $config['transcode']);
        self::assertSame('', $config['auth_user']);
        self::assertSame('', $config['auth_hash']);
        self::assertSame(Users::defaultDatabasePath('paul'), $config['db_path']);
    }

    public function testResolveConfigThrowsForAnUnknownUser(): void
    {
        $this->initialiseApp();
        $this->expectException(RuntimeException::class);
        Users::resolveConfig('inconnu', ['music_root' => '', 'db_path' => '']);
    }
}
