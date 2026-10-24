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
    case VerificationEnregistree = 'verification_enregistree';
    case PieceDeposee = 'piece_deposee';
    case PieceEffacee = 'piece_effacee';
    case PiecesConsultees = 'pieces_consultees';
    case CodeEnvoye = 'code_envoye';
    case CodeSaisi = 'code_saisi';
    case CodeAnnule = 'code_annule';
    case SocieteValidee = 'societe_validee';
    case InscriptionClose = 'inscription_close';
    case MagasinCree = 'magasin_cree';
    case MagasinModifie = 'magasin_modifie';
    case SiretChange = 'siret_change';
    case MagasinFerme = 'magasin_ferme';
    case MagasinCede = 'magasin_cede';
    case MagasinRouvert = 'magasin_rouvert';
    case EtablissementPrincipalChoisi = 'etablissement_principal_choisi';
    case DemonstrationOuverte = 'demonstration_ouverte';
    case DemonstrationFermee = 'demonstration_fermee';
    case MembreCree = 'membre_cree';
    case AdministrateurCree = 'administrateur_cree';
    case AdministrateurModifie = 'administrateur_modifie';
    case RoleDeSocieteChange = 'role_de_societe_change';
    case ExpertComptableCree = 'expert_comptable_cree';
    case DroitsModifies = 'droits_modifies';
    case ResponsableNomme = 'responsable_nomme';
    case ResponsableRetire = 'responsable_retire';
    case NominationAnnulee = 'nomination_annulee';
    case CompteSuspendu = 'compte_suspendu';
    case CompteReactive = 'compte_reactive';
    case CompteRetire = 'compte_retire';
    case CompteReintegre = 'compte_reintegre';
    case AccesRedonne = 'acces_redonne';
    case PremiereConnexion = 'premiere_connexion';
    case IdentifiantConsulte = 'identifiant_consulte';
    case TransmissionDemandee = 'transmission_demandee';
    case TransmissionAnnulee = 'transmission_annulee';
    case ProprieteTransferee = 'propriete_transferee';
    case ProprieteReprise = 'propriete_reprise';
    case AdresseDeConnexionChangee = 'adresse_de_connexion_changee';
    case AdresseDeConnexionRetiree = 'adresse_de_connexion_retiree';
    case VenteSansStock = 'vente_sans_stock';
    case AvoirFournisseurEmis = 'avoir_fournisseur_emis';
    case InventaireOuvertureValide = 'inventaire_ouverture_valide';

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
            self::VerificationEnregistree => 'Vérification enregistrée',
            self::PieceDeposee => 'Pièce déposée pour l\'éditeur',
            self::PieceEffacee => 'Pièce effacée',
            self::PiecesConsultees => 'Pièces consultées par l\'éditeur',
            self::CodeEnvoye => 'Code de confirmation envoyé',
            self::CodeSaisi => 'Code de confirmation saisi',
            self::CodeAnnule => 'Code de confirmation annulé',
            self::SocieteValidee => 'Société validée',
            self::InscriptionClose => 'Inscription close',
            self::MagasinCree => 'Magasin créé',
            self::MagasinModifie => 'Magasin modifié',
            self::SiretChange => 'SIRET du magasin changé',
            self::MagasinFerme => 'Magasin fermé',
            self::MagasinCede => 'Magasin cédé après sa fermeture',
            self::MagasinRouvert => 'Magasin rouvert',
            self::EtablissementPrincipalChoisi => 'Établissement principal indiqué',
            self::DemonstrationOuverte => 'Magasin de démonstration ouvert',
            self::DemonstrationFermee => 'Magasin de démonstration fermé',
            self::MembreCree => 'Compte créé',
            self::AdministrateurCree => 'Administrateur créé',
            self::AdministrateurModifie => 'Administrateur modifié',
            self::RoleDeSocieteChange => 'Rôle de société changé',
            self::ExpertComptableCree => 'Expert-comptable créé',
            self::DroitsModifies => 'Droits modifiés',
            self::ResponsableNomme => 'Responsable nommé',
            self::ResponsableRetire => 'Responsable retiré',
            self::NominationAnnulee => 'Nomination d\'un responsable annulée',
            self::CompteSuspendu => 'Compte suspendu',
            self::CompteReactive => 'Compte réactivé',
            self::CompteRetire => 'Compte retiré de la société',
            self::CompteReintegre => 'Compte réintégré',
            self::AccesRedonne => 'Accès redonné',
            self::PremiereConnexion => 'Première connexion',
            self::IdentifiantConsulte => 'Identifiant affiché à un administrateur',
            self::TransmissionDemandee => 'Transmission de la propriété demandée',
            self::TransmissionAnnulee => 'Transmission de la propriété annulée',
            self::ProprieteTransferee => 'Propriété transférée',
            self::ProprieteReprise => 'Propriété reprise par le code du courrier',
            self::AdresseDeConnexionChangee => 'Adresse de connexion changée',
            self::AdresseDeConnexionRetiree => 'Adresse de connexion retirée à la clôture de l\'inscription',
            self::VenteSansStock => 'Vente au-delà du stock',
            self::AvoirFournisseurEmis => 'Avoir fournisseur émis',
            self::InventaireOuvertureValide => 'Inventaire d\'ouverture validé',
        };
    }

    public function categorie(): CategorieAudit
    {
        return match ($this) {
            self::VerificationEnregistree, self::PieceDeposee, self::PieceEffacee, self::PiecesConsultees,
            self::CodeEnvoye, self::CodeSaisi, self::CodeAnnule, self::SocieteValidee, self::InscriptionClose => CategorieAudit::Verification,
            self::MembreCree, self::AdministrateurCree, self::AdministrateurModifie, self::RoleDeSocieteChange, self::ExpertComptableCree, self::DroitsModifies,
            self::ResponsableNomme, self::ResponsableRetire, self::NominationAnnulee, self::CompteSuspendu, self::CompteReactive, self::CompteRetire,
            self::CompteReintegre, self::AccesRedonne, self::PremiereConnexion, self::IdentifiantConsulte, self::TransmissionDemandee,
            self::TransmissionAnnulee, self::ProprieteTransferee, self::ProprieteReprise, self::AdresseDeConnexionChangee,
            self::AdresseDeConnexionRetiree => CategorieAudit::Acces,
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
            self::VerificationEnregistree => ['type', 'resultat', 'fournisseur', 'siret'],
            self::PieceDeposee => ['type_piece', 'empreinte'],
            self::PieceEffacee => ['type_piece', 'motif'],
            self::PiecesConsultees => ['pieces'],
            self::CodeEnvoye => ['motif', 'source_adresse', 'reference_envoi'],
            self::CodeSaisi => ['motif', 'resultat', 'tentatives'],
            self::CodeAnnule => ['motif', 'raison'],
            // La preuve qui valide la société (voir PreuveDeValidation), le
            // propriétaire qui l'a donnée et les SIRET de ses magasins mis
            // en activité.
            self::SocieteValidee => ['preuve', 'proprietaire', 'sirets'],
            self::InscriptionClose, self::DemonstrationOuverte, self::DemonstrationFermee => ['motif'],
            self::MagasinCree, self::MagasinModifie => ['nom', 'code_magasin', 'siret', 'adresse', 'complement_adresse', 'code_postal', 'ville', 'pays', 'etablissement_verifie'],
            self::SiretChange => ['siret', 'adresse', 'complement_adresse', 'code_postal', 'ville', 'etablissement_verifie'],
            // Pour une cession, l'indication d'établissement principal
            // retirée, s'il la portait, la valeur hors taxe du stock sorti
            // au coût moyen, le nombre de produits sortis et ceux restés en
            // stock négatif, sans mouvement (voir FermerMagasinAction) ; la
            // cession d'un magasin déjà fermé change son seul motif de
            // fermeture, de l'ancien à la cession.
            self::MagasinFerme => ['statut', 'motif', 'est_etablissement_principal', 'valeur_stock_sorti_ht', 'produits_sortis', 'produits_en_stock_negatif'],
            self::MagasinCede => ['motif', 'est_etablissement_principal', 'valeur_stock_sorti_ht', 'produits_sortis', 'produits_en_stock_negatif'],
            self::MagasinRouvert => ['statut', 'etablissement_verifie'],
            self::EtablissementPrincipalChoisi => ['etablissement_principal'],
            // Les droits de chaque compte touché, avant et après, avec son
            // nom au moment de l'opération, son rôle de société et ses rôles
            // de magasin (voir JournalDesDroits).
            self::MembreCree, self::AdministrateurCree, self::AdministrateurModifie, self::RoleDeSocieteChange, self::ExpertComptableCree, self::DroitsModifies,
            self::ResponsableNomme, self::ResponsableRetire, self::NominationAnnulee, self::CompteSuspendu, self::CompteReactive, self::CompteRetire,
            self::CompteReintegre, self::ProprieteTransferee, self::ProprieteReprise => ['droits'],
            // Le compte (son identifiant technique et son nom au moment de
            // l'opération), jamais son identifiant de connexion, qui serait
            // son mot de passe tant qu'il ne l'a pas changé ; pour un accès
            // redonné, si la personne était présente.
            self::PremiereConnexion, self::IdentifiantConsulte, self::AdresseDeConnexionChangee, self::AdresseDeConnexionRetiree => ['compte'],
            self::AccesRedonne => ['compte', 'en_presence'],
            // L'administrateur à qui la propriété est proposée ; pour un
            // lien renvoyé, la mention qu'il l'a été.
            self::TransmissionDemandee => ['destinataire', 'lien_renvoye'],
            self::TransmissionAnnulee => ['destinataire'],
            // Le numéro de la vente et, pour chaque produit qu'elle a fait
            // passer sous zéro, sa référence, son stock avant la vente et
            // la quantité vendue (voir EmettreFactureAction).
            self::VenteSansStock => ['vente', 'produits'],
            // Le numéro de l'avoir fournisseur, celui de la facture d'achat
            // qu'il crédite, le n° de l'avoir du fournisseur, ses totaux et,
            // pour chaque ligne, la référence et la quantité retournées
            // (voir EmettreAvoirFournisseurAction).
            self::AvoirFournisseurEmis => ['avoir', 'facture_achat', 'numero_fournisseur', 'total_ht', 'total_tva', 'total_ttc', 'lignes'],
            // Le numéro de l'inventaire d'ouverture, la valeur hors taxe du
            // stock qu'il fait entrer et, pour chaque ligne, la référence du
            // produit, la quantité inventoriée, si elle est entrée en stock
            // (suivi_stock : un produit qui ne suit pas son stock ne reçoit
            // que son coût de départ, sans valeur de stock), son coût
            // unitaire HT et si la validation a créé le produit (voir
            // ValiderInventaireOuvertureAction).
            self::InventaireOuvertureValide => ['inventaire', 'valeur_ht', 'lignes'],
        };
    }
}
