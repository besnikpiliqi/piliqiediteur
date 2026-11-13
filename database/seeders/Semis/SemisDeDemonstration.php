<?php

namespace Database\Seeders\Semis;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\AccepterLInvitationAction;
use App\Domain\Cabinets\Actions\ChoisirLeMotDePasseDeLExpertComptableAction;
use App\Domain\Cabinets\Actions\ConfierDesClientsAction;
use App\Domain\Cabinets\Actions\CreerCollaborateurAction;
use App\Domain\Cabinets\Actions\DeclarerSonExpertComptableAction;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Cabinets\Actions\VerifierLOrdreAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Mail\InvitationDuCabinetMail;
use App\Domain\Cabinets\Mail\LienDuMotDePasseDeLExpertComptableMail;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Actions\ChangerSonMotDePasseAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\CreerSocieteAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PosteReconnu;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\Ordre\FauxAnnuaireDeLOrdre;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\FauxRne;
use App\Integrations\Rne\IdentiteRne;
use App\Integrations\Rne\RepresentantRne;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;

/**
 * Le jeu de démonstration de PILIQI pour un poste de développement (lot
 * SEED, demande de l'éditeur du 13/11/2026), celui de la campagne de tests
 * 1 bis : trois sociétés validées par le code de leur courrier (une
 * « Commerce ou atelier » à deux magasins réels, qui s'est essayée dans son
 * magasin de démonstration avant sa validation, une « Services, sans vente
 * d'articles » à un magasin, qui déclare son expert-comptable par le SIREN
 * d'un cabinet absent de PILIQI, et un cabinet d'expertise comptable
 * vérifié à l'Ordre, à deux collaborateurs), une quatrième société, née de
 * l'invitation du cabinet et validée par elle, et une inscription qui
 * attend le code de son courrier, avec son magasin de démonstration
 * ouvert et ses comptes d'entraînement. Le cabinet est lié à la société
 * « Commerce ou atelier » et à la société qu'il a invitée, chacune confiée
 * à un collaborateur.
 *
 * Tout passe par les vraies actions, au nom de la personne qui fait chaque
 * geste, avec les fournisseurs factices (répertoire Sirene, registre
 * national des entreprises, courrier recommandé, annuaire de l'Ordre,
 * plateforme agréée et antivirus) : rien ne s'écrit directement en base,
 * aucun produit ne naît hors d'une facture d'achat ou d'un inventaire
 * d'ouverture validé, et le stock suit son grand livre. L'histoire se
 * déroule dans l'ordre, sur JOURS jours qui finissent la veille du semis
 * (voir HorlogeDuSemis), puis l'activité des magasins se sème (voir
 * ActiviteDesMagasins).
 */
final class SemisDeDemonstration
{
    /**
     * Le nombre de jours de l'histoire semée : assez pour qu'une facture à
     * 30 jours émise au début soit en retard à la fin.
     */
    public const JOURS = 35;

    public const SIREN_COMMERCE = '000000109';

    public const SIREN_SERVICES = '000000117';

    public const SIREN_CLIENT_INVITE = '000000125';

    public const SIREN_INSCRIPTION = '000000133';

    /**
     * Le SIREN du cabinet de l'expert-comptable que la société de services
     * déclare, d'activité 69.20Z au répertoire factice le temps du semis,
     * jamais inscrit dans PILIQI.
     */
    public const SIREN_CABINET_DECLARE = '000000158';

    public const ADRESSE_COMMERCE = 'proprietaire@commerce.exemple.test';

    public const ADRESSE_SERVICES = 'proprietaire@services.exemple.test';

    public const ADRESSE_CABINET = 'proprietaire@cabinet.exemple.test';

    public const ADRESSE_CLIENT_INVITE = 'proprietaire@client-du-cabinet.exemple.test';

    public const ADRESSE_INSCRIPTION = 'proprietaire@inscription.exemple.test';

    public const ADRESSE_EXPERT_COMPTABLE = 'marc.petit@petit-associes.exemple.test';

    /**
     * La catégorie juridique (nomenclature de l'INSEE) des sociétés semées,
     * des SAS, comme toute entreprise du registre factice.
     */
    private const CATEGORIE_SAS = '5710';

    /**
     * Les sociétés, magasins et comptes semés, par nom, que l'activité des
     * magasins reprend.
     *
     * @var array<string, Societe>
     */
    public array $societes = [];

