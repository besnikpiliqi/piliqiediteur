<?php

namespace App\Domain\Facturation\Contracts;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Facturation\Services\Emetteur;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Un document qui part par e-mail, son PDF en pièce jointe (voir
 * EnvoyerDocumentAction) : devis, facture, avoir ou reçu de paiement.
 */
interface DocumentEnvoyable
{
    /**
     * Son historique d'envois et de relances (voir EnvoiDocument).
     */
    public function envois(): MorphMany;

    /**
     * Ce que le document est pour son client, tel qu'imprimé sur son PDF
     * (« Facture », « Ticket de caisse », « Devis », « Avoir », « Reçu de
     * paiement »).
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
     * numéroté a signé à son émission (voir FigeSonEmetteur), celui qu'un
     * devis a figé à son dernier envoi (voir Devis::emetteur()), celui
     * qu'un reçu de paiement a figé le jour de l'encaissement (voir
     * RecuPaiement::emetteur()), la société et le magasin tels qu'ils sont
     * aujourd'hui pour un brouillon.
     *
     * @throws DocumentSansEnregistrementSigneException
     */
    public function emetteur(): Emetteur;
}
