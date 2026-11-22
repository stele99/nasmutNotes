<?php

declare(strict_types=1);

use App\Middleware\CsrfMiddleware;
use App\Middleware\RequestIdMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\SessionAuthMiddleware;
use App\Middleware\TrustedProxyMiddleware;
use App\Support\Bootstrap;
use App\Support\Env;
use App\Support\ForbiddenException;
use App\Support\JsonResponse;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
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

// Letzte Verteidigungslinie: Was außerhalb von Slims Fehlerbehandlung
// scheitert (Start, Container, Konfiguration), landet ebenfalls im Log -
// ohne Details in der Antwort.
// Scheitert schon der Start (z. B. Datenbank nicht erreichbar, bevor ein
// Controller läuft), meldet /health trotzdem 503 statt 500.
$failHard = static function (\Throwable $e): void {
    error_log((string) $e);
    $isHealth = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/health';
    if (!headers_sent()) {
        http_response_code($isHealth ? 503 : 500);
        header('Content-Type: ' . ($isHealth ? 'application/json' : 'text/plain; charset=utf-8'));
    }
    echo $isHealth ? '{"status":"error","database":"error"}' : "Interner Serverfehler.\n";
};
set_exception_handler($failHard);

$rootPath = dirname(__DIR__);
Bootstrap::init($rootPath);

$buildContainer = require $rootPath . '/app/Config/container.php';
$container = $buildContainer($rootPath);

AppFactory::setContainer($container);
$app = AppFactory::create();

$isProduction = Env::get('APP_ENV') === 'production';
$isDebug = Bootstrap::displayErrorDetails();
$viteDevServerUrl = Env::bool('APP_DEBUG', false) && Env::get('APP_ENV') === 'development'
    ? Env::get('VITE_DEV_SERVER', 'http://localhost:5173')
    : null;

$registerRoutes = require $rootPath . '/app/Config/routes.php';
$registerRoutes($app, $container);

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
// Außen: Schema (X-Forwarded-Proto) und HTTPS-Umleitung vor allem anderen,
// damit HSTS und Cookies das Schema des Browsers sehen.
$app->add(new TrustedProxyMiddleware($isProduction));

// Slim protokolliert selbst nicht; der Standard-Handler unten schreibt jede
// unerwartete Ausnahme genau einmal per error_log() ins Container-Log.
$errorMiddleware = $app->addErrorMiddleware($isDebug, false, false, $container->get(LoggerInterface::class));
$defaultErrorHandler = $errorMiddleware->getDefaultErrorHandler();
// Bewusst nicht static: Slim bindet Handler-Closures an den Container.
$errorMiddleware->setDefaultErrorHandler(
    function (
        Request $request,
        \Throwable $e,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ) use ($defaultErrorHandler): \Psr\Http\Message\ResponseInterface {
        // 404/405 & Co. sind keine Fehler der Anwendung.
        if (!$e instanceof HttpException || $e->getCode() >= 500) {
            error_log((string) $e);
        }

        return $defaultErrorHandler($request, $e, $displayErrorDetails, false, false);
    },
);

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

try {
    $app->run();
} catch (\Throwable $e) {
    $failHard($e);
}
