<?php

declare(strict_types=1);

namespace App\Finance;

use App\I18n\Translator;

/** Réutilisable par les futurs bulletins : aucune conversion ni arithmétique flottante. */
final class Money
{
    public const REFERENCE_CURRENCY = 'MAD';

    public static function format(string $amount, string $currency): string
    {
        if (!preg_match('/\A([0-9]{1,10})(?:\.([0-9]{1,2}))?\z/D', $amount, $parts)
            || !in_array($currency, [self::REFERENCE_CURRENCY, 'UM'], true)) {
            throw new \UnexpectedValueException('Invalid monetary representation.');
        }
        $fr = Translator::locale() === 'fr';
        $whole = ltrim($parts[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $grouped = preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', $fr ? "\u{202F}" : ',', $whole);
        return $grouped . ($fr ? ',' : '.') . str_pad($parts[2] ?? '', 2, '0') . ' ' . $currency;
    }
}
