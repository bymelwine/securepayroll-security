<?php

declare(strict_types=1);

namespace App\Access;

use PDO;

/**
 * « Mes accès » : historique des rôles du compte connecté, filtré par date de début.
 *
 * Version sécurisée (phase 5) : validation serveur de la date, puis requête préparée où la saisie
 * n'est qu'une valeur liée. La variante délibérément vulnérable étudiée en phase 4 se trouve dans
 * lab/vulnerable/ et n'est chargée que par App\Security\LabMode, dans une instance de laboratoire.
 */
final class AccessHistory
{
    public const DEFAULT_SINCE = '2000-01-01';

    /** Validation serveur : une date réelle au format AAAA-MM-JJ, sinon null. */
    public static function parseDate(string $value): ?string
    {
        if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[1] < 1970 || (int) $parts[1] > 2100) {
            return null;
        }
        return $value;
    }

    /** @return list<array{0: string, 1: string, 2: ?string, 3: ?string}> code, début, expiration, révocation */
    public static function search(PDO $db, int $accountId, string $since): array
    {
        $query = $db->prepare('SELECT r.code, ra.valid_from, ra.expires_at, ra.revoked_at
            FROM role_assignments ra JOIN roles r ON r.id = ra.role_id
            WHERE ra.account_id = ? AND ra.valid_from >= ?
            ORDER BY ra.valid_from DESC LIMIT 100');
        $query->execute([$accountId, $since . ' 00:00:00']);
        return $query->fetchAll(PDO::FETCH_NUM);
    }
}
