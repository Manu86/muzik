<?php

declare(strict_types=1);

final class DiagTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->initialiseApp();
    }

    public function testDiagnosticPayloadIsPersistedNextToTheDatabase(): void
    {
        $_POST['log'] = json_encode([
            'log' => [
                ['t' => '12:00:00.000', 'e' => 'playing', 'd' => 'detail', 'h' => 1, 'ct' => 3.5, 'ns' => 2, 'rs' => 4, 'buf' => 42],
                ['t' => '12:00:01.000', 'e' => 'heartbeat'],
            ],
        ], JSON_THROW_ON_ERROR);
        $response = $this->captureJson(static fn() => SettingsController::diag());
        self::assertSame(200, $response->status);
        self::assertTrue($response->data['ok']);

        $directory = $this->temporaryDirectory;
        $files = glob($directory . '/diag-*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $stored = json_decode((string) file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        self::assertCount(2, $stored);
        $first = $stored[0];
        self::assertIsArray($first);
        self::assertSame('playing', $first['e']);
        self::assertSame('detail', $first['d']);
        self::assertSame(1, $first['h']);
        self::assertSame(4, $first['rs']);
    }

    public function testDiagnosticPayloadIsRejectedWhenEmptyOrMalformed(): void
    {
        $_POST = [];
        $empty = $this->captureJson(static fn() => SettingsController::diag());
        self::assertSame(400, $empty->status);

        $_POST['log'] = '{"log": []}';
        $noEntries = $this->captureJson(static fn() => SettingsController::diag());
        self::assertSame(400, $noEntries->status);

        $_POST['log'] = '{"foo": "bar"}';
        $noLogKey = $this->captureJson(static fn() => SettingsController::diag());
        self::assertSame(400, $noLogKey->status);

        self::assertSame([], glob($this->temporaryDirectory . '/diag-*.json') ?: []);
    }
}
