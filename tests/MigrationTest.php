<?php

declare(strict_types=1);

final class MigrationTest extends TestCase
{
    public function testLegacyInstallationWithDbPathRelativeToTheProjectIsMigrated(): void
    {
        $this->initialiseApp();
        $dummyDatabase = file_put_contents($this->temporaryDirectory . '/muzik.db', '');
        self::assertNotFalse($dummyDatabase);
        file_put_contents(
            $this->temporaryDirectory . '/config.local.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'music_root' => '/mnt/nas/musique',
    'db_path' => __DIR__ . '/muzik.db',
    'ffmpeg' => '/usr/bin/ffmpeg',
    'transcode' => 192,
    'auth_user' => 'emmanuel',
    'auth_hash' => '$2y$10$examplehash',
];
PHP,
        );

        Users::ensureSchema($this->temporaryDirectory);

        $profile = Users::find('emmanuel');
        self::assertIsArray($profile);
        self::assertSame('/mnt/nas/musique', $profile['music_root']);
        self::assertSame($this->temporaryDirectory . '/muzik.db', $profile['db_path']);
    }

    public function testMigrationKeepsTheLegacyPasswordWorking(): void
    {
        $this->initialiseApp();
        $hash = password_hash('S3cretP@ss', PASSWORD_BCRYPT);
        file_put_contents(
            $this->temporaryDirectory . '/config.local.php',
            "<?php\nreturn ['music_root'=>'/m','db_path'=>'/m/muzik.db','ffmpeg'=>'','transcode'=>0,'auth_user'=>'emmanuel','auth_hash'=>" . var_export($hash, true) . "];\n",
        );

        Users::ensureSchema($this->temporaryDirectory);

        self::assertTrue(Auth::enabled());
        self::assertTrue(Auth::attempt('emmanuel', 'S3cretP@ss'));
        self::assertSame('emmanuel', Auth::currentLogin());
    }

    public function testMigrationIsIdempotentAcrossCalls(): void
    {
        $this->initialiseApp();
        file_put_contents(
            $this->temporaryDirectory . '/config.local.php',
            "<?php\nreturn ['music_root'=>'/m','db_path'=>'/m/muzik.db','ffmpeg'=>'','transcode'=>0,'auth_user'=>'emmanuel','auth_hash'=>'\$2y\$10\$hash'];\n",
        );

        Users::ensureSchema($this->temporaryDirectory);
        Users::ensureSchema($this->temporaryDirectory);
        Users::ensureSchema($this->temporaryDirectory);

        self::assertSame(1, Users::count());
    }
}
