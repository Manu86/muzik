<?php

declare(strict_types=1);

final class DocumentationTest extends TestCase
{
    public function testHumanAgentAndMachineDocumentationExists(): void
    {
        $root = dirname(__DIR__);
        foreach (['README.md', 'AGENTS.md', 'openapi.yaml'] as $document) {
            $path = $root . '/' . $document;
            self::assertFileExists($path);
            self::assertGreaterThan(500, filesize($path));
        }
    }

    public function testOpenApiDocumentsEveryRouterPath(): void
    {
        $root = dirname(__DIR__);
        $router = file_get_contents($root . '/src/Router.php');
        $openApi = file_get_contents($root . '/openapi.yaml');
        self::assertNotFalse($router);
        self::assertNotFalse($openApi);

        preg_match_all("/\\['(api\\/[^']+)'/", $router, $routerMatches);
        $routerPaths = array_map(
            static fn(string $route): string => '/' . str_replace('(\\d+)', '{id}', $route),
            $routerMatches[1],
        );
        preg_match_all('/^  (\/api\/[^:]+):$/m', $openApi, $openApiMatches);

        $routerPaths = array_values(array_unique($routerPaths));
        $openApiPaths = array_values(array_unique($openApiMatches[1]));
        sort($routerPaths);
        sort($openApiPaths);

        self::assertSame($routerPaths, $openApiPaths);
    }

    public function testOpenApiOperationIdentifiersAreUnique(): void
    {
        $openApi = file_get_contents(dirname(__DIR__) . '/openapi.yaml');
        self::assertNotFalse($openApi);
        preg_match_all('/^      operationId: (.+)$/m', $openApi, $matches);

        self::assertNotEmpty($matches[1]);
        self::assertSame($matches[1], array_values(array_unique($matches[1])));
    }

    public function testOpenApiDocumentsEveryRouterMethod(): void
    {
        $root = dirname(__DIR__);
        $router = file_get_contents($root . '/src/Router.php');
        $openApi = file_get_contents($root . '/openapi.yaml');
        self::assertNotFalse($router);
        self::assertNotFalse($openApi);

        preg_match_all("/\\['(api\\/[^']+)',\\s*'[^']+',\\s*'(GET|POST|PATCH|DELETE)'\\]/", $router, $matches, PREG_SET_ORDER);
        self::assertNotEmpty($matches);

        foreach ($matches as $match) {
            $path = '/' . str_replace('(\\d+)', '{id}', $match[1]);
            $methods = [$match[2]];
            if ($match[2] === 'GET') {
                $methods[] = 'HEAD';
            }

            foreach ($methods as $method) {
                $pathPattern = preg_quote($path, '/');
                $methodPattern = strtolower($method);
                self::assertMatchesRegularExpression(
                    "/^  {$pathPattern}:\\R(?:(?!^  \/api\/)[\\s\\S])*?^    {$methodPattern}:/m",
                    $openApi,
                    "OpenAPI doit documenter {$method} {$path}.",
                );
            }
        }
    }

    public function testSensitiveLocalFilesAreIgnored(): void
    {
        $gitignore = file_get_contents(dirname(__DIR__) . '/.gitignore');
        self::assertNotFalse($gitignore);

        foreach ([
            '/.htpasswd',
            '/config.local.php',
            '/config/apache-muzik.conf',
            '/data/*',
            '/vendor/',
            '__pycache__/',
        ] as $pattern) {
            self::assertStringContainsString($pattern, $gitignore);
        }
    }

    public function testIndexOfProductionLoadsEverySourceClass(): void
    {
        $root = dirname(__DIR__);
        $index = file_get_contents($root . '/public/index.php');
        self::assertNotFalse($index);

        $sources = glob($root . '/src/*.php');
        self::assertNotEmpty($sources);

        foreach ($sources as $source) {
            $class = basename($source);
            self::assertStringContainsString(
                "require __DIR__ . '/../src/{$class}';",
                $index,
                "public/index.php doit charger {$class}.",
            );
        }
    }

    public function testPublishedConfigurationDoesNotContainPrivatePaths(): void
    {
        $root = dirname(__DIR__);
        $publishedConfiguration = implode("\n", array_map(
            static function (string $file) use ($root): string {
                $contents = file_get_contents($root . '/' . $file);
                self::assertNotFalse($contents);

                return $contents;
            },
            [
                'config/app.php',
                'config.local.example.php',
                'config/apache-muzik.conf.example',
                'bin/deploy-apache.sh',
            ],
        ));

        self::assertStringNotContainsString('/mnt/' . 'nas', $publishedConfiguration);
        self::assertStringNotContainsString('serveur' . '.local', $publishedConfiguration);
    }
}
