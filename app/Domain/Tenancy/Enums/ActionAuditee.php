<?php

namespace App\Domain\Tenancy\Enums;

use App\Domain\Tenancy\Models\Societe;
use Filament\Support\Contracts\HasLabel;

/**
 * Les actions qui s'inscrivent au journal d'audit d'une société (voir
 * JournalAudit), chacune dans sa catégorie (voir categorie()) et avec les
 * seuls champs qu'elle retient (voir champsAudites()) : jamais un mot de
 * passe, un jeton, un secret ni un code.
 */
enum ActionAuditee: string implements HasLabel
{
    case SocieteInscrite = 'societe_inscrite';
    case IdentiteModifiee = 'identite_modifiee';
    case ParametresFiscauxModifies = 'parametres_fiscaux_modifies';
    case SirenCorrigeAvantValidation = 'siren_corrige_avant_validation';
    case VerificationEnregistree = 'verification_enregistree';
    case SocieteRefusee = 'societe_refusee';
    case MagasinCree = 'magasin_cree';
    case MagasinModifie = 'magasin_modifie';

    /**
     * Les mots qui, dans un nom de champ (entre ses traits de soulignement,
     * voir JournalAudit), désignent ce qui ne s'inscrit jamais au journal :
     * un mot de passe, un jeton, un secret, un code de confirmation.
     */
    public const CHAMPS_INTERDITS = ['password', 'mot_de_passe', 'remember_token', 'jeton', 'token', 'secret', 'code_confirmation'];

    public function getLabel(): string
    {
        return match ($this) {
            self::SocieteInscrite => 'Société inscrite',
            self::IdentiteModifiee => 'Identité de la société modifiée',
            self::ParametresFiscauxModifies => 'Paramètres fiscaux modifiés',
            self::SirenCorrigeAvantValidation => 'SIREN corrigé avant la validation',
            self::VerificationEnregistree => 'Vérification enregistrée',
            self::SocieteRefusee => 'Société refusée',
            self::MagasinCree => 'Magasin créé',
            self::MagasinModifie => 'Magasin modifié',
        };
    }

    public function categorie(): CategorieAudit
    {
        return match ($this) {
            self::VerificationEnregistree, self::SocieteRefusee => CategorieAudit::Verification,
            default => CategorieAudit::Operations,
        };
    }

    /**
     * Les seuls champs que l'action retient dans ses anciennes et nouvelles
     * valeurs. Le code court d'un magasin (voir CodeMagasin), qui n'a rien
     * de secret, s'y écrit « code_magasin ».
     *
     * @return list<string>
     */
    public function champsAudites(): array
    {
        return match ($this) {
            self::SocieteInscrite => ['siren', 'raison_sociale', 'forme_juridique', 'proprietaire', 'premier_magasin'],
            self::IdentiteModifiee => Societe::CHAMPS_DE_L_IDENTITE,
            self::ParametresFiscauxModifies => ['regime_tva', 'option_debits_tva', 'mois_fin_exercice'],
            self::SirenCorrigeAvantValidation => ['siren', 'sirets', ...Societe::CHAMPS_DE_L_IDENTITE],
            self::VerificationEnregistree => ['type', 'resultat', 'fournisseur', 'siret'],
            self::SocieteRefusee => ['motif'],
            self::MagasinCree, self::MagasinModifie => ['nom', 'code_magasin', 'siret', 'adresse', 'complement_adresse', 'code_postal', 'ville', 'pays', 'etablissement_verifie'],
        };
    }
}
