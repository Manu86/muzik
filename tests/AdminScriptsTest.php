<?php

declare(strict_types=1);

final class AdminScriptsTest extends TestCase
{
    public function testTagWriterReportsMissingAndUnsupportedFilesWithoutTouchingProduction(): void
    {
        $unsupported = $this->temporaryDirectory . '/track.txt';
        file_put_contents($unsupported, 'not audio');
        $plan = $this->temporaryDirectory . '/plan.json';
        file_put_contents($plan, json_encode([
            ['path' => $this->temporaryDirectory . '/missing.mp3', 'title' => 'Missing'],
            ['path' => $unsupported, 'title' => 'Unsupported'],
        ], JSON_THROW_ON_ERROR));

        [$status, $output] = $this->runCommand([
            'python3',
            dirname(__DIR__) . '/bin/tag_apply.py',
            $plan,
        ]);

        self::assertSame(0, $status);
        self::assertStringContainsString('MANQUANT:', $output);
        self::assertStringContainsString('SKIP format:', $output);
        self::assertStringContainsString('Taggés : 0, échecs : 2', $output);
        self::assertSame('not audio', file_get_contents($unsupported));
    }

    public function testTagAuditNormalisationHelpersHandleListsNumbersAndGenres(): void
    {
        $script = dirname(__DIR__) . '/bin/check-tags.py';
        $python = <<<'PYTHON'
import importlib.util, json, sys
spec = importlib.util.spec_from_file_location("check_tags", sys.argv[1])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
print(json.dumps({
    "genre": module.canon_genre(" hip-hop/rap "),
    "norm": module.norm("  Mixed   Case "),
    "first": module.first(["one", "two"]),
    "integer": module.first_int("03/12"),
}))
PYTHON;

        [$status, $output] = $this->runCommand(['python3', '-c', $python, $script]);
        self::assertSame(0, $status);
        $values = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            'genre' => 'Rap/Hip Hop',
            'norm' => 'mixed case',
            'first' => 'one',
            'integer' => 3,
        ], $values);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function runCommand(array $arguments): array
    {
        $command = implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
        $lines = [];
        exec($command, $lines, $status);

        return [$status, implode("\n", $lines)];
    }
}
