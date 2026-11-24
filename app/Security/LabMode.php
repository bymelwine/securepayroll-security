<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Verrou du composant délibérément vulnérable (phases 4 et 5).
 *
 * Il n'est chargé que si TOUTES les conditions sont réunies :
 *  - variable SECUREPAYROLL_LAB_VULNERABLE=access-history ;
 *  - serveur web intégré de PHP (php -S), jamais un serveur de production ;
 *  - répertoire d'exécution d'une instance de laboratoire jetable, sous /tmp/securepayroll-lab-* ;
 *  - fichier présent dans lab/vulnerable/.
 * L'instance quotidienne (.local/) ne remplit jamais la troisième condition, et bin/init-local.py
 * retire de toute façon les variables SECUREPAYROLL_LAB_* de l'environnement de l'application.
 *
 * Phase 5 : SECUREPAYROLL_LAB_VARIANT choisit quel contrôle unique est réactivé, pour mesurer
 * ce que chaque contrôle bloque à lui seul (liste fermée VARIANTS ; valeur inconnue = refus).
 */
final class LabMode
{
    public const FLAG = 'SECUREPAYROLL_LAB_VULNERABLE';
    public const RUNTIME_PREFIX = '/tmp/securepayroll-lab-';
    public const VARIANT = 'SECUREPAYROLL_LAB_VARIANT';
    /** Variante → contrôle réactivé seul. 'vulnerable' : aucun des trois contrôles de code. */
    public const VARIANTS = ['vulnerable', 'prepared-only', 'validation-only', 'generic-errors'];

    public static function vulnerableAccessHistory(): bool
    {
        $flag = getenv(self::FLAG);
        if ($flag === false || $flag === '') {
            return false;
        }
        $runtime = getenv('APP_RUNTIME_DIR');
        $real = is_string($runtime) && $runtime !== '' ? realpath($runtime) : false;
        if ($flag !== 'access-history' || self::variant() === null || PHP_SAPI !== 'cli-server' || $real === false
            || !str_starts_with($real, self::RUNTIME_PREFIX) || !is_file(self::vulnerableFile())) {
            error_log(json_encode(['at' => gmdate('c'), 'event' => 'lab.vulnerable_refused'], JSON_THROW_ON_ERROR));
            return false;
        }
        return true;
    }

    /** Variante demandée, 'vulnerable' par défaut ; null si la valeur n'est pas dans la liste fermée. */
    public static function variant(): ?string
    {
        $variant = getenv(self::VARIANT);
        $variant = $variant === false || $variant === '' ? 'vulnerable' : $variant;
        return in_array($variant, self::VARIANTS, true) ? $variant : null;
    }

    public static function vulnerableFile(): string
    {
        return dirname(__DIR__, 2) . '/lab/vulnerable/VulnerableAccessHistory.php';
    }
}
