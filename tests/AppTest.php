<?php

declare(strict_types=1);

final class AppTest extends TestCase
{
    public function testConfigurationDatabaseAndEscaping(): void
    {
        $root = $this->temporaryDirectory . '/music/';
        $this->initialiseApp($root);

        self::assertSame(rtrim($root, '/'), App::musicRoot());
        self::assertSame('/usr/bin/ffmpeg', App::config('ffmpeg'));
        self::assertNull(App::config('unknown'));
        self::assertIsArray(App::config());
        self::assertInstanceOf(PDO::class, App::pdo());
        self::assertSame('&lt;b&gt;&quot;Muzik&quot;&lt;/b&gt;', App::e('<b>"Muzik"</b>'));
    }

    public function testJsonAndErrorResponsesCanBeCaptured(): void
    {
        $response = $this->captureJson(static fn() => App::json(['é' => '/'], 201));
        self::assertSame(201, $response->status);
        self::assertSame(['é' => '/'], $response->data);

        $error = $this->captureJson(static fn() => App::err('Invalid', 422));
        self::assertSame(422, $error->status);
        self::assertSame(['error' => 'Invalid'], $error->data);
    }

    public function testRawConfigurationIsValidatedBeforeInitialisation(): void
    {
        foreach ([null, [], ['music_root' => '/tmp']] as $invalid) {
            try {
                App::initConfig($invalid);
                self::fail('Une configuration invalide aurait dû être rejetée.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        App::initConfig([
            'music_root' => $this->temporaryDirectory . '/music',
            'db_path' => $this->temporaryDirectory . '/validated.sqlite',
            'ffmpeg' => '/custom/ffmpeg',
            'transcode' => 192,
            'auth_user' => 'testuser',
            'auth_hash' => '$2y$10$II3h8YiIvgXKdcX.Zniw8OO9c2Iv/DGhJYJqdh16HvUJVr1xcF49C',
        ]);
        self::assertSame('/custom/ffmpeg', App::ffmpeg());
        self::assertSame(192, App::transcodeBitrate());
        self::assertSame('testuser', App::config('auth_user'));
        $authHash = App::config('auth_hash');
        self::assertIsString($authHash);
        self::assertStringStartsWith('$2y$', $authHash);
    }
}
