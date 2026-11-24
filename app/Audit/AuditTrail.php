<?php

declare(strict_types=1);

namespace App\Audit;

use PDO;
use Throwable;

/**
 * Piste d'audit de sécurité (phase 7). Chaque événement applicatif est écrit DANS LA BASE, via la
 * procédure DEFINER audit_record (table en ajout seul, inviolable), EN PLUS du journal fichier
 * (auditEvent) — défense en profondeur.
 *
 * La classe est configurée une fois par requête (useConnection + actor). L'écriture en base est
 * « best-effort » : une panne d'audit ne doit jamais casser la requête, mais laisse une trace
 * fichier. On ne stocke JAMAIS d'identifiant saisi ni de secret : l'appelant ne transmet que des
 * identifiants numériques, un résultat et un contexte déjà expurgés.
 */
final class AuditTrail
{
    private static ?PDO $db = null;
    private static ?int $actor = null;

    /** Branche la piste sur la connexion de la requête (appelé dès la connexion établie). */
    public static function useConnection(PDO $db): void
    {
        self::$db = $db;
    }

    /** Acteur applicatif de la requête authentifiée (sert d'acteur par défaut et aux déclencheurs). */
    public static function actor(?int $accountId): void
    {
        self::$actor = $accountId;
    }

    public static function reset(): void
    {
        self::$db = null;
        self::$actor = null;
    }

    /**
     * Enregistre un événement en base. Appelée par auditEvent() après l'écriture fichier.
     * $resource = identifiant de l'enregistrement concerné ; $context peut porter 'table' (ressource)
     * et 'actor_label' (acteur lisible quand il n'y a pas de compte). Ces deux clés sont retirées du
     * contexte JSON stocké.
     */
    public static function record(string $event, ?int $actor, ?int $resource, array $context): void
    {
        if (self::$db === null) {
            return;
        }
        $table = isset($context['table']) ? (string) $context['table'] : null;
        $label = isset($context['actor_label']) ? (string) $context['actor_label'] : null;
        unset($context['table'], $context['actor_label']);
        $json = $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $query = self::$db->prepare('CALL audit_record(?, ?, ?, ?, ?, ?, ?)');
            $query->execute([$event, self::outcome($event), $actor ?? self::$actor, $label, $table, $resource, $json]);
            $query->closeCursor();
        } catch (Throwable $error) {
            // La piste fichier garde la trace ; on signale seulement l'indisponibilité de la base.
            error_log(json_encode(['at' => gmdate('c'), 'event' => 'audit.persist_failed', 'for' => $event]));
        }
    }

    /** Résultat déduit du nom de l'événement : refus, échec, ou succès. */
    private static function outcome(string $event): string
    {
        if (str_contains($event, 'denied')) {
            return 'denied';
        }
        foreach (['failed', 'throttled', 'rejected', 'operation_failed'] as $needle) {
            if (str_contains($event, $needle)) {
                return 'failure';
            }
        }
        return 'success';
    }
}
