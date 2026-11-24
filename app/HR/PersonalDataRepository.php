<?php

declare(strict_types=1);

namespace App\HR;

use App\Security\FieldCipher;
use PDO;
use PDOException;

/**
 * Accès du salarié à SES propres données personnelles (phase 6). Tout passe par les procédures DEFINER
 * personal_data_* (autorisation + confinement à la fiche de l'acteur) ; le déchiffrement et le masquage se font
 * ici. L'application ne voit jamais la table en direct : le rôle sp_employee n'a que l'EXECUTE des procédures.
 */
final class PersonalDataRepository
{
    public function __construct(private PDO $db) {}

    /**
     * Les seules données du salarié connecté, déchiffrées puis MASQUÉES pour l'affichage.
     * Retourne null si aucune fiche n'existe encore.
     */
    public function viewOwn(int $actorAccountId): ?array
    {
        $query = $this->db->prepare('CALL personal_data_view(?)');
        $query->execute([$actorAccountId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $query->closeCursor();
        if ($row === false) {
            return null;
        }
        $cipher = FieldCipher::fromRuntime();
        $document = $cipher->decrypt($row['id_document_number']);
        $cnss = $row['cnss_number'] !== null ? $cipher->decrypt($row['cnss_number']) : null;
        $rib = $row['rib'] !== null ? $cipher->decrypt($row['rib']) : null;
        return [
            'id_document_type' => $row['id_document_type'],
            'id_document_masked' => PersonalData::maskDocument($document),
            'cnss_masked' => $cnss !== null ? PersonalData::maskCnss($cnss) : null,
            'rib_masked' => $rib !== null ? PersonalData::maskRib($rib) : null,
            'has_rib' => $rib !== null,
            'bank_name' => $row['bank_name'],
            'birth_date' => $row['birth_date'],
            'marital_status' => $row['marital_status'],
            'dependent_children' => (int) $row['dependent_children'],
            'personal_email' => $row['personal_email'],
            'personal_phone' => $row['personal_phone'],
            'city' => $row['city'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * Saisit ou met à jour le RIB du salarié connecté. Renvoie une clé de message d'erreur, ou null si réussi.
     * La valeur est validée puis chiffrée en PHP ; l'unicité est portée par l'index aveugle (rejet d'un doublon).
     */
    public function setRib(int $actorAccountId, string $rib, string $bankName): ?string
    {
        $normalized = PersonalData::normalizeRib($rib);
        if ($normalized === null) {
            return 'personal.rib_invalid';
        }
        $bankName = trim($bankName);
        if ($bankName === '' || mb_strlen($bankName) > 100) {
            return 'personal.bank_invalid';
        }
        $cipher = FieldCipher::fromRuntime();
        $query = $this->db->prepare('CALL personal_data_set_rib(?, ?, ?, ?)');
        try {
            $query->execute([$actorAccountId, $cipher->encrypt($normalized), $cipher->blindIndex($normalized), $bankName]);
            $query->closeCursor();
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                // Index aveugle unique : ce RIB est déjà enregistré (indice de fraude : deux salaires, un compte).
                return 'personal.rib_duplicate';
            }
            // Texte du SIGNAL comparé à un code fixe, jamais affiché ni journalisé.
            if (($error->errorInfo[2] ?? '') === 'PDATA_NO_RECORD') {
                return 'personal.no_record';
            }
            throw $error;
        }
        return null;
    }
}
