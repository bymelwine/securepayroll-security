<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Security\FieldCipher;

/** Chiffrement de champ (phase 6) : AES-256-GCM, index aveugle, rotation, gestion des clés — sans connexion. */
$count = 0;
$verify = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) { fwrite(STDERR, 'Échec : ' . $label . "\n"); exit(1); }
    ++$count;
};
$throws = static function (callable $work): bool {
    try { $work(); } catch (\Throwable) { return true; }
    return false;
};

$dataKey = random_bytes(32);
$indexKey = random_bytes(32);
$cipher = new FieldCipher($dataKey, $indexKey);

// 1. Aller-retour sur des valeurs variées (vide, RIB, accents, binaire).
foreach (['', '999780000000000000010117', 'ZZ104521', 'Nadia Traoré — éàç', "\x00\x01\x02binaire"] as $plain) {
    $verify($cipher->decrypt($cipher->encrypt($plain)) === $plain, 'Aller-retour chiffrement/déchiffrement');
}

// 2. Même valeur, nonce aléatoire : deux chiffrés différents, tous deux déchiffrables.
$a = $cipher->encrypt('999780000000000000010117');
$b = $cipher->encrypt('999780000000000000010117');
$verify($a !== $b && $cipher->decrypt($a) === $cipher->decrypt($b),
    'Nonce aléatoire : chiffrés distincts pour une même valeur');

// 3. Format du blob : octet de version 0x01, longueur >= version+nonce+tag.
$verify($a[0] === "\x01" && strlen($a) >= 1 + 12 + 16, 'Format du blob (version, nonce, tag)');

// 4. Intégrité GCM : une altération d'un octet du chiffré fait échouer le déchiffrement.
$tampered = $a;
$tampered[20] = $tampered[20] === "\x00" ? "\x01" : "\x00";
$verify($throws(fn() => $cipher->decrypt($tampered)), 'Altération détectée (tag GCM)');

// 5. Mauvaise clé de données : déchiffrement refusé.
$other = new FieldCipher(random_bytes(32), $indexKey);
$verify($throws(fn() => $other->decrypt($a)), 'Mauvaise clé : déchiffrement refusé');

// 6. Index aveugle : déterministe, longueur 32, sensible à la valeur.
$verify($cipher->blindIndex('ZZ104521') === $cipher->blindIndex('ZZ104521')
    && strlen($cipher->blindIndex('ZZ104521')) === 32
    && $cipher->blindIndex('ZZ104521') !== $cipher->blindIndex('ZZ104522'),
    'Index aveugle déterministe, 32 octets, sensible à la valeur');

// 7. Clé d'index distincte : l'index dépend de la clé d'index, pas de la clé de données.
$diffIndex = new FieldCipher($dataKey, random_bytes(32));
$sameIndex = new FieldCipher(random_bytes(32), $indexKey);
$verify($diffIndex->blindIndex('ZZ104521') !== $cipher->blindIndex('ZZ104521')
    && $sameIndex->blindIndex('ZZ104521') === $cipher->blindIndex('ZZ104521'),
    'Index aveugle piloté par la seule clé d’index');

// 8. Clé de mauvaise taille : construction refusée.
$verify($throws(fn() => new FieldCipher('court', $indexKey)), 'Clé de 32 octets exigée');

// 9. Blob tronqué ou de mauvaise version : refusé.
$verify($throws(fn() => $cipher->decrypt('x')) && $throws(fn() => $cipher->decrypt("\x02" . str_repeat('x', 40))),
    'Blob invalide (tronqué, mauvaise version) refusé');

// 10. Provisionnement des clés : fichiers 0600, idempotent.
$dir = sys_get_temp_dir() . '/securepayroll-keys-' . bin2hex(random_bytes(6));
FieldCipher::provision($dir);
$perms = (fileperms($dir . '/field.key') & 0777) === 0600 && (fileperms($dir . '/blind.key') & 0777) === 0600;
$first = file_get_contents($dir . '/field.key');
FieldCipher::provision($dir); // deuxième passage : ne régénère pas
$verify($perms && strlen((string) $first) === 32 && file_get_contents($dir . '/field.key') === $first,
    'Clés provisionnées en 0600, provisionnement idempotent');
array_map('unlink', glob($dir . '/*.key') ?: []);
@rmdir($dir);

echo $count . " contrôles de chiffrement de champ réussis, sans connexion.\n";
