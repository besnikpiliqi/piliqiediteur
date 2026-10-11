<?php

namespace App\Integrations\EnvoiRecommande\Contracts;

use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\EnvoiRecommande\EnvoiRecommandeIndisponibleException;
use App\Integrations\EnvoiRecommande\SuiviDEnvoi;

/**
 * Le prestataire d'envoi de l'éditeur : courrier recommandé avec accusé de
 * réception et remise contre signature, lettre recommandée électronique
 * qualifiée (eIDAS art. 44, équivalente au recommandé papier, CPCE L100)
 * remise à un dirigeant identifié, et lettre simple. Chaque envoi rend sa
 * référence, que suivre() et les rappels du prestataire reprennent.
 *
 * Le corps d'un pli peut porter un code de confirmation en clair : il ne
 * se garde nulle part ailleurs que dans le pli (voir CodesDeConfirmation).
 * Une classe par prestataire, quand l'éditeur l'aura choisi ; une
 * implémentation factice (FauxServiceEnvoiRecommande) sert au
 * développement et aux tests, jamais en production (voir
 * VerificationServiceProvider).
 */
interface ServiceEnvoiRecommande
{
    /**
     * @param  list<string>  $corps
     *
     * @throws EnvoiRecommandeIndisponibleException
     */
    public function envoyerCourrier(AdressePostale $destinataire, string $objet, array $corps): string;

    /**
     * @param  list<string>  $corps
     *
     * @throws EnvoiRecommandeIndisponibleException
     */
    public function envoyerLre(string $nomDuDestinataire, string $email, string $objet, array $corps): string;

    /**
     * @param  list<string>  $corps
     *
     * @throws EnvoiRecommandeIndisponibleException
     */
    public function envoyerLettreSimple(AdressePostale $destinataire, string $objet, array $corps): string;

    /**
     * @throws EnvoiRecommandeIndisponibleException
     */
    public function suivre(string $reference): SuiviDEnvoi;
}
