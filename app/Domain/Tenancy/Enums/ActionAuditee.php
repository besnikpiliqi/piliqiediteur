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
    case AccordDirigeantAnnule = 'accord_dirigeant_annule';
    case CodeEnvoye = 'code_envoye';
    case CodeSaisi = 'code_saisi';
    case CodeAnnule = 'code_annule';
    case SocieteValidee = 'societe_validee';
    case SocieteRefusee = 'societe_refusee';
    case ReclamationOuverte = 'reclamation_ouverte';
    case MagasinCree = 'magasin_cree';
    case MagasinModifie = 'magasin_modifie';
    case SiretChange = 'siret_change';
    case MagasinFerme = 'magasin_ferme';
    case MagasinRouvert = 'magasin_rouvert';
    case EtablissementPrincipalChoisi = 'etablissement_principal_choisi';
    case DemonstrationOuverte = 'demonstration_ouverte';
    case DemonstrationFermee = 'demonstration_fermee';
    case MembreCree = 'membre_cree';
    case AdministrateurCree = 'administrateur_cree';
    case AdministrateurNomme = 'administrateur_nomme';
    case AdministrateurModifie = 'administrateur_modifie';
    case ExpertComptableCree = 'expert_comptable_cree';
    case DroitsModifies = 'droits_modifies';
    case ResponsableNomme = 'responsable_nomme';
    case ResponsableRetire = 'responsable_retire';
    case NominationAnnulee = 'nomination_annulee';
    case CompteSuspendu = 'compte_suspendu';
    case CompteReactive = 'compte_reactive';
    case CompteRetire = 'compte_retire';
    case CompteReintegre = 'compte_reintegre';
    case CompteRefuse = 'compte_refuse';
    case MotDePasseProvisoireDonne = 'provisoire_donne';
    case LienDAccesEnvoye = 'lien_d_acces_envoye';
    case RattachementPropose = 'rattachement_propose';
    case RattachementAccepte = 'rattachement_accepte';
    case SocieteCreeeParUnCompteRetire = 'societe_creee_par_un_compte_retire';
    case ProprieteTransferee = 'propriete_transferee';
    case AdresseDeConnexionChangee = 'adresse_de_connexion_changee';
    case VenteSansStock = 'vente_sans_stock';

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
            self::AccordDirigeantAnnule => 'Demande d\'accord d\'un dirigeant annulée',
            self::CodeEnvoye => 'Code de confirmation envoyé',
            self::CodeSaisi => 'Code de confirmation saisi',
            self::CodeAnnule => 'Code de confirmation annulé',
            self::SocieteValidee => 'Société validée',
            self::SocieteRefusee => 'Société refusée',
            self::ReclamationOuverte => 'Réclamation ouverte',
            self::MagasinCree => 'Magasin créé',
            self::MagasinModifie => 'Magasin modifié',
            self::SiretChange => 'SIRET du magasin changé',
            self::MagasinFerme => 'Magasin fermé',
            self::MagasinRouvert => 'Magasin rouvert',
            self::EtablissementPrincipalChoisi => 'Établissement principal indiqué',
            self::DemonstrationOuverte => 'Magasin de démonstration ouvert',
            self::DemonstrationFermee => 'Magasin de démonstration fermé',
            self::MembreCree => 'Compte créé',
            self::AdministrateurCree => 'Administrateur créé',
            self::AdministrateurNomme => 'Administrateur nommé',
            self::AdministrateurModifie => 'Administrateur modifié',
            self::ExpertComptableCree => 'Expert-comptable créé',
            self::DroitsModifies => 'Droits modifiés',
            self::ResponsableNomme => 'Responsable nommé',
            self::ResponsableRetire => 'Responsable retiré',
            self::NominationAnnulee => 'Nomination d\'un responsable annulée',
            self::CompteSuspendu => 'Compte suspendu',
            self::CompteReactive => 'Compte réactivé',
            self::CompteRetire => 'Compte retiré de la société',
            self::CompteReintegre => 'Compte réintégré',
            self::CompteRefuse => 'Compte refusé par la personne',
            self::MotDePasseProvisoireDonne => 'Nouveau mot de passe provisoire donné',
            self::LienDAccesEnvoye => 'Lien de renouvellement de l\'accès envoyé',
            self::RattachementPropose => 'Rattachement proposé',
            self::RattachementAccepte => 'Rattachement accepté',
            self::SocieteCreeeParUnCompteRetire => 'Société créée par un compte retiré',
            self::ProprieteTransferee => 'Propriété transférée',
            self::AdresseDeConnexionChangee => 'Adresse de connexion changée',
            self::VenteSansStock => 'Vente au-delà du stock',
        };
    }

    public function categorie(): CategorieAudit
    {
        return match ($this) {
            self::VerificationEnregistree, self::PieceDeposee, self::PieceEffacee, self::PiecesConsultees, self::AccordDirigeantDemande, self::AccordDirigeantAnnule,
            self::CodeEnvoye, self::CodeSaisi, self::CodeAnnule, self::SocieteValidee, self::SocieteRefusee, self::ReclamationOuverte => CategorieAudit::Verification,
            self::MembreCree, self::AdministrateurCree, self::AdministrateurNomme, self::AdministrateurModifie, self::ExpertComptableCree, self::DroitsModifies,
            self::ResponsableNomme, self::ResponsableRetire, self::NominationAnnulee, self::CompteSuspendu, self::CompteReactive, self::CompteRetire,
            self::CompteReintegre, self::CompteRefuse, self::MotDePasseProvisoireDonne, self::LienDAccesEnvoye, self::RattachementPropose,
            self::RattachementAccepte, self::SocieteCreeeParUnCompteRetire, self::ProprieteTransferee, self::AdresseDeConnexionChangee => CategorieAudit::Acces,
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
            self::SocieteInscrite => ['siren', 'raison_sociale', 'forme_juridique', 'proprietaire'],
            self::IdentiteModifiee => Societe::CHAMPS_DE_L_IDENTITE,
            self::ParametresFiscauxModifies => ['regime_tva', 'option_debits_tva', 'mois_fin_exercice'],
            self::SirenCorrigeAvantValidation => ['siren', 'sirets', ...Societe::CHAMPS_DE_L_IDENTITE],
            self::VerificationEnregistree => ['type', 'resultat', 'fournisseur', 'siret', 'signal', 'beneficiaire'],
            self::PieceDeposee => ['type_piece', 'empreinte'],
            self::PieceEffacee => ['type_piece', 'motif'],
            self::PiecesConsultees => ['pieces'],
            self::AccordDirigeantDemande => ['dirigeant', 'role'],
            self::AccordDirigeantAnnule => ['dirigeant', 'raison'],
            self::CodeEnvoye => ['motif', 'canal', 'source_adresse', 'reference_envoi'],
            self::CodeSaisi => ['motif', 'resultat', 'tentatives'],
            self::CodeAnnule => ['motif', 'raison'],
            self::SocieteValidee => ['preuve', 'beneficiaire', 'personne_verifiee', 'qualite_du_titulaire', 'sirets'],
            self::SocieteRefusee, self::ReclamationOuverte, self::DemonstrationOuverte, self::DemonstrationFermee => ['motif'],
            self::MagasinCree, self::MagasinModifie => ['nom', 'code_magasin', 'siret', 'adresse', 'complement_adresse', 'code_postal', 'ville', 'pays', 'etablissement_verifie'],
            self::SiretChange => ['siret', 'adresse', 'complement_adresse', 'code_postal', 'ville', 'etablissement_verifie'],
            // Pour une cession, l'indication d'établissement principal
            // retirée, s'il la portait, la valeur hors taxe du stock sorti
            // au coût moyen, le nombre de produits sortis et ceux restés en
            // stock négatif, sans mouvement (voir FermerMagasinAction).
            self::MagasinFerme => ['statut', 'motif', 'est_etablissement_principal', 'valeur_stock_sorti_ht', 'produits_sortis', 'produits_en_stock_negatif'],
            self::MagasinRouvert => ['statut', 'etablissement_verifie'],
            self::EtablissementPrincipalChoisi => ['etablissement_principal'],
            // Les droits de chaque compte touché, avant et après, avec son
            // nom au moment de l'opération (voir JournalDesDroits).
            self::MembreCree, self::AdministrateurCree, self::AdministrateurNomme, self::AdministrateurModifie, self::ExpertComptableCree, self::DroitsModifies,
            self::ResponsableNomme, self::ResponsableRetire, self::NominationAnnulee, self::CompteSuspendu, self::CompteReactive, self::CompteRetire,
            self::CompteReintegre, self::CompteRefuse, self::RattachementAccepte, self::ProprieteTransferee => ['droits'],
            self::MotDePasseProvisoireDonne, self::LienDAccesEnvoye, self::AdresseDeConnexionChangee => ['compte'],
            self::RattachementPropose => ['compte', 'role_societe', 'magasins'],
            self::SocieteCreeeParUnCompteRetire => ['compte', 'siren', 'societe_quittee'],
            // Le numéro de la vente et, pour chaque produit qu'elle a fait
            // passer sous zéro, sa référence, son stock avant la vente et
            // la quantité vendue (voir EmettreFactureAction).
            self::VenteSansStock => ['vente', 'produits'],
        };
    }
}
