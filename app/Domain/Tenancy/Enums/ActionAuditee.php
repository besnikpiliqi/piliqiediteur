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
    case PieceDeposee = 'piece_deposee';
    case PieceEffacee = 'piece_effacee';
    case PiecesConsultees = 'pieces_consultees';
    case AccordDirigeantDemande = 'accord_dirigeant_demande';
    case CodeEnvoye = 'code_envoye';
    case CodeSaisi = 'code_saisi';
    case SocieteValidee = 'societe_validee';
    case SocieteRefusee = 'societe_refusee';
    case ReclamationOuverte = 'reclamation_ouverte';
    case MagasinCree = 'magasin_cree';
    case MagasinModifie = 'magasin_modifie';
    case DemonstrationOuverte = 'demonstration_ouverte';
    case DemonstrationFermee = 'demonstration_fermee';

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
            self::PieceDeposee => 'Pièce déposée pour l\'éditeur',
            self::PieceEffacee => 'Pièce effacée',
            self::PiecesConsultees => 'Pièces consultées par l\'éditeur',
            self::AccordDirigeantDemande => 'Accord d\'un dirigeant demandé',
            self::CodeEnvoye => 'Code de confirmation envoyé',
            self::CodeSaisi => 'Code de confirmation saisi',
            self::SocieteValidee => 'Société validée',
            self::SocieteRefusee => 'Société refusée',
            self::ReclamationOuverte => 'Réclamation ouverte',
            self::MagasinCree => 'Magasin créé',
            self::MagasinModifie => 'Magasin modifié',
            self::DemonstrationOuverte => 'Magasin de démonstration ouvert',
            self::DemonstrationFermee => 'Magasin de démonstration fermé',
        };
    }

    public function categorie(): CategorieAudit
    {
        return match ($this) {
            self::VerificationEnregistree, self::PieceDeposee, self::PieceEffacee, self::PiecesConsultees, self::AccordDirigeantDemande,
            self::CodeEnvoye, self::CodeSaisi, self::SocieteValidee, self::SocieteRefusee, self::ReclamationOuverte => CategorieAudit::Verification,
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
            self::VerificationEnregistree => ['type', 'resultat', 'fournisseur', 'siret', 'signal', 'beneficiaire'],
            self::PieceDeposee => ['type_piece', 'empreinte'],
            self::PieceEffacee => ['type_piece', 'motif'],
            self::PiecesConsultees => ['pieces'],
            self::AccordDirigeantDemande => ['dirigeant', 'role'],
            self::CodeEnvoye => ['motif', 'canal', 'source_adresse', 'reference_envoi'],
            self::CodeSaisi => ['motif', 'resultat', 'tentatives'],
            self::SocieteValidee => ['preuve', 'beneficiaire', 'sirets'],
            self::SocieteRefusee, self::ReclamationOuverte, self::DemonstrationOuverte, self::DemonstrationFermee => ['motif'],
            self::MagasinCree, self::MagasinModifie => ['nom', 'code_magasin', 'siret', 'adresse', 'complement_adresse', 'code_postal', 'ville', 'pays', 'etablissement_verifie'],
        };
    }
}
