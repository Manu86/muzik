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
        $router = file_get_contents($root . '/src/Router/Router.php');
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

    public function testOpenApiDocumentsSessionAuthenticationAndPublicOperations(): void
    {
        $openApi = file_get_contents(dirname(__DIR__) . '/openapi.yaml');
        self::assertNotFalse($openApi);

        self::assertStringContainsString("  - sessionCookie: []\n", $openApi);
        self::assertStringContainsString("    sessionCookie:\n      type: apiKey\n      in: cookie\n      name: muzik_session", $openApi);

        foreach (['getAuth', 'headAuth', 'login', 'logout'] as $operationId) {
            self::assertMatchesRegularExpression(
                '/operationId: ' . $operationId . '\R      security: \[\]/',
                $openApi,
            );
        }
    }

    public function testArchitectureDocumentationReferencesExtractedFiles(): void
    {
        $root = dirname(__DIR__);
        $readme = file_get_contents($root . '/README.md');
        $agents = file_get_contents($root . '/AGENTS.md');
        self::assertNotFalse($readme);
        self::assertNotFalse($agents);

        foreach (['public/app.html', 'public/assets/css/app.css', 'src/Repo/Catalogue.php'] as $path) {
            self::assertStringContainsString($path, $readme);
        }
        self::assertStringContainsString('src/Repo/Catalogue.php', $agents);
    }

    public function testOpenApiDocumentsEveryRouterMethod(): void
    {
        $root = dirname(__DIR__);
        $router = file_get_contents($root . '/src/Router/Router.php');
        $openApi = file_get_contents($root . '/openapi.yaml');
        self::assertNotFalse($router);
        self::assertNotFalse($openApi);

        preg_match_all(
            "/\\['(api\\/[^']+)',\\s*\\[[A-Za-z0-9_]+::class,\\s*'[^']+'\\],\\s*'(GET|POST|PATCH|DELETE)'\\]/",
            $router,
            $matches,
            PREG_SET_ORDER,
        );
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
        self::assertStringContainsString("require __DIR__ . '/../vendor/autoload.php';", $index);

        $classMap = file_get_contents($root . '/vendor/composer/autoload_classmap.php');
        self::assertNotFalse($classMap);

        $sources = glob($root . '/src/*.php') ?: [];
        $nested = glob($root . '/src/*/*.php') ?: [];
        $files = array_merge($sources, $nested);
        self::assertNotEmpty($files);

        foreach ($files as $source) {
            $class = basename($source, '.php');
            $relative = '/' . str_replace($root . '/', '', $source);
            $entry = "'" . $class . "' => \$baseDir . '" . $relative . "',";
            self::assertMatchesRegularExpression(
                '/' . preg_quote($entry, '/') . '/',
                $classMap,
                "Le classmap Composer doit charger {$relative}.",
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
