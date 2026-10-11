<?php

namespace App\Integrations\EnvoiRecommande;

/**
 * Une adresse où part un courrier : la dénomination du destinataire et
 * l'adresse officielle de son siège, avec sa source, le répertoire Sirene
 * de l'INSEE ou, quand il la masque (diffusion partielle), le registre
 * national des entreprises. Jamais une adresse saisie ni tirée d'une pièce
 * déposée (voir AdresseOfficielle).
 */
final readonly class AdressePostale
{
    public const SOURCE_SIRENE = 'sirene';

    public const SOURCE_RNE = 'rne';

    public function __construct(
        public ?string $denomination,
        public string $adresse,
        public ?string $complementAdresse,
        public string $codePostal,
        public string $ville,
        public string $source,
    ) {}

    /**
     * Les lignes de l'adresse telles que le pli les imprime.
     *
     * @return list<string>
     */
    public function lignes(): array
    {
        return array_values(array_filter([$this->denomination, $this->adresse, $this->complementAdresse, trim("{$this->codePostal} {$this->ville}")], filled(...)));
    }

    /**
     * @return array{denomination: string|null, adresse: string, complement_adresse: string|null, code_postal: string, ville: string, source: string}
     */
    public function enTableau(): array
    {
        return [
            'denomination' => $this->denomination,
            'adresse' => $this->adresse,
            'complement_adresse' => $this->complementAdresse,
            'code_postal' => $this->codePostal,
            'ville' => $this->ville,
            'source' => $this->source,
        ];
    }
}
