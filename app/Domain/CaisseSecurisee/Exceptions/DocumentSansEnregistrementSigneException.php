<?php

namespace App\Domain\CaisseSecurisee\Exceptions;

use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;

/**
 * Un ticket, une facture ou un avoir numéroté n'a aucun enregistrement
 * dans la chaîne des ventes du registre fiscal : il a été numéroté hors de
 * son émission, directement en base, ce que signale aussi la vérification
 * de l'intégrité (voir VerificateurIntegrite).
 *
 * Fermé par défaut : seul son contenu signé dit quel émetteur il a
 * imprimé (voir FigeSonEmetteur), et son PDF ne se produit pas, ni pour
 * être téléchargé ni pour partir par e-mail, plutôt que d'être reconstitué
 * depuis le magasin tel qu'il est aujourd'hui.
 *
 * Son message tient, même pour un numéro de 40 caractères et avec la
 * phrase qu'ajoute l'envoi qu'il fait échouer (voir EnvoyerDocumentJob),
 * dans les 255 caractères de l'erreur d'un envoi.
 */
class DocumentSansEnregistrementSigneException extends RefusFiscalException
{
    public static function pour(Facture|Avoir $document): self
    {
        $designation = match (true) {
            $document instanceof Avoir => "L'avoir {$document->numero}",
            $document->estPourUnProfessionnel() => "La facture {$document->numero}",
            default => "Le ticket {$document->numero}",
        };

        return new self("{$designation} n'a aucun enregistrement signé au registre fiscal : son PDF n'est pas produit. Lancez la vérification de l'intégrité (Ventes > Caisse sécurisée) et contactez le support.");
    }
}
