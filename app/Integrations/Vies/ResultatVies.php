<?php

namespace App\Integrations\Vies;

/**
 * Ce que VIES répond pour un n° de TVA (voir ClientVies::verifier()) :
 * valide ou inconnu, le nom et l'adresse que l'État membre en publie (le
 * plus souvent rien, ce que VIES écrit « --- »), le n° qu'il donne à la
 * consultation quand le demandeur s'est identifié par son propre n° de
 * TVA, et sa réponse telle qu'elle a été reçue, conservée comme preuve.
 */
final readonly class ResultatVies
{
    public function __construct(
        public bool $valide,
        public ?string $nom,
        public ?string $adresse,
        public ?string $identifiantConsultation,
        public string $reponse,
    ) {}
}
