<?php

namespace App\Integrations\Sirene;

/**
 * Ce que le répertoire Sirene dit d'un établissement (un SIRET) : actif ou
 * fermé, le SIREN de l'entreprise à laquelle il est rattaché, son adresse,
 * absente en diffusion partielle hors la commune, le code de sa commune
 * (code officiel géographique, qui donne le territoire du magasin, voir
 * Territoire::depuisLeRepertoire()), absent pour un établissement à
 * l'étranger, son enseigne (à défaut sa dénomination usuelle) et s'il est
 * le siège de l'entreprise.
 */
final readonly class EtablissementSirene
{
    public function __construct(
        public string $siret,
        public string $siren,
        public bool $actif,
        public ?string $adresse,
        public ?string $complementAdresse,
        public ?string $codePostal,
        public ?string $codeCommune,
        public ?string $ville,
        public bool $diffusionPartielle,
        public ?string $enseigne,
        public bool $estSiege,
    ) {}

    public function estRattacheA(string $siren): bool
    {
        return $this->siren === $siren && str_starts_with($this->siret, $siren);
    }

    /**
     * Son NIC : les cinq derniers chiffres de son SIRET, après le SIREN.
     */
    public function nic(): string
    {
        return substr($this->siret, 9);
    }
}
