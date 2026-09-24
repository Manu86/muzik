<?php

declare(strict_types=1);

final class InstallerTest extends TestCase
{
    public function testRequirementsListsEveryCheck(): void
    {
        $this->initialiseApp();
        mkdir($this->temporaryDirectory . '/data', 0777, true);

        $requirements = Installer::requirements($this->temporaryDirectory);

        $checks = array_column($requirements, 'check');
        self::assertContains('PHP 8.1 ou supérieur', $checks);
        foreach (['pdo_sqlite', 'mbstring', 'json', 'fileinfo'] as $extension) {
            self::assertContains('Extension ' . $extension, $checks);
        }
        self::assertContains('Dépendances PHP (vendor/)', $checks);
        self::assertContains('FFmpeg (transcodage)', $checks);
        self::assertContains('Écriture dans data/', $checks);
        self::assertNotContains('Écriture à la racine du projet', $checks);

        foreach ($requirements as $check) {
            self::assertArrayHasKey('check', $check);
            self::assertArrayHasKey('ok', $check);
            self::assertArrayHasKey('detail', $check);
            self::assertArrayHasKey('level', $check);
            self::assertContains($check['level'], ['required', 'optional']);
        }
    }

    public function testRequirementsReportsVendorStatus(): void
    {
        $this->initialiseApp();

        $requirements = Installer::requirements($this->temporaryDirectory);
        $vendor = null;
        foreach ($requirements as $check) {
            if ($check['check'] === 'Dépendances PHP (vendor/)') {
                $vendor = $check;
                break;
            }
        }
        self::assertNotNull($vendor);
        self::assertSame('required', $vendor['level']);
        self::assertFalse($vendor['ok']);

        mkdir($this->temporaryDirectory . '/vendor', 0777, true);
        file_put_contents($this->temporaryDirectory . '/vendor/autoload.php', "<?php\n");

        $requirements = Installer::requirements($this->temporaryDirectory);
        $vendor = null;
        foreach ($requirements as $check) {
            if ($check['check'] === 'Dépendances PHP (vendor/)') {
                $vendor = $check;
                break;
            }
        }
        self::assertNotNull($vendor);
        self::assertTrue($vendor['ok']);
    }

    public function testDetectFfmpegReturnsAnExecutableOrNull(): void
    {
        $ffmpeg = Installer::detectFfmpeg();

        if ($ffmpeg === null) {
            self::markTestSkipped('FFmpeg absent sur cette machine.');
        }

        self::assertFileExists($ffmpeg);
        self::assertTrue(is_executable($ffmpeg));
    }

    public function testWriteConfigProducesALoadablePhpFile(): void
    {
        $target = $this->temporaryDirectory . '/config.local.php';

        $written = Installer::writeConfig($target, [
            'music_root' => '/tmp/music',
            'db_path' => '/tmp/data.db',
            'ffmpeg' => '',
            'transcode' => 192,
        ]);

        self::assertTrue($written);
        $config = require $target;
        self::assertIsArray($config);
        self::assertSame('/tmp/music', $config['music_root']);
        self::assertSame('/tmp/data.db', $config['db_path']);
        self::assertSame('', $config['ffmpeg']);
        self::assertSame(192, $config['transcode']);
        self::assertSame('', $config['auth_user']);
        self::assertSame('', $config['auth_hash']);
    }

    public function testWriteConfigKeepsProvidedAuthenticationKeys(): void
    {
        $target = $this->temporaryDirectory . '/config.local.php';

        Installer::writeConfig($target, [
            'music_root' => '/tmp/music',
            'db_path' => '/tmp/data.db',
            'ffmpeg' => '',
            'transcode' => 0,
            'auth_user' => 'testuser',
            'auth_hash' => '$2y$10$abc',
        ]);

        $config = require $target;
        self::assertIsArray($config);
        self::assertSame('testuser', $config['auth_user']);
        self::assertSame('$2y$10$abc', $config['auth_hash']);
    }

