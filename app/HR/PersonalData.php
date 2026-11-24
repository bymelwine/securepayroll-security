<?php

declare(strict_types=1);

namespace App\HR;

/**
 * Règles métier des données personnelles sensibles (phase 6), sans base ni clé : validation de format,
 * normalisation pour l'index aveugle, et masquage à l'affichage. Partagé par le semeur chiffré et l'application
 * pour que chiffrement, unicité et affichage suivent exactement les mêmes règles.
 */
final class PersonalData
{
    public const DOCUMENT_TYPES = ['cin', 'residence_card', 'passport'];

    private const DOCUMENT_PATTERNS = [
        'cin' => '/^[A-Z]{1,2}[0-9]{4,6}$/',
        'residence_card' => '/^[A-Z]{1,2}[0-9]{4,8}[A-Z]?$/',
        'passport' => '/^[A-Z0-9]{6,9}$/',
    ];

    /** RIB marocain : 24 chiffres. Renvoie la forme normalisée (sans espaces) ou null si invalide. */
    public static function normalizeRib(string $rib): ?string
    {
        $digits = preg_replace('/\s+/', '', $rib) ?? '';
        return preg_match('/^[0-9]{24}$/', $digits) === 1 ? $digits : null;
    }

    /** Document d'identité : type connu + numéro au format du type. Normalisé en « type:NUMÉRO ». */
    public static function normalizeDocument(string $type, string $number): ?string
    {
        $number = strtoupper(trim($number));
        if (!isset(self::DOCUMENT_PATTERNS[$type]) || preg_match(self::DOCUMENT_PATTERNS[$type], $number) !== 1) {
            return null;
        }
        return $type . ':' . $number;
    }

    /** CNSS : 9 chiffres (facultatif). '' ou null => null (pas d'affiliation). */
    public static function normalizeCnss(?string $cnss): ?string
    {
        $digits = preg_replace('/\s+/', '', (string) $cnss) ?? '';
        if ($digits === '') {
            return null;
        }
        return preg_match('/^[0-9]{9}$/', $digits) === 1 ? $digits : null;
    }

    /** Masque un RIB : seuls les 4 derniers chiffres restent visibles. */
    public static function maskRib(string $rib): string
    {
        $tail = substr($rib, -4);
        return '•••• •••• •••• •••• •••• ' . $tail;
    }

    /** Masque un numéro de document : 2 premiers et 2 derniers caractères visibles. */
    public static function maskDocument(string $number): string
    {
        $length = strlen($number);
        if ($length <= 4) {
            return str_repeat('•', $length);
        }
        return substr($number, 0, 2) . str_repeat('•', $length - 4) . substr($number, -2);
    }

    /** Masque un numéro CNSS : seuls les 3 derniers chiffres restent visibles. */
    public static function maskCnss(string $cnss): string
    {
        return str_repeat('•', max(0, strlen($cnss) - 3)) . substr($cnss, -3);
    }
}
