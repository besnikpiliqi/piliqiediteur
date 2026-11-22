<?php

namespace Database\Seeders\Semis;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\AccepterLInvitationAction;
use App\Domain\Cabinets\Actions\AjouterUnExpertComptableAction;
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
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Tenancy\Actions\ChangerSonMotDePasseAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\CreerSocieteAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PosteReconnu;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeursLues;
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
 * Le semis complet de PILIQI pour un poste de développement (lot SEED,
 * demande de l'éditeur du 13/11/2026), celui de la campagne de tests
 * 1 bis : trois sociétés validées par le code de leur courrier (une
 * « Commerce ou atelier » à deux magasins, une « Services, sans vente
 * d'articles » à un magasin, qui déclare son expert-comptable par le SIREN
 * d'un cabinet absent de PILIQI, et un cabinet d'expertise comptable
 * vérifié à l'Ordre, à deux collaborateurs), une quatrième société, née de
 * l'invitation du cabinet et validée par elle, et une inscription qui
 * attend le code de son courrier. Le cabinet est lié à la société
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
final class SemisComplet
{
    /**
     * Le nombre de jours de l'histoire semée, qui finit la veille du semis :
     * plus de treize mois, pour que les graphiques aient leurs douze derniers
     * mois et « Comparer avec l'année précédente » de quoi comparer, et
     * qu'une facture à 30 jours émise au début soit en retard à la fin.
     */
    public const JOURS = 400;

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

    public const ADRESSE_SECONDE_EXPERTE_COMPTABLE = 'sophie.petit@petit-associes.exemple.test';

    /**
     * La catégorie juridique (nomenclature de l'INSEE) des sociétés semées,
     * des SAS, comme toute entreprise du registre factice.
     */
    private const CATEGORIE_SAS = '5710';

    /**
     * Le NIC, sans sa clé, de l'établissement de Maison Ferrand à
     * Fort-de-France (Martinique), que le répertoire Sirene factice connaît
     * le temps du semis.
     */
    private const NIC_DE_FORT_DE_FRANCE = '0003';

    /**
     * Les NIC, sans leur clé, des trois magasins de plus de Maison Ferrand,
     * qui n'ont que des achats (voir AchatsDeLEcartDesPrix) : l'Entrepôt, qui
     * porte tous les cas de la carte « Écart des prix d'achat », l'Éphémère,
     * qui n'a aucun achat validé, et le Marché, dont l'unique produit n'est
     * acheté qu'une fois ; le répertoire Sirene factice les connaît le temps
     * du semis.
     */
    private const NIC_DE_L_ENTREPOT = '0004';

    private const NIC_DE_L_EPHEMERE = '0005';

    private const NIC_DU_MARCHE = '0006';

    /**
     * Les sociétés qui s'inscrivent le premier jour de l'histoire, par
     * clé : leur SIREN, leur type d'activité et l'adresse de leur
     * propriétaire.
     *
     * @var array<string, array{0: string, 1: TypeDActivite, 2: string}>
     */
    private const SOCIETES_INSCRITES_LE_PREMIER_JOUR = [
        'commerce' => [self::SIREN_COMMERCE, TypeDActivite::Commerce, self::ADRESSE_COMMERCE],
        'services' => [self::SIREN_SERVICES, TypeDActivite::Services, self::ADRESSE_SERVICES],
        'cabinet' => [FauxRepertoireSirene::SIREN_CABINET, TypeDActivite::Cabinet, self::ADRESSE_CABINET],
    ];

    /**
     * Les sociétés semées dans la base modèle de chaque type du site de
     * démonstration (lot DEMO-COPIE, F.1 et F.2), par les mêmes gestes que
     * sur le poste : la société mise en avant du modèle et, dans celui du
     * cabinet, les clients liés du cabinet, qui ne s'ouvrent que depuis son
     * espace ; jamais une société qu'aucun compte du bandeau ne peut
     * ouvrir. Les sociétés des cas (voir SocietesDesCas), l'inscription qui
     * attend son code et les démonstrations des sociétés (site réel) ne
     * sont semées que sur le poste.
     *
     * @var array<string, list<string>>
     */
    private const SOCIETES_DES_MODELES = [
        'commerce' => ['commerce'],
        'services' => ['services'],
        'cabinet' => ['cabinet', 'commerce', 'client'],
    ];

    /**
     * La société de chaque magasin des sociétés des modèles.
     *
     * @var array<string, string>
     */
    private const SOCIETE_DES_MAGASINS = [
        'halles' => 'commerce',
        'republique' => 'commerce',
        'martinique' => 'commerce',
        'entrepot' => 'commerce',
        'ephemere' => 'commerce',
        'marche' => 'commerce',
        'conseil' => 'services',
        'epicerie' => 'client',
    ];

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

    /**
     * Le mot de passe que chaque autre compte a choisi, par sa clé, qu'il
     * redonne pour les gestes qui le demandent.
     *
     * @var array<string, string>
     */
    private array $motsDePasseDesComptes = [];

    /**
     * @param  TypeDActivite|null  $modele  le type de la base modèle du site de démonstration que ce semis remplit (voir ConstructionDUnModele), ou null pour le poste de développement
     */
    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly MotsDePasseDuSemis $motsDePasse,
        private readonly CodesLusDansLesCourriers $codes,
        private readonly ComptesSemes $liste,
        public readonly ?TypeDActivite $modele,
    ) {}

    /**
     * Sème tout le jeu, dans l'ordre de son histoire.
     */
    public function semer(): void
    {
        $this->nommerLesEntreprises();
        $societesDesCas = new SocietesDesCas($this->horloge, $this, $this->motsDePasse, $this->liste);

        if ($this->surLePoste()) {
            $societesDesCas->nommer();
        }

        $this->horloge->aller(0, '09:00');

        foreach (self::SOCIETES_INSCRITES_LE_PREMIER_JOUR as $cle => [$siren, $type, $adresse]) {
            if ($this->seme($cle)) {
                $this->inscrire($cle, $siren, $type, $adresse);
            }
        }

        if ($this->surLePoste()) {
            $societesDesCas->inscrire();
        }

        $this->horloge->aller(1, '09:00');

        foreach (array_keys(self::SOCIETES_INSCRITES_LE_PREMIER_JOUR) as $cle) {
            if ($this->seme($cle)) {
                $this->saisirLeCode($cle);
            }
        }

        $this->horloge->aller(1, '09:30');

        if ($this->seme('commerce')) {
            $this->completerLIdentite('commerce', ['capital_social' => '20000.00', 'immatriculation' => 'RCS Paris 000 000 109']);
            $this->reglerLaFiscalite('commerce', ['regime_tva' => RegimeTva::ReelNormalMensuel->value]);
        }

        if ($this->seme('services')) {
            $this->completerLIdentite('services', [
                'capital_social' => '5000.00',
                'immatriculation' => 'RCS Paris 000 000 117',
                'assurance_assureur' => 'Mutuelle des Professions Libérales, 12 rue de la Paix, 75002 Paris',
                'assurance_contrat' => 'RCP-2026-4471',
                'assurance_couverture' => 'France métropolitaine',
            ]);
            $this->reglerLaFiscalite('services', ['regime_tva' => $this->modele === TypeDActivite::Services ? RegimeTva::ReelNormalMensuel->value : RegimeTva::ReelSimplifie->value]);
        }

        if ($this->seme('cabinet')) {
            $this->verifierLeCabinet();
        }

        $this->horloge->aller(1, '10:00');

        if ($this->seme('commerce')) {
            $this->ouvrirUnMagasin('commerce', 'halles', 'Maison Ferrand Halles', 'HALLES', 'siege');
            $this->ouvrirUnMagasin('commerce', 'republique', 'Maison Ferrand République', 'REPUB', 'boutique');
            $this->ouvrirUnMagasin('commerce', 'martinique', 'Maison Ferrand Fort-de-France', 'FDF', self::NIC_DE_FORT_DE_FRANCE);
            $this->ouvrirUnMagasin('commerce', 'entrepot', 'Maison Ferrand Entrepôt', 'ENTREPOT', self::NIC_DE_L_ENTREPOT);
            $this->ouvrirUnMagasin('commerce', 'ephemere', 'Maison Ferrand Éphémère', 'EPHEMERE', self::NIC_DE_L_EPHEMERE);
            $this->ouvrirUnMagasin('commerce', 'marche', 'Maison Ferrand Marché', 'MARCHE', self::NIC_DU_MARCHE);
        }

        if ($this->seme('services')) {
            $this->ouvrirUnMagasin('services', 'conseil', 'Conseil Vasseur Paris', 'PARIS', 'siege');
        }

        $this->horloge->aller(1, '11:00');

        if ($this->seme('commerce')) {
            $this->administrateur('commerce', 'Nadia Benali', null, [], [PermissionRegistry::GRAPHIQUES_VOIR, PermissionRegistry::CHARGES_AJOUTER], AutorisationsSociete::modeleDeLAdministrateur());
            $this->administrateur('commerce', 'Thomas Girard', ['halles', 'republique'], PermissionRegistry::deVente());
            $this->membre('halles', 'Camille Laurent', RoleMagasin::Responsable);
            $this->membre('halles', 'Lucas Moreau', RoleMagasin::Vendeur);
            $this->membre('halles', 'Inès Garcia', RoleMagasin::Vendeur);
            $this->reconnaitreUnPoste('halles', 'Camille Laurent', 'Caisse des Halles');
            $this->membre('republique', 'Hugo Lefebvre', RoleMagasin::Responsable);
            $this->membre('republique', 'Lucas Moreau', RoleMagasin::Vendeur, cle: 'Lucas Moreau (République)');
            $this->membre('republique', 'Sarah Petit', RoleMagasin::Vendeur);
            $this->membre('martinique', 'Joëlle Marie-Sainte', RoleMagasin::Responsable);
            $this->membre('martinique', 'Kévin Louisy', RoleMagasin::Vendeur);
        }

        if ($this->seme('services')) {
            $this->administrateur('services', 'Léa Fontaine', null, []);
            $this->administrateur('services', 'Antoine Roux', ['conseil'], PermissionRegistry::deVente());
            $this->membre('conseil', 'Claire Dubois', RoleMagasin::Responsable);
            $this->membre('conseil', 'Maxime Henry', RoleMagasin::Vendeur);
            $this->membre('conseil', 'Julie Mercier', RoleMagasin::Vendeur);
        }

        $this->horloge->aller(1, '14:00');

        if ($this->seme('cabinet')) {
            $this->collaborateur('Paul Lefèvre');
            $this->collaborateur('Emma Rousseau');
            $this->collaborateur('Gabriel Faure', choisitSonMotDePasse: false);
            $this->lierLeCabinetA('commerce');
            $this->confier('Paul Lefèvre', 'commerce');
        } elseif ($this->seme('commerce')) {
            $this->declarerLExpertComptableDeMaisonFerrand();
        }

        if ($this->surLePoste()) {
            $this->horloge->aller(1, '15:00');
            $societesDesCas->valider();
            $this->horloge->aller(1, '16:00');
            $societesDesCas->remplir();
        }

        if ($this->seme('client')) {
            $this->horloge->aller(2, '09:00');
            $this->accepterLInvitationDuCabinet();
            $this->confier('Emma Rousseau', 'client');
            $this->reglerLaFiscalite('client', ['regime_tva' => RegimeTva::ReelNormalTrimestriel->value]);
            $this->ouvrirUnMagasin('client', 'epicerie', 'Épicerie Lemoine', 'EPICERIE', 'siege');
            $this->administrateur('client', 'Romane Gauthier', null, []);
            $this->administrateur('client', 'Bastien Leroy', ['epicerie'], PermissionRegistry::deVente());
            $this->membre('epicerie', 'Nicolas Lemoine', RoleMagasin::Responsable);
            $this->membre('epicerie', 'Chloé Bernard', RoleMagasin::Vendeur);
            $this->membre('epicerie', 'Mehdi Brahimi', RoleMagasin::Vendeur, gardeSonMotDePasseProvisoire: true);
        }

        $this->horloge->aller(2, '10:00');

        if ($this->seme('services')) {
            $this->declarerLExpertComptable();
        }

        $comptesDesCas = new ComptesDesCas($this->horloge, $this, $this->liste);
        $comptesDesCas->creer();
        $this->horloge->aller(2, '10:30');

        if ($this->surLePoste()) {
            $societesDesCas->lierAuCabinet();
        }

        if ($this->seme('cabinet')) {
            $societesDesCas->inviterCeQuiExpirera();
        }

        (new ActiviteDesMagasins($this->horloge, $this, $comptesDesCas, $societesDesCas))->semer();

        $this->horloge->aller(self::JOURS - 1, '09:00');

        if ($this->surLePoste()) {
            $this->inscrire('inscription', self::SIREN_INSCRIPTION, TypeDActivite::Commerce, self::ADRESSE_INSCRIPTION);
            (new DemonstrationsDesSocietes($this->horloge, $this))->creerAvantLaValidation();
        } elseif ($this->seme('cabinet')) {
            $societesDesCas->inviterDesEntreprisesQuiNeSInscriventPas();
        }

        $this->horloge->aller(self::JOURS - 1, '11:00');
        (new DerniersGestes($this, $this->motsDePasse))->semer();

        $this->liste->oublierLesComptesRetires($comptesDesCas->retiresGardes());

        if ($this->surLePoste()) {
            $this->direCeQuIlFautRegarder();
        } else {
            (new ComptesDuBandeauDuModele($this))->ecrire();
        }
    }

    /**
     * Vrai pour le semis du poste de développement, qui sème toutes les
     * sociétés ; faux pour celui d'une base modèle du site de
     * démonstration.
     */
    public function surLePoste(): bool
    {
        return $this->modele === null;
    }

    /**
     * Vrai quand ce semis sème la société de cette clé : toutes sur le
     * poste, celles du modèle dans une base modèle (voir
     * SOCIETES_DES_MODELES).
     */
    public function seme(string $societe): bool
    {
        return $this->modele === null || in_array($societe, self::SOCIETES_DES_MODELES[$this->modele->value], true);
    }

    /**
     * Vrai quand ce semis sème le magasin de cette clé, l'un de ceux des
     * sociétés des modèles (voir SOCIETE_DES_MAGASINS).
     *
     * @throws LogicException pour la clé d'un autre magasin
     */
    public function semeLeMagasin(string $magasin): bool
    {
        return $this->seme(self::SOCIETE_DES_MAGASINS[$magasin] ?? throw new LogicException("Le magasin {$magasin} n'est d'aucune société des modèles."));
    }

    /**
     * À l'instant du semis, après l'histoire : le vendeur des Halles a une
     * vente en cours à la caisse, que la clôture de la journée
     * abandonnera si elle n'est pas encaissée avant (voir PanierDeCaisse).
     */
    public function laisserUneVenteEnCours(): void
    {
        if (! $this->seme('commerce')) {
            return;
        }

        $halles = new GestesDuMagasin($this->magasin('halles'));
        $halles->dans(fn (): ?PanierCaisse => app(PanierDeCaisse::class)->enregistrer($this->compte('Lucas Moreau'), GestesDuMagasin::jeton(), $halles->comptoir()->id, [
            'ligne-1' => [...$halles->ligne('BOUGIE-FIGUE', 2), 'prix_unitaire_ttc' => '18.00'],
            'ligne-2' => [...$halles->ligne('CARNET-A5', 1), 'prix_unitaire_ttc' => '4.90'],
        ]));
    }

    /**
     * Pour chaque compte de la liste, ce qu'il faut regarder avec lui
     * (demande de l'éditeur du 15/11/2026, cahier SEED-TOUT § 4).
     */
    private function direCeQuIlFautRegarder(): void
    {
        foreach ([
            'propriétaire commerce' => 'Espace de la société : tableau de bord et graphiques de ses six magasins, dont la carte « Écart des prix d\'achat » à l\'affichage des 12 mois (choix « Tous mes magasins », l\'Entrepôt qui porte tous les cas mois par mois, l\'Éphémère sans aucun achat validé, le Marché dont l\'unique produit n\'est acheté qu\'une fois ; le menu « Période » dit « Année précédente » pour les achats d\'avant), « Les caisses », charges, équipe, cabinet lié, téléchargements (prêts, et un en préparation) ; ne vend pas ; saisit et valide les achats des trois magasins de plus',
            'propriétaire services' => 'Espace de la société : expert-comptable déclaré (deux comptes), charges validées par lui, magasin de services',
            'propriétaire cabinet' => 'Espace du cabinet : clients liés, invitations et demandes dans chaque état, collaborateurs et clients confiés ; la page « Graphiques » de Maison Ferrand montre « Écart des prix d\'achat » de l\'année précédente sans geste',
            'Nadia Benali' => 'Administratrice sans vente : tableau de bord de la société avec graphiques (dont « Écart des prix d\'achat », à ajouter par « Ajouter un graphique »), charges qu\'elle ajoute, magasins de sa portée',
            'Thomas Girard' => 'Administrateur qui vend dans deux magasins : sa caisse aux Halles puis à la République, ses relevés ; sans la case des graphiques, il ne voit pas « Écart des prix d\'achat »',
            'Camille Laurent' => 'Responsable des Halles : « À faire » (exports sans preuve, détaxe, devis), graphiques dont « Écart des prix d\'achat » par mois (set de table acheté de cinq façons, boule de Noël d\'un mois à venir, plateau renommé), charges du magasin, vente sans stock, facture rejetée par la plateforme agréée et facture non transmise (incident technique), envoi en échec et envoi en attente',
            'Lucas Moreau' => 'Vendeur des Halles : ses tickets, sa caisse fermée par sa responsable, ses factures d\'export et de détaxe',
            'Inès Garcia' => 'Vendeuse des Halles : sa responsable lui a redonné l\'accès la veille, en son absence ; son mot de passe provisoire vaut encore (72 heures), une seule fois, depuis le poste reconnu « Caisse des Halles » : votre navigateur n\'est pas ce poste',
            'Hugo Lefebvre' => 'Responsable de la République : inventaire d\'ouverture, réassorts, avoir du Studio Nord ; saisit la lampe qui perd 210 €, le cadre photo archivé et la théière ; sans la case des graphiques, il ne voit pas « Écart des prix d\'achat »',
            'Lucas Moreau (République)' => 'La même personne que le vendeur des Halles, avec un autre compte, promue responsable de la République',
            'Sarah Petit' => 'Vendeuse de la République qui voit les factures de toute l\'équipe',
            'Léa Fontaine' => 'Administratrice sans vente de la société de services',
            'Antoine Roux' => 'Administrateur qui vend au magasin de services',
            'Claire Dubois' => 'Responsable du magasin de services : prestations à l\'étranger (autoliquidation, hors UE), mairie, prélèvement',
            'Maxime Henry' => 'Vendeur de services : ses tickets à la caisse',
            'Julie Mercier' => 'Vendeuse de services : ses tickets à la caisse',
            'Gabriel Faure' => 'Collaborateur du cabinet créé, qui n\'a pas encore choisi son mot de passe : aucun mot de passe provisoire n\'ouvre un compte de cabinet, le propriétaire du cabinet le lui fait choisir en sa présence (« Redonner l\'accès ») ; aucun client ne lui est confié',
            'Paul Lefèvre' => 'Collaborateur du cabinet : Maison Ferrand confiée (ses six magasins), charges à valider et validées, graphiques du client dont « Écart des prix d\'achat »',
            'Emma Rousseau' => 'Collaboratrice du cabinet : Épicerie Lemoine confiée (seul magasin ouvert au cabinet, sans les consultations d\'achats : elle ne voit pas « Écart des prix d\'achat » de ses réassorts)',
            'propriétaire client' => 'Société née de l\'invitation du cabinet : cabinet lié, magasins ouverts au cabinet',
            'Romane Gauthier' => 'Administratrice devenue responsable de l\'Épicerie',
            'Bastien Leroy' => 'Administrateur qui vend à l\'Épicerie',
            'Nicolas Lemoine' => 'Responsable de l\'Épicerie : équipe (vendeuse suspendue puis réactivée, accès redonné)',
            'Chloé Bernard' => 'Vendeuse de l\'Épicerie : ses tickets et ses relevés',
            'Mehdi Brahimi' => 'Vendeur de l\'Épicerie qui n\'a jamais utilisé son mot de passe provisoire : il a expiré (72 heures après la création de son compte, il y a plus d\'un an), ses tickets et ses relevés restent ; « Redonner l\'accès » en tire un nouveau',
            'Marc Petit' => 'Expert-comptable déclaré : « Tableau de bord de la société » avec tous ses graphiques affichés (« Tous les graphiques sont affichés »), charges à valider, magasins en lecture ; société de services, sans achats : pas de « Écart des prix d\'achat »',
            'propriétaire inscription' => 'Inscription qui attend le code du courrier : aucune vente avant sa validation',
            'Élodie Fabre' => 'Observatrice des Halles : tout en lecture, aucune vente ; reçoit les alertes de stock (la propriétaire lui a coché « ajuster le stock »)',
            'Victor Blanchard' => 'Observateur du magasin de services',
            'Margaux Perrin' => 'Observatrice de l\'Épicerie : son responsable lui a redonné l\'accès l\'avant-veille, en son absence ; son mot de passe provisoire vaut encore (72 heures), mais l\'Épicerie n\'a aucun poste reconnu : la connexion est refusée tant que ce responsable ou le propriétaire n\'a pas reconnu un navigateur',
            'Yanis Morel' => 'Compte suspendu : la connexion est refusée ; ses anciens tickets restent',
            'Bruno Caron' => 'Compte retiré de la société : la connexion est refusée',
            'Anaïs Lambert' => 'Vendeuse retirée puis réintégrée : ses tickets d\'avant et d\'après, le ZIP de ses ventes demandé juste avant son retrait, en échec',
            'Léna Roussel' => 'Accès redonné en son absence : son mot de passe provisoire a servi à une première connexion qui n\'a pas fini le choix de son mot de passe ; « Redonner l\'accès » en tire un nouveau',
            'propriétaire comptoir' => 'Cinq magasins : Martinique (8,5 %), Lyon fermé temporairement, Bordeaux rouvert, Nantes cédé, Lille suspendu ; transmission en attente',
            'Gaëlle Hoarau' => 'Administratrice à qui la propriété est proposée (lien pas encore suivi)',
            'Joëlle Marie-Sainte' => 'Responsable de Maison Ferrand Fort-de-France : heure des Antilles, TVA à 8,5 % et 2,1 %, réassorts, loyer à 8,5 % dans les charges, un savon acheté deux fois dont l\'un reçu sans date le dernier soir d\'un mois (rangé au jour de sa validation en Martinique) ; sans la case des graphiques, elle ne voit pas « Écart des prix d\'achat »',
            'Kévin Louisy' => 'Vendeur de Maison Ferrand Fort-de-France : ses tickets à l\'heure de la Martinique',
            'Joël Sainte-Rose' => 'Responsable en Martinique : heure des Antilles, TVA à 8,5 % et 2,1 %',
            'Maëva Jean-Louis' => 'Vendeuse en Martinique : ses tickets à l\'heure locale',
            'Bastien Royer' => 'Responsable de Lyon : inventaire d\'ouverture en brouillon, magasin fermé temporairement',
            'Inès Lafitte' => 'Responsable de Bordeaux : magasin fermé pour cessation puis rouvert',
            'Ronan Guillou' => 'Responsable de Nantes : magasin cédé, en lecture seule',
            'Chloé Vandamme' => 'Responsable de Lille : magasin suspendu par l\'éditeur, en lecture seule',
            'Mathilde Brun (atelier)' => 'Franchise en base : ventes à 0 %, « TVA non applicable, art. 293 B du CGI »',
            'Rémi Lacroix' => 'Option pour les débits : factures de services encaissées ; après la correction du SIREN, une facture rectificative émise et les autres factures à régulariser',
            'Julien Delattre' => 'Membre d\'un assujetti unique : ventes refusées',
            'Sophie Duval' => 'Société suspendue : lecture seule',
            'Hervé Noël' => 'Société résiliée : lecture seule',
            'Étienne Vidal' => 'Devenu propriétaire de la Librairie par transmission',
            'propriétaire librairie' => 'Ancienne propriétaire devenue administratrice à la transmission',
            'propriétaire sans code' => 'Inscription close faute de code : se connecte seulement avec son identifiant',
            'propriétaire type erroné' => 'Inscription close par l\'éditeur (type d\'activité choisi par erreur)',
            'propriétaire cabinet non vérifié' => 'Cabinet inscrit, pas encore vérifié à l\'Ordre : aucun client',
            'propriétaire cabinet suspendu' => 'Cabinet vérifié puis suspendu',
            'propriétaire cabinet radié' => 'Cabinet radié du tableau de l\'Ordre (constat de l\'éditeur) : plus vérifié, son lien avec la Boulangerie Duval a pris fin',
            'propriétaire atelier' => 'Franchise en base ; sa demande de lien au cabinet attend la réponse',
            'propriétaire studio' => 'Option pour les débits ; sa demande de lien a expiré ; SIREN corrigé par l\'éditeur : une facture régularisée par son avoir de régularisation, les autres à régulariser ; exercice qui ne finit plus en décembre : le menu « Période » des graphiques propose l\'exercice en cours et le précédent',
            'propriétaire groupe' => 'Assujetti unique ; son lien avec le cabinet a pris fin',
            'propriétaire boulangerie' => 'Société suspendue ; lien terminé par le cabinet Martin, puis par la radiation du cabinet Faure',
            'propriétaire refusée' => 'Inscription close par l\'éditeur (refus) : se connecte seulement avec son identifiant',
            'propriétaire révoquée' => 'Société révoquée par l\'éditeur (SIREN pris à tort) : lecture seule, son magasin fermé',
            'propriétaire caduque' => 'Inscrite d\'elle-même pendant que le cabinet Martin l\'invitait (son invitation est devenue caduque) : attend le code de son courrier',
            'propriétaire pièces' => 'Inscription dont les courriers ne sont pas parvenus : nouveau courrier demandé, pièces déposées, en attente de l\'éditeur',
        ] as $cle => $aRegarder) {
            $this->liste->regarder($this->compte($cle), $aRegarder);
        }
    }

    /**
     * Le compte semé sous ce nom (ou sous la clé donnée à sa création).
     */
    public function compte(string $nom): User
    {
        return $this->comptes[$nom] ?? throw new LogicException("Aucun compte semé ne s'appelle {$nom}.");
    }

    /**
     * Relit ce magasin en base, après un changement de son statut (fermeture,
     * réouverture, suspension), pour que les gestes suivants le voient.
     */
    public function relireLeMagasin(string $cle): Magasin
    {
        return $this->magasins[$cle] = $this->magasin($cle)->refresh();
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
     * Le mot de passe que le propriétaire de cette société redonne pour les
     * gestes qui le demandent.
     */
    public function motDePasseDuProprietaire(string $societe): string
    {
        return $this->motsDePasseDesProprietaires[$societe] ?? throw new LogicException("La société {$societe} n'a pas de propriétaire semé.");
    }

    /**
     * Le mot de passe que ce compte a choisi.
     */
    public function motDePasseDe(string $compte): string
    {
        return $this->motsDePasseDesComptes[$compte] ?? throw new LogicException("Le compte {$compte} n'a pas choisi de mot de passe pendant le semis.");
    }

    /**
     * Ce que le répertoire Sirene et le registre national des entreprises
     * factices disent des entreprises semées, le temps du semis : leur
     * nom, leur dirigeant, leur siège et leur identité, et l'activité
     * comptable du cabinet déclaré.
     */
    private function nommerLesEntreprises(): void
    {
        $sirene = ValeursLues::instance(app(RepertoireSirene::class), FauxRepertoireSirene::class);
        $rne = ValeursLues::instance(app(RegistreNationalDesEntreprises::class), FauxRne::class);

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

        $fortDeFrance = IdentifiantsInsee::avecSaCle(self::SIREN_COMMERCE.self::NIC_DE_FORT_DE_FRANCE);
        $sirene->ajouterUnEtablissement($fortDeFrance, null);
        $sirene->deplacer($fortDeFrance, '97200', 'FORT-DE-FRANCE', '97209');

        foreach ([self::NIC_DE_L_ENTREPOT => 'ENTREPOT DE LOGISTIQUE', self::NIC_DE_L_EPHEMERE => 'BOUTIQUE EPHEMERE', self::NIC_DU_MARCHE => 'STAND DU MARCHE'] as $nic => $enseigne) {
            $sirene->ajouterUnEtablissement(IdentifiantsInsee::avecSaCle(self::SIREN_COMMERCE.$nic), $enseigne);
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
    public function inscrire(string $cle, string $siren, TypeDActivite $type, string $adresse): void
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
     * validée.
     */
    public function saisirLeCode(string $cle): void
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

    /**
     * Le code du dernier courrier recommandé parti, tel que son
     * destinataire le lit sur le pli.
     */
    public function codeDuDernierCourrier(): string
    {
        return $this->codes->dernier();
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
    public function completerLIdentite(string $cle, array $donnees): void
    {
        $this->societes[$cle] = $this->horsDeToutContexte(fn (): Societe => app(ModifierIdentiteSocieteAction::class)->handle($this->proprietaire($cle), $this->societe($cle), $donnees));
    }

    /**
     * Le propriétaire règle les paramètres fiscaux communs de sa société
     * (voir ModifierParametresFiscauxSocieteAction) : chaque société qui a
     * un magasin choisit son régime de TVA, les sociétés semées se
     * répartissant entre le réel normal mensuel, le réel normal
     * trimestriel, le réel simplifié et la franchise en base (constat de
     * l'éditeur du 15/11/2026 : aucune société qui vend sans régime).
     *
     * @param  array<string, mixed>  $reglages
     */
    public function reglerLaFiscalite(string $cle, array $reglages): void
    {
        $this->societes[$cle] = $this->horsDeToutContexte(fn (): Societe => app(TenantContext::class)->runAsSociete($this->societe($cle), fn (): Societe => app(ModifierParametresFiscauxSocieteAction::class)->handle($this->proprietaire($cle), $this->societe($cle)->refresh(), $reglages)));
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
     * liste pour son SIREN : son siège, sa boutique, ou l'établissement de
     * ce NIC sans sa clé (« 0003 »), que le répertoire connaît (voir
     * CreerEtablissementAction).
     */
    public function ouvrirUnMagasin(string $societe, string $cle, string $nom, string $code, string $etablissement): Magasin
    {
        $siren = (string) $this->societe($societe)->siren;
        $nic = substr(match ($etablissement) {
            'siege' => FauxRepertoireSirene::siretDuSiege($siren),
            'boutique' => FauxRepertoireSirene::siretDeLaBoutique($siren),
            default => IdentifiantsInsee::avecSaCle($siren.$etablissement),
        }, 9);

        $this->magasins[$cle] = $this->horsDeToutContexte(fn (): Magasin => app(CreerEtablissementAction::class)->handle($this->proprietaire($societe), $this->societe($societe), [
            'nom' => $nom,
            'code' => $code,
            'nic' => $nic,
        ]));
        $this->nomsDesSocietes[$cle] = $this->nomDeLaSociete($societe);

        return $this->magasins[$cle];
    }

    public function nomDeLaSociete(string $cle): string
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
     * @param  list<string>  $autresCases  les cases réservées au propriétaire, qu'il coche ici (graphiques, ajout des charges)
     * @param  list<string>  $casesDeSociete  les autorisations de société (voir AutorisationsSociete)
     */
    public function administrateur(string $societe, string $nom, ?array $magasins, array $vente, array $autresCases = [], array $casesDeSociete = []): void
    {
        $type = $this->societe($societe)->type_activite;
        $cases = array_values(array_intersect(PermissionRegistry::pourLeType($type), [...PermissionRegistry::modeleDeLAdministrateur($type), ...$vente, ...$autresCases]));
        $compte = $this->horsDeToutContexte(fn (): User => app(CreerAdministrateurAction::class)->handle(
            $this->proprietaire($societe),
            $this->societe($societe),
            ['name' => $nom],
            $magasins === null ? null : array_map(fn (string $cle): int => $this->magasin($cle)->id, $magasins),
            $cases,
            $casesDeSociete,
            GestesDuMagasin::jeton(),
        ));

        $this->choisirSonMotDePasse($compte, $nom, $this->nomDeLaSociete($societe), $magasins === null ? 'Tous les magasins' : implode(', ', array_map(fn (string $cle): string => (string) $this->magasin($cle)->nom, $magasins)), $vente === [] ? 'Administrateur (sans vente)' : 'Administrateur (vend)');
    }

    /**
     * Un membre d'un magasin, créé par le propriétaire pour ce seul magasin
     * (voir CreerMembreAction), avec les cases du modèle de son rôle ; il
     * choisit ensuite son mot de passe, sauf celui qui garde son mot de passe
     * provisoire, tiré au hasard à la création du compte, que la liste des
     * comptes donne : il ne sert qu'une fois, depuis un poste reconnu du
     * magasin, tant que son délai n'est pas passé.
     */
    public function membre(string $magasin, string $nom, RoleMagasin $role, bool $gardeSonMotDePasseProvisoire = false, ?string $cle = null): User
    {
        $leMagasin = $this->magasin($magasin);
        $proprietaire = $this->compte('propriétaire '.$this->cleDeLaSocieteDe($leMagasin));
        $compte = app(TenantContext::class)->runAs($leMagasin, fn (): User => app(CreerMembreAction::class)->handle($proprietaire, $leMagasin, ['name' => $nom], $role, null, GestesDuMagasin::jeton()));
        $libelle = $role->getLabel();

        if ($gardeSonMotDePasseProvisoire) {
            $this->liste->noter($compte, $this->nomsDesSocietes[$magasin], (string) $leMagasin->nom, $libelle, $compte->motDePasseProvisoire?->valeur());

            return $this->comptes[$cle ?? $nom] = $compte->refresh();
        }

        return $this->choisirSonMotDePasse($compte, $cle ?? $nom, $this->nomsDesSocietes[$magasin], (string) $leMagasin->nom, $libelle);
    }

    /**
     * Ce responsable reconnaît le poste de caisse de son magasin, sous ce
     * nom, depuis son navigateur (voir PostesReconnus::reconnaitre()) : un
     * compte qui n'a pas encore choisi son mot de passe peut s'y connecter
     * avec son mot de passe provisoire. Le témoin du poste reste à ce poste du magasin, qu'aucun
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
    private function choisirSonMotDePasse(User $compte, string $cle, string $societe, string $magasin, string $role): User
    {
        $motDePasse = $this->motsDePasse->pour((string) $compte->identifiantDeConnexion());
        app(ChangerSonMotDePasseAction::class)->handle($compte, $motDePasse);
        $this->liste->noter($compte, $societe, $magasin, $role, $motDePasse);
        $this->motsDePasseDesComptes[$cle] = $motDePasse;

        return $this->comptes[$cle] = $compte->refresh();
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
     * Un collaborateur du cabinet, créé par son propriétaire (voir
     * CreerCollaborateurAction), qui choisit son mot de passe en sa
     * présence, sur l'écran du propriétaire (voir RedonnerLAccesAction) ;
     * celui qui ne l'a pas encore choisi n'a aucun mot de passe utilisable,
     * et la liste des comptes le dit.
     */
    private function collaborateur(string $nom, bool $choisitSonMotDePasse = true): void
    {
        $proprietaire = $this->proprietaire('cabinet');
        $compte = $this->horsDeToutContexte(fn (): User => app(CreerCollaborateurAction::class)->handle($proprietaire, $this->societe('cabinet'), ['name' => $nom], GestesDuMagasin::jeton()));

        if (! $choisitSonMotDePasse) {
            $this->comptes[$nom] = $compte->refresh();
            $this->liste->noter($compte, $this->nomDeLaSociete('cabinet'), '', 'Collaborateur du cabinet', null);

            return;
        }

        $motDePasse = $this->motsDePasse->pour((string) $compte->identifiantDeConnexion());
        $this->horsDeToutContexte(fn (): bool => app(RedonnerLAccesAction::class)->enSaPresence($proprietaire, $compte, $motDePasse, GestesDuMagasin::jeton()));
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

        $this->horsDeToutContexte(fn () => app(ConfierDesClientsAction::class)->handle($this->proprietaire('cabinet'), $this->compte($collaborateur), [$lien->id]));
        $this->liste->confier($this->compte($collaborateur), $this->nomDeLaSociete($societe));
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
        $jeton = Str::afterLast(ValeursLues::instance(Mail::sent(InvitationDuCabinetMail::class)->last(), InvitationDuCabinetMail::class)->lien, '/');
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
     * declarerUnExpertComptable()) : Marc Petit, qui choisit son mot de
     * passe, et Sophie Petit, pas encore.
     */
    private function declarerLExpertComptable(): void
    {
        $this->declarerUnExpertComptable('services', self::SIREN_CABINET_DECLARE, ['Marc Petit', self::ADRESSE_EXPERT_COMPTABLE], ['Sophie Petit', self::ADRESSE_SECONDE_EXPERTE_COMPTABLE]);
    }

    /**
     * Dans la base modèle « Commerce ou atelier » du site de démonstration,
     * où le cabinet Martin n'existe pas (F.1), la propriétaire de Maison
     * Ferrand déclare son expert-comptable par le SIREN fictif de ce
     * cabinet, absent de cette base, avec les mêmes personnes : Paul
     * Lefèvre, qui choisit son mot de passe et valide les charges comme le
     * collaborateur du cabinet le fait sur le poste, et Lina Martin, qui ne
     * l'a pas encore choisi. C'est le seul geste du poste remplacé dans ce
     * modèle : le lien de Maison Ferrand avec son cabinet.
     */
    private function declarerLExpertComptableDeMaisonFerrand(): void
    {
        $this->declarerUnExpertComptable('commerce', FauxRepertoireSirene::SIREN_CABINET, ['Paul Lefèvre', 'paul.lefevre@martin-expertise.exemple.test'], ['Lina Martin', 'lina.martin@martin-expertise.exemple.test']);
    }

    /**
     * Le propriétaire de cette société déclare son expert-comptable par le
     * SIREN d'un cabinet absent de PILIQI (voir
     * DeclarerSonExpertComptableAction), avec les autorisations par défaut,
     * pour une première personne, qui choisit son mot de passe par le lien
     * reçu (voir ChoisirLeMotDePasseDeLExpertComptableAction), puis ajoute
     * une seconde personne, qui n'a pas encore suivi le sien.
     *
     * @param  array{0: string, 1: string}  $premiere  le nom et l'adresse professionnelle de la première personne
     * @param  array{0: string, 1: string}  $seconde  ceux de la seconde
     */
    private function declarerUnExpertComptable(string $societe, string $siren, array $premiere, array $seconde): void
    {
        [$nom, $adresse] = $premiere;
        $compte = $this->horsDeToutContexte(fn (): User => app(DeclarerSonExpertComptableAction::class)->handle($this->proprietaire($societe), $this->societe($societe), [
            'siren' => $siren,
            'name' => $nom,
            'email' => $adresse,
            'email_confirmation' => $adresse,
            'autorisations' => PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet($this->societe($societe)->type_activite)),
            'remplacer' => false,
        ], $this->motsDePasseDesProprietaires[$societe], GestesDuMagasin::jeton()));
        $jeton = Str::afterLast(ValeursLues::instance(Mail::sent(LienDuMotDePasseDeLExpertComptableMail::class)->last(), LienDuMotDePasseDeLExpertComptableMail::class)->lien, '/');
        $motDePasse = $this->motsDePasse->nouveau();
        $this->horsDeToutContexte(fn (): User => app(ChoisirLeMotDePasseDeLExpertComptableAction::class)->handle($jeton, $motDePasse, $motDePasse));
        $compte = $compte->refresh();
        $this->motsDePasse->controlerAvec($motDePasse, (string) $compte->identifiantDeConnexion());
        $this->comptes[$nom] = $compte;
        $this->liste->noter($compte, $this->nomDeLaSociete($societe), 'Tous les magasins', 'Expert-comptable déclaré', $motDePasse);

        $declaration = LienCabinet::query()->withoutGlobalScopes()
            ->where('societe_id', $this->societe($societe)->getKey())
            ->where('etat', EtatDuLien::Declare->value)
            ->sole();
        [$nom, $adresse] = $seconde;
        $compte = $this->horsDeToutContexte(fn (): User => app(AjouterUnExpertComptableAction::class)->handle($this->proprietaire($societe), $declaration, [
            'name' => $nom,
            'email' => $adresse,
            'email_confirmation' => $adresse,
        ], $this->motsDePasseDesProprietaires[$societe], GestesDuMagasin::jeton()));
        $this->comptes[$nom] = $compte;
        $this->liste->noter($compte, $this->nomDeLaSociete($societe), 'Tous les magasins', 'Expert-comptable déclarée', null, 'Lien du mot de passe envoyé, pas encore suivi : elle ne se connecte pas encore', 'pas encore choisi (lien de 7 jours envoyé)');
    }

    /**
     * Le propriétaire de la société de services redonne l'accès à son
     * expert-comptable, absent, par un nouveau lien de sept jours : son
     * mot de passe d'avant ne sert plus, et il en choisit un autre par ce
     * lien.
     */
    public function redonnerLAccesALExpertComptable(): void
    {
        $marc = $this->compte('Marc Petit');
        $this->horsDeToutContexte(fn (): bool => app(RedonnerLAccesAction::class)->parUnNouveauLien($this->proprietaire('services'), $marc, GestesDuMagasin::jeton()));
        $mail = Mail::sent(LienDuMotDePasseDeLExpertComptableMail::class, fn (LienDuMotDePasseDeLExpertComptableMail $mail): bool => $mail->hasTo(self::ADRESSE_EXPERT_COMPTABLE))->last();
        $jeton = Str::afterLast(ValeursLues::instance($mail, LienDuMotDePasseDeLExpertComptableMail::class)->lien, '/');
        $motDePasse = $this->motsDePasse->pour((string) $marc->identifiantDeConnexion());
        $this->horsDeToutContexte(fn (): User => app(ChoisirLeMotDePasseDeLExpertComptableAction::class)->handle($jeton, $motDePasse, $motDePasse));
        $this->comptes['Marc Petit'] = $marc->refresh();
        $this->liste->changerLeMotDePasse($marc, $motDePasse);
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
    public function horsDeToutContexte(Closure $geste): mixed
    {
        app(TenantContext::class)->clear();

        try {
            return $geste();
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}