    public function testHashPasswordProducesAnAusableBcryptHash(): void
    {
        $hash = Installer::hashPassword('S3cretP@ss');

        self::assertStringStartsWith('$2y$', $hash);
        self::assertTrue(password_verify('S3cretP@ss', $hash));
        self::assertFalse(password_verify('wrong', $hash));
    }

    public function testInstalledIsFalseBeforeAnyAccount(): void
    {
        $this->initialiseApp();

        self::assertFalse(Installer::installed($this->temporaryDirectory));
    }

    public function testInstalledBecomesTrueWhenAnAccountExists(): void
    {
        $this->initialiseApp();
        Users::create('paul', 'S3cretP@ss', $this->temporaryDirectory . '/music');

        self::assertTrue(Installer::installed($this->temporaryDirectory));
    }

    public function testInstalledMigratesALegacyConfigFileIntoAnAccount(): void
    {
        $this->initialiseApp();
        $hash = password_hash('S3cretP@ss', PASSWORD_BCRYPT);
        Installer::writeConfig($this->temporaryDirectory . '/config.local.php', [
            'music_root' => '/tmp/music',
            'db_path' => '/tmp/data.db',
            'ffmpeg' => '',
            'transcode' => 0,
            'auth_user' => 'Emmanuel',
            'auth_hash' => $hash,
        ]);

        self::assertTrue(Installer::installed($this->temporaryDirectory));

        $profile = Users::find('emmanuel');
        self::assertIsArray($profile);
        self::assertSame('emmanuel', $profile['login']);
        self::assertSame($hash, $profile['auth_hash']);
        self::assertSame('/tmp/music', $profile['music_root']);
        self::assertSame('/tmp/data.db', $profile['db_path']);
        self::assertSame(1, Users::count());
    }

    public function testInstalledDoesNotDuplicateAnExistingAccountDuringMigration(): void
    {
        $this->initialiseApp();
        Users::create('emmanuel', 'S3cretP@ss', $this->temporaryDirectory . '/music');
        $hash = password_hash('S3cretP@ss', PASSWORD_BCRYPT);
        Installer::writeConfig($this->temporaryDirectory . '/config.local.php', [
            'music_root' => '/tmp/music',
            'db_path' => '/tmp/data.db',
            'ffmpeg' => '',
            'transcode' => 0,
            'auth_user' => 'emmanuel',
            'auth_hash' => $hash,
        ]);

        Installer::installed($this->temporaryDirectory);

        self::assertSame(1, Users::count());
    }

    public function testCountAudioFilesCountsOnlyRecognisedExtensions(): void
    {
        $music = $this->temporaryDirectory . '/music';
        mkdir($music . '/artiste/album', 0777, true);
        mkdir($music . '/autres', 0777, true);
        file_put_contents($music . '/artiste/album/01-piste.mp3', 'x');
        file_put_contents($music . '/artiste/piste.flac', 'x');
        file_put_contents($music . '/autres/notes.txt', 'x');
        file_put_contents($music . '/autres/cover.jpg', 'x');

        self::assertSame(2, Installer::countAudioFiles($music));
    }

    public function testCountAudioFilesReturnsZeroForMissingRoot(): void
    {
        self::assertSame(0, Installer::countAudioFiles($this->temporaryDirectory . '/absent'));
    }

    public function testStartBackgroundScanReturnsFalseWithoutScript(): void
    {
        $this->initialiseApp();
        self::assertFalse(Installer::startBackgroundScan($this->temporaryDirectory));
    }

    public function testStartBackgroundScanSpawnsAPerUserInstallerLog(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open est désactivé.');
        }

        $this->initialiseApp();
        mkdir($this->temporaryDirectory . '/bin', 0777, true);
        file_put_contents($this->temporaryDirectory . '/bin/scan.php', "<?php\n");
        mkdir($this->temporaryDirectory . '/data', 0777, true);

        self::assertTrue(Installer::startBackgroundScan($this->temporaryDirectory, 'testuser'));

        usleep(300000);
        self::assertFileExists($this->temporaryDirectory . '/data/scan-testuser.log');
    }
}
