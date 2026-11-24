<?php

declare(strict_types=1);

namespace App\Auth;

use App\Mail\Outbox;
use PDO;

/**
 * Embauche : la RH crée le salarié ; la procédure account_onboard crée aussi son compte professionnel
 * (prenom.nom@securepayroll.ma), son invitation et son rôle Employé. Le mot de passe temporaire est généré ici,
 * haché avant d'atteindre la base, envoyé à l'adresse personnelle du salarié, et jamais montré à la RH.
 */
final class Onboarding
{
    public const DOMAIN = 'securepayroll.ma';
    /** Sans caractères ambigus (0/O, 1/l/I) : le salarié le recopie depuis un e-mail. */
    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private PDO $db) {}

    /** Partie locale de l'identifiant : prénom.nom en minuscules ASCII, accents retirés. */
    public static function loginBase(string $firstName, string $lastName): string
    {
        $parts = [];
        foreach ([$firstName, $lastName] as $value) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value;
            $value = mb_strtolower(preg_replace('/\p{Mn}+/u', '', $value) ?? '', 'UTF-8');
            $value = trim(preg_replace('/[^a-z0-9]+/', '-', $value) ?? '', '-');
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $base = substr(implode('.', $parts), 0, 140);
        $base = rtrim($base, '.-');
        return $base === '' ? 'salarie' : $base;
    }

    /** Validation serveur de l'adresse personnelle ; clé de traduction de l'erreur, ou null. */
    public static function personalEmailError(string $email): ?string
    {
        if ($email === '' || strlen($email) > 254 || preg_match('/[\x00-\x20\x7F]/', $email) === 1
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'onboarding.email_invalid';
        }
        if (preg_match('/@securepayroll\.ma\z/i', $email) === 1) {
            return 'onboarding.email_professional';
        }
        return null;
    }

    /** 16 caractères aléatoires (≈ 92 bits), présentés en 4 groupes : abcd-efgh-ijkl-mnop. */
    public static function temporaryPassword(): string
    {
        $characters = '';
        for ($i = 0; $i < 16; ++$i) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return implode('-', str_split($characters, 4));
    }

    /**
     * @param array{first_name: string, last_name: string} $values
     * @return array{employee_id: int, account_id: int, employee_number: string, login: string, mail_sent: bool}
     */
    public function onboard(int $actor, array $values, string $personalEmail): array
    {
        if ($this->db->inTransaction()) {
            throw new \LogicException('Transaction already active.');
        }
        $temporary = self::temporaryPassword();
        // Le matricule est généré par la procédure (EMP-<année>-<numéro>), pas saisi par la RH.
        $query = $this->db->prepare('CALL account_onboard(?,?,?,?,?,?)');
        try {
            $query->execute([$actor, $values['first_name'], $values['last_name'],
                self::loginBase($values['first_name'], $values['last_name']), $personalEmail,
                password_hash($temporary, PASSWORD_ARGON2ID)]);
            $row = $query->fetch();
            while ($query->nextRowset()) {
            }
        } finally {
            $query->closeCursor();
        }
        if (!is_array($row) || !isset($row['employee_id'], $row['employee_number'], $row['login'], $row['expires_at'])) {
            throw new \UnexpectedValueException('Missing onboarding result.');
        }
        $body = implode("\n", [
            'Bonjour ' . $values['first_name'] . ',',
            '',
            'Votre compte professionnel SecurePayroll vient d’être créé par le service des ressources humaines.',
            '',
            '  Identifiant           : ' . $row['login'],
            '  Mot de passe temporaire : ' . $temporary,
            '  Valable jusqu’au      : ' . substr((string) $row['expires_at'], 0, 16) . ' (UTC)',
            '',
            'À votre première connexion, vous devrez choisir un nouveau mot de passe personnel.',
            'Votre compte ne sera pleinement actif qu’après ce changement. Faites-le dès que possible :',
            'passé ce délai, le mot de passe temporaire ne fonctionnera plus et il faudra contacter les RH.',
            '',
            'Si vous n’attendiez pas ce message, ignorez-le et prévenez le service RH.',
            '',
            '— SecurePayroll (message automatique, ne pas répondre)',
        ]);
        // Le compte est déjà validé en base : un échec d'envoi est signalé, pas masqué par une erreur générique.
        try {
            Outbox::send($personalEmail, 'Votre compte SecurePayroll a été créé', $body);
            $sent = true;
        } catch (\Throwable) {
            $sent = false;
        }
        return ['employee_id' => (int) $row['employee_id'], 'account_id' => (int) $row['account_id'],
            'employee_number' => (string) $row['employee_number'], 'login' => (string) $row['login'], 'mail_sent' => $sent];
    }
}
