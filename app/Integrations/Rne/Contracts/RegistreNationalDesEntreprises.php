<?php

namespace App\Integrations\Rne\Contracts;

use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\Rne\IdentiteRne;
use App\Integrations\Rne\RepresentantRne;
use App\Integrations\Rne\RneIndisponibleException;

/**
 * Le registre national des entreprises (RNE) de l'INPI (voir InpiRne) : il
 * dit qui dirige une entreprise, personnes physiques ou morales, donne
 * l'adresse officielle de son siège et son identité légale. Le courrier
 * recommandé de la vérification part au nom de son représentant légal, à
 * cette adresse (voir DestinataireOfficiel) ; la société d'un client
 * invité par un cabinet d'expertise comptable naît avec cette identité
 * (voir AccepterLInvitationAction).
 *
 * Une implémentation factice (FauxRne) sert au développement et aux tests,
 * jamais en production (voir VerificationServiceProvider).
 */
interface RegistreNationalDesEntreprises
{
    /**
     * Les dirigeants de l'entreprise, vides si le registre ne la connaît
     * pas.
     *
     * @return list<RepresentantRne>
     *
     * @throws RneIndisponibleException
     */
    public function representants(string $siren): array;

    /**
     * L'adresse du siège de l'entreprise, null si le registre ne la donne
     * pas.
     *
     * @throws RneIndisponibleException
     */
    public function adresseDuSiege(string $siren): ?AdressePostale;

    /**
     * L'identité légale de l'entreprise (dénomination, catégorie
     * juridique, capital, immatriculation, siège), null si le registre ne
     * la connaît pas ; un champ qu'il ne donne pas, ou donne illisible, est
     * nul, sans rendre toute la réponse illisible.
     *
     * @throws RneIndisponibleException
     */
    public function identite(string $siren): ?IdentiteRne;
}
