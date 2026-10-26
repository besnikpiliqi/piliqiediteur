<?php

namespace App\Integrations\Rne\Contracts;

use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\Rne\RepresentantRne;
use App\Integrations\Rne\RneIndisponibleException;

/**
 * Le registre national des entreprises (RNE) de l'INPI (voir InpiRne) : il
 * dit qui dirige une entreprise, personnes physiques ou morales, et donne
 * l'adresse officielle de son siège. Le courrier recommandé de la
 * vérification part au nom de son représentant légal, à cette adresse
 * (voir DestinataireOfficiel).
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
}
