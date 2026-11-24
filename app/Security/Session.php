<?php

declare(strict_types=1);

namespace App\Security;

final class Session
{
    public static function start(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        if (getenv('APP_RUNTIME_DIR') !== false && getenv('APP_RUNTIME_DIR') !== '') {
            ini_set('session.save_path', Runtime::directory());
        }
        session_name('secure_payroll_session');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'httponly' => true,
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Lax',
        ]);
        session_start();
        $now = time();
        if (isset($_SESSION['account_id']) && (
            $now - ($_SESSION['last_seen'] ?? 0) > 1200
            || $now - ($_SESSION['signed_in'] ?? 0) > 28800
        )) {
            self::clear();
        }
        if ($now - ($_SESSION['rotated_at'] ?? 0) > 900) {
            session_regenerate_id(true);
            $_SESSION['rotated_at'] = $now;
        }
        $_SESSION['last_seen'] = $now;
        $_SESSION['language'] = \App\I18n\Translator::locale();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function signIn(int $accountId): void
    {
        $language = \App\I18n\Translator::locale();
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION = ['account_id' => $accountId, 'signed_in' => time(),
            'last_seen' => time(), 'rotated_at' => time(), 'csrf' => bin2hex(random_bytes(32)), 'language' => $language];
    }

    public static function clear(): void
    {
        $language = \App\I18n\Translator::locale();
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['language'] = $language;
    }

    public static function validCsrf(mixed $token): bool
    {
        return is_string($token) && hash_equals($_SESSION['csrf'], $token);
    }
}
