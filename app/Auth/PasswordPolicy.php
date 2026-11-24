<?php

declare(strict_types=1);

namespace App\Auth;

/** Règles d'un nouveau mot de passe choisi par l'utilisateur. Renvoie la clé de traduction de l'erreur, ou null. */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;

    /** Mots de passe trop courants, refusés quelle que soit leur longueur (comparaison insensible à la casse). */
    private const COMMON = ['motdepasse123', 'motdepasse2026', 'azertyuiop123', '123456789012', 'password1234',
        'password2026', 'securepayroll', 'securepayroll1', 'securepayroll2026', 'bienvenue2026', 'bienvenue123',
        'soleil2026!!', 'qwertyuiop12', 'administrateur', 'changeme1234'];

    /** @param list<string> $personal identifiant, prénom, nom : ne doivent pas figurer dans le mot de passe */
    public static function error(string $new, string $confirmation, string $current, array $personal): ?string
    {
        $length = mb_strlen($new, 'UTF-8');
        if (!mb_check_encoding($new, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $new) === 1) {
            return 'password.invalid';
        }
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return 'password.length';
        }
        if (!hash_equals($new, $confirmation)) {
            return 'password.mismatch';
        }
        if (hash_equals($current, $new)) {
            return 'password.same';
        }
        $lower = mb_strtolower($new, 'UTF-8');
        if (in_array($lower, self::COMMON, true) || preg_match('/\A(.)\1+\z/u', $new) === 1) {
            return 'password.common';
        }
        foreach ($personal as $value) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value, 'UTF-8')) ?: [] as $part) {
                if (mb_strlen($part, 'UTF-8') >= 3 && $part !== 'securepayroll' && str_contains($lower, $part)) {
                    return 'password.personal';
                }
            }
        }
        return null;
    }
}
