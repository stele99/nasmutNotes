<?php

declare(strict_types=1);

use App\Middleware\CsrfMiddleware;
use App\Middleware\RequestIdMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\SessionAuthMiddleware;
use App\Support\Env;
use App\Support\ForbiddenException;
use App\Support\JsonResponse;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $publicPath = realpath(__DIR__);
    $requestedFile = is_string($requestPath) ? realpath(__DIR__ . '/' . ltrim($requestPath, '/')) : false;

    if (
        $publicPath !== false
        && $requestedFile !== false
        && str_starts_with($requestedFile, $publicPath . DIRECTORY_SEPARATOR)
        && is_file($requestedFile)
    ) {
        return false;
    }
}

$rootPath = dirname(__DIR__);
Env::load($rootPath);

$buildContainer = require $rootPath . '/app/Config/container.php';
$container = $buildContainer($rootPath);

AppFactory::setContainer($container);
$app = AppFactory::create();

$isProduction = Env::get('APP_ENV') === 'production';
$isDebug = Env::bool('APP_DEBUG', false);
$viteDevServerUrl = Env::bool('APP_DEBUG', false) && Env::get('APP_ENV') === 'development'
    ? Env::get('VITE_DEV_SERVER', 'http://localhost:5173')
    : null;

$registerRoutes = require $rootPath . '/app/Config/routes.php';
$registerRoutes($app);

// Slim führt Middleware LIFO aus (zuletzt hinzugefügt = äußerste Schicht).
// Reihenfolge unten von innen (zuerst hinzugefügt) nach außen (zuletzt hinzugefügt):
// Routing -> BodyParsing -> Csrf -> SessionAuth -> SecurityHeaders -> RequestId -> ErrorMiddleware
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();
$app->add(new CsrfMiddleware(
    Env::get('APP_URL', 'http://localhost:8080') ?? 'http://localhost:8080',
    $isProduction,
));
$app->add($container->get(SessionAuthMiddleware::class));
$app->add(new SecurityHeadersMiddleware($isProduction, $viteDevServerUrl));
$app->add(new RequestIdMiddleware());

$errorMiddleware = $app->addErrorMiddleware($isDebug, true, true, $container->get(LoggerInterface::class));

$errorMiddleware->setErrorHandler(
    ValidationException::class,
    fn (Request $request, \Throwable $e): \Psr\Http\Message\ResponseInterface
        => JsonResponse::error(new Slim\Psr7\Response(), 'VALIDATION_FAILED', $e->getMessage(), 422),
    true,
);
$errorMiddleware->setErrorHandler(
    NotFoundException::class,
    fn (Request $request, \Throwable $e): \Psr\Http\Message\ResponseInterface
        => JsonResponse::error(new Slim\Psr7\Response(), 'NOT_FOUND', $e->getMessage(), 404),
    true,
);
$errorMiddleware->setErrorHandler(
    ForbiddenException::class,
    fn (Request $request, \Throwable $e): \Psr\Http\Message\ResponseInterface
        => JsonResponse::error(new Slim\Psr7\Response(), 'FORBIDDEN', $e->getMessage(), 403),
    true,
);

$app->run();
