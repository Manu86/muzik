<?php

declare(strict_types=1);

final class StreamerTest extends TestCase
{
    private string $audioFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialiseApp();
        $this->audioFile = $this->temporaryDirectory . '/audio.mp3';
        file_put_contents($this->audioFile, '0123456789');
    }

    public function testDirectStreamReturnsTheWholeFile(): void
    {
        self::assertSame('0123456789', $this->stream());
        self::assertSame(200, http_response_code());
    }

    public function testDirectStreamSupportsExplicitAndSuffixRanges(): void
    {
        $_SERVER['HTTP_RANGE'] = 'bytes=2-5';
        self::assertSame('2345', $this->stream());
        self::assertSame(206, http_response_code());

        $_SERVER['HTTP_RANGE'] = 'bytes=6-';
        self::assertSame('6789', $this->stream());
        self::assertSame(206, http_response_code());

        $_SERVER['HTTP_RANGE'] = 'bytes=7-999';
        self::assertSame('789', $this->stream());
        self::assertSame(206, http_response_code());
    }

    public function testMalformedRangeFallsBackToTheWholeFile(): void
    {
        $_SERVER['HTTP_RANGE'] = 'not-a-byte-range';
        self::assertSame('0123456789', $this->stream());
        self::assertSame(200, http_response_code());

        $_SERVER['HTTP_RANGE'] = 'bytes=-3';
        self::assertSame('789', $this->stream());
        self::assertSame(206, http_response_code());
    }

    public function testRangeValidationRejectsUnsatisfiableBounds(): void
    {
        $method = new ReflectionMethod(Streamer::class, 'resolveRange');

        self::assertSame([0, 9, false], $method->invoke(null, 10, ''));
        self::assertSame([2, 5, true], $method->invoke(null, 10, 'bytes=2-5'));
        self::assertSame([7, 9, true], $method->invoke(null, 10, 'bytes=-3'));
        self::assertNull($method->invoke(null, 10, 'bytes=10-12'));
        self::assertNull($method->invoke(null, 10, 'bytes=8-4'));
    }

    public function testHeadRequestDoesNotEmitTheFile(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        self::assertSame('', $this->stream());
    }

    public function testTranscodeUsesConfiguredFfmpegAndNormalisesTheBitrate(): void
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

        ob_start();
        Streamer::transcode($this->audioFile, 42);
        $arguments = (string) ob_get_clean();

        self::assertStringContainsString($this->audioFile, $arguments);
        self::assertStringContainsString('-b:a 128k', $arguments);
    }

    public function testTranscodeHonoursAStartOffset(): void
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

        ob_start();
        Streamer::transcode($this->audioFile, 128, 12.5);
        $arguments = (string) ob_get_clean();

        self::assertStringContainsString('-ss 12.5', $arguments);
        self::assertStringContainsString('-b:a 128k', $arguments);
    }

    private function stream(): string
    {
        ob_start();
        Streamer::direct($this->audioFile);
        return (string) ob_get_clean();
    }
}
