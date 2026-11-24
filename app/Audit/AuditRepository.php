<?php

declare(strict_types=1);

namespace App\Audit;

use PDO;

/**
 * Lecture de la piste d'audit pour l'auditeur de sécurité (phase 7). Tout passe par les procédures
 * DEFINER audit_recent / audit_suspicious : le rôle sp_security_auditor n'a aucun accès direct à la
 * table security_audit_log.
 */
final class AuditRepository
{
    public function __construct(private PDO $db) {}

    /** Les derniers événements, acteur rendu lisible. */
    public function recent(int $limit = 100): array
    {
        $query = $this->db->prepare('CALL audit_recent(?)');
        $query->execute([$limit]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $query->closeCursor();
        return $rows;
    }

    /** Signaux d'activité suspecte sur 24 h (rafales d'échecs, refus répétés, lectures massives). */
    public function suspicious(): array
    {
        $query = $this->db->query('CALL audit_suspicious()');
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $query->closeCursor();
        return $rows;
    }
}
