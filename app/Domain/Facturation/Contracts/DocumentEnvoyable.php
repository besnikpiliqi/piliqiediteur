<?php

namespace App\Domain\Facturation\Contracts;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Facturation\Services\Emetteur;
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

    /**
     * Le vendeur que le document imprime, et que présente l'e-mail qui le
     * porte (voir DocumentMail) : celui qu'une facture ou un avoir
     * numéroté a signé à son émission (voir FigeSonEmetteur), le magasin
     * tel qu'il est aujourd'hui pour un devis, jamais signé.
     *
     * @throws DocumentSansEnregistrementSigneException
     */
    public function emetteur(): Emetteur;
}
