<?php

namespace Database\Seeders\Semis;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\DeclarerSonExpertComptableAction;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\RefuserLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\RefuserLInvitationAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Actions\RetirerLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\RetirerLInscriptionALOrdreAction;
use App\Domain\Cabinets\Actions\RetirerLInvitationAction;
use App\Domain\Cabinets\Actions\VerifierLOrdreAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\EtatInvitation;
use App\Domain\Cabinets\Mail\InvitationDuCabinetMail;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EmettreLaFactureRectificativeAction;
use App\Domain\Facturation\Actions\FaireLAvoirDeRegularisationAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\RegularisationDUneFacture;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\ChangerFinExerciceAction;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\CorrigerSirenAction;
use App\Domain\Tenancy\Actions\CreerMandatPdpAction;
use App\Domain\Tenancy\Actions\DemanderLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\DeposerUnePieceAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\ExaminerLesPiecesAction;
use App\Domain\Tenancy\Actions\ExaminerSirenAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\RevokerMandatPdpAction;
use App\Domain\Tenancy\Actions\RevoquerSocieteAction;
use App\Domain\Tenancy\Actions\RouvrirMagasinAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\VerifierUnEcartSireneAction;
use App\Domain\Tenancy\Enums\DecisionExamenSiren;
use App\Domain\Tenancy\Enums\ExploitationEnGerance;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\OrganeDeLaDecision;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Mail\TransmissionDeLaProprieteMail;
use App\Domain\Tenancy\Models\EcartSirene;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\Ordre\Contracts\AnnuaireDeLOrdre;
use App\Integrations\Ordre\FauxAnnuaireDeLOrdre;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\FauxRne;
use App\Integrations\Rne\IdentiteRne;
use App\Integrations\Rne\RepresentantRne;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;

/**
 * Les sociétés de chaque cas (demande de l'éditeur du 15/11/2026, cahier
 * SEED-TOUT § 3.1), chacune par les vraies actions, au nom de qui fait le
 * geste :
 * - Comptoir des Îles, « Commerce ou atelier » à huit magasins : à
 *   Fort-de-France (Martinique, TVA à 8,5 %, heure des Antilles), à Lyon
 *   (son inventaire d'ouverture en brouillon, puis fermé temporairement),
 *   à Bordeaux (fermé pour cessation, puis rouvert), à Nantes (cédé), à
 *   Lille (suspendu par l'éditeur), à Pointe-à-Pitre (Guadeloupe) et à
 *   Saint-Denis (La Réunion), et à Ajaccio (Corse, fermé pour cessation
 *   sans avoir vendu) ; son propriétaire a demandé de
 *   transmettre la propriété à son administratrice, qui n'a pas encore
 *   suivi le lien ;
 * - l'Atelier Brun, « Services, sans vente d'articles » en franchise en
 *   base, entrepreneur individuel à responsabilité limitée ; le Studio
 *   Lumière, « Services » qui a opté pour les débits, à capital variable
 *   sans capital saisi ;
 * - le Groupe Nord Distribution, membre d'un assujetti unique et
 *   gérant-mandataire ;
 * - la Boulangerie Duval, entrepreneur individuel en location-gérance,
 *   suspendue, et la Librairie des Quais, société en nom collectif sans
 *   capital saisi, en liquidation trente-deux jours avant la fin, résiliée
 *   après la transmission de sa propriété à son administrateur ;
 * - une inscription close faute de code (son lien avec le cabinet Martin
 *   prend fin à la clôture), deux autres closes par l'éditeur
 *   (refus, type d'activité choisi par erreur), et une inscription qui
 *   attend son code (celle qui rend caduque une invitation) ;
 * - un cabinet inscrit qui n'est pas vérifié à l'Ordre, un cabinet
 *   suspendu ;
 * - les invitations du cabinet Martin dans chaque état (ouverte, refusée,
 *   retirée, expirée, caduque), ses demandes de lien dans chaque état
 *   (demandée par le client, demandée par le cabinet, refusée, retirée,
 *   expirée, terminée), et le choix des magasins que l'Épicerie lui ouvre.
 * La suspension et la résiliation d'une société et la suspension d'un
 * magasin par l'éditeur n'ont pas encore d'écran ni d'action (étapes 12 et
 * 13 de la feuille de route) : le semis les pose comme les tests, en
 * changeant leur statut. Les ventes de ces sociétés se font le soir, entre
 * 20 h et 21 h 15 à Paris (de 14 h à 16 h 15 en Martinique), puis
 * l'événement du jour, à 21 h 30.
 */
final class SocietesDesCas
{
    public const SIREN_COMPTOIR = '000000141';

    public const SIREN_ATELIER = '000000166';

    public const SIREN_STUDIO = '000000174';

    public const SIREN_GROUPE = '000000182';

    public const SIREN_BOULANGERIE = '000000190';

    public const SIREN_LIBRAIRIE = '000000208';

    public const SIREN_SANS_CODE = '000000307';

    public const SIREN_TYPE_ERRONE = '000000315';

    public const SIREN_REFUSEE = '000000398';

    public const SIREN_CABINET_NON_VERIFIE = '000000323';

    public const SIREN_CABINET_SUSPENDU = '000000331';

    /** La société inscrite sous un SIREN qui n'était pas le sien, révoquée par l'éditeur. */
    public const SIREN_REVOQUEE = '000000406';

    /** L'inscription dont les courriers ne sont pas parvenus : nouveau courrier, puis pièces. */
    public const SIREN_PIECES = '000000414';

    /** L'inscription liée au cabinet Martin dont une autre personne que l'inscrite saisit le code du courrier. */
    public const SIREN_REMPLACEE = '000000430';

    /** Le cabinet vérifié dont l'éditeur constate la radiation du tableau de l'Ordre. */
    public const SIREN_CABINET_RADIE = '000000448';

    /**
     * Les SIREN des entreprises que le cabinet Martin invite : l'invitation
     * reste ouverte, est refusée, retirée, expire, ou devient caduque quand
     * l'entreprise s'inscrit elle-même.
     *
     * @var array<string, string>
     */
    public const SIRENS_INVITES = [
        'ouverte' => '000000349',
        'refusee' => '000000356',
        'retiree' => '000000364',
        'expiree' => '000000372',
        'caduque' => '000000380',
    ];

    /**
     * Le jour, compté depuis la fin de l'histoire, où chaque société des
     * cas commence à vendre.
     */
    private const DEBUT_DU_COMPTOIR = 120;

    private const DEBUT_DES_AUTRES = 60;

    /**
     * Les jours, comptés depuis la fin de l'histoire, de chaque événement.
     */
    private const FERMETURE_DE_LYON = 100;

    private const CESSATION_DE_BORDEAUX = 70;

    private const REOUVERTURE_DE_BORDEAUX = 55;

    private const CESSION_DE_NANTES = 40;

    private const SUSPENSION_DE_LILLE = 30;

    private const SUSPENSION_DE_LA_BOULANGERIE = 20;

    private const TRANSMISSION_DE_LA_LIBRAIRIE = 15;

    private const RESILIATION_DE_LA_LIBRAIRIE = 10;

    /**
     * La Librairie des Quais se déclare en liquidation : ses tickets d'avant
     * n'impriment pas la mention, ceux d'après l'impriment (décision P14.7).
     */
    private const LIQUIDATION_DE_LA_LIBRAIRIE = 32;

    private const INVITATIONS_ET_DEMANDES = 6;

    private const ECARTS_SIRENE = 35;

    private const ECART_VERIFIE = 33;

    private const FIN_D_EXERCICE_CHANGEE = 25;

    /**
     * La Maroquinerie Delmas s'inscrit et saisit son code, ouvre un
     * magasin ; le vrai titulaire du SIREN se manifeste : l'éditeur envoie
     * le courrier de révocation au représentant légal, son code est saisi
     * sur sa page, puis l'éditeur révoque la société (son magasin se ferme).
     */
    private const INSCRIPTION_A_REVOQUER = 14;

    /** Le magasin d'Ajaccio (Corse), qui n'a jamais vendu, ferme pour cessation. */
    private const CESSATION_D_AJACCIO = 80;

    /**
     * La Maroquinerie Delmas, liée au cabinet Martin, déclare à sa place
     * l'expert-comptable d'un cabinet absent de PILIQI : le lien prend fin
     * (« le client a choisi un autre expert-comptable »), puis la
     * déclaration prend fin à la révocation de la société.
     */
    private const DECLARATION_A_LA_PLACE_DU_CABINET = 7;

