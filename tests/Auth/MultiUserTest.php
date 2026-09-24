<?php

declare(strict_types=1);

final class MultiUserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->initialiseApp();
    }

    public function testAuthIsDisabledWhenNoAccountExists(): void
    {
        self::assertFalse(Auth::enabled());
        self::assertTrue(Auth::check());
        self::assertNull(Auth::currentLogin());
    }

    public function testAuthIsEnabledWhenAnAccountExists(): void
    {
        Users::create('paul', 'S3cretP@ss', $this->temporaryDirectory . '/music');

        self::assertTrue(Auth::enabled());
        self::assertFalse(Auth::check());
        self::assertNull(Auth::currentLogin());
    }

    public function testAttemptChecksCredentialsAgainstTheAccount(): void
    {
        Users::create('paul', 'S3cretP@ss', $this->temporaryDirectory . '/music');

        self::assertTrue(Auth::attempt('paul', 'S3cretP@ss'));
        self::assertSame('paul', Auth::currentLogin());

        Auth::logout();
        self::assertFalse(Auth::attempt('paul', 'mauvais'));
        self::assertFalse(Auth::attempt('inconnu', 'S3cretP@ss'));

        Auth::logout();
        self::assertTrue(Auth::attempt('PAUL', 'S3cretP@ss'));
        self::assertSame('paul', Auth::currentLogin());
    }

    public function testEachAccountHasItsOwnCatalogueAndMusicRoot(): void
    {
        Users::create('paul', 'S3cretP@ss', '/musique/de/paul');
        Users::create('anna', 'S3cretP@ss', '/studio/d/anna');

        $paul = Users::resolveConfig('paul', ['music_root' => '', 'db_path' => '']);
        $anna = Users::resolveConfig('anna', ['music_root' => '', 'db_path' => '']);

        self::assertSame('/musique/de/paul', $paul['music_root']);
        self::assertSame('/studio/d/anna', $anna['music_root']);
        self::assertNotSame($paul['db_path'], $anna['db_path']);
        self::assertSame(
            Users::defaultDatabasePath('paul'),
            $paul['db_path'],
        );
    }

    public function testSettingsExposeTheConnectedLoginAndAuthState(): void
    {
        $this->createAndLoginUser('paul');

        $settings = $this->captureJson(static fn() => SettingsController::settings());

        self::assertSame('paul', $settings->data['user']);
        self::assertTrue($settings->data['auth_enabled']);
    }

    public function testAccountsAreSealedOnceProtectionIsOn(): void
    {
        Users::create('paul', 'S3cretP@ss', $this->temporaryDirectory . '/music');
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $blocked = $this->captureJson(static fn() => Router::handle('/api/summary', 'GET'));
        self::assertSame(401, $blocked->status);
    }
}
