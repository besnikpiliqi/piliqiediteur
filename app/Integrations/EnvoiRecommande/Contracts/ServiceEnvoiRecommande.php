<?php

namespace App\Integrations\EnvoiRecommande\Contracts;

use App\Integrations\EnvoiRecommande\DestinataireDuCourrier;
use App\Integrations\EnvoiRecommande\EnvoiRecommandeIndisponibleException;
use App\Integrations\EnvoiRecommande\SuiviDEnvoi;

/**
 * Le prestataire d'envoi recommandé de l'éditeur, l'API de La Poste :
 * il imprime le courrier que le logiciel a mis en page (un PDF A4, voir
 * CourrierDeCode), le met sous pli et l'envoie en recommandé avec avis de
 * réception. Avec la remise au destinataire en personne, il n'est remis
 * que contre la signature du destinataire lui-même, qui va le chercher au
 * bureau de poste s'il est absent (à confirmer au contrat avec La Poste :
 * à défaut, une personne munie d'une procuration peut le retirer).
 * Chaque envoi rend sa référence, que suivre() et les rappels du
 * prestataire reprennent.
 *
 * Le courrier porte un code de confirmation en clair : il ne se garde
 * nulle part ailleurs que dans le pli (voir CodesDeConfirmation). Une
 * classe par prestataire, branchée sur le contrat de l'éditeur ; une
 * implémentation factice (FauxServiceEnvoiRecommande) sert au
 * développement et aux tests, jamais en production (voir
 * VerificationServiceProvider).
 */
interface ServiceEnvoiRecommande
{
    /**
     * @param  string  $pdf  le courrier mis en page, tel qu'il s'imprime
     *
     * @throws EnvoiRecommandeIndisponibleException
     */
    public function envoyerCourrier(DestinataireDuCourrier $destinataire, string $pdf, bool $remiseEnPersonne): string;

    /**
     * @throws EnvoiRecommandeIndisponibleException
     */
    public function suivre(string $reference): SuiviDEnvoi;
}
