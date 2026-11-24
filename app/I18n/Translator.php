<?php

declare(strict_types=1);

namespace App\I18n;

final class Translator
{
    private static array $catalogues = [];

    public static function locale(): string
    {
        return in_array($_SESSION['language'] ?? null, ['fr', 'en'], true) ? $_SESSION['language'] : 'fr';
    }

    public static function text(string $key, array $parameters = []): string
    {
        $language = self::locale();
        $catalogue = self::catalogue($language);
        $fallback = self::catalogue('fr');
        $text = $catalogue[$key] ?? $fallback[$key] ?? $fallback['common.unavailable'];
        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements[':' . $name] = (string) $value;
        }
        return strtr($text, $replacements);
    }

    private static function catalogue(string $language): array
    {
        // Seuls les deux noms constants autorisés ci-dessus peuvent atteindre ce chemin.
        return self::$catalogues[$language] ??= require dirname(__DIR__, 2) . '/lang/' . $language . '.php';
    }
}
