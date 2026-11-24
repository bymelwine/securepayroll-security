<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Limitation des tentatives de connexion, sur deux compteurs indépendants (fenêtre de 15 minutes) :
 *  - par identifiant saisi : 5 tentatives, contre la force brute sur un compte ;
 *  - par adresse IP : 30 tentatives, contre la pulvérisation de mots de passe sur plusieurs comptes.
 *
 * Le compteur par identifiant s'applique aussi aux identifiants inexistants : son comportement ne révèle
 * pas quels comptes existent. Aucun identifiant n'est écrit en clair : les fichiers d'état sont nommés
 * par une empreinte SHA-256 et ne contiennent que le compteur et l'échéance.
 */
final class LoginLimiter
{
    public const ACCOUNT_LIMIT = 5;
    public const ADDRESS_LIMIT = 30;
    public const WINDOW = 900;

    /** @var array{address?: array{string, int}, account?: array{string, int}} [chemin, échéance] réservés par cette requête */
    private static array $reserved = [];
    private static ?string $blocked = null;

    public static function allow(string $login): bool
    {
        self::$reserved = [];
        self::$blocked = null;
        // L'adresse d'abord : un identifiant nouveau ne crée un fichier que si l'adresse est encore admise,
        // ce qui borne le nombre de fichiers créés par une même source.
        $address = self::path('address', $_SERVER['REMOTE_ADDR'] ?? 'local');
        $addressUntil = self::reserve($address, self::ADDRESS_LIMIT);
        if ($addressUntil === null) {
            self::$blocked = 'address';
            return false;
        }
        $account = self::path('account', mb_strtolower($login, 'UTF-8'));
        $accountUntil = self::reserve($account, self::ACCOUNT_LIMIT);
        if ($accountUntil === null) {
            // Un compte déjà bloqué ne déclenche aucun calcul Argon2id : ne pas consommer le quota de l'adresse.
            self::update($address, $addressUntil, static fn (int $count): int => $count - 1);
            self::$blocked = 'account';
            return false;
        }
        self::$reserved = ['address' => [$address, $addressUntil], 'account' => [$account, $accountUntil]];
        return true;
    }

    /** Portée du dernier refus : 'account', 'address' ou null. */
    public static function blockedScope(): ?string
    {
        return self::$blocked;
    }

    /**
     * Après une connexion réussie : la tentative n'est pas décomptée de l'adresse, et les échecs
     * antérieurs du compte sont effacés, puisque seul le titulaire du mot de passe peut réussir.
     * Les autres échecs de l'adresse restent comptés.
     */
    public static function succeeded(): void
    {
        $reserved = self::$reserved;
        self::$reserved = [];
        if (isset($reserved['address'])) {
            self::update(...$reserved['address'], change: static fn (int $count): int => $count - 1);
        }
        if (isset($reserved['account'])) {
            self::update(...$reserved['account'], change: static fn (): int => 0);
        }
    }

    private static function path(string $scope, string $key): string
    {
        return Runtime::directory() . '/login-' . $scope . '-' . hash('sha256', $key) . '.json';
    }

    /** Réserve une tentative ; renvoie l'échéance de la fenêtre, ou null si la limite est atteinte. */
    private static function reserve(string $path, int $limit): ?int
    {
        $oldMask = umask(0077);
        try {
            $handle = fopen($path, 'c+');
        } finally {
            umask($oldMask);
        }
        if ($handle === false) {
            throw new \RuntimeException('Limiteur indisponible.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Limiteur indisponible.');
            }
            $state = json_decode(stream_get_contents($handle), true);
            if (!is_array($state) || time() >= ($state['until'] ?? 0)) {
                $state = ['until' => time() + self::WINDOW, 'count' => 0];
            }
            if ($state['count'] >= $limit) {
                return null;
            }
            ++$state['count'];
            self::write($handle, $state);
            return $state['until'];
        } finally {
            fclose($handle);
        }
    }

    /** Modifie le compteur seulement s'il s'agit encore de la fenêtre réservée par cette requête. */
    private static function update(string $path, int $until, callable $change): void
    {
        $handle = fopen($path, 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Limiteur indisponible.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Limiteur indisponible.');
            }
            $state = json_decode(stream_get_contents($handle), true);
            if (!is_array($state) || ($state['until'] ?? null) !== $until) {
                return;
            }
            $state['count'] = max(0, (int) $change((int) $state['count']));
            self::write($handle, $state);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private static function write($handle, array $state): void
    {
        rewind($handle);
        ftruncate($handle, 0);
        $data = json_encode($state, JSON_THROW_ON_ERROR);
        if (fwrite($handle, $data) !== strlen($data)) {
            throw new \RuntimeException('Limiteur indisponible.');
        }
    }
}
