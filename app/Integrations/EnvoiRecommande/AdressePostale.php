<?php

namespace App\Integrations\EnvoiRecommande;

/**
 * L'adresse officielle du siège d'une entreprise, avec sa dénomination et
 * sa source : le registre national des entreprises ou, quand il n'en donne
 * pas, le répertoire Sirene de l'INSEE. Jamais une adresse saisie dans le
 * logiciel ni tirée d'une pièce déposée (voir DestinataireOfficiel).
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
     * Les lignes de l'adresse telles que le courrier les imprime, sous le
     * nom du destinataire.
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

    /**
     * @param  array{denomination: string|null, adresse: string, complement_adresse: string|null, code_postal: string, ville: string, source: string}  $donnees  ce qu'a écrit enTableau()
     */
    public static function depuisTableau(array $donnees): self
    {
        return new self($donnees['denomination'], $donnees['adresse'], $donnees['complement_adresse'], $donnees['code_postal'], $donnees['ville'], $donnees['source']);
    }
}
