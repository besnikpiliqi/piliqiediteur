<?php

namespace App\Domain\Facturation\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Un document qui part par e-mail, son PDF en pièce jointe (voir
 * EnvoyerDocumentAction) : devis, facture ou avoir.
 */
interface DocumentEnvoyable
{
    /**
     * Son historique d'envois et de relances (voir EnvoiDocument).
     */
    public function envois(): MorphMany;

    /**
     * Ce que le document est pour son client, tel qu'imprimé sur son PDF
     * (« Facture », « Ticket de caisse », « Devis », « Avoir »).
     */
    public function libelleDocument(): string;

    /**
     * Seul un document qui existe légalement pour son client part par
     * e-mail : jamais une facture ou un avoir encore brouillon, par
     * exemple.
     */
    public function peutEtreEnvoye(): bool;
}
