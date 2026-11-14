<?php

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Le journal d'audit ne peut pas chaîner son entrée : sa clé HMAC
 * (JOURNAL_AUDIT_CLE) ou son identifiant (JOURNAL_AUDIT_CLE_ID) est
 * absent ou trop court. Fermé par défaut : l'action qui devait s'y
 * inscrire est refusée avec lui, dans la même transaction. Un incident du
 * serveur, signalé au journal de l'application (voir
 * GereLesExceptionsMetier) ; aucun message ne contient la clé.
 */
class JournalAuditNonConfigureException extends RuntimeException
{
    public static function cleAbsente(): self
    {
        return new self('Journal d\'audit non configuré : sa clé (JOURNAL_AUDIT_CLE, au moins 32 octets en base64) ou son identifiant (JOURNAL_AUDIT_CLE_ID) est absent. Rien n\'a été enregistré.');
    }
}
