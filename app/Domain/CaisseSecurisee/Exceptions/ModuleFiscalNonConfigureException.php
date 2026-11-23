<?php

namespace App\Domain\CaisseSecurisee\Exceptions;

/**
 * Le module fiscal ne peut pas signer : clé privée absente ou illisible,
 * clé publique non épinglée, clé épinglée invalide.
 *
 * Fermé par défaut : SignataireFiscal lève cette exception au lieu de
 * signer, et toute écriture fiscale qui la reçoit refuse l'opération
 * qu'elle devait enregistrer, plutôt que de l'enregistrer sans signature.
 * Aucun message ne contient la clé privée : seulement l'empreinte
 * (cle_id) de clés publiques, le nom d'un fichier épinglé, ou les erreurs
 * qu'OpenSSL a données en lisant une clé (voir ErreursOpenSsl).
 */
class ModuleFiscalNonConfigureException extends RefusFiscalException
{
    public static function clePriveeAbsente(): self
    {
        return new self('Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.');
    }

    /**
     * @param  string  $cause  pourquoi elle ne se lit pas : sa valeur, ou les erreurs d'OpenSSL
     */
    public static function clePriveeIllisible(string $cause): self
    {
        return new self("Caisse sécurisée non configurée : la clé privée de signature est illisible (PEM encodé en base64 attendu, phrase secrète à vérifier). {$cause}");
    }

    public static function clePriveeNonPriseEnCharge(): self
    {
        return new self('Caisse sécurisée non configurée : la clé privée de signature doit être une clé ECDSA P-256.');
    }

    public static function cleNonEpinglee(string $cleId): self
    {
        return new self("Caisse sécurisée non configurée : la clé publique {$cleId} n'est pas épinglée dans resources/caisse-securisee/cles.");
    }

    /**
     * @param  string  $cause  pourquoi elle ne sert pas : les erreurs d'OpenSSL, ou sa courbe
     */
    public static function cleEpingleeIllisible(string $fichier, string $cause): self
    {
        return new self("Caisse sécurisée non configurée : la clé épinglée {$fichier} n'est pas une clé publique ECDSA P-256 lisible. {$cause}");
    }

    public static function clePriveeEpinglee(string $fichier): self
    {
        return new self("Caisse sécurisée non configurée : le fichier épinglé {$fichier} contient une clé privée. Retirez-le du dépôt et remplacez cette clé.");
    }
}