    /** Le cabinet Martin met fin à son lien avec la Boulangerie Duval. */
    private const LIEN_TERMINE_PAR_LE_CABINET = 45;

    /** Le magasin de Bordeaux, rouvert, connecte la plateforme agréée puis révoque ce mandat. */
    private const MANDAT_REVOQUE = 50;

    private const CODE_A_REVOQUER = 12;

    private const COURRIER_DE_REVOCATION = 8;

    private const REVOCATION = 5;

    /**
     * La Fromagerie Morin attend son code : ses courriers ne lui sont pas
     * parvenus ; elle demande le nouveau courrier quinze jours après le
     * premier, puis dépose ses pièces, que l'éditeur n'a pas encore
     * examinées.
     */
    private const INSCRIPTION_SANS_COURRIER_RECU = 42;

    private const NOUVEAU_COURRIER = 26;

    private const PIECES_DEPOSEES = 22;

    private const PIECES_EXAMINEES = 19;

    private const NOUVELLES_PIECES = 11;

    /**
     * Les courriers des autres procédures de l'éditeur (récupération du
     * compte de la propriétaire de la Boulangerie Duval, libération du
     * SIRET du magasin cédé de Nantes, examen du SIREN du Studio Lumière),
     * et une revérification de tous les établissements pendant que le
     * répertoire Sirene ne répond pas (résultat « indisponible »).
     */
    private const PROCEDURES_DE_L_EDITEUR = 9;

    /**
     * La Boulangerie Duval, dont le cabinet Martin a mis fin au lien,
     * désigne le cabinet Faure, qui accepte ; l'éditeur constate ensuite
     * la radiation de ce cabinet du tableau de l'Ordre : leur lien prend
     * fin (« cabinet plus vérifié »).
     */
    private const LIEN_AVEC_LE_CABINET_RADIE = 44;

    private const RADIATION_CONSTATEE = 29;

    /**
     * Le Verger des Coteaux s'inscrit et demande le lien au cabinet Martin,
     * qui l'accepte ; cinq jours après, une autre personne que l'inscrite
     * saisit le code du courrier : elle devient propriétaire, les comptes
     * d'avant sont retirés, et le lien prend fin (« propriétaire
     * remplacé »).
     */
    private const INSCRIPTION_REMPLACEE = 21;

    private const CODE_SAISI_PAR_UNE_AUTRE_PERSONNE = 16;

    /**
     * L'examen du SIREN du Studio Lumière (voir PROCEDURES_DE_L_EDITEUR) :
     * le représentant légal de l'entité correcte saisit le code du
     * courrier, l'éditeur examine le SIREN puis le corrige ; le lendemain,
     * la plus récente des factures que l'examen désigne se régularise
     * (avoir de régularisation par la propriétaire, facture rectificative
     * par le responsable) ; les autres attendent leur régularisation.
     */
    private const CORRECTION_DU_SIREN = 4;

    private const REGULARISATION_DU_STUDIO = 3;

    /** Le SIREN de l'entité que l'examen du SIREN du Studio Lumière désigne comme la bonne. */
    private const SIREN_DE_L_EXAMEN = '000000422';

    /**
     * La justification des procédures de l'éditeur semées.
     */
    private const JUSTIFICATION = 'Demande écrite du propriétaire reçue le jour même, pièces examinées par deux opérateurs.';

    /** Le code du courrier de révocation, tel que le titulaire évincé le lit sur le pli. */
    private ?string $codeDeRevocation = null;

    /** Le code du courrier de l'examen du SIREN du Studio Lumière, tel que son destinataire le lit sur le pli. */
    private ?string $codeDeLExamen = null;

