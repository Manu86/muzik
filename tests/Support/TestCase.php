<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $directory = tempnam(sys_get_temp_dir(), 'muzik-test-');
        self::assertNotFalse($directory);
        unlink($directory);
        mkdir($directory, 0777, true);
        $this->temporaryDirectory = $directory;

        Users::reset();
        Users::init($this->temporaryDirectory . '/users.db');

        App::setJsonResponder(static function (mixed $data, int $status): never {
            throw new CapturedJsonResponse($data, $status);
        });
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_RANGE'], $_SERVER['QUERY_STRING']);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        App::setJsonResponder(null);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->removeDirectory($this->temporaryDirectory);
        $_GET = [];
        $_POST = [];
        unset($_SERVER['HTTP_RANGE'], $_SERVER['QUERY_STRING']);
        Users::reset();
        parent::tearDown();
    }

    protected function initialiseApp(?string $musicRoot = null): void
    {
        $musicRoot ??= $this->temporaryDirectory . '/music';
        if (!is_dir($musicRoot)) {
            mkdir($musicRoot, 0777, true);
        }
        App::init([
            'music_root' => $musicRoot,
            'db_path' => $this->temporaryDirectory . '/muzik.sqlite',
            'ffmpeg' => '/usr/bin/ffmpeg',
            'transcode' => 0,
            'auth_user' => '',
            'auth_hash' => '',
        ]);
    }

    /** @param callable(): void $callback */
    protected function captureJson(callable $callback): CapturedJsonResponse
    {
        try {
            $callback();
        } catch (CapturedJsonResponse $response) {
            return $response;
        }

        self::fail('The callback did not emit a JSON response.');
    }

    /**
     * Crée un compte utilisateur et ouvre une session pour celui-ci.
     */
    protected function createAndLoginUser(string $login = 'testuser', string $password = 'S3cretP@ss'): string
    {
        Users::create($login, $password, $this->temporaryDirectory . '/music');
        if (!Auth::attempt($login, $password)) {
            self::fail('Impossible d\'ouvrir la session de test.');
        }

        return $login;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                throw new RuntimeException('Unexpected directory entry.');
            }
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
