#!/usr/bin/env python3
"""Phase 8 — chiffrement au repos d'une sauvegarde (AES-256-GCM authentifié).

Le dump logique produit par bin/backup.py contient la structure, les empreintes de mots de passe
(Argon2id) et les colonnes chiffrées de la phase 6. Même si les données sensibles y sont déjà du
chiffré, la sauvegarde complète doit être protégée au repos. Cet outil chiffre/déchiffre un fichier
avec une clé de sauvegarde de 32 octets lue dans un FICHIER (jamais en argument ni dans l'historique
shell). L'authentification GCM détecte toute altération ou une mauvaise clé.

La clé de sauvegarde est distincte de la clé de chiffrement des données (phase 6,
.local/runtime/keys/field.key) : voir docs/security-assessment/13-SAUVEGARDE-ET-RESTAURATION.md.

Sous-commandes :
  keygen  --key FICHIER                         génère une clé de sauvegarde de 32 octets (0600)
  encrypt --in F --key FICHIER --out F.enc      chiffre F
  decrypt --in F.enc --key FICHIER --out F      déchiffre et vérifie l'intégrité
"""
import argparse
import os
from pathlib import Path
import stat
import sys

from cryptography.hazmat.primitives.ciphers.aead import AESGCM

MAGIC = b'SPB1'                       # en-tête de format (SecurePayroll Backup v1)
AAD = b'securepayroll-backup-v1'      # données associées authentifiées (lie le format au chiffré)
KEY_LEN = 32                          # AES-256
NONCE_LEN = 12                        # nonce GCM standard
MAX_BYTES = 1024 * 1024 * 1024        # garde-fou : chiffrement en mémoire, 1 Gio max


def private_write(path: Path, data: bytes) -> None:
    """Écrit en 0600, sans écraser un fichier existant."""
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
    except BaseException:
        path.unlink(missing_ok=True)
        raise


def load_key(path: Path) -> bytes:
    info = path.stat()
    if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) & 0o077 or path.is_symlink():
        raise RuntimeError('La clé de sauvegarde doit être un fichier privé (0600) vous appartenant.')
    key = path.read_bytes()
    if len(key) != KEY_LEN:
        raise RuntimeError('Clé de sauvegarde invalide : ' + str(KEY_LEN) + ' octets attendus.')
    return key


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('action', choices=['keygen', 'encrypt', 'decrypt'])
    parser.add_argument('--key', required=True)
    parser.add_argument('--in', dest='source')
    parser.add_argument('--out', dest='target')
    args = parser.parse_args()
    if os.geteuid() == 0:
        parser.error('À exécuter sans sudo : une sauvegarde ne se manipule pas en root.')
    key_path = Path(args.key).expanduser()

    if args.action == 'keygen':
        if key_path.exists():
            parser.error('La clé existe déjà ; refus d\'écrasement.')
        private_write(key_path, os.urandom(KEY_LEN))
        print('Clé de sauvegarde créée (0600) : ' + str(key_path))
        print('À conserver HORS des sauvegardes et hors du dépôt. Sa perte rend les sauvegardes illisibles.')
        return 0

    if not args.source or not args.target:
        parser.error('--in et --out sont requis pour ' + args.action + '.')
    source = Path(args.source).expanduser().resolve(strict=True)
    target = Path(args.target).expanduser()
    if target.exists():
        parser.error('La cible existe déjà ; refus d\'écrasement : ' + str(target))
    if source.stat().st_size > MAX_BYTES:
        parser.error('Fichier trop volumineux pour un chiffrement en mémoire (> 1 Gio).')
    key = load_key(key_path)
    aes = AESGCM(key)

    if args.action == 'encrypt':
        nonce = os.urandom(NONCE_LEN)
        blob = MAGIC + nonce + aes.encrypt(nonce, source.read_bytes(), AAD)
        private_write(target, blob)
        print('Sauvegarde chiffrée (AES-256-GCM, 0600) : ' + str(target))
        return 0

    # decrypt
    blob = source.read_bytes()
    if len(blob) < len(MAGIC) + NONCE_LEN + 16 or blob[:len(MAGIC)] != MAGIC:
        raise RuntimeError('Format de sauvegarde chiffrée non reconnu.')
    nonce = blob[len(MAGIC):len(MAGIC) + NONCE_LEN]
    ciphertext = blob[len(MAGIC) + NONCE_LEN:]
    try:
        plaintext = aes.decrypt(nonce, ciphertext, AAD)          # lève si clé fausse ou altération
    except Exception as error:
        raise RuntimeError('Déchiffrement refusé : mauvaise clé ou sauvegarde altérée.') from error
    private_write(target, plaintext)
    print('Sauvegarde déchiffrée et intégrité vérifiée (0600) : ' + str(target))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (RuntimeError, OSError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
