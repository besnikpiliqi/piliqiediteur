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
    case SocieteRevoquee = 'societe_revoquee';
    case SirenExamine = 'siren_examine';
    case SirenCorrige = 'siren_corrige';
    case ActiviteDuCabinetControlee = 'activite_du_cabinet_controlee';
    case OrdreDemande = 'ordre_demande';
    case OrdreVerifie = 'ordre_verifie';
    case OrdreRefuse = 'ordre_refuse';
    case OrdreRetire = 'ordre_retire';
    case OrdreARefaire = 'ordre_a_refaire';
    case MagasinCree = 'magasin_cree';
    case MagasinModifie = 'magasin_modifie';
    case SiretChange = 'siret_change';
    case MagasinFerme = 'magasin_ferme';
    case MagasinCede = 'magasin_cede';
    case MagasinRouvert = 'magasin_rouvert';
    case TerritoireCorrige = 'territoire_corrige';
    case SiretLibere = 'siret_libere';
    case CessionAnnulee = 'cession_annulee';
    case FinExerciceChangee = 'fin_exercice_changee';
    case EtablissementPrincipalChoisi = 'etablissement_principal_choisi';
    case DemonstrationOuverte = 'demonstration_ouverte';
    case DemonstrationFermee = 'demonstration_fermee';
    case MembreCree = 'membre_cree';
    case AdministrateurCree = 'administrateur_cree';
    case AdministrateurModifie = 'administrateur_modifie';
    case RoleDeSocieteChange = 'role_de_societe_change';
    case CollaborateurCree = 'collaborateur_cree';
    case ClientsConfiesModifies = 'clients_confies_modifies';
    case InvitationEnvoyee = 'invitation_envoyee';
    case InvitationRetiree = 'invitation_retiree';
    case InvitationAcceptee = 'invitation_acceptee';
    case InvitationRefusee = 'invitation_refusee';
    case InvitationCaduque = 'invitation_caduque';
    case LienDemande = 'lien_demande';
    case LienRefuse = 'lien_refuse';
    case DemandeDeLienRetiree = 'demande_de_lien_retiree';
    case DemandeDeLienExpiree = 'demande_de_lien_expiree';
    case LienActive = 'lien_active';
    case LienTermine = 'lien_termine';
    case AutorisationsDuCabinetModifiees = 'autorisations_du_cabinet_modifiees';
    case EspaceCabinetSuggere = 'espace_cabinet_suggere';
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
    case FactureAchatValidee = 'facture_achat_validee';
    case FactureAchatModifiee = 'facture_achat_modifiee';
    case EcartsDuFournisseurConfirmes = 'ecarts_du_fournisseur_confirmes';
    case TvaAVerifierPosee = 'tva_a_verifier_posee';
    case TvaAVerifierLevee = 'tva_a_verifier_levee';
    case AvoirFournisseurEmis = 'avoir_fournisseur_emis';
    case PieceDuFournisseurJointe = 'piece_du_fournisseur_jointe';
    case PieceDuFournisseurRetiree = 'piece_du_fournisseur_retiree';
    case PieceDuFournisseurManquantePosee = 'piece_du_fournisseur_manquante_posee';
    case PieceDuFournisseurManquanteLevee = 'piece_du_fournisseur_manquante_levee';
    case DocumentsTelecharges = 'documents_telecharges';
    case InventaireOuvertureValide = 'inventaire_ouverture_valide';
    case AvoirDeRegularisationEmis = 'avoir_de_regularisation_emis';
    case FactureRectificativeEmise = 'facture_rectificative_emise';

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
            self::SocieteRevoquee => 'Société révoquée par l\'éditeur',
            self::SirenExamine => 'SIREN examiné par l\'éditeur',
            self::SirenCorrige => 'SIREN corrigé par l\'éditeur',
            self::ActiviteDuCabinetControlee => 'Code d\'activité du cabinet contrôlé',
            self::OrdreDemande => 'Vérification de l\'inscription à l\'Ordre demandée',
            self::OrdreVerifie => 'Inscription à l\'Ordre vérifiée',
            self::OrdreRefuse => 'Inscription à l\'Ordre refusée',
            self::OrdreRetire => 'Radiation de l\'Ordre constatée par l\'éditeur',
            self::OrdreARefaire => 'Inscription à l\'Ordre à vérifier de nouveau',
            self::MagasinCree => 'Magasin créé',
            self::MagasinModifie => 'Magasin modifié',
            self::SiretChange => 'SIRET du magasin changé',
            self::MagasinFerme => 'Magasin fermé',
            self::MagasinCede => 'Magasin cédé après sa fermeture',
            self::MagasinRouvert => 'Magasin rouvert',
            self::TerritoireCorrige => 'Territoire du magasin corrigé par l\'éditeur',
            self::SiretLibere => 'SIRET du magasin libéré par l\'éditeur',
            self::CessionAnnulee => 'Cession du magasin annulée par l\'éditeur',
            self::FinExerciceChangee => 'Date de clôture de l\'exercice changée par l\'éditeur',
            self::EtablissementPrincipalChoisi => 'Établissement principal indiqué',
            self::DemonstrationOuverte => 'Magasin de démonstration ouvert',
            self::DemonstrationFermee => 'Magasin de démonstration fermé',
            self::MembreCree => 'Compte créé',
            self::AdministrateurCree => 'Administrateur créé',
            self::AdministrateurModifie => 'Administrateur modifié',
            self::RoleDeSocieteChange => 'Rôle de société changé',
            self::CollaborateurCree => 'Collaborateur du cabinet créé',
            self::ClientsConfiesModifies => 'Clients confiés à un collaborateur modifiés',
            self::InvitationEnvoyee => 'Invitation d\'un client envoyée',
            self::InvitationRetiree => 'Invitation d\'un client retirée',
            self::InvitationAcceptee => 'Invitation du cabinet acceptée',
            self::InvitationRefusee => 'Invitation du cabinet refusée',
            self::InvitationCaduque => 'Invitation du cabinet devenue caduque',
            self::LienDemande => 'Rattachement à un cabinet demandé',
            self::LienRefuse => 'Rattachement à un cabinet refusé',
            self::DemandeDeLienRetiree => 'Demande de rattachement retirée',
            self::DemandeDeLienExpiree => 'Demande de rattachement expirée',
            self::LienActive => 'Lien avec un cabinet actif',
            self::LienTermine => 'Lien avec un cabinet terminé',
            self::AutorisationsDuCabinetModifiees => 'Autorisations du cabinet modifiées',
            self::EspaceCabinetSuggere => 'Expert-comptable invité à créer l\'espace de son cabinet',
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
            self::FactureAchatValidee => 'Facture d\'achat validée',
            self::FactureAchatModifiee => 'Facture d\'achat validée corrigée',
            self::EcartsDuFournisseurConfirmes => 'Écart avec le document du fournisseur confirmé',
            self::TvaAVerifierPosee => 'Marque « TVA à vérifier » posée',
            self::TvaAVerifierLevee => 'Marque « TVA à vérifier » levée',
            self::AvoirFournisseurEmis => 'Avoir fournisseur émis',
            self::PieceDuFournisseurJointe => 'Pièce du fournisseur jointe',
            self::PieceDuFournisseurRetiree => 'Pièce du fournisseur retirée',
            self::PieceDuFournisseurManquantePosee => 'Marque « pièce du fournisseur manquante » posée',
            self::PieceDuFournisseurManquanteLevee => 'Marque « pièce du fournisseur manquante » levée',
            self::DocumentsTelecharges => 'Documents téléchargés par période',
            self::InventaireOuvertureValide => 'Inventaire d\'ouverture validé',
            self::AvoirDeRegularisationEmis => 'Avoir de régularisation émis après la correction du SIREN',
            self::FactureRectificativeEmise => 'Facture rectificative émise après la correction du SIREN',
        };
    }

    public function categorie(): CategorieAudit
    {
        return match ($this) {
            self::VerificationEnregistree, self::PieceDeposee, self::PieceEffacee, self::PiecesConsultees,
            self::CodeEnvoye, self::CodeSaisi, self::CodeAnnule, self::SocieteValidee, self::InscriptionClose,
            self::ActiviteDuCabinetControlee, self::OrdreDemande, self::OrdreVerifie, self::OrdreRefuse, self::OrdreRetire,
            self::OrdreARefaire => CategorieAudit::Verification,
            self::MembreCree, self::AdministrateurCree, self::AdministrateurModifie, self::RoleDeSocieteChange, self::CollaborateurCree, self::DroitsModifies,
            self::ResponsableNomme, self::ResponsableRetire, self::NominationAnnulee, self::CompteSuspendu, self::CompteReactive, self::CompteRetire,
            self::CompteReintegre, self::AccesRedonne, self::PremiereConnexion, self::IdentifiantConsulte, self::TransmissionDemandee,
            self::TransmissionAnnulee, self::ProprieteTransferee, self::ProprieteReprise, self::AdresseDeConnexionChangee,
            self::AdresseDeConnexionRetiree, self::ClientsConfiesModifies, self::InvitationEnvoyee, self::InvitationRetiree,
            self::InvitationAcceptee, self::InvitationRefusee, self::InvitationCaduque, self::LienDemande, self::LienRefuse,
            self::DemandeDeLienRetiree, self::DemandeDeLienExpiree, self::LienActive, self::LienTermine,
            self::AutorisationsDuCabinetModifiees => CategorieAudit::Acces,
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
            // Le type d'activité choisi, et le code d'activité lu au
            // répertoire Sirene à la confirmation avec sa nomenclature,
            // enregistrés nulle part ailleurs sur la société (sauf, pour un
            // cabinet d'expertise comptable, sur sa ligne de vérification,
            // voir Cabinet) ; son origine : « inscription », la société
            // s'inscrit elle-même.
            self::SocieteInscrite => ['siren', 'type_activite', 'code_activite', 'nomenclature_activite', 'raison_sociale', 'forme_juridique', 'proprietaire', 'origine'],
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
            // propriétaire qui l'a donnée, les SIRET de ses magasins mis en
            // activité et, pour une invitation acceptée, le cabinet qui l'a
            // envoyée (son identifiant, son SIREN et sa dénomination).
            self::SocieteValidee => ['preuve', 'proprietaire', 'sirets', 'cabinet'],
            self::InscriptionClose, self::DemonstrationOuverte, self::DemonstrationFermee => ['motif'],
            // Le statut de la société avant et après sa révocation par la
            // procédure de l'éditeur, et les SIRET de ses magasins libérés
            // au registre des SIRET réservés (voir RevoquerSocieteAction).
            self::SocieteRevoquee => ['statut', 'sirets_liberes'],
            // Le SIREN de l'entité correcte, la décision motivée de l'examen
            // (voir DecisionExamenSiren) et le résumé des documents émis
            // concernés, quel que soit leur nombre : l'instant avant lequel
            // ils ont été émis, leur nombre, leur premier et leur dernier
            // numéro par magasin et par type, et l'empreinte de leur liste
            // (voir ExaminerSirenAction).
            self::SirenExamine => ['siren_correct', 'decision', 'documents_emis_avant', 'documents', 'empreinte_des_documents'],
            // Le SIREN, le n° de TVA et le SIRET de chaque magasin qui en
            // change, par son code, avant et après la correction (voir
            // CorrigerSirenAction).
            self::SirenCorrige => ['siren', 'numero_tva', 'sirets'],
            // Le code d'activité d'un cabinet lu au répertoire Sirene, sa
            // nomenclature, et le résultat : « concluant » (69.20Z en NAF
            // rév. 2), « a_controler » (code absent ou d'une autre
            // nomenclature, contrôlé ensuite par l'éditeur),
            // « autre_activite » (un autre code en NAF rév. 2, lu après la
            // correction de son SIREN, qui ne se contrôle pas) ou
            // « controle_par_l_editeur » (voir Cabinet).
            self::ActiviteDuCabinetControlee => ['code_activite', 'nomenclature', 'resultat'],
            // Le n° d'inscription au tableau de l'Ordre et le conseil
            // régional déclarés, la voie de la vérification (voir
            // VoieDeVerificationOrdre), ou le motif d'un refus.
            self::OrdreDemande => ['numero_ordre', 'conseil_regional'],
            self::OrdreVerifie => ['voie', 'numero_ordre', 'conseil_regional'],
            // Le motif d'un refus, de la radiation constatée par l'éditeur
            // (voir RetirerLInscriptionALOrdreAction), ou de la vérification
            // remise à faire par la correction du SIREN (voir
            // CorrigerSirenAction).
            self::OrdreRefuse, self::OrdreRetire, self::OrdreARefaire => ['motif'],
            self::MagasinCree, self::MagasinModifie => ['nom', 'code_magasin', 'siret', 'adresse', 'complement_adresse', 'code_postal', 'ville', 'pays', 'territoire', 'etablissement_verifie'],
            self::SiretChange => ['siret', 'adresse', 'complement_adresse', 'code_postal', 'ville', 'territoire', 'etablissement_verifie'],
            // Pour une cession, l'indication d'établissement principal
            // retirée, s'il la portait, la valeur hors taxe du stock sorti
            // au coût moyen, le nombre de produits sortis et ceux restés en
            // stock négatif, sans mouvement (voir FermerMagasinAction) ; la
            // cession d'un magasin déjà fermé change son seul motif de
            // fermeture, de l'ancien à la cession.
            self::MagasinFerme => ['statut', 'motif', 'est_etablissement_principal', 'valeur_stock_sorti_ht', 'produits_sortis', 'produits_en_stock_negatif'],
            self::MagasinCede => ['motif', 'est_etablissement_principal', 'valeur_stock_sorti_ht', 'produits_sortis', 'produits_en_stock_negatif'],
            self::MagasinRouvert => ['statut', 'territoire', 'etablissement_verifie'],
            // Le territoire mal lu au répertoire Sirene, avant et après sa
            // correction par la procédure de l'éditeur (voir
            // CorrigerLeTerritoireAction).
            self::TerritoireCorrige => ['territoire'],
            // Le SIRET libéré d'un magasin, le motif de sa libération et,
            // pour un territoire mal lu, le territoire du magasin, qui ne
            // change pas, et celui où le répertoire Sirene situe son
            // établissement (voir LibererLeSiretAction et
            // LibererUnSiretSaisiParErreurAction).
            self::SiretLibere => ['siret', 'motif', 'territoire', 'territoire_lu'],
            // Le motif de fermeture du magasin avant et après l'annulation
            // de sa cession par la procédure de l'éditeur, le nombre de
            // produits rentrés en stock et la valeur hors taxe de ce stock,
            // au coût de leur sortie (voir AnnulerCessionAction).
            self::CessionAnnulee => ['motif', 'produits_rentres', 'valeur_stock_rentre_ht'],
            // Le mois de fin d'exercice et la fin de l'exercice en cours,
            // avant et après le changement légal de la date de clôture, le
            // jour de la décision et l'expert-comptable de la société qui a
            // validé la durée de l'exercice transitoire (voir
            // ChangerFinExerciceAction).
            self::FinExerciceChangee => ['mois_fin_exercice', 'fin_exercice_en_cours', 'decision_du', 'duree_validee_par'],
            self::EtablissementPrincipalChoisi => ['etablissement_principal'],
            // Les droits de chaque compte touché, avant et après, avec son
            // nom au moment de l'opération, son rôle de société et ses rôles
            // de magasin (voir JournalDesDroits).
            self::MembreCree, self::AdministrateurCree, self::AdministrateurModifie, self::RoleDeSocieteChange, self::CollaborateurCree, self::DroitsModifies,
            self::ResponsableNomme, self::ResponsableRetire, self::NominationAnnulee, self::CompteSuspendu, self::CompteReactive, self::CompteRetire,
            self::CompteReintegre, self::ProprieteTransferee, self::ProprieteReprise, self::ClientsConfiesModifies => ['droits'],
            // Le SIREN invité, jamais l'adresse à laquelle part le lien
            // (« sans donnée personnelle inutile ») ; pour une invitation
            // acceptée, la société créée ; pour une invitation devenue
            // caduque, sa raison.
            self::InvitationEnvoyee, self::InvitationRetiree, self::InvitationRefusee => ['siren'],
            self::InvitationAcceptee => ['siren', 'client'],
            self::InvitationCaduque => ['siren', 'raison'],
            // Le cabinet et le client du lien (identifiant, SIREN et
            // dénomination ; au journal d'un cabinet, pour une demande qui
            // n'est pas devenue un lien actif, le SIREN seul du client, voir
            // IdentiteDansLeJournal), son origine (voir OrigineDuLien), son
            // motif de fin (voir MotifDeFinDuLien), les autorisations d'une
            // demande acceptée sur la page de rattachement (« par_defaut »
            // ou « ajustees ») et les droits des comptes du cabinet touchés
            // dans les magasins réels du client (voir JournalDesDroits).
            self::LienDemande => ['cabinet', 'client', 'origine'],
            self::LienRefuse, self::DemandeDeLienRetiree, self::DemandeDeLienExpiree => ['cabinet', 'client'],
            self::LienActive => ['cabinet', 'client', 'origine', 'autorisations', 'droits'],
            self::LienTermine => ['cabinet', 'client', 'motif', 'droits'],
            self::AutorisationsDuCabinetModifiees => ['cabinet', 'droits'],
            // Si l'e-mail a pu partir ; l'adresse de l'expert-comptable
            // invité ne s'écrit pas.
            self::EspaceCabinetSuggere => ['envoye'],
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
            // Le numéro de la facture d'achat, le n° de la facture du
            // fournisseur, ses totaux, la base, la TVA retenue et la TVA
            // calculée de chaque taux, sa marque « TVA à vérifier » et, pour
            // chaque ligne, la référence, la quantité, le PU HT, le HT
            // retenu et sa TVA ; ni le fournisseur ni les notes, qui restent
            // sur la facture (voir ValiderFactureAchatAction). La correction
            // d'une facture validée retient, de ces champs et de sa date et
            // de son escompte, ceux qu'elle change, avant et après (voir
            // ModifierFactureAchatAction) : la TVA d'une facture déjà
            // déclarée ne se réécrit jamais sans trace (art. 271, II-3 du
            // CGI).
            self::FactureAchatValidee => ['facture_achat', 'numero_fournisseur', 'total_ht', 'total_tva', 'total_ttc', 'taux', 'tva_a_verifier', 'lignes'],
            self::FactureAchatModifiee => ['numero_fournisseur', 'date_facture', 'escompte_pourcentage', 'total_ht', 'total_tva', 'total_ttc', 'taux', 'tva_a_verifier', 'lignes'],
            // Le document du fournisseur (sa nature, notre numéro, son n°
            // chez le fournisseur) et, pour chaque taux confirmé, la base,
            // la TVA saisie, la TVA calculée, leur écart et chaque ligne hors
            // tolérance (sa référence, son HT saisi, son HT calculé, leur
            // écart), en centimes (voir TvaDuFournisseur).
            self::EcartsDuFournisseurConfirmes => ['document', 'ecarts'],
            self::TvaAVerifierPosee, self::TvaAVerifierLevee => ['document'],
            // Le numéro de l'avoir fournisseur, celui de la facture d'achat
            // qu'il crédite, le n° de l'avoir du fournisseur, ses totaux, la
            // base, la TVA retenue et la TVA calculée de chaque taux, sa
            // marque « TVA à vérifier » et, pour chaque ligne, la référence
            // et la quantité retournées (voir EmettreAvoirFournisseurAction).
            self::AvoirFournisseurEmis => ['avoir', 'facture_achat', 'numero_fournisseur', 'total_ht', 'total_tva', 'total_ttc', 'taux', 'tva_a_verifier', 'lignes'],
            // Le document du fournisseur (sa nature, notre numéro, son n°
            // chez le fournisseur), le nom d'origine du fichier et son
            // empreinte SHA-256, jamais son contenu (voir
            // PiecesDuFournisseur) ; la marque « pièce du fournisseur
            // manquante », le seul document.
            self::PieceDuFournisseurJointe, self::PieceDuFournisseurRetiree => ['document', 'nom_origine', 'empreinte'],
            self::PieceDuFournisseurManquantePosee, self::PieceDuFournisseurManquanteLevee => ['document'],
            // Le téléchargement des documents par période qu'un compte a
            // préparé (voir PreparerLeTelechargementDesDocumentsJob) : les
            // codes des magasins dont il a reçu des documents, la période,
            // les types, l'origine des achats demandés et s'ils n'étaient
            // demandés que marqués « pièce du fournisseur manquante », le
            // nombre de documents et le format ; jamais un document ni un
            // tiers.
            self::DocumentsTelecharges => ['magasins', 'du', 'au', 'types', 'origine', 'piece_manquante_seulement', 'nombre_documents', 'format'],
            // Le numéro de l'inventaire d'ouverture, la valeur hors taxe du
            // stock qu'il fait entrer et, pour chaque ligne, la référence du
            // produit, la quantité inventoriée, si elle est entrée en stock
            // (suivi_stock : un produit qui ne suit pas son stock ne reçoit
            // que son coût de départ, sans valeur de stock), son coût
            // unitaire HT et si la validation a créé le produit (voir
            // ValiderInventaireOuvertureAction).
            self::InventaireOuvertureValide => ['inventaire', 'valeur_ht', 'lignes'],
            // Le numéro de l'avoir de régularisation, celui de la facture
            // qu'il régularise, le SIREN correct de la société et son total
            // TTC, en centimes ; pour une facture rectificative, son numéro,
            // celui de la facture qu'elle rectifie et son total TTC (voir
            // RegularisationDUneFacture).
            self::AvoirDeRegularisationEmis => ['avoir', 'facture', 'siren_correct', 'total_ttc'],
            self::FactureRectificativeEmise => ['facture', 'facture_rectifiee', 'total_ttc'],
        };
    }
}
