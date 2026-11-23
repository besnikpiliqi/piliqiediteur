<?php

namespace App\Integrations\Sirene;

/**
 * Ce que le répertoire Sirene dit d'une unité légale (une entreprise, un
 * SIREN) : active ou cessée, sa dénomination (le nom et le prénom d'un
 * entrepreneur individuel), sa catégorie juridique, le NIC de son siège,
 * son code d'activité principale et la nomenclature de ce code (NAFRev2,
 * « 47.11B » ; null quand le répertoire ne les donne pas : voir
 * AvertissementsDuCodeDActivite et
 * ControleSirene::exerceLesActivitesComptables()).
 * En diffusion partielle (statut « P »), l'entreprise a demandé que son
 * identité et son adresse ne soient pas publiées : seule la commune reste
 * publique, et la dénomination est absente.
 */
final readonly class UniteSirene
{
    public function __construct(
        public string $siren,
        public bool $active,
        public ?string $denomination,
        public ?string $categorieJuridique,
        public ?string $nicDuSiege,
        public bool $diffusionPartielle,
        public ?string $activitePrincipale,
        public ?string $nomenclatureActivite,
    ) {}

    public function siretDuSiege(): ?string
    {
        return $this->nicDuSiege === null ? null : $this->siren.$this->nicDuSiege;
    }
}