    /** @var array<string, Magasin> */
    public array $magasins = [];

    /** @var array<string, User> */
    public array $comptes = [];

    /**
     * Le code du courrier recommandé de chaque société inscrite, tel que
     * son destinataire le lit sur le pli.
     *
     * @var array<string, string>
     */
    private array $codesDesCourriers = [];

    /**
     * Le nom de la société de chaque magasin semé, pour la liste des
     * comptes.
     *
     * @var array<string, string>
     */
    private array $nomsDesSocietes = [];

    /**
     * Le mot de passe de chaque propriétaire, qu'il redonne pour les gestes
     * qui le demandent.
     *
     * @var array<string, string>
     */
    private array $motsDePasseDesProprietaires = [];

    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly MotsDePasseDuSemis $motsDePasse,
        private readonly CodesLusDansLesCourriers $codes,
        private readonly ComptesSemes $liste,
    ) {}

    /**
     * Sème tout le jeu, dans l'ordre de son histoire.
     */
    public function semer(): void
    {
        $this->nommerLesEntreprises();

        $this->horloge->aller(0, '09:00');
        $this->inscrire('commerce', self::SIREN_COMMERCE, TypeDActivite::Commerce, self::ADRESSE_COMMERCE);
        $this->inscrire('services', self::SIREN_SERVICES, TypeDActivite::Services, self::ADRESSE_SERVICES);
        $this->inscrire('cabinet', FauxRepertoireSirene::SIREN_CABINET, TypeDActivite::Cabinet, self::ADRESSE_CABINET);

        $this->horloge->aller(0, '10:30');
        $this->sEssayerDansLaDemonstration('commerce', [['Romain Lefort', RoleMagasin::Vendeur]]);

        $this->horloge->aller(1, '09:00');
        $this->saisirLeCode('commerce');
        $this->saisirLeCode('services');
        $this->saisirLeCode('cabinet');

        $this->horloge->aller(1, '09:30');
        $this->completerLIdentite('commerce', ['capital_social' => '20000.00', 'immatriculation' => 'RCS Paris 000 000 109']);
        $this->completerLIdentite('services', [
            'capital_social' => '5000.00',
            'immatriculation' => 'RCS Paris 000 000 117',
            'assurance_assureur' => 'Mutuelle des Professions Libérales, 12 rue de la Paix, 75002 Paris',
            'assurance_contrat' => 'RCP-2026-4471',
            'assurance_couverture' => 'France métropolitaine',
        ]);
        $this->verifierLeCabinet();

        $this->horloge->aller(1, '10:00');
        $this->ouvrirUnMagasin('commerce', 'halles', 'Maison Ferrand Halles', 'HALLES', 'siege');
        $this->ouvrirUnMagasin('commerce', 'republique', 'Maison Ferrand République', 'REPUB', 'boutique');
        $this->ouvrirUnMagasin('services', 'conseil', 'Conseil Vasseur Paris', 'PARIS', 'siege');

        $this->horloge->aller(1, '11:00');
        $this->administrateur('commerce', 'Nadia Benali', null, []);
        $this->administrateur('commerce', 'Thomas Girard', ['halles', 'republique'], PermissionRegistry::deVente());
        $this->membre('halles', 'Camille Laurent', RoleMagasin::Responsable);
        $this->membre('halles', 'Lucas Moreau', RoleMagasin::Vendeur);
        $this->membre('halles', 'Inès Garcia', RoleMagasin::Vendeur, gardeSonIdentifiant: true);
        $this->reconnaitreUnPoste('halles', 'Camille Laurent', 'Caisse des Halles');
        $this->membre('republique', 'Hugo Lefebvre', RoleMagasin::Responsable);
        $this->membre('republique', 'Lucas Moreau', RoleMagasin::Vendeur, cle: 'Lucas Moreau (République)');
        $this->membre('republique', 'Sarah Petit', RoleMagasin::Vendeur);

        $this->administrateur('services', 'Léa Fontaine', null, []);
        $this->administrateur('services', 'Antoine Roux', ['conseil'], PermissionRegistry::deVente());
        $this->membre('conseil', 'Claire Dubois', RoleMagasin::Responsable);
        $this->membre('conseil', 'Maxime Henry', RoleMagasin::Vendeur);
        $this->membre('conseil', 'Julie Mercier', RoleMagasin::Vendeur);

        $this->horloge->aller(1, '14:00');
        $this->collaborateur('Paul Lefèvre');
        $this->collaborateur('Emma Rousseau');
        $this->lierLeCabinetA('commerce');
        $this->confier('Paul Lefèvre', 'commerce');

        $this->horloge->aller(2, '09:00');
        $this->accepterLInvitationDuCabinet();
        $this->confier('Emma Rousseau', 'client');
        $this->ouvrirUnMagasin('client', 'epicerie', 'Épicerie Lemoine', 'EPICERIE', 'siege');
        $this->administrateur('client', 'Romane Gauthier', null, []);
        $this->administrateur('client', 'Bastien Leroy', ['epicerie'], PermissionRegistry::deVente());
        $this->membre('epicerie', 'Nicolas Lemoine', RoleMagasin::Responsable);
        $this->membre('epicerie', 'Chloé Bernard', RoleMagasin::Vendeur);
        $this->membre('epicerie', 'Mehdi Brahimi', RoleMagasin::Vendeur);

        $this->horloge->aller(2, '10:00');
        $this->declarerLExpertComptable();

        (new ActiviteDesMagasins($this->horloge, $this))->semer();

        $this->horloge->aller(self::JOURS - 1, '09:00');
        $this->inscrire('inscription', self::SIREN_INSCRIPTION, TypeDActivite::Commerce, self::ADRESSE_INSCRIPTION);
        $this->horloge->aller(self::JOURS - 1, '09:30');
        $this->sEssayerDansLaDemonstration('inscription', [['Karim Haddad', RoleMagasin::Responsable], ['Zoé Marchand', RoleMagasin::Vendeur]]);

        $this->liste->oublierLesComptesRetires();
    }

    /**
     * Le compte semé sous ce nom (ou sous la clé donnée à sa création).
     */
    public function compte(string $nom): User
    {
        return $this->comptes[$nom] ?? throw new LogicException("Aucun compte semé ne s'appelle {$nom}.");
    }

    public function magasin(string $cle): Magasin
    {
        return $this->magasins[$cle] ?? throw new LogicException("Aucun magasin semé n'a la clé {$cle}.");
    }

    public function societe(string $cle): Societe
    {
        return $this->societes[$cle] ?? throw new LogicException("Aucune société semée n'a la clé {$cle}.");
    }

    public function proprietaire(string $societe): User
    {
        return $this->compte("propriétaire {$societe}");
    }

    /**
     * Ce que le répertoire Sirene et le registre national des entreprises
     * factices disent des entreprises semées, le temps du semis : leur
     * nom, leur dirigeant, leur siège et leur identité, et l'activité
     * comptable du cabinet déclaré.
     */
    private function nommerLesEntreprises(): void
    {
        $sirene = app(RepertoireSirene::class);
        $rne = app(RegistreNationalDesEntreprises::class);

        if (! $sirene instanceof FauxRepertoireSirene || ! $rne instanceof FauxRne) {
            throw new LogicException('Le semis ne passe que par le répertoire Sirene et le registre national des entreprises factices.');
        }

        foreach ([
            self::SIREN_COMMERCE => ['MAISON FERRAND', 'FERRAND', ['Hélène'], 'Présidente'],
            self::SIREN_SERVICES => ['CONSEIL VASSEUR', 'VASSEUR', ['Julien'], 'Président'],
            FauxRepertoireSirene::SIREN_CABINET => ['MARTIN EXPERTISE COMPTABLE', 'MARTIN', ['Isabelle'], 'Présidente'],
            self::SIREN_CLIENT_INVITE => ['EPICERIE LEMOINE', 'LEMOINE', ['Bernard'], 'Président'],
            self::SIREN_INSCRIPTION => ['FLEURS DE SAISON', 'BLANC', ['Aurélie'], 'Présidente'],
        ] as $siren => [$denomination, $nom, $prenoms, $role]) {
            $siege = new AdressePostale($denomination, '1 RUE DE LA REPUBLIQUE', null, '75001', 'PARIS', AdressePostale::SOURCE_RNE);
            $sirene->nommer($siren, $denomination);
            $rne->dirigeants($siren, [new RepresentantRne($nom, null, $prenoms, '1980-05', $role, true, null, '2020-01-01')]);
            $rne->domicilier($siren, $siege);
            $rne->identifier($siren, new IdentiteRne($denomination, self::CATEGORIE_SAS, '10000.00', 'EUR', false, 'RCS Paris', $siege));
        }

        $sirene->nommer(self::SIREN_CABINET_DECLARE, 'PETIT ET ASSOCIES');
        $sirene->exercer(self::SIREN_CABINET_DECLARE, ControleSirene::CODE_ACTIVITES_COMPTABLES);
    }

    /**
     * L'inscription d'une société par la confirmation de son lien (voir
     * CreerSocieteAction) : son propriétaire, au nom de son représentant
     * légal, son adresse de connexion confirmée par ce lien, et son premier
     * courrier recommandé.
     */
    private function inscrire(string $cle, string $siren, TypeDActivite $type, string $adresse): void
    {
        if (! IdentifiantsInsee::sirenValide($siren)) {
            throw new LogicException("Le SIREN semé {$siren} n'est pas valide.");
        }

        $motDePasse = $this->motsDePasse->nouveau();
        $societe = $this->horsDeToutContexte(fn (): Societe => app(CreerSocieteAction::class)->handle($siren, $type, $adresse, $motDePasse));
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $this->motsDePasse->controlerAvec($motDePasse, (string) $proprietaire->identifiantDeConnexion());

        $this->societes[$cle] = $societe;
        $this->codesDesCourriers[$cle] = $this->codes->dernier();
        $this->comptes["propriétaire {$cle}"] = $proprietaire;
        $this->motsDePasseDesProprietaires[$cle] = $motDePasse;
        $this->liste->noter($proprietaire, (string) $societe->raison_sociale, '', 'Propriétaire', $motDePasse);
    }

    /**
     * Le propriétaire inscrit saisit le code de son courrier, avec
     * l'adresse et le mot de passe de son inscription : la société est
     * validée, et son magasin de démonstration se ferme.
     */
    private function saisirLeCode(string $cle): void
    {
        $proprietaire = $this->proprietaire($cle);
        $code = $this->codeDe($cle);

        $this->horsDeToutContexte(fn (): User => app(SaisirCodeDeConfirmationAction::class)->handle(
            (string) $this->societe($cle)->siren,
            $code,
            (string) $proprietaire->email,
            $this->motsDePasseDesProprietaires[$cle],
            '',
            '127.0.0.1',
        ));
        $this->societes[$cle] = $this->societe($cle)->refresh();
    }

    private function codeDe(string $cle): string
    {
        return $this->codesDesCourriers[$cle] ?? throw new LogicException("Aucun courrier n'est parti pour la société {$cle}.");
    }

    /**
     * Le propriétaire complète l'identité légale de sa société (voir
     * ModifierIdentiteSocieteAction) : ce que le répertoire Sirene ne donne
     * pas, le capital et l'immatriculation, que les factures aux
     * professionnels exigent.
     *
     * @param  array<string, string>  $donnees
     */
    private function completerLIdentite(string $cle, array $donnees): void
    {
        $this->societes[$cle] = $this->horsDeToutContexte(fn (): Societe => app(ModifierIdentiteSocieteAction::class)->handle($this->proprietaire($cle), $this->societe($cle), $donnees));
    }

    /**
     * Le propriétaire du cabinet fait vérifier son inscription au tableau
     * de l'Ordre par l'annuaire factice (voir VerifierLOrdreAction).
     */
    private function verifierLeCabinet(): void
    {
        $this->horsDeToutContexte(fn () => app(VerifierLOrdreAction::class)->handle($this->proprietaire('cabinet'), $this->societe('cabinet'), [
            'numero_ordre' => FauxAnnuaireDeLOrdre::NUMERO_DU_CABINET_DE_TEST,
            'conseil_regional' => FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST,
        ]));
    }

    /**
     * Le magasin que le propriétaire crée dans l'espace de sa société, sur
     * l'un des établissements actifs que le répertoire Sirene factice
     * liste pour son SIREN : son siège ou sa boutique (voir
     * CreerEtablissementAction).
     */
    private function ouvrirUnMagasin(string $societe, string $cle, string $nom, string $code, string $etablissement): void
    {
        $siren = (string) $this->societe($societe)->siren;
        $nic = substr($etablissement === 'siege' ? FauxRepertoireSirene::siretDuSiege($siren) : FauxRepertoireSirene::siretDeLaBoutique($siren), 9);

        $this->magasins[$cle] = $this->horsDeToutContexte(fn (): Magasin => app(CreerEtablissementAction::class)->handle($this->proprietaire($societe), $this->societe($societe), [
            'nom' => $nom,
            'code' => $code,
            'nic' => $nic,
        ]));
        $this->nomsDesSocietes[$cle] = $this->nomDeLaSociete($societe);
    }

    private function nomDeLaSociete(string $cle): string
    {
        return (string) $this->societe($cle)->refresh()->raison_sociale;
    }

    /**
     * Un administrateur créé par le propriétaire (voir
     * CreerAdministrateurAction), de tous les magasins ou de ceux donnés,
     * avec le modèle de cases de l'administrateur et, en plus, ces
     * autorisations de vente, qui ne sont jamais précochées ; puis il
     * choisit son mot de passe à sa première connexion.
     *
     * @param  list<string>|null  $magasins
     * @param  list<string>  $vente
     */
    private function administrateur(string $societe, string $nom, ?array $magasins, array $vente): void
    {
        $type = $this->societe($societe)->type_activite;
        $cases = array_values(array_intersect(PermissionRegistry::pourLeType($type), [...PermissionRegistry::modeleDeLAdministrateur($type), ...$vente]));
        $compte = $this->horsDeToutContexte(fn (): User => app(CreerAdministrateurAction::class)->handle(
            $this->proprietaire($societe),
            $this->societe($societe),
            ['name' => $nom],
            $magasins === null ? null : array_map(fn (string $cle): int => (int) $this->magasin($cle)->getKey(), $magasins),
            $cases,
            [],
            GestesDuMagasin::jeton(),
        ));

        $this->choisirSonMotDePasse($compte, $nom, $this->nomDeLaSociete($societe), $magasins === null ? 'Tous les magasins' : implode(', ', array_map(fn (string $cle): string => (string) $this->magasin($cle)->nom, $magasins)), $vente === [] ? 'Administrateur (sans vente)' : 'Administrateur (vend)');
    }

    /**
     * Un membre d'un magasin, créé par le propriétaire pour ce seul magasin
     * (voir CreerMembreAction), avec les cases du modèle de son rôle ; il
     * choisit ensuite son mot de passe, sauf celui qui garde son
     * identifiant, qui n'ouvre alors son compte que depuis un poste
     * reconnu du magasin.
     */
    private function membre(string $magasin, string $nom, RoleMagasin $role, bool $gardeSonIdentifiant = false, ?string $cle = null): void
    {
        $leMagasin = $this->magasin($magasin);
        $proprietaire = $this->compte('propriétaire '.$this->cleDeLaSocieteDe($leMagasin));
        $compte = app(TenantContext::class)->runAs($leMagasin, fn (): User => app(CreerMembreAction::class)->handle($proprietaire, $leMagasin, ['name' => $nom], $role, null, GestesDuMagasin::jeton()));

        if ($gardeSonIdentifiant) {
            $this->comptes[$cle ?? $nom] = $compte->refresh();
            $this->liste->noter($compte, $this->nomsDesSocietes[$magasin], (string) $leMagasin->nom, $role->getLabel(), null);

            return;
        }

        $this->choisirSonMotDePasse($compte, $cle ?? $nom, $this->nomsDesSocietes[$magasin], (string) $leMagasin->nom, $role->getLabel());
    }

    /**
     * Ce responsable reconnaît le poste de caisse de son magasin, sous ce
     * nom, depuis son navigateur (voir PostesReconnus::reconnaitre()) : un
     * compte dont le mot de passe est encore son identifiant peut s'y
     * connecter. Le témoin du poste reste à ce poste du magasin, qu'aucun
     * navigateur de l'éditeur ne reçoit.
     */
    private function reconnaitreUnPoste(string $magasin, string $responsable, string $nom): void
    {
        $leMagasin = $this->magasin($magasin);
        $this->horsDeToutContexte(fn (): PosteReconnu => app(PostesReconnus::class)->reconnaitre($this->compte($responsable), $leMagasin, $nom, Request::create('/app/'.$leMagasin->uuid.'/postes-reconnus', 'POST')));
        Cookie::unqueue(PostesReconnus::TEMOIN);
    }

    /**
     * La personne choisit son mot de passe dans son profil, ou à sa
     * première connexion (voir ChangerSonMotDePasseAction).
     */
    private function choisirSonMotDePasse(User $compte, string $cle, string $societe, string $magasin, string $role): void
    {
        $motDePasse = $this->motsDePasse->pour((string) $compte->identifiantDeConnexion());
        app(ChangerSonMotDePasseAction::class)->handle($compte, $motDePasse);
        $this->comptes[$cle] = $compte->refresh();
        $this->liste->noter($compte, $societe, $magasin, $role, $motDePasse);
    }

    private function cleDeLaSocieteDe(Magasin $magasin): string
    {
        foreach ($this->societes as $cle => $societe) {
            if ($societe->getKey() === $magasin->societe_id) {
                return $cle;
            }
        }

        throw new LogicException("Le magasin {$magasin->nom} n'est d'aucune société semée.");
    }

    /**
     * Le propriétaire de la société ouvre son magasin de démonstration,
     * avant la validation, y crée ces comptes d'entraînement et y vend
     * lui-même, comme un responsable ; chaque compte d'entraînement
     * choisit son mot de passe et vend un ticket.
     *
     * @param  list<array{0: string, 1: RoleMagasin}>  $comptes
     */
    private function sEssayerDansLaDemonstration(string $societe, array $comptes): void
    {
        $proprietaire = $this->proprietaire($societe);
        $demonstration = $this->horsDeToutContexte(fn (): Magasin => app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $this->societe($societe)));
        $this->magasins["démonstration {$societe}"] = $demonstration;
        $this->nomsDesSocietes["démonstration {$societe}"] = $this->nomDeLaSociete($societe);
        $gestes = new GestesDuMagasin($demonstration);
        $gestes->vendreALaCaisse($proprietaire, $gestes->comptoir(), [['produit_id' => $gestes->produit('DEMO-TASSE')->getKey(), 'quantite' => 1]]);

        foreach ($comptes as [$nom, $role]) {
            $this->membre("démonstration {$societe}", $nom, $role);
        }

        foreach ($comptes as $rang => [$nom]) {
            $gestes->vendreALaCaisse($this->compte($nom), $gestes->comptoir(), [['produit_id' => $gestes->produit('DEMO-CARNET')->getKey(), 'quantite' => $rang + 1]]);
        }
    }

    /**
     * Un collaborateur du cabinet, créé par son propriétaire (voir
     * CreerCollaborateurAction), qui choisit son mot de passe en sa
     * présence, sur l'écran du propriétaire (voir RedonnerLAccesAction).
     */
    private function collaborateur(string $nom): void
    {
        $proprietaire = $this->proprietaire('cabinet');
        $compte = $this->horsDeToutContexte(fn (): User => app(CreerCollaborateurAction::class)->handle($proprietaire, $this->societe('cabinet'), ['name' => $nom], GestesDuMagasin::jeton()));
        $motDePasse = $this->motsDePasse->pour((string) $compte->identifiantDeConnexion());
        $this->horsDeToutContexte(fn (): bool => app(RedonnerLAccesAction::class)->handle($proprietaire, $compte, true, $motDePasse, GestesDuMagasin::jeton()));
        $this->comptes[$nom] = $compte->refresh();
        $this->liste->noter($compte, $this->nomDeLaSociete('cabinet'), '', 'Collaborateur du cabinet', $motDePasse);
    }

    /**
     * Le propriétaire de la société désigne le cabinet vérifié (voir
     * DesignerUnCabinetAction), et le propriétaire du cabinet accepte, avec
     * les autorisations par défaut (voir AccepterLaDemandeDeLienAction).
     */
    private function lierLeCabinetA(string $societe): void
    {
        $demande = $this->horsDeToutContexte(fn (): LienCabinet => app(DesignerUnCabinetAction::class)->handle($this->proprietaire($societe), $this->societe($societe), $this->societe('cabinet')));
        $this->horsDeToutContexte(fn (): LienCabinet => app(AccepterLaDemandeDeLienAction::class)->handle($this->proprietaire('cabinet'), $demande));
    }

    /**
     * Le propriétaire du cabinet confie le client lié à ce collaborateur
     * (voir ConfierDesClientsAction).
     */
    private function confier(string $collaborateur, string $societe): void
    {
        $lien = LienCabinet::query()->withoutGlobalScopes()
            ->where('cabinet_id', $this->societe('cabinet')->getKey())
            ->where('societe_id', $this->societe($societe)->getKey())
            ->where('etat', EtatDuLien::Actif->value)
            ->sole();

        $this->horsDeToutContexte(fn () => app(ConfierDesClientsAction::class)->handle($this->proprietaire('cabinet'), $this->compte($collaborateur), [(int) $lien->getKey()]));
    }

    /**
     * Le propriétaire du cabinet invite une entreprise qui n'est pas encore
     * inscrite, par son SIREN et l'adresse de son dirigeant (voir
     * InviterUneEntrepriseAction) ; la personne suit le lien de l'e-mail,
     * choisit son mot de passe et le type d'activité de sa société, et en
     * devient propriétaire : la société naît validée, liée au cabinet (voir
     * AccepterLInvitationAction).
     */
    private function accepterLInvitationDuCabinet(): void
    {
        $this->horsDeToutContexte(fn () => app(InviterUneEntrepriseAction::class)->handle($this->proprietaire('cabinet'), $this->societe('cabinet'), self::SIREN_CLIENT_INVITE, self::ADRESSE_CLIENT_INVITE, $this->motsDePasseDesProprietaires['cabinet']));
        $jeton = Str::afterLast((string) Mail::sent(InvitationDuCabinetMail::class)->last()?->lien, '/');
        $motDePasse = $this->motsDePasse->nouveau();
        $proprietaire = $this->horsDeToutContexte(fn (): User => app(AccepterLInvitationAction::class)->handle($jeton, [
            'password' => $motDePasse,
            'password_confirmation' => $motDePasse,
            'type_activite' => TypeDActivite::Commerce->value,
            'choix_definitif' => true,
        ]));
        $this->motsDePasse->controlerAvec($motDePasse, (string) $proprietaire->identifiantDeConnexion());

        $this->societes['client'] = $proprietaire->societe()->firstOrFail();
        $this->comptes['propriétaire client'] = $proprietaire;
        $this->motsDePasseDesProprietaires['client'] = $motDePasse;
        $this->liste->noter($proprietaire, $this->nomDeLaSociete('client'), '', 'Propriétaire', $motDePasse);
    }

    /**
     * Le propriétaire de la société de services déclare son
     * expert-comptable par le SIREN de son cabinet, absent de PILIQI (voir
     * DeclarerSonExpertComptableAction), avec les autorisations par défaut ;
     * la personne choisit son mot de passe par le lien reçu (voir
     * ChoisirLeMotDePasseDeLExpertComptableAction).
     */
    private function declarerLExpertComptable(): void
    {
        $compte = $this->horsDeToutContexte(fn (): User => app(DeclarerSonExpertComptableAction::class)->handle($this->proprietaire('services'), $this->societe('services'), [
            'siren' => self::SIREN_CABINET_DECLARE,
            'name' => 'Marc Petit',
            'email' => self::ADRESSE_EXPERT_COMPTABLE,
            'email_confirmation' => self::ADRESSE_EXPERT_COMPTABLE,
            'autorisations' => PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Services)),
            'remplacer' => false,
        ], $this->motsDePasseDesProprietaires['services'], GestesDuMagasin::jeton()));
        $jeton = Str::afterLast((string) Mail::sent(LienDuMotDePasseDeLExpertComptableMail::class)->last()?->lien, '/');
        $motDePasse = $this->motsDePasse->nouveau();
        $this->horsDeToutContexte(fn (): User => app(ChoisirLeMotDePasseDeLExpertComptableAction::class)->handle($jeton, $motDePasse, $motDePasse));
        $compte = $compte->refresh();
        $this->motsDePasse->controlerAvec($motDePasse, (string) $compte->identifiantDeConnexion());
        $this->comptes['Marc Petit'] = $compte;
        $this->liste->noter($compte, $this->nomDeLaSociete('services'), 'Tous les magasins', 'Expert-comptable déclaré', $motDePasse);
    }

    /**
     * Exécute $geste sans société ni magasin actif, comme une requête de
     * l'espace de la société, du cabinet ou d'une page publique.
     *
     * @template TResultat
     *
     * @param  Closure(): TResultat  $geste
     * @return TResultat
     */
    private function horsDeToutContexte(Closure $geste): mixed
    {
        app(TenantContext::class)->clear();

        try {
            return $geste();
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}
