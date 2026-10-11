<?php

namespace App\Integrations\Sirene;

/**
 * Ce que le répertoire Sirene dit d'un établissement (un SIRET) : actif ou
 * fermé, le SIREN de l'entreprise à laquelle il est rattaché, et son
 * adresse, absente en diffusion partielle hors la commune.
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
        public ?string $ville,
        public bool $diffusionPartielle,
    ) {}

    public function estRattacheA(string $siren): bool
    {
        return $this->siren === $siren && str_starts_with($this->siret, $siren);
    }
}
