<?php

declare(strict_types=1);

namespace App\Mail;

use App\Security\Runtime;

/**
 * Boîte d'envoi locale : chaque message est écrit dans un fichier privé (0600) du répertoire d'exécution,
 * au lieu d'être envoyé. Le projet n'a pas de serveur de messagerie ; un envoi SMTP réel remplacerait
 * cette seule classe. Lecture : `python3 bin/init-local.py outbox`.
 */
final class Outbox
{
    public const SENDER = 'SecurePayroll <no-reply@securepayroll.ma>';

    public static function send(string $to, string $subject, string $body): string
    {
        // En-têtes : aucun retour à la ligne, sinon un destinataire pourrait injecter d'autres en-têtes.
        if (preg_match('/[\r\n\x00]/', $to . $subject) === 1 || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Destinataire ou objet invalide.');
        }
        // Les messages contiennent des mots de passe temporaires : jamais dans l'arborescence du projet.
        if ((string) getenv('APP_RUNTIME_DIR') === '') {
            throw new \RuntimeException('Boîte d’envoi : répertoire d’exécution privé requis.');
        }
        $folder = Runtime::directory() . '/outbox';
        $oldMask = umask(0077);
        try {
            if (!is_dir($folder) && !mkdir($folder, 0700) && !is_dir($folder)) {
                throw new \RuntimeException('Boîte d’envoi indisponible.');
            }
            $name = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6)) . '.eml';
            $handle = fopen($folder . '/' . $name, 'x');
            if ($handle === false) {
                throw new \RuntimeException('Boîte d’envoi indisponible.');
            }
        } finally {
            umask($oldMask);
        }
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $message = 'From: ' . self::SENDER . "\r\n"
            . 'To: ' . $to . "\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
            . 'Date: ' . gmdate(DATE_RFC2822) . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . str_replace("\n", "\r\n", $body) . "\r\n";
        try {
            if (fwrite($handle, $message) !== strlen($message)) {
                throw new \RuntimeException('Boîte d’envoi indisponible.');
            }
        } finally {
            fclose($handle);
        }
        return $name;
    }
}