    /** Le code du courrier du Verger des Coteaux, tel que la personne qui le reçoit le lit sur le pli. */
    private ?string $codeDeLaRemplacee = null;

    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly SemisComplet $semis,
        private readonly MotsDePasseDuSemis $motsDePasse,
        private readonly ComptesSemes $liste,
    ) {}

    /**
     * Ce que le répertoire Sirene, le registre national des entreprises et
     * l'annuaire de l'Ordre factices disent des entreprises des cas.
     */
    public function nommer(): void
    {
        $sirene = ValeursLues::instance(app(RepertoireSirene::class), FauxRepertoireSirene::class);
        $rne = ValeursLues::instance(app(RegistreNationalDesEntreprises::class), FauxRne::class);

        foreach ([
            self::SIREN_COMPTOIR => ['COMPTOIR DES ILES', 'HOARAU', ['Patrick'], 'Président'],
            self::SIREN_ATELIER => ['ATELIER BRUN', 'BRUN', ['Mathilde'], 'Entrepreneur individuel'],
            self::SIREN_STUDIO => ['STUDIO LUMIERE', 'LACROIX', ['Olivier'], 'Président'],
            self::SIREN_GROUPE => ['GROUPE NORD DISTRIBUTION', 'DELATTRE', ['François'], 'Président'],
            self::SIREN_BOULANGERIE => ['BOULANGERIE DUVAL', 'DUVAL', ['Marc'], 'Entrepreneur individuel'],
            self::SIREN_LIBRAIRIE => ['LIBRAIRIE DES QUAIS', 'NOEL', ['Agnès'], 'Gérante'],
            self::SIREN_SANS_CODE => ['MENUISERIE PICARD', 'PICARD', ['Denis'], 'Gérant'],
            self::SIREN_TYPE_ERRONE => ['TRAITEUR GIRAUD', 'GIRAUD', ['Sandrine'], 'Présidente'],
            self::SIREN_REFUSEE => ['GARAGE MOREL', 'MOREL', ['Alain'], 'Gérant'],
            self::SIREN_CABINET_NON_VERIFIE => ['CABINET ROUSSEL', 'ROUSSEL', ['Henri'], 'Président'],
            self::SIREN_CABINET_SUSPENDU => ['AUDIT CONSEIL BRETAGNE', 'LE GALL', ['Yann'], 'Président'],
            self::SIRENS_INVITES['caduque'] => ['PATISSERIE ARNAUD', 'ARNAUD', ['Claire'], 'Présidente'],
            self::SIREN_REVOQUEE => ['MAROQUINERIE DELMAS', 'DELMAS', ['Jérôme'], 'Gérant'],
            self::SIREN_PIECES => ['FROMAGERIE MORIN', 'MORIN', ['Élise'], 'Présidente'],
            self::SIREN_DE_L_EXAMEN => ['STUDIO LUMIERE PHOTOGRAPHIE', 'LACROIX', ['Olivier'], 'Président'],
            self::SIREN_REMPLACEE => ['VERGER DES COTEAUX', 'CHAMPION', ['Hélène'], 'Présidente'],
            self::SIREN_CABINET_RADIE => ['CABINET FAURE ET ASSOCIES', 'FAURE', ['Bernard'], 'Président'],
        ] as $siren => [$denomination, $nom, $prenoms, $role]) {
            $siege = new AdressePostale($denomination, '1 RUE DE LA REPUBLIQUE', null, '75001', 'PARIS', AdressePostale::SOURCE_RNE);
            $sirene->nommer($siren, $denomination);
            $rne->dirigeants($siren, [new RepresentantRne($nom, null, $prenoms, '1975-03', $role, true, null, '2019-06-01')]);
            $rne->domicilier($siren, $siege);
            $rne->identifier($siren, new IdentiteRne($denomination, '5710', '15000.00', 'EUR', false, 'RCS Paris', $siege));
        }

        // Le répertoire dit de l'Atelier Brun et de la Boulangerie Duval qu'ils
        // sont des entrepreneurs individuels, dont le nom est contenu dans leur
        // dénomination (décision P14.4), et de la Librairie des Quais une
        // société en nom collectif.
        $sirene->entrepreneur(self::SIREN_ATELIER, 'BRUN');
        $sirene->entrepreneur(self::SIREN_BOULANGERIE, 'DUVAL');
        $sirene->categoriser(self::SIREN_LIBRAIRIE, '5202');

        foreach (['0003', '0004', '0005', '0006', '0007', '0008'] as $nic) {
            $sirene->ajouterUnEtablissement(IdentifiantsInsee::avecSaCle(self::SIREN_COMPTOIR.$nic), null);
        }

        $sirene->deplacer(FauxRepertoireSirene::siretDuSiege(self::SIREN_COMPTOIR), '97200', 'FORT-DE-FRANCE', '97209');
        $sirene->deplacer(FauxRepertoireSirene::siretDeLaBoutique(self::SIREN_COMPTOIR), '69002', 'LYON', '69382');
        $sirene->deplacer(IdentifiantsInsee::avecSaCle(self::SIREN_COMPTOIR.'0003'), '33000', 'BORDEAUX', '33063');
        $sirene->deplacer(IdentifiantsInsee::avecSaCle(self::SIREN_COMPTOIR.'0004'), '44000', 'NANTES', '44109');
        $sirene->deplacer(IdentifiantsInsee::avecSaCle(self::SIREN_COMPTOIR.'0005'), '59000', 'LILLE', '59350');
        $sirene->deplacer(IdentifiantsInsee::avecSaCle(self::SIREN_COMPTOIR.'0006'), '97110', 'POINTE-A-PITRE', '97120');
        $sirene->deplacer(IdentifiantsInsee::avecSaCle(self::SIREN_COMPTOIR.'0007'), '97400', 'SAINT-DENIS', '97411');
        $sirene->deplacer(IdentifiantsInsee::avecSaCle(self::SIREN_COMPTOIR.'0008'), '20000', 'AJACCIO', '2A004');

        foreach ([self::SIREN_CABINET_NON_VERIFIE, self::SIREN_CABINET_SUSPENDU, self::SIREN_CABINET_RADIE] as $cabinet) {
            $sirene->exercer($cabinet, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        }

        $annuaire = ValeursLues::instance(app(AnnuaireDeLOrdre::class), FauxAnnuaireDeLOrdre::class);
        $annuaire->inscrire(self::SIREN_CABINET_SUSPENDU, '35-11872', 'Bretagne');
        $annuaire->inscrire(self::SIREN_CABINET_RADIE, '31-20457', 'Occitanie');
    }

    /**
     * Le premier jour : les inscriptions des sociétés des cas.
     */
    public function inscrire(): void
    {
        foreach ([
            'comptoir' => [self::SIREN_COMPTOIR, TypeDActivite::Commerce],
            'atelier' => [self::SIREN_ATELIER, TypeDActivite::Services],
            'studio' => [self::SIREN_STUDIO, TypeDActivite::Services],
            'groupe' => [self::SIREN_GROUPE, TypeDActivite::Commerce],
            'boulangerie' => [self::SIREN_BOULANGERIE, TypeDActivite::Commerce],
            'librairie' => [self::SIREN_LIBRAIRIE, TypeDActivite::Commerce],
            'sans code' => [self::SIREN_SANS_CODE, TypeDActivite::Commerce],
            'type erroné' => [self::SIREN_TYPE_ERRONE, TypeDActivite::Services],
            'refusée' => [self::SIREN_REFUSEE, TypeDActivite::Commerce],
            'cabinet non vérifié' => [self::SIREN_CABINET_NON_VERIFIE, TypeDActivite::Cabinet],
            'cabinet suspendu' => [self::SIREN_CABINET_SUSPENDU, TypeDActivite::Cabinet],
            'cabinet radié' => [self::SIREN_CABINET_RADIE, TypeDActivite::Cabinet],
        ] as $cle => [$siren, $type]) {
            $this->semis->inscrire($cle, $siren, $type, 'proprietaire@'.Str::slug($cle).'.exemple.test');
        }
    }

    /**
     * Le lendemain : chaque société des cas saisit le code de son
     * courrier, sauf l'inscription close faute de code (la tâche de chaque
     * heure la clôt trente jours après) et celle que l'éditeur clôt pour
     * un type d'activité choisi par erreur ; puis leurs réglages, leurs
     * magasins, leurs équipes, et la vérification à l'Ordre du cabinet que
     * l'éditeur suspend ensuite.
     */
    public function valider(): void
    {
        foreach (['comptoir', 'atelier', 'studio', 'groupe', 'boulangerie', 'librairie', 'cabinet non vérifié', 'cabinet suspendu', 'cabinet radié'] as $cle) {
            $this->semis->saisirLeCode($cle);
        }

        $this->semis->horsDeToutContexte(fn () => app(CloreLInscriptionAction::class)->parLEditeur(
            $this->semis->societe('type erroné'),
            new InterventionDeLEditeur(self::JUSTIFICATION.' Type d\'activité choisi par erreur : un traiteur vend des articles.', 'Courriel de la gérante du '.$this->horloge->jour(1), ['opérateur A', 'opérateur B']),
            MotifCloture::TypeErrone,
        ));

        $this->semis->horsDeToutContexte(fn () => app(CloreLInscriptionAction::class)->parLEditeur(
            $this->semis->societe('refusée'),
            new InterventionDeLEditeur(self::JUSTIFICATION.' Le gérant déclare ne pas avoir demandé cette inscription.', 'Lettre du gérant du '.$this->horloge->jour(1), ['opérateur A', 'opérateur B']),
            MotifCloture::Refus,
        ));

        foreach ($this->identitesDesCas() as $cle => $identite) {
            $this->semis->completerLIdentite($cle, $identite);
        }

        $this->semis->reglerLaFiscalite('atelier', ['regime_tva' => RegimeTva::FranchiseEnBase->value]);
        $this->semis->reglerLaFiscalite('studio', ['option_debits_tva' => true, 'regime_tva' => RegimeTva::ReelNormalTrimestriel->value]);
        $this->semis->reglerLaFiscalite('groupe', ['membre_assujetti_unique' => true, 'regime_tva' => RegimeTva::ReelSimplifie->value]);
        $this->semis->reglerLaFiscalite('comptoir', ['regime_tva' => RegimeTva::ReelNormalMensuel->value]);
        $this->semis->reglerLaFiscalite('boulangerie', ['regime_tva' => RegimeTva::ReelSimplifie->value]);
        $this->semis->reglerLaFiscalite('librairie', ['regime_tva' => RegimeTva::ReelNormalTrimestriel->value]);

        $this->semis->horsDeToutContexte(fn () => app(VerifierLOrdreAction::class)->handle($this->semis->proprietaire('cabinet suspendu'), $this->semis->societe('cabinet suspendu'), [
            'numero_ordre' => '35-11872',
            'conseil_regional' => 'Bretagne',
        ]));

        $this->semis->societe('cabinet suspendu')->forceFill(['statut' => StatutSociete::Suspendue])->save();

        $this->semis->horsDeToutContexte(fn () => app(VerifierLOrdreAction::class)->handle($this->semis->proprietaire('cabinet radié'), $this->semis->societe('cabinet radié'), [
            'numero_ordre' => '31-20457',
            'conseil_regional' => 'Occitanie',
        ]));

        $this->semis->ouvrirUnMagasin('comptoir', 'fort-de-france', 'Comptoir des Îles Fort-de-France', 'FDF', 'siege');
        $this->semis->ouvrirUnMagasin('comptoir', 'lyon', 'Comptoir des Îles Lyon', 'LYON', 'boutique');
        $this->semis->ouvrirUnMagasin('comptoir', 'bordeaux', 'Comptoir des Îles Bordeaux', 'BDX', '0003');
        $this->semis->ouvrirUnMagasin('comptoir', 'nantes', 'Comptoir des Îles Nantes', 'NANTES', '0004');
        $this->semis->ouvrirUnMagasin('comptoir', 'lille', 'Comptoir des Îles Lille', 'LILLE', '0005');
        $this->semis->ouvrirUnMagasin('comptoir', 'pointe-a-pitre', 'Comptoir des Îles Pointe-à-Pitre', 'PAP', '0006');
        $this->semis->ouvrirUnMagasin('comptoir', 'saint-denis', 'Comptoir des Îles Saint-Denis', 'RUN', '0007');
        $this->semis->ouvrirUnMagasin('comptoir', 'ajaccio', 'Comptoir des Îles Ajaccio', 'AJACCIO', '0008');
        $this->semis->ouvrirUnMagasin('atelier', 'atelier', 'Atelier Brun', 'ATELIER', 'siege');
        $this->semis->ouvrirUnMagasin('studio', 'studio', 'Studio Lumière', 'STUDIO', 'siege');
        $this->semis->ouvrirUnMagasin('groupe', 'groupe', 'Groupe Nord Roubaix', 'ROUBAIX', 'siege');
        $this->semis->ouvrirUnMagasin('boulangerie', 'boulangerie', 'Boulangerie Duval', 'DUVAL', 'siege');
        $this->semis->ouvrirUnMagasin('librairie', 'librairie', 'Librairie des Quais', 'QUAIS', 'siege');

        $this->semis->administrateur('comptoir', 'Gaëlle Hoarau', null, []);
        $this->semis->membre('fort-de-france', 'Joël Sainte-Rose', RoleMagasin::Responsable);
        $this->semis->membre('fort-de-france', 'Maëva Jean-Louis', RoleMagasin::Vendeur);
        $this->semis->membre('lyon', 'Bastien Royer', RoleMagasin::Responsable);
        $this->semis->membre('bordeaux', 'Inès Lafitte', RoleMagasin::Responsable);
        $this->semis->membre('nantes', 'Ronan Guillou', RoleMagasin::Responsable);
        $this->semis->membre('lille', 'Chloé Vandamme', RoleMagasin::Responsable);
        $this->semis->membre('atelier', 'Mathilde Brun', RoleMagasin::Responsable, cle: 'Mathilde Brun (atelier)');
        $this->semis->membre('studio', 'Rémi Lacroix', RoleMagasin::Responsable);
        $this->semis->membre('groupe', 'Julien Delattre', RoleMagasin::Responsable);
        $this->semis->membre('boulangerie', 'Sophie Duval', RoleMagasin::Responsable);
        $this->semis->membre('librairie', 'Hervé Noël', RoleMagasin::Responsable);
        $this->semis->administrateur('librairie', 'Étienne Vidal', null, []);
    }

    /**
     * Ce que le propriétaire de chaque société des cas complète de son
     * identité légale, que le répertoire Sirene ne donne pas, par les
     * mentions de l'identité (décisions P14.1, P14.3, P14.4 et P14.7) :
     * - le Comptoir des Îles, à capital variable, dit son capital ;
     * - le Studio Lumière, à capital variable, n'en dit aucun ;
     * - la Librairie des Quais, société en nom collectif, n'en saisit pas ;
     * - le Groupe Nord Distribution, société par actions simplifiée à
     *   capital fixe, dit le sien et exploite ses supermarchés en
     *   gérance-mandat ;
     * - l'Atelier Brun est un entrepreneur individuel à responsabilité
     *   limitée, qui dit l'objet de son activité ;
     * - la Boulangerie Duval est un entrepreneur individuel
     *   locataire-gérant de son fonds.
     * Les artisans s'immatriculent au répertoire des métiers.
     *
     * @return array<string, array<string, string|bool>>
     */
    private function identitesDesCas(): array
    {
        $siren = fn (string $cle): string => trim(chunk_split(substr((string) $this->semis->societe($cle)->siren, 0, 9), 3, ' '));
        $registre = fn (string $cle): string => 'RCS Paris '.$siren($cle);
        $metiers = fn (string $cle): string => 'RM 75 '.$siren($cle);

        return [
            'comptoir' => ['capital_social' => '15000.00', 'capital_variable' => true, 'immatriculation' => $registre('comptoir')],
            'atelier' => ['immatriculation' => $metiers('atelier'), 'eirl' => true, 'eirl_objet' => 'Retouche et confection de vêtements sur mesure'],
            'studio' => ['capital_variable' => true, 'immatriculation' => $registre('studio')],
            'groupe' => ['capital_social' => '15000.00', 'immatriculation' => $registre('groupe'), 'exploitation_en_gerance' => ExploitationEnGerance::GerantMandataire->value],
            'boulangerie' => ['immatriculation' => $metiers('boulangerie'), 'exploitation_en_gerance' => ExploitationEnGerance::LocataireGerant->value],
            'librairie' => ['immatriculation' => $registre('librairie')],
        ];
    }

    /**
     * Les premières fiches des sociétés des cas qui vendent : l'inventaire
     * d'ouverture de chaque magasin d'articles (celui de Lyon reste en
     * brouillon), les services des sociétés de services et leurs clients.
     */
    public function remplir(): void
    {
        $articles = [
            ['produit' => ['nom' => 'Savon au coco', 'reference' => 'SAVON-COCO', 'prix_vente_ttc' => '6.50', 'taux_tva' => '8.5', 'seuil_alerte' => 10], 'quantite' => 400, 'cout_unitaire_ht' => '2.10'],
            ['produit' => ['nom' => 'Épices colombo 100 g', 'reference' => 'COLOMBO', 'prix_vente_ttc' => '4.20', 'taux_tva' => '2.1', 'seuil_alerte' => 10], 'quantite' => 400, 'cout_unitaire_ht' => '1.60'],
        ];
        (new GestesDuMagasin($this->semis->magasin('fort-de-france')))->ouvrirLeStock($this->semis->compte('Joël Sainte-Rose'), $articles);

        foreach (['bordeaux' => 'Inès Lafitte', 'nantes' => 'Ronan Guillou', 'lille' => 'Chloé Vandamme', 'groupe' => 'Julien Delattre', 'boulangerie' => 'Sophie Duval', 'librairie' => 'Hervé Noël'] as $magasin => $responsable) {
            (new GestesDuMagasin($this->semis->magasin($magasin)))->ouvrirLeStock($this->semis->compte($responsable), [
                ['produit' => ['nom' => 'Panier en osier', 'reference' => 'PANIER-OSIER', 'prix_vente_ttc' => '22.00', 'taux_tva' => '20', 'seuil_alerte' => 5], 'quantite' => 120, 'cout_unitaire_ht' => '8.00'],
                ['produit' => ['nom' => 'Confiture artisanale', 'reference' => 'CONFITURE', 'prix_vente_ttc' => '5.80', 'taux_tva' => '5.5', 'seuil_alerte' => 10], 'quantite' => 200, 'cout_unitaire_ht' => '2.40'],
            ]);
        }

        $lyon = new GestesDuMagasin($this->semis->magasin('lyon'));
        $lyon->dans(fn (): InventaireOuverture => app(CreerInventaireOuvertureAction::class)->handle($this->semis->compte('Bastien Royer'), [
            ['produit' => ['nom' => 'Panier en osier', 'reference' => 'PANIER-OSIER', 'prix_vente_ttc' => '22.00', 'taux_tva' => '20', 'seuil_alerte' => 5], 'quantite' => 30, 'cout_unitaire_ht' => '8.00'],
        ]));

        foreach (['atelier' => 'Mathilde Brun (atelier)', 'studio' => 'Rémi Lacroix'] as $magasin => $responsable) {
            $gestes = new GestesDuMagasin($this->semis->magasin($magasin));
            $gestes->fiche($this->semis->compte($responsable), Service::class, ['nom' => 'Séance de prise de vue', 'prix_ttc' => '150.00', 'taux_tva' => '20', 'actif' => true]);
            $gestes->fiche($this->semis->compte($responsable), Service::class, ['nom' => 'Retouche de vêtement', 'prix_ttc' => '25.00', 'taux_tva' => '20', 'actif' => true]);
            $gestes->fiche($this->semis->compte($responsable), Client::class, ['nom' => 'Agence Pixel', 'type_client' => TypeClient::Professionnel, 'siren' => IdentifiantsInsee::avecSaCle('00000040'), 'adresse' => '4 rue du Louvre', 'code_postal' => '75001', 'ville' => 'Paris', 'pays' => 'FR', 'email' => 'compta@pixel.exemple.test']);
        }
    }

    /**
     * Les liens du cabinet Martin avec les sociétés des cas, au début de
     * l'histoire : demandes du client acceptée (terminée au soixantième
     * jour), refusée, retirée, et qui expirera sept jours après par la
     * tâche de chaque heure ; et les magasins que l'Épicerie ouvre à son
     * cabinet, dont elle retire « Factures d'achat : voir » et « Avoirs
     * fournisseur : voir » (lien actif jusqu'à la fin du semis : le cabinet
     * ne voit pas la carte « Écart des prix d'achat » de cette cliente, qui
     * lit les achats, alors qu'il la voit pour Maison Ferrand).
     */
    public function lierAuCabinet(): void
    {
        $cabinet = $this->semis->societe('cabinet');
        $proprietaireDuCabinet = $this->semis->proprietaire('cabinet');

        foreach (['groupe', 'librairie', 'atelier', 'studio', 'boulangerie', 'sans code'] as $cle) {
            $this->semis->horsDeToutContexte(fn (): LienCabinet => app(DesignerUnCabinetAction::class)->handle($this->semis->proprietaire($cle), $this->semis->societe($cle), $cabinet));
        }

        $this->semis->horsDeToutContexte(fn () => app(AccepterLaDemandeDeLienAction::class)->handle($proprietaireDuCabinet, $this->demande('groupe')));
        $this->semis->horsDeToutContexte(fn () => app(AccepterLaDemandeDeLienAction::class)->handle($proprietaireDuCabinet, $this->demande('boulangerie')));
        $this->semis->horsDeToutContexte(fn () => app(AccepterLaDemandeDeLienAction::class)->handle($proprietaireDuCabinet, $this->demande('sans code')));
        $this->semis->horsDeToutContexte(fn () => app(RefuserLaDemandeDeLienAction::class)->handle($proprietaireDuCabinet, $this->demande('librairie')));
        $this->semis->horsDeToutContexte(fn () => app(RetirerLaDemandeDeLienAction::class)->handle($this->semis->proprietaire('atelier'), $this->demande('atelier')));

        $lienDeLEpicerie = LienCabinet::query()->withoutGlobalScopes()
            ->where('cabinet_id', $cabinet->getKey())
            ->where('societe_id', $this->semis->societe('client')->getKey())
            ->where('etat', EtatDuLien::Actif->value)
            ->sole();
        $this->semis->horsDeToutContexte(fn () => app(ReglerLesAutorisationsDuCabinetAction::class)->handle(
            $this->semis->proprietaire('client'),
            $lienDeLEpicerie,
            array_values(array_diff(
                PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)),
                [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::AVOIR_FOURNISSEUR_VIEW],
            )),
            [$this->semis->magasin('epicerie')->id],
        ));
    }

    /**
     * Le travail de ce jour de l'histoire, le soir, entre 20 h et 21 h 30 :
     * les ventes des sociétés des cas, puis l'événement que ce jour porte.
     */
    public function auJour(int $rang): void
    {
        $avantLaFin = SemisComplet::JOURS - $rang;

        if ($rang === 60) {
            $this->leSoir($rang, '20:00', fn () => $this->semis->horsDeToutContexte(fn () => app(MettreFinAuLienAction::class)->handle($this->semis->proprietaire('groupe'), $this->lien('groupe', EtatDuLien::Actif))));
        }

        if ($avantLaFin <= self::DEBUT_DU_COMPTOIR) {
            $this->vendre($rang, $avantLaFin);
        }

        match ($avantLaFin) {
            self::FERMETURE_DE_LYON => $this->leSoir($rang, '21:30', fn () => $this->fermer('lyon', MotifFermeture::Temporaire)),
            self::CESSATION_DE_BORDEAUX => $this->leSoir($rang, '21:30', fn () => $this->fermer('bordeaux', MotifFermeture::Cessation)),
            self::REOUVERTURE_DE_BORDEAUX => $this->leSoir($rang, '21:30', function (): void {
                $this->semis->horsDeToutContexte(fn (): Magasin => app(RouvrirMagasinAction::class)->handle($this->semis->proprietaire('comptoir'), $this->semis->relireLeMagasin('bordeaux'), $this->semis->motDePasseDuProprietaire('comptoir')));
                $this->semis->relireLeMagasin('bordeaux');
            }),
            self::CESSION_DE_NANTES => $this->leSoir($rang, '21:30', fn () => $this->fermer('nantes', MotifFermeture::Cession, 'Comptoir des Îles Nantes')),
            self::CESSATION_D_AJACCIO => $this->leSoir($rang, '21:30', fn () => $this->fermer('ajaccio', MotifFermeture::Cessation)),
            self::SUSPENSION_DE_LILLE => $this->leSoir($rang, '21:30', function (): void {
                $this->semis->magasin('lille')->forceFill(['suspendu_le' => now()])->save();
                $this->semis->relireLeMagasin('lille');
            }),
            self::SUSPENSION_DE_LA_BOULANGERIE => $this->leSoir($rang, '21:30', fn () => $this->semis->societe('boulangerie')->forceFill(['statut' => StatutSociete::Suspendue])->save()),
            self::TRANSMISSION_DE_LA_LIBRAIRIE => $this->leSoir($rang, '21:30', fn () => $this->transmettreLaLibrairie()),
            self::RESILIATION_DE_LA_LIBRAIRIE => $this->leSoir($rang, '21:30', fn () => $this->semis->societe('librairie')->forceFill(['statut' => StatutSociete::Resiliee])->save()),
            self::LIQUIDATION_DE_LA_LIBRAIRIE => $this->leSoir($rang, '21:30', fn () => $this->semis->completerLIdentite('librairie', ['en_liquidation' => true])),
            self::INVITATIONS_ET_DEMANDES => $this->leSoir($rang, '21:30', fn () => $this->inviter()),
            self::ECARTS_SIRENE => $this->leSoir($rang, '21:30', fn () => $this->fermerDesEtablissementsAuRepertoire()),
            self::ECART_VERIFIE => $this->leSoir($rang, '21:30', function (): void {
                $ecart = EcartSirene::query()->withoutGlobalScopes()->where('magasin_id', $this->semis->magasin('studio')->id)->whereNull('verifie_le')->sole();
                $this->semis->horsDeToutContexte(fn () => app(VerifierUnEcartSireneAction::class)->handle($this->semis->proprietaire('studio'), $ecart));
            }),
            self::FIN_D_EXERCICE_CHANGEE => $this->leSoir($rang, '21:30', fn () => $this->changerLaFinDExercice($rang)),
            self::INSCRIPTION_A_REVOQUER => $this->leSoir($rang, '21:30', fn () => $this->semis->inscrire('révoquée', self::SIREN_REVOQUEE, TypeDActivite::Commerce, 'proprietaire@maroquinerie-delmas.exemple.test')),
            self::CODE_A_REVOQUER => $this->leSoir($rang, '21:30', function (): void {
                $this->semis->saisirLeCode('révoquée');
                $this->semis->ouvrirUnMagasin('révoquée', 'maroquinerie', 'Maroquinerie Delmas', 'DELMAS', 'siege');
                $this->semis->horsDeToutContexte(fn (): LienCabinet => app(DesignerUnCabinetAction::class)->handle($this->semis->proprietaire('révoquée'), $this->semis->societe('révoquée'), $this->semis->societe('cabinet')));
                $this->semis->horsDeToutContexte(fn () => app(AccepterLaDemandeDeLienAction::class)->handle($this->semis->proprietaire('cabinet'), $this->demande('révoquée')));
            }),
            self::DECLARATION_A_LA_PLACE_DU_CABINET => $this->leSoir($rang, '21:30', fn () => $this->semis->horsDeToutContexte(fn () => app(DeclarerSonExpertComptableAction::class)->handle($this->semis->proprietaire('révoquée'), $this->semis->societe('révoquée'), [
                'siren' => SemisComplet::SIREN_CABINET_DECLARE,
                'name' => 'Lucie Garnier',
                'email' => 'lucie.garnier@petit-associes.exemple.test',
                'email_confirmation' => 'lucie.garnier@petit-associes.exemple.test',
                'autorisations' => PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)),
                'remplacer' => true,
            ], $this->semis->motDePasseDuProprietaire('révoquée'), GestesDuMagasin::jeton()))),
            self::LIEN_TERMINE_PAR_LE_CABINET => $this->leSoir($rang, '21:30', fn () => $this->semis->horsDeToutContexte(fn () => app(MettreFinAuLienAction::class)->handle($this->semis->proprietaire('cabinet'), $this->lien('boulangerie', EtatDuLien::Actif)))),
            self::MANDAT_REVOQUE => $this->leSoir($rang, '21:30', function (): void {
                $bordeaux = $this->semis->relireLeMagasin('bordeaux');
                app(TenantContext::class)->runAs($bordeaux, fn () => app(RevokerMandatPdpAction::class)->handle(app(CreerMandatPdpAction::class)->handle($bordeaux, $this->semis->proprietaire('comptoir'))));
            }),
            self::COURRIER_DE_REVOCATION => $this->leSoir($rang, '21:30', function () use ($rang): void {
                $this->semis->horsDeToutContexte(fn () => app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur(
                    $this->semis->societe('révoquée'),
                    MotifCode::Revocation,
                    new InterventionDeLEditeur('Le titulaire du SIREN 000 000 406 conteste cette inscription, pièces à l\'appui (extrait Kbis et pièce d\'identité du gérant).', 'Lettre du titulaire du SIREN reçue le '.$this->horloge->jour($rang - 1), ['opérateur A', 'opérateur B']),
                ));
                $this->codeDeRevocation = $this->semis->codeDuDernierCourrier();
            }),
            self::REVOCATION => $this->leSoir($rang, '21:30', function () use ($rang): void {
                $code = $this->codeDeRevocation ?? throw new LogicException('Le courrier de révocation n\'est pas parti.');
                $this->semis->horsDeToutContexte(fn () => app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure(self::SIREN_REVOQUEE, MotifCode::Revocation, $code, '203.0.113.40'));
                $this->semis->horsDeToutContexte(fn (): array => app(RevoquerSocieteAction::class)->handle($this->semis->societe('révoquée'), new InterventionDeLEditeur(self::JUSTIFICATION.' Le titulaire évincé du SIREN a reçu le courrier de révocation et en a saisi le code.', 'Code du courrier de révocation saisi le '.$this->horloge->jour($rang), ['opérateur A', 'opérateur B'])));
                $this->semis->relireLeMagasin('maroquinerie');
            }),
            self::INSCRIPTION_SANS_COURRIER_RECU => $this->leSoir($rang, '21:30', fn () => $this->semis->inscrire('pièces', self::SIREN_PIECES, TypeDActivite::Commerce, 'proprietaire@fromagerie-morin.exemple.test')),
            self::NOUVEAU_COURRIER => $this->leSoir($rang, '21:30', fn () => $this->semis->horsDeToutContexte(fn () => app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($this->semis->proprietaire('pièces'), $this->semis->societe('pièces')))),
            self::PIECES_DEPOSEES => $this->leSoir($rang, '21:30', fn () => $this->deposerLesPieces([
                [TypePiece::Kbis, 'Extrait Kbis Fromagerie Morin.pdf'],
                [TypePiece::Identite, 'Carte d\'identité Élise Morin.pdf'],
            ])),
            self::PIECES_EXAMINEES => $this->leSoir($rang, '21:30', fn () => $this->semis->horsDeToutContexte(fn () => app(ExaminerLesPiecesAction::class)->handle(
                $this->semis->societe('pièces')->refresh(),
                new InterventionDeLEditeur(self::JUSTIFICATION.' L\'extrait Kbis et la pièce d\'identité concordent avec le registre : nouveau courrier envoyé.', 'Pièces déposées le '.$this->horloge->jour(SemisComplet::JOURS - self::PIECES_DEPOSEES), ['opérateur A', 'opérateur B']),
            ))),
            self::NOUVELLES_PIECES => $this->leSoir($rang, '21:30', fn () => $this->deposerLesPieces([
                [TypePiece::ExtraitRne, 'Extrait RNE Fromagerie Morin.pdf'],
                [TypePiece::Autre, 'Attestation de domiciliation.pdf'],
            ])),
            self::PROCEDURES_DE_L_EDITEUR => $this->leSoir($rang, '21:30', fn () => $this->procedureDeLEditeur($rang)),
            self::LIEN_AVEC_LE_CABINET_RADIE => $this->leSoir($rang, '21:30', function (): void {
                $this->semis->horsDeToutContexte(fn (): LienCabinet => app(DesignerUnCabinetAction::class)->handle($this->semis->proprietaire('boulangerie'), $this->semis->societe('boulangerie'), $this->semis->societe('cabinet radié')));
                $this->semis->horsDeToutContexte(fn () => app(AccepterLaDemandeDeLienAction::class)->handle($this->semis->proprietaire('cabinet radié'), $this->lien('boulangerie', EtatDuLien::DemandeParLeClient, 'cabinet radié')));
            }),
            self::RADIATION_CONSTATEE => $this->leSoir($rang, '21:30', fn () => $this->semis->horsDeToutContexte(fn (): int => app(RetirerLInscriptionALOrdreAction::class)->handle(
                $this->semis->societe('cabinet radié')->refresh(),
                new InterventionDeLEditeur(self::JUSTIFICATION.' Radiation publiée par le conseil régional de l\'Ordre.', 'Avis du conseil régional reçu le '.$this->horloge->jour($rang - 1), ['opérateur A', 'opérateur B']),
                'Radiation du tableau de l\'Ordre décidée par le conseil régional d\'Occitanie.',
            ))),
            self::INSCRIPTION_REMPLACEE => $this->leSoir($rang, '21:30', function (): void {
                $this->semis->inscrire('remplacée', self::SIREN_REMPLACEE, TypeDActivite::Commerce, 'proprietaire@verger-des-coteaux.exemple.test');
                $this->codeDeLaRemplacee = $this->semis->codeDuDernierCourrier();
                $this->semis->horsDeToutContexte(fn (): LienCabinet => app(DesignerUnCabinetAction::class)->handle($this->semis->proprietaire('remplacée'), $this->semis->societe('remplacée'), $this->semis->societe('cabinet')));
                $this->semis->horsDeToutContexte(fn () => app(AccepterLaDemandeDeLienAction::class)->handle($this->semis->proprietaire('cabinet'), $this->demande('remplacée')));
            }),
            self::CODE_SAISI_PAR_UNE_AUTRE_PERSONNE => $this->leSoir($rang, '21:30', fn () => $this->saisirLeCodeALaPlaceDeLInscrite()),
            self::CORRECTION_DU_SIREN => $this->leSoir($rang, '21:30', fn () => $this->corrigerLeSirenDuStudio($rang)),
            self::REGULARISATION_DU_STUDIO => $this->leSoir($rang, '21:30', fn () => $this->regulariserUneFactureDuStudio()),
            default => null,
        };
    }

    /**
     * Les ventes du soir : un ou deux tickets au magasin de la Martinique
     * presque chaque jour, et un ticket tous les trois jours dans les
     * autres magasins des cas, tant qu'ils sont ouverts.
     */
    private function vendre(int $rang, int $avantLaFin): void
    {
        $this->horloge->aller($rang, '20:00');
        $fortDeFrance = new GestesDuMagasin($this->semis->magasin('fort-de-france'));

        if ($rang % 4 !== 0) {
            $fortDeFrance->vendreALaCaisse($this->semis->compte($rang % 2 === 0 ? 'Joël Sainte-Rose' : 'Maëva Jean-Louis'), $fortDeFrance->comptoir(), [$fortDeFrance->ligne('SAVON-COCO', 2), $fortDeFrance->ligne('COLOMBO', 1)], ModePaiement::CarteBancaire);
        }

        if ($rang % 3 !== 0) {
            return;
        }

        $this->horloge->aller($rang, '20:30');
        $ouverts = [
            'bordeaux' => $avantLaFin > self::CESSATION_DE_BORDEAUX || $avantLaFin < self::REOUVERTURE_DE_BORDEAUX ? 'Inès Lafitte' : null,
            'nantes' => $avantLaFin > self::CESSION_DE_NANTES ? 'Ronan Guillou' : null,
            'lille' => $avantLaFin > self::SUSPENSION_DE_LILLE ? 'Chloé Vandamme' : null,
        ];

        if ($avantLaFin <= self::DEBUT_DES_AUTRES) {
            $ouverts += [
                'boulangerie' => $avantLaFin > self::SUSPENSION_DE_LA_BOULANGERIE ? 'Sophie Duval' : null,
                'librairie' => $avantLaFin > self::RESILIATION_DE_LA_LIBRAIRIE ? 'Hervé Noël' : null,
            ];
        }

        foreach (array_filter($ouverts) as $magasin => $vendeur) {
            $gestes = new GestesDuMagasin($this->semis->magasin($magasin));
            $gestes->vendreALaCaisse($this->semis->compte($vendeur), $gestes->comptoir(), [$gestes->ligne('PANIER-OSIER', 1), $gestes->ligne('CONFITURE', 2)], ModePaiement::CarteBancaire);
        }

        if ($avantLaFin <= self::DEBUT_DES_AUTRES) {
            $this->horloge->aller($rang, '21:15');
            $atelier = new GestesDuMagasin($this->semis->magasin('atelier'));
            $atelier->vendreALaCaisse($this->semis->compte('Mathilde Brun (atelier)'), $atelier->comptoir(), [$atelier->ligne('Retouche de vêtement', 2)], ModePaiement::Especes);
            $studio = new GestesDuMagasin($this->semis->magasin('studio'));
            $pixel = $studio->dans(fn (): Client => Client::query()->where('nom', 'Agence Pixel')->sole());
            $facture = $studio->facturer($this->semis->compte('Rémi Lacroix'), $pixel, [$studio->ligne('Séance de prise de vue', 1)]);
            $studio->encaisser($this->semis->compte('Rémi Lacroix'), $facture, [new PartDeReglement(ModePaiement::Virement, 15000)], $this->horloge->jour($rang));
        }
    }

    /**
     * Le propriétaire du Comptoir des Îles ferme ce magasin, pour ce motif,
     * en redonnant son mot de passe (et en retapant le nom du magasin pour
     * une cession).
     */
    private function fermer(string $magasin, MotifFermeture $motif, ?string $confirmation = null): void
    {
        $this->semis->horsDeToutContexte(fn () => app(FermerMagasinAction::class)->handle(
            $this->semis->proprietaire('comptoir'),
            $this->semis->relireLeMagasin($magasin),
            $motif,
            $this->semis->motDePasseDuProprietaire('comptoir'),
            $confirmation,
        ));
        $this->semis->relireLeMagasin($magasin);
    }

    /**
     * L'administrateur de la Librairie des Quais renseigne son adresse dans
     * son profil, et la propriétaire lui transmet la propriété : il suit le
     * lien reçu, connecté à son compte, et devient propriétaire ; elle
     * devient administratrice. Le Comptoir des Îles demande la même
     * transmission à son administratrice, qui n'a pas encore suivi le lien.
     */
    private function transmettreLaLibrairie(): void
    {
        $etienne = $this->semis->compte('Étienne Vidal');
        $etienne->forceFill(['email' => 'etienne.vidal@librairie-des-quais.exemple.test'])->save();
        $ancienne = $this->semis->proprietaire('librairie');
        $this->semis->horsDeToutContexte(fn (): TransmissionPropriete => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($ancienne, $etienne, $this->semis->motDePasseDuProprietaire('librairie')));
        $lien = ValeursLues::instance(Mail::sent(TransmissionDeLaProprieteMail::class)->last(), TransmissionDeLaProprieteMail::class)->lien ?? throw new LogicException('L\'e-mail de la transmission ne porte aucun lien.');
        $this->semis->horsDeToutContexte(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($etienne->refresh(), Str::afterLast($lien, '/'), $this->semis->motDePasseDe('Étienne Vidal')));
        $this->liste->noterLaTransmission($ancienne->refresh(), $etienne->refresh());

        $gaelle = $this->semis->compte('Gaëlle Hoarau');
        $gaelle->forceFill(['email' => 'gaelle.hoarau@comptoir-des-iles.exemple.test'])->save();
        $this->semis->horsDeToutContexte(fn (): TransmissionPropriete => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($this->semis->proprietaire('comptoir'), $gaelle, $this->semis->motDePasseDuProprietaire('comptoir')));
    }

    /**
     * Le répertoire Sirene dit fermés les établissements du Studio Lumière
     * et du Groupe Nord : la revérification de toutes les sociétés, que le
     * planificateur lance le premier du mois, relève un écart pour chacun,
     * sans rien bloquer ; la propriétaire du Studio dira ensuite « C'est
     * vérifié », l'autre écart reste à regarder.
     */
    private function fermerDesEtablissementsAuRepertoire(): void
    {
        $sirene = ValeursLues::instance(app(RepertoireSirene::class), FauxRepertoireSirene::class);
        $sirene->fermer(FauxRepertoireSirene::siretDuSiege(self::SIREN_STUDIO));
        $sirene->fermer(FauxRepertoireSirene::siretDuSiege(self::SIREN_GROUPE));

        if (Artisan::call('app:reverifier-sirene', ['--toutes' => true]) !== 0) {
            throw new LogicException('La revérification au répertoire Sirene a échoué : '.trim(Artisan::output()));
        }
    }

    /**
     * Le Studio Lumière raccourcit son exercice en cours, qui finira le
     * dernier jour du mois suivant, par une décision de son assemblée
     * générale extraordinaire, que l'éditeur enregistre par sa procédure
     * (deux opérateurs, justification et pièce).
     */
    private function changerLaFinDExercice(int $rang): void
    {
        $finDuMoisSuivant = Calendrier::ajouterJours(Calendrier::premierJourDuMois(Calendrier::ajouterJours(Calendrier::premierJourDuMois($this->horloge->jour($rang)), 40)), -1);
        app(ChangerFinExerciceAction::class)->handle(
            $this->semis->societe('studio')->refresh(),
            (int) substr($finDuMoisSuivant, 5, 2),
            $finDuMoisSuivant,
            $this->horloge->jour($rang - 1),
            OrganeDeLaDecision::AssembleeGeneraleExtraordinaire,
            null,
            null,
            new InterventionDeLEditeur(self::JUSTIFICATION.' Procès-verbal de l\'assemblée reçu et vérifié.', 'Procès-verbal de l\'AGE du '.$this->horloge->jour($rang - 1), ['opérateur A', 'opérateur B']),
        );
    }

    /**
     * Les derniers jours, les invitations du cabinet Martin dans chaque
     * état (celle dont l'entreprise s'inscrit ensuite elle-même devient
     * caduque à la tâche de l'heure suivante), sa demande de rattachement
     * au Comptoir des Îles, déjà inscrit, et la nouvelle demande de
     * l'Atelier Brun, qui attendent toutes deux leur réponse.
     */
    private function inviter(): void
    {
        $this->inviterDesEntreprisesQuiNeSInscriventPas();
        $this->invitation(self::SIRENS_INVITES['caduque']);
        $this->semis->inscrire('caduque', self::SIRENS_INVITES['caduque'], TypeDActivite::Commerce, 'proprietaire@patisserie-arnaud.exemple.test');
        $this->invitation(self::SIREN_COMPTOIR);
        $this->semis->horsDeToutContexte(fn (): LienCabinet => app(DesignerUnCabinetAction::class)->handle($this->semis->proprietaire('atelier'), $this->semis->societe('atelier'), $this->semis->societe('cabinet')));
    }

    /**
     * Les invitations du cabinet Martin à des entreprises qui ne
     * s'inscrivent pas : l'une reste ouverte, une autre est refusée par la
     * personne qui reçoit le lien, la troisième retirée par le cabinet.
     * Elles ne créent aucune société : la base modèle « Cabinet » du site
     * de démonstration les reçoit aussi (F.2).
     */
    public function inviterDesEntreprisesQuiNeSInscriventPas(): void
    {
        foreach (['ouverte', 'refusee', 'retiree'] as $etat) {
            $this->invitation(self::SIRENS_INVITES[$etat]);
        }

        $this->semis->horsDeToutContexte(fn () => app(RefuserLInvitationAction::class)->handle($this->jetonDeLInvitation(self::SIRENS_INVITES['refusee'])));
        $this->semis->horsDeToutContexte(fn () => app(RetirerLInvitationAction::class)->handle($this->semis->proprietaire('cabinet'), $this->invitationOuverte(self::SIRENS_INVITES['retiree'])));
    }

    /**
     * L'invitation qui expirera sept jours après, par la tâche de chaque
     * heure, partie au début de l'histoire.
     */
    public function inviterCeQuiExpirera(): void
    {
        $this->invitation(self::SIRENS_INVITES['expiree']);
    }

    private function invitation(string $siren): void
    {
        $this->semis->horsDeToutContexte(fn () => app(InviterUneEntrepriseAction::class)->handle(
            $this->semis->proprietaire('cabinet'),
            $this->semis->societe('cabinet'),
            $siren,
            'dirigeant-'.$siren.'@exemple.test',
            $this->semis->motDePasseDuProprietaire('cabinet'),
        ));
    }

    private function jetonDeLInvitation(string $siren): string
    {
        $mail = Mail::sent(InvitationDuCabinetMail::class, fn (InvitationDuCabinetMail $mail): bool => $mail->siren === $siren)->last();

        return Str::afterLast(ValeursLues::instance($mail, InvitationDuCabinetMail::class)->lien, '/');
    }

    private function invitationOuverte(string $siren): InvitationCabinet
    {
        return InvitationCabinet::query()->withoutGlobalScopes()
            ->where('societe_id', $this->semis->societe('cabinet')->getKey())
            ->where('siren', $siren)
            ->where('etat', EtatInvitation::Ouverte->value)
            ->sole();
    }

    private function demande(string $client): LienCabinet
    {
        return $this->lien($client, EtatDuLien::DemandeParLeClient);
    }

    private function lien(string $client, EtatDuLien $etat, string $cabinet = 'cabinet'): LienCabinet
    {
        return LienCabinet::query()->withoutGlobalScopes()
            ->where('cabinet_id', $this->semis->societe($cabinet)->getKey())
            ->where('societe_id', $this->semis->societe($client)->getKey())
            ->where('etat', $etat->value)
            ->sole();
    }

    /**
     * La propriétaire de la Fromagerie Morin, dont aucun courrier n'est
     * parvenu, dépose ces pièces une fois le nouveau courrier parti (voir
     * DeposerUnePieceAction) ; l'éditeur examine les premières (traitement
     * à la main, qui les efface et envoie un nouveau courrier), pas encore
     * les secondes.
     *
     * @param  list<array{0: TypePiece, 1: string}>  $pieces
     */
    private function deposerLesPieces(array $pieces): void
    {
        foreach ($pieces as [$type, $nom]) {
            $this->semis->horsDeToutContexte(fn () => app(DeposerUnePieceAction::class)->handle($this->semis->proprietaire('pièces'), $this->semis->societe('pièces')->refresh(), $type, new PieceDeposee($nom, PieceDuSemis::pdf('FROMAGERIE MORIN', $nom))));
        }
    }

    /**
     * Les courriers des autres procédures de l'éditeur, chacun au
     * représentant légal à l'adresse officielle du siège, et une
     * revérification de tous les établissements pendant une panne du
     * répertoire Sirene.
     */
    private function procedureDeLEditeur(int $rang): void
    {
        $intervention = fn (string $motif): InterventionDeLEditeur => new InterventionDeLEditeur(self::JUSTIFICATION.' '.$motif, 'Demande reçue le '.$this->horloge->jour($rang - 1), ['opérateur A', 'opérateur B']);
        $courrier = app(EnvoyerCodeDeConfirmationAction::class);

        $this->semis->horsDeToutContexte(fn () => $courrier->parLEditeur($this->semis->societe('boulangerie'), MotifCode::Recuperation, $intervention('La propriétaire a perdu l\'accès à son adresse de connexion.')));
        $this->semis->horsDeToutContexte(fn () => $courrier->parLEditeur($this->semis->societe('comptoir'), MotifCode::LiberationSiret, $intervention('Le repreneur du magasin de Nantes demande la libération de son SIRET.'), siretALiberer: (string) $this->semis->magasin('nantes')->siret));
        $this->semis->horsDeToutContexte(fn () => $courrier->parLEditeur($this->semis->societe('studio'), MotifCode::ExamenSiren, $intervention('Le gérant signale que son entreprise a été inscrite sous le SIREN d\'une autre entité.'), sirenDeLExamen: self::SIREN_DE_L_EXAMEN));
        $this->codeDeLExamen = $this->semis->codeDuDernierCourrier();

        $sirene = ValeursLues::instance(app(RepertoireSirene::class), FauxRepertoireSirene::class);
        $sirene->rendreInjoignable();

        try {
            Artisan::call('app:reverifier-sirene', ['--toutes' => true]);
        } finally {
            $sirene->rendreInjoignable(false);
        }
    }

    /**
     * Une autre personne que l'inscrite, la présidente de l'entreprise,
     * reçoit le courrier du Verger des Coteaux et en saisit le code avec
     * son adresse et son mot de passe : elle devient propriétaire de la
     * société validée, les comptes créés avant sont retirés, et le lien
     * avec le cabinet Martin prend fin (voir SaisirCodeDeConfirmationAction).
     */
    private function saisirLeCodeALaPlaceDeLInscrite(): void
    {
        $code = $this->codeDeLaRemplacee ?? throw new LogicException('Le courrier du Verger des Coteaux n\'est pas parti.');
        $motDePasse = $this->motsDePasse->nouveau();
        $proprietaire = $this->semis->horsDeToutContexte(fn (): User => app(SaisirCodeDeConfirmationAction::class)->handle(self::SIREN_REMPLACEE, $code, 'helene.champion@verger-des-coteaux.exemple.test', $motDePasse, $motDePasse, '203.0.113.42'));
        $this->motsDePasse->controlerAvec($motDePasse, (string) $proprietaire->identifiantDeConnexion());
        $this->liste->noter($proprietaire, 'VERGER DES COTEAUX', '', 'Propriétaire', $motDePasse, 'Propriétaire qui a saisi le code du courrier à la place de la personne inscrite : les comptes d\'avant sont retirés, le lien avec le cabinet Martin a pris fin, elle choisira elle-même son cabinet');
    }

    /**
     * Le représentant légal de l'entité correcte saisit le code du courrier
     * de l'examen ; l'éditeur examine le SIREN du Studio Lumière (erreur :
     * la liste des factures à régulariser est inscrite au journal d'audit),
     * puis le corrige, avec le nouveau SIRET de son magasin (voir
     * ExaminerSirenAction et CorrigerSirenAction).
     */
    private function corrigerLeSirenDuStudio(int $rang): void
    {
        $code = $this->codeDeLExamen ?? throw new LogicException('Le courrier de l\'examen du SIREN du Studio Lumière n\'est pas parti.');
        $editeur = new InterventionDeLEditeur(self::JUSTIFICATION.' Le représentant légal de STUDIO LUMIERE PHOTOGRAPHIE a reçu le courrier de l\'examen et en a saisi le code.', 'Code du courrier de l\'examen saisi le '.$this->horloge->jour($rang), ['opérateur A', 'opérateur B']);

        $this->semis->horsDeToutContexte(fn () => app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure(self::SIREN_DE_L_EXAMEN, MotifCode::ExamenSiren, $code, '203.0.113.41'));
        $this->semis->horsDeToutContexte(fn (): int => app(ExaminerSirenAction::class)->handle($this->semis->societe('studio')->refresh(), self::SIREN_DE_L_EXAMEN, DecisionExamenSiren::Erreur, $editeur));
        $this->horloge->aller($rang, '21:35');
        $this->semis->horsDeToutContexte(fn (): Societe => app(CorrigerSirenAction::class)->handle(
            $this->semis->societe('studio')->refresh(),
            self::SIREN_DE_L_EXAMEN,
            [(string) $this->semis->magasin('studio')->code => FauxRepertoireSirene::siretDuSiege(self::SIREN_DE_L_EXAMEN)],
            null,
            $editeur,
        ));
        $this->semis->societe('studio')->refresh();
        $this->semis->relireLeMagasin('studio');
    }

    /**
     * La plus récente des factures de l'Agence Pixel que l'examen du SIREN
     * désigne se régularise : avoir de régularisation par la propriétaire,
     * puis facture rectificative par le responsable, qui reçoit le crédit
     * de l'avoir (voir RegularisationDUneFacture).
     */
    private function regulariserUneFactureDuStudio(): void
    {
        $studio = new GestesDuMagasin($this->semis->relireLeMagasin('studio'));
        $regularisation = app(RegularisationDUneFacture::class);
        $facture = $studio->dans(fn (): Facture => Facture::query()
            ->with('magasin.societe.dernierExamenDuSiren')
            ->whereNotNull('numero')
            ->whereHas('client', fn ($client) => $client->where('nom', 'Agence Pixel'))
            ->orderByDesc('id')
            ->get()
            ->first(fn (Facture $facture): bool => $regularisation->estDesignee($facture)) ?? throw new LogicException('Aucune facture du Studio Lumière n\'est désignée par l\'examen de son SIREN.'));

        $studio->dans(fn (): Avoir => app(FaireLAvoirDeRegularisationAction::class)->handle($this->semis->proprietaire('studio'), $facture, GestesDuMagasin::jeton()));
        $studio->dans(fn (): Facture => app(EmettreLaFactureRectificativeAction::class)->handle($this->semis->compte('Rémi Lacroix'), $facture->refresh(), GestesDuMagasin::jeton()));
    }

    /**
     * @param  callable(): mixed  $geste
     */
    private function leSoir(int $rang, string $heure, callable $geste): void
    {
        $this->horloge->aller($rang, $heure);
        app(TenantContext::class)->clear();
        $geste();
    }
}
