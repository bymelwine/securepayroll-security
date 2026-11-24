<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Chiffrement au repos des champs les plus sensibles (RIB, pièce d'identité, CNSS) — phase 6.
 *
 * AES-256-GCM (chiffrement authentifié) : chaque valeur reçoit un nonce aléatoire ; le format du blob est
 *   version(1) || nonce(12) || texte_chiffré || tag(16).
 * L'octet de version prépare la rotation de clé sans migration de format. GCM garantit la confidentialité ET
 * l'intégrité : toute altération du texte chiffré fait échouer le déchiffrement.
 *
 * Index aveugle (HMAC-SHA256, clé distincte) : permet de conserver l'unicité et la recherche par valeur, que
 * le nonce aléatoire rend impossibles sur le texte chiffré. Il ne révèle pas la valeur et, clé séparée oblige,
 * ne permet pas de déchiffrer.
 *
 * Gestion des clés : deux clés de 32 octets dans le répertoire d'exécution privé (0600), HORS dépôt et HORS
 * base. Un vidage de la base ne contient donc aucune clé ; les clés ne transitent jamais par SQL.
 */
final class FieldCipher
{
    private const VERSION = "\x01";
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;
    private const KEY_LEN = 32;

    public function __construct(private string $dataKey, private string $indexKey)
    {
        if (strlen($dataKey) !== self::KEY_LEN || strlen($indexKey) !== self::KEY_LEN) {
            throw new \InvalidArgumentException('Clés de chiffrement invalides (32 octets attendus).');
        }
    }

    /** Clés lues depuis le répertoire d'exécution privé ; échec fermé si absentes. */
    public static function fromRuntime(): self
    {
        $directory = Runtime::directory() . '/keys';
        return new self(self::loadKey($directory . '/field.key'), self::loadKey($directory . '/blind.key'));
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->dataKey, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN);
        if ($ciphertext === false) {
            throw new \RuntimeException('Échec du chiffrement.');
        }
        return self::VERSION . $nonce . $ciphertext . $tag;
    }

    public function decrypt(string $blob): string
    {
        if (strlen($blob) < 1 + self::NONCE_LEN + self::TAG_LEN || $blob[0] !== self::VERSION) {
            throw new \RuntimeException('Blob chiffré invalide.');
        }
        $nonce = substr($blob, 1, self::NONCE_LEN);
        $tag = substr($blob, -self::TAG_LEN);
        $ciphertext = substr($blob, 1 + self::NONCE_LEN, -self::TAG_LEN);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->dataKey, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plaintext === false) {
            // Mauvaise clé ou texte chiffré altéré : le tag GCM ne valide pas.
            throw new \RuntimeException('Échec du déchiffrement ou intégrité compromise.');
        }
        return $plaintext;
    }

    /** Index déterministe d'une valeur normalisée, pour l'unicité et la recherche (32 octets binaires). */
    public function blindIndex(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, $this->indexKey, true);
    }

    /** Génère les clés absentes (0600) dans le répertoire fourni. Utilisé par l'installateur et les tests. */
    public static function provision(string $keysDirectory): void
    {
        $oldMask = umask(0077);
        try {
            if (!is_dir($keysDirectory) && !mkdir($keysDirectory, 0700) && !is_dir($keysDirectory)) {
                throw new \RuntimeException('Répertoire de clés indisponible.');
            }
            foreach (['field.key', 'blind.key'] as $name) {
                $path = $keysDirectory . '/' . $name;
                if (!is_file($path)) {
                    if (file_put_contents($path, random_bytes(self::KEY_LEN)) !== self::KEY_LEN) {
                        throw new \RuntimeException('Écriture de clé impossible.');
                    }
                    chmod($path, 0600);
                }
            }
        } finally {
            umask($oldMask);
        }
    }

    private static function loadKey(string $path): string
    {
        if (!is_file($path) || (fileperms($path) & 0077) !== 0) {
            throw new \RuntimeException('Clé de chiffrement absente ou permissions trop ouvertes.');
        }
        $key = file_get_contents($path);
        if ($key === false || strlen($key) !== self::KEY_LEN) {
            throw new \RuntimeException('Clé de chiffrement illisible ou de taille incorrecte.');
        }
        return $key;
    }
}
