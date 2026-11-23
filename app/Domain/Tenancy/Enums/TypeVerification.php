<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Ce que retient une ligne du registre des vérifications d'une société
 * (voir VerificationSociete) : l'unité légale au répertoire Sirene de
 * l'INSEE (l'entreprise existe et n'est pas cessée), le SIRET d'un magasin
 * (actif et rattaché au SIREN de la société), le code d'un courrier
 * recommandé saisi (la seule preuve qui valide la société, voir
 * SaisirCodeDeConfirmationAction), ou la décision de l'éditeur sur les
 * pièces d'un courrier non reçu (voir ExaminerLesPiecesAction).
 */
enum TypeVerification: string implements HasLabel
{
    case SireneUnite = 'sirene_unite';
    case SireneEtablissement = 'sirene_etablissement';
    case Courrier = 'courrier';
    case Manuelle = 'manuelle';

    public function getLabel(): string
    {
        return match ($this) {
            self::SireneUnite => 'Entreprise au répertoire Sirene',
            self::SireneEtablissement => 'Établissement au répertoire Sirene',
            self::Courrier => 'Code du courrier recommandé',
            self::Manuelle => 'Décision de l\'éditeur',
        };
    }

    /**
     * Les détails que la ligne retient, et seulement eux : jamais l'image
     * ni le numéro d'une pièce d'identité, ni l'adresse d'une personne.
     * Le code d'un courrier et la décision de l'éditeur retiennent le
     * SIREN qu'ils prouvent.
     *
     * @return list<string>
     */
    public function detailsRetenus(): array
    {
        return match ($this) {
            self::SireneUnite => ['siren', 'etat_administratif', 'categorie_juridique', 'denomination', 'diffusion_partielle'],
            self::SireneEtablissement => ['siret', 'etat_administratif', 'rattache', 'adresse', 'motif'],
            self::Courrier => ['siren', 'envoi_id', 'motif', 'source_adresse', 'reference_envoi'],
            self::Manuelle => ['siren', 'pieces', 'decision', 'justification'],
        };
    }
}
