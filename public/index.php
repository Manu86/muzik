<?php

declare(strict_types=1);
error_reporting(E_ALL);

$uriValue = $_SERVER['REQUEST_URI'] ?? '/';
$uri = is_string($uriValue) ? $uriValue : '/';
$parsedPath = parse_url($uri, PHP_URL_PATH);
$path = is_string($parsedPath) ? $parsedPath : '/';

$scriptValue = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$scriptName = is_string($scriptValue) ? $scriptValue : '/index.php';
if (PHP_SAPI === 'cli-server') {
    $base = '';
} else {
    $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
}
$routePath = $path;
if ($base !== '' && str_starts_with($routePath, $base)) {
    $routePath = substr($routePath, strlen($base));
}
if ($routePath === '' || $routePath[0] !== '/') {
    $routePath = '/' . $routePath;
}

if (PHP_SAPI === 'cli-server' && $routePath !== '/' && $routePath !== '/index.php') {
    $candidate = realpath(__DIR__ . '/' . ltrim($routePath, '/'));
    if ($candidate !== false && str_starts_with($candidate, __DIR__ . DIRECTORY_SEPARATOR)
        && is_file($candidate) && pathinfo($candidate, PATHINFO_EXTENSION) === 'php') {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

$baseConfig = require __DIR__ . '/../config.php';
if (!is_array($baseConfig)) {
    throw new RuntimeException('config.php doit retourner un tableau.');
}
/** @var array<string, mixed> $baseConfig */
$projectRoot = __DIR__ . '/..';

Users::ensureSchema($projectRoot);

if (!Installer::installed($projectRoot)) {
    $isStaticAsset = false;
    if ($routePath !== '/') {
        $candidate = realpath(__DIR__ . '/' . ltrim($routePath, '/'));
        $isStaticAsset = $candidate !== false
            && str_starts_with($candidate, __DIR__ . DIRECTORY_SEPARATOR)
            && is_file($candidate);
    }
    if (!$isStaticAsset) {
        header('Location: ' . $base . '/install.php');
        exit;
    }
}

$authEnabled = Auth::enabled();
$authenticated = Auth::check();

if ($authEnabled && !$authenticated) {
    $publicPage = $routePath === '/login' || $routePath === '/login.html';
    $publicAsset = in_array($routePath, ['/manifest.json', '/assets/icon-192.png', '/assets/icon-512.png'], true);
    $publicApi = in_array($routePath, ['/api/login', '/api/logout', '/api/auth'], true);
    if (!$publicPage && !$publicAsset && !$publicApi) {
        if (str_starts_with($routePath, '/api/')) {
            App::err('Unauthorized', 401);
        }
        header('Location: ' . $base . '/login', true, 302);
        return;
    }
}

if ($routePath === '/login' || $routePath === '/login.html') {
    if (!$authEnabled || $authenticated) {
        header('Location: ' . ($base === '' ? '/' : $base . '/'), true, 302);
        return;
    }
    header('Content-Type: text/html');
    readfile(__DIR__ . '/login.html');
    return;
}

if ($routePath === '/') {
    header('Content-Type: text/html');
    readfile(__DIR__ . '/app.html');
    return;
}

if ($routePath !== '/index.php') {
    $public = __DIR__;
    $candidate = realpath($public . '/' . ltrim($routePath, '/'));
    if ($candidate !== false && str_starts_with($candidate, $public . DIRECTORY_SEPARATOR)
        && is_file($candidate)) {
        $mime = [
            'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'json' => 'application/json',
        ];
        $ext = pathinfo($candidate, PATHINFO_EXTENSION);
        header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: no-cache');
        readfile($candidate);
        return;
    }
}

// Les routes publiques (login, logout, auth) tournent sans catalogue : leur
// connexion est inutile. Pour tout le reste, on ouvre la base et la racine
// musicale de l'utilisateur connecté.
$publicApi = in_array($routePath, ['/api/login', '/api/logout', '/api/auth'], true);
if (!$publicApi) {
    $login = Auth::currentLogin();
    if ($login === null || Users::profile($login) === null) {
        App::err('Unauthorized', 401);
    }
    App::initConfig(Users::resolveConfig($login, $baseConfig));
}

$methodValue = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$method = is_string($methodValue) ? $methodValue : 'GET';
Router::handle($uri, $method);
