<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Ce qu'a contrôlé une vérification de la société (voir
 * VerificationSociete) : l'unité légale au répertoire Sirene de l'INSEE
 * (l'entreprise existe et n'est pas cessée), ou un établissement (le
 * SIRET d'un magasin est actif et rattaché au SIREN de la société).
 */
enum TypeVerification: string implements HasLabel
{
    case SireneUnite = 'sirene_unite';
    case SireneEtablissement = 'sirene_etablissement';

    public function getLabel(): string
    {
        return match ($this) {
            self::SireneUnite => 'Entreprise au répertoire Sirene',
            self::SireneEtablissement => 'Établissement au répertoire Sirene',
        };
    }

    /**
     * Les détails que la ligne retient de la réponse, et seulement eux.
     *
     * @return list<string>
     */
    public function detailsRetenus(): array
    {
        return match ($this) {
            self::SireneUnite => ['siren', 'etat_administratif', 'categorie_juridique', 'denomination', 'diffusion_partielle'],
            self::SireneEtablissement => ['siret', 'etat_administratif', 'rattache', 'adresse'],
        };
    }
}
