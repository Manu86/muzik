<?php

declare(strict_types=1);

final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->initialiseApp();
    }

    public function testBasePrefixQueryStringAndHeadAreHandled(): void
    {
        $response = $this->captureJson(static fn() => Router::handle('/muzik/api/summary?ignored=1', 'HEAD'));
        self::assertSame(200, $response->status);
        self::assertSame(0, $response->data['songs']);
    }

    public function testArtistsLetterSelectsTheSpecificHandler(): void
    {
        App::pdo()->exec("INSERT INTO artists(name, path) VALUES('Beta', '/beta')");
        $_GET = ['letter' => 'B'];

        $response = $this->captureJson(static fn() => Router::handle('/api/artists?letter=B', 'GET'));
        $artist = $response->data[0];
        self::assertIsArray($artist);
        self::assertSame('Beta', $artist['name']);
    }

    public function testPingIsALightweightContactPoint(): void
    {
        $response = $this->captureJson(static fn() => Router::handle('/api/ping', 'GET'));
        self::assertSame(200, $response->status);
        self::assertSame(['ok' => true], $response->data);

        $head = $this->captureJson(static fn() => Router::handle('/api/ping', 'HEAD'));
        self::assertSame(200, $head->status);
        self::assertSame(['ok' => true], $head->data);
    }

    public function testUnknownRouteAndUnsupportedMethodReturnErrors(): void
    {
        $missing = $this->captureJson(static fn() => Router::handle('/api/unknown', 'GET'));
        self::assertSame(404, $missing->status);
        self::assertSame(['error' => 'Not found'], $missing->data);

        $method = $this->captureJson(static fn() => Router::handle('/api/summary', 'OPTIONS'));
        self::assertSame(405, $method->status);
        self::assertSame(['error' => 'Method not allowed'], $method->data);
    }

    public function testAlbumPatchUpdatesTheAlbum(): void
    {
        App::pdo()->exec("INSERT INTO artists(id, name, path) VALUES(1, 'A', '/a')");
        App::pdo()->exec("INSERT INTO albums(id, artist_id, name, year, path) VALUES(1, 1, 'Old', 2000, '/x')");
        $_GET = ['name' => 'New', 'year' => '1987'];

        $response = $this->captureJson(static fn() => Router::handle('/api/album/1', 'PATCH'));
        self::assertSame(200, $response->status);
        self::assertSame('New', $response->data['name']);
        self::assertSame(1987, $response->data['year']);
    }

    public function testAuthEndpointsAreAccessibleWhenProtectionIsOff(): void
    {
        $auth = $this->captureJson(static fn() => Router::handle('/api/auth', 'GET'));
        self::assertSame(200, $auth->status);
        self::assertTrue($auth->data['authenticated']);
        self::assertNull($auth->data['user']);

        $login = $this->captureJson(static fn() => Router::handle('/api/login', 'POST'));
        self::assertSame(200, $login->status);
        self::assertTrue($login->data['ok']);
        self::assertNull($login->data['user']);

        $logout = $this->captureJson(static fn() => Router::handle('/api/logout', 'POST'));
        self::assertSame(200, $logout->status);
        self::assertTrue($logout->data['ok']);
    }

    public function testProtectedRouteReturns401UntilLoginSetsASession(): void
    {
        $hash = password_hash('S3cretP@ss', PASSWORD_BCRYPT);
        App::init([
            'music_root' => $this->temporaryDirectory . '/music',
            'db_path' => $this->temporaryDirectory . '/muzik.sqlite',
            'ffmpeg' => '/usr/bin/ffmpeg',
            'transcode' => 0,
            'auth_user' => 'testuser',
            'auth_hash' => $hash,
        ]);

        $blocked = $this->captureJson(static fn() => Router::handle('/api/summary', 'GET'));
        self::assertSame(401, $blocked->status);
        self::assertSame(['error' => 'Unauthorized'], $blocked->data);

        $before = $this->captureJson(static fn() => Router::handle('/api/auth', 'GET'));
        self::assertSame(200, $before->status);
        self::assertFalse($before->data['authenticated']);

        $_POST = ['user' => 'testuser', 'pass' => 'S3cretP@ss'];
        $login = $this->captureJson(static fn() => Router::handle('/api/login', 'POST'));
        self::assertSame(200, $login->status);
        self::assertSame(['ok' => true, 'user' => 'testuser'], $login->data);

        $after = $this->captureJson(static fn() => Router::handle('/api/auth', 'GET'));
        self::assertSame(200, $after->status);
        self::assertTrue($after->data['authenticated']);

        $unlocked = $this->captureJson(static fn() => Router::handle('/api/summary', 'GET'));
        self::assertSame(200, $unlocked->status);

        $logout = $this->captureJson(static fn() => Router::handle('/api/logout', 'POST'));
        self::assertSame(200, $logout->status);

        $afterLogout = $this->captureJson(static fn() => Router::handle('/api/auth', 'GET'));
        self::assertSame(200, $afterLogout->status);
        self::assertFalse($afterLogout->data['authenticated']);

        $_POST = [];
        Auth::logout();
    }

    public function testLoginRejectsWrongCredentials(): void
    {
        $hash = password_hash('S3cretP@ss', PASSWORD_BCRYPT);
        App::init([
            'music_root' => $this->temporaryDirectory . '/music',
            'db_path' => $this->temporaryDirectory . '/muzik.sqlite',
            'ffmpeg' => '/usr/bin/ffmpeg',
            'transcode' => 0,
            'auth_user' => 'testuser',
            'auth_hash' => $hash,
        ]);

        $_POST = ['user' => 'testuser', 'pass' => 'mauvais'];
        $response = $this->captureJson(static fn() => Router::handle('/api/login', 'POST'));
        self::assertSame(401, $response->status);
        self::assertSame(['error' => 'Unauthorized'], $response->data);

        $_POST = [];
        Auth::logout();
    }

    public function testLoginWithRememberPersistsTheSession(): void
    {
        $hash = password_hash('S3cretP@ss', PASSWORD_BCRYPT);
        App::init([
            'music_root' => $this->temporaryDirectory . '/music',
            'db_path' => $this->temporaryDirectory . '/muzik.sqlite',
            'ffmpeg' => '/usr/bin/ffmpeg',
            'transcode' => 0,
            'auth_user' => 'testuser',
            'auth_hash' => $hash,
        ]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $_POST = ['user' => 'testuser', 'pass' => 'S3cretP@ss', 'remember' => '1'];
        $login = $this->captureJson(static fn() => Router::handle('/api/login', 'POST'));
        self::assertSame(200, $login->status);
        self::assertSame(2592000, session_get_cookie_params()['lifetime']);

        $_POST = [];
        Auth::logout();
    }
}
