<?php

namespace App\Integrations\Rne\Contracts;

use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\Rne\RepresentantRne;
use App\Integrations\Rne\RneIndisponibleException;

/**
 * Le registre national des entreprises (RNE) de l'INPI (voir InpiRne) : il
 * dit qui dirige une entreprise, personnes physiques ou morales, et donne
 * l'adresse de son siège, que le répertoire Sirene masque pour une
 * entreprise en diffusion partielle. Il ne dit pas que la personne qui
 * s'inscrit est l'un de ces dirigeants : seule une identité vérifiée,
 * rapprochée de l'un d'eux, le prouve (voir RapprochementDesDirigeants).
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
