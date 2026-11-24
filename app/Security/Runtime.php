<?php

declare(strict_types=1);

namespace App\Security;

/** Optional private runtime directory isolates logs, limiter state and sessions. */
final class Runtime
{
    public static function directory(): string
    {
        $directory = getenv('APP_RUNTIME_DIR');
        if ($directory === false || $directory === '') {
            return dirname(__DIR__, 2) . '/storage/logs';
        }
        $path = realpath($directory);
        if ($path === false || $directory[0] !== '/' || !is_dir($path) || !is_writable($path)
            || (fileperms($path) & 0077) !== 0
            || (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid())) {
            throw new \RuntimeException('Private runtime directory unavailable.');
        }
        return $path;
    }
}
