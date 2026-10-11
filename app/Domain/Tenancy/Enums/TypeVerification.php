<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Ce que retient une ligne du registre des vérifications d'une société
 * (voir VerificationSociete et EvaluateurDeVerification) :
 * - A, l'entreprise et ses établissements : l'unité légale au répertoire
 *   Sirene de l'INSEE (l'entreprise existe et n'est pas cessée), le SIRET
 *   d'un magasin (actif et rattaché au SIREN de la société) ;
 * - B, la qualité de représentant du propriétaire : son identité vérifiée
 *   par un prestataire certifié, rapprochée des dirigeants du registre
 *   national des entreprises (B1), ou le mandat qu'un dirigeant ainsi
 *   vérifié lui donne (B2), la connexion vérifiée à la plateforme agréée
 *   au même SIREN (B3, étape 2), ou les pièces examinées par l'éditeur
 *   (B4, toujours avec C) ;
 * - C, le contrôle de l'adresse officielle : le code d'un courrier
 *   recommandé, ou d'une lettre recommandée électronique qualifiée remise à
 *   un dirigeant identifié, saisi dans le compte ;
 * - les signaux de risque constatés (voir SignalDeRisque) et la lettre
 *   simple qui informe le siège à la validation.
 */
enum TypeVerification: string implements HasLabel
{
    case SireneUnite = 'sirene_unite';
    case SireneEtablissement = 'sirene_etablissement';
    case RneRepresentants = 'rne_representants';
    case Identite = 'identite';
    case Mandat = 'mandat';
    case Courrier = 'courrier';
    case Lre = 'lre';
    case PdpKyb = 'pdp_kyb';
    case Manuelle = 'manuelle';
    case Signal = 'signal';
    case LettreInformation = 'lettre_information';

    public function getLabel(): string
    {
        return match ($this) {
            self::SireneUnite => 'Entreprise au répertoire Sirene',
            self::SireneEtablissement => 'Établissement au répertoire Sirene',
            self::RneRepresentants => 'Dirigeants au registre national des entreprises',
            self::Identite => 'Identité vérifiée à distance',
            self::Mandat => 'Mandat approuvé par un dirigeant',
            self::Courrier => 'Code du courrier recommandé',
            self::Lre => 'Code de la lettre recommandée électronique',
            self::PdpKyb => 'Connexion vérifiée à la plateforme agréée',
            self::Manuelle => 'Décision de l\'éditeur',
            self::Signal => 'Signal de risque',
            self::LettreInformation => 'Lettre d\'information au siège',
        };
    }

    /**
     * Les détails que la ligne retient, et seulement eux : jamais l'image
     * ni le numéro d'une pièce d'identité, ni l'adresse de la personne.
     *
     * @return list<string>
     */
    public function detailsRetenus(): array
    {
        return match ($this) {
            self::SireneUnite => ['siren', 'etat_administratif', 'categorie_juridique', 'denomination', 'diffusion_partielle'],
            self::SireneEtablissement => ['siret', 'etat_administratif', 'rattache', 'adresse'],
            self::RneRepresentants => ['siren', 'identite_id', 'chaine', 'role', 'debut_fonction'],
            self::Identite => ['nom', 'nom_usage', 'prenoms', 'naissance', 'niveau_garantie', 'consentement', 'demande_accord_id'],
            self::Mandat => ['demande_accord_id', 'dirigeant', 'role', 'identite_id'],
            self::Courrier => ['envoi_id', 'motif', 'source_adresse', 'reference_envoi'],
            self::Lre => ['envoi_id', 'motif', 'dirigeant', 'reference_envoi'],
            self::PdpKyb => ['company_verification_status', 'user_identity_verification_status', 'nom', 'prenoms'],
            self::Manuelle => ['pieces', 'decision'],
            self::Signal => ['constat'],
            self::LettreInformation => ['reference_envoi', 'personne_verifiee', 'source_adresse'],
        };
    }
}
