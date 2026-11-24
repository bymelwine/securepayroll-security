<?php

declare(strict_types=1);

// Ce fichier ne doit produire aucune sortie.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1');
ini_set('error_log', dirname(__DIR__) . '/storage/logs/application.log');
date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\') && preg_match('/\AApp\\\\[a-zA-Z0-9_\\\\]+\z/D', $class)) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $exception): void {
    // Ne pas journaliser les messages ou les arguments : ils peuvent contenir des secrets.
    error_log(sprintf(
        'Unhandled %s in %s:%d',
        get_class($exception),
        $exception->getFile(),
        $exception->getLine()
    ));

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Une erreur interne est survenue. Consulter le journal local.\n");
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo \App\I18n\Translator::text('errors.internal');
});

// Explicit override for isolated deployments; the default runtime is unchanged.
ini_set('error_log', \App\Security\Runtime::directory() . '/application.log');
