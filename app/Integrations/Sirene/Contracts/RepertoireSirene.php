<?php

namespace App\Integrations\Sirene\Contracts;

use App\Integrations\Sirene\EtablissementSirene;
use App\Integrations\Sirene\SireneIndisponibleException;
use App\Integrations\Sirene\UniteSirene;

/**
 * Le répertoire Sirene de l'INSEE, répertoire public des entreprises et de
 * leurs établissements (voir InseeSirene) : il dit si une entreprise
 * existe et n'est pas cessée, si un établissement est actif et à quelle
 * entreprise il est rattaché, et leurs adresses. Il ne dit rien de la
 * personne qui s'inscrit.
 *
 * Une implémentation factice (FauxRepertoireSirene) sert au développement
 * et aux tests, jamais en production (voir VerificationServiceProvider).
 */
interface RepertoireSirene
{
    /**
     * L'unité légale d'un SIREN, null si le répertoire ne la connaît pas.
     *
     * @throws SireneIndisponibleException
     */
    public function unite(string $siren): ?UniteSirene;

    /**
     * L'établissement d'un SIRET, null si le répertoire ne le connaît pas.
     *
     * @throws SireneIndisponibleException
     */
    public function etablissement(string $siret): ?EtablissementSirene;
}
