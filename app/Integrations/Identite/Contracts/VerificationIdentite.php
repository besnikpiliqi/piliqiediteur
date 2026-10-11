<?php

namespace App\Integrations\Identite\Contracts;

use App\Integrations\Identite\DemandeDIdentite;
use App\Integrations\Identite\IdentiteIndisponibleException;
use App\Integrations\Identite\ResultatDIdentite;
use App\Integrations\Identite\SessionDIdentite;

/**
 * La vérification d'identité à distance d'un prestataire certifié par
 * l'ANSSI (PVID, niveau substantiel) : la personne y présente sa pièce
 * d'identité et son visage, puis revient dans PILIQI. Elle prouve qui est
 * la personne, jamais seule sa qualité de dirigeant (voir
 * RapprochementDesDirigeants).
 *
 * Chaque session est à usage unique et liée par son state à la session
 * PILIQI qui l'a ouverte (voir DemarrerVerificationIdentiteAction). Une
 * classe par prestataire, quand l'éditeur l'aura choisi ; une
 * implémentation factice (FausseVerificationIdentite) sert au
 * développement et aux tests, jamais en production (voir
 * VerificationServiceProvider).
 */
interface VerificationIdentite
{
    /**
     * @throws IdentiteIndisponibleException
     */
    public function demarrer(DemandeDIdentite $demande): SessionDIdentite;

    /**
     * Le résultat de la session, dont le state doit être celui donné à son
     * démarrage.
     *
     * @throws IdentiteIndisponibleException
     */
    public function resultat(string $reference, string $state): ResultatDIdentite;
}
