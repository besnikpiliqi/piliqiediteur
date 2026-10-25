<?php

namespace Tests\Feature\Authorization;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Actions\ExporterJournalDesEvenementsAction;
use App\Domain\CaisseSecurisee\Actions\VerifierIntegriteAction;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Actions\TrouverOuCreerServiceAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Actions\VerifierNumeroTvaAction;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\AnnulerUneNominationAction;
use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\DemanderLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\DeposerUnePieceAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Database\Factories\MagasinFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * Chaque action métier contrôle elle-même son auteur, appelée sans aucun
 * écran (une commande, un job, une requête forgée) : un compte sans le
 * droit, d'une autre société, suspendu, retiré, ou l'expert-comptable
 * pour toute écriture, est refusé pour ce motif, et rien n'est écrit en
 * base ni envoyé.
 */
class ActionsHorsEcranTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les messages des refus pour défaut de droit, et d'eux seuls : un
     * geste refusé pour une autre raison (une donnée mal préparée) ne
     * passe pas pour un refus de droit.
     */
    private const REFUS_DES_DROITS = [
        LecteurDesDroits::REFUS,
        LecteurDesDroits::REFUS_DANS_LA_SOCIETE,
        PorteeDesDroits::REFUS,
        PorteeDesDroits::REFUS_AU_DELA,
        CreerAdministrateurAction::MESSAGE_PROPRIETAIRE_SEUL,
        CreerExpertComptableAction::MESSAGE_PROPRIETAIRE_SEUL,
        CreerEtablissementAction::MESSAGE_SANS_AUTORISATION,
        ModifierEtablissementAction::MESSAGE_SANS_AUTORISATION,
        ModifierIdentiteSocieteAction::MESSAGE_SANS_AUTORISATION,
        OuvrirLaDemonstrationAction::MESSAGE_SANS_AUTORISATION,
        EnvoyerCodeDeConfirmationAction::MESSAGE_SANS_AUTORISATION,
        DeposerUnePieceAction::MESSAGE_SANS_AUTORISATION,
        DemanderLaTransmissionDeLaProprieteAction::MESSAGE_PROPRIETAIRE_SEUL,
    ];

    /**
     * Les gestes de consultation : l'expert-comptable les fait.
     */
    private const CONSULTATIONS = ['exporter le journal des événements', 'vérifier l\'intégrité'];

    /**
     * Une donnée par action : son nom (voir geste()), qui en a le droit
     * d'ordinaire (« magasin » : un responsable ; « societe » : un
     * administrateur de tous les magasins, ou le propriétaire seul), et si
     * elle se fait dans une société encore en cours de vérification.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function actions(): array
    {
        $magasin = [
            'créer une facture', 'modifier une facture', 'émettre une facture', 'vendre à la caisse',
            'créer un devis', 'modifier un devis', 'envoyer un devis', 'accepter un devis', 'refuser un devis', 'facturer un devis',
            'créer un avoir', 'enregistrer un avoir', 'émettre un avoir', 'enregistrer un paiement', 'contre-passer un paiement',
            'clôturer la journée', 'clôturer les journées en attente', 'fermer la caisse d\'un vendeur',
            'envoyer un document', 'relancer une facture', 'ajouter une preuve', 'noter un bordereau de détaxe',
            'saisir une facture d\'achat', 'modifier une facture d\'achat', 'valider une facture d\'achat', 'créer un avoir fournisseur', 'émettre un avoir fournisseur',
            'créer un service à la caisse', 'vérifier un numéro de TVA',
            'exporter le journal des événements', 'vérifier l\'intégrité',
            'créer un vendeur', 'modifier un vendeur', 'suspendre un vendeur', 'réactiver un vendeur', 'retirer un vendeur', 'réintégrer un vendeur',
            'redonner l\'accès',
        ];
        $societe = [
            'créer un administrateur', 'changer le rôle de société', 'modifier un administrateur',
            'créer l\'expert-comptable', 'réintégrer l\'expert-comptable', 'annuler une nomination', 'demander la transmission de la propriété',
            'créer un magasin', 'modifier un magasin', 'modifier l\'identité de la société', 'modifier les réglages fiscaux',
        ];
        $verification = [
            'ouvrir la démonstration', 'demander un nouveau courrier', 'déposer une pièce',
        ];

        return [
            ...array_combine($magasin, array_map(static fn (string $nom): array => [$nom, 'magasin', false], $magasin)),
            ...array_combine($societe, array_map(static fn (string $nom): array => [$nom, 'societe', false], $societe)),
            ...array_combine($verification, array_map(static fn (string $nom): array => [$nom, 'societe', true], $verification)),
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function actionsDEcriture(): array
    {
        return array_diff_key(self::actions(), array_flip(self::CONSULTATIONS));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    #[DataProvider('actions')]
    public function test_chaque_action_refuse_un_auteur_sans_le_droit_sans_rien_ecrire(string $nom, string $porte, bool $aVerifier): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => $aVerifier]);
        $geste = $this->geste($nom, $magasin);
        $sansDroit = $this->membre($magasin, RoleMagasin::Observateur, []);

        $this->assertRefuseSansRienEcrire($geste, $sansDroit);
    }

    #[DataProvider('actions')]
    public function test_chaque_action_refuse_un_auteur_d_une_autre_societe_sans_rien_ecrire(string $nom, string $porte, bool $aVerifier): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => $aVerifier]);
        $geste = $this->geste($nom, $magasin);
        $proprietaireDAilleurs = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Autre boutique']));
        app(TenantContext::class)->set($magasin);

        $this->assertRefuseSansRienEcrire($geste, $proprietaireDAilleurs);
    }

    #[DataProvider('actions')]
    public function test_chaque_action_refuse_un_auteur_suspendu_ou_retire_sans_rien_ecrire(string $nom, string $porte, bool $aVerifier): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => $aVerifier]);
        $geste = $this->geste($nom, $magasin);
        $proprietaire = $this->proprietaireDe($magasin);
        $autorise = fn (): User => $porte === 'magasin'
            ? $this->responsable($magasin)
            : $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::modeleDeLAdministrateur());

        $suspendu = $autorise();
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        $this->assertRefuseSansRienEcrire($geste, $suspendu);

        $retire = $autorise();
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);
        $this->assertRefuseSansRienEcrire($geste, $retire);
    }

    #[DataProvider('actionsDEcriture')]
    public function test_l_expert_comptable_n_ecrit_rien_meme_par_l_action(string $nom, string $porte, bool $aVerifier): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => $aVerifier]);
        $geste = $this->geste($nom, $magasin);
        $expert = $this->expertComptable($magasin);

        $this->assertRefuseSansRienEcrire($geste, $expert);
    }

    /**
     * Le geste, préparé dans le magasin par son propriétaire, ses ventes
     * par le responsable de test, puisque le propriétaire ne vend jamais :
     * appelé avec l'auteur, il fait l'action.
     *
     * @return Closure(User): mixed
     */
    private function geste(string $nom, Magasin $magasin): Closure
    {
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->responsableDeTest($magasin);
        $societe = $magasin->societe;

        return match ($nom) {
            'créer une facture' => $this->avec([Client::factory()->create(), $this->lignes()], fn (array $vente, User $auteur) => app(CreerFactureAction::class)->handle($auteur, ...$vente)),
            'modifier une facture' => $this->avec([$this->brouillon($vendeur), $this->lignes(2)], fn (array $modification, User $auteur) => app(ModifierFactureAction::class)->handle($auteur, $modification[0], $modification[0]->client, $modification[1])),
            'émettre une facture' => $this->avec($this->brouillon($vendeur), fn (Facture $brouillon, User $auteur) => app(EmettreFactureAction::class)->handle($auteur, $brouillon)),
            'vendre à la caisse' => $this->avec([Client::factory()->create(), $this->lignes()], fn (array $vente, User $auteur) => self::vendreAuComptant($auteur, ...$vente, mode: ModePaiement::Especes)),
            'créer un devis' => $this->avec([Client::factory()->create(), $this->lignes()], fn (array $vente, User $auteur) => app(CreerDevisAction::class)->handle($auteur, ...$vente)),
            'modifier un devis' => $this->avec([$this->devis($proprietaire), $this->lignes(2)], fn (array $modification, User $auteur) => app(ModifierDevisAction::class)->handle($auteur, $modification[0], $modification[0]->client, $modification[1])),
            'envoyer un devis' => $this->avec($this->devis($proprietaire), fn (Devis $devis, User $auteur) => app(EmettreDevisAction::class)->handle($auteur, $devis)),
            'accepter un devis' => $this->avec(app(EmettreDevisAction::class)->handle($proprietaire, $this->devis($proprietaire)), fn (Devis $devis, User $auteur) => app(AccepterDevisAction::class)->handle($auteur, $devis)),
            'refuser un devis' => $this->avec(app(EmettreDevisAction::class)->handle($proprietaire, $this->devis($proprietaire)), fn (Devis $devis, User $auteur) => app(RefuserDevisAction::class)->handle($auteur, $devis)),
            'facturer un devis' => $this->avec(app(AccepterDevisAction::class)->handle($proprietaire, app(EmettreDevisAction::class)->handle($proprietaire, $this->devis($proprietaire))), fn (Devis $devis, User $auteur) => app(CreerFactureDepuisDevisAction::class)->handle($auteur, $devis)),
            'créer un avoir' => $this->avec($this->ticket($vendeur), fn (Facture $ticket, User $auteur) => app(CreerAvoirAction::class)->handle($auteur, $ticket->client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]])),
            'enregistrer un avoir' => $this->avec($this->ticket($vendeur), fn (Facture $ticket, User $auteur) => app(EnregistrerAvoirAction::class)->handle($auteur, $ticket->client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour')),
            'émettre un avoir' => $this->avec($this->ticket($vendeur), function (Facture $ticket) use ($proprietaire): Closure {
                $avoir = app(CreerAvoirAction::class)->handle($proprietaire, $ticket->client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]]);

                return fn (User $auteur) => app(EmettreAvoirAction::class)->handle($auteur, $avoir);
            }),
            'enregistrer un paiement' => $this->avec($this->ticket($vendeur), fn (Facture $ticket, User $auteur) => $this->payer($auteur, $ticket)),
            'contre-passer un paiement' => $this->avec($this->payer($vendeur, $this->ticket($vendeur)), fn ($paiement, User $auteur) => app(ContrePasserPaiementAction::class)->handle($auteur, $paiement, 'Erreur de saisie')),
            'clôturer la journée' => $this->avec($this->ticket($vendeur), fn (Facture $ticket, User $auteur) => app(CloturerCaisseAction::class)->handle($auteur, $magasin, Calendrier::deParis()->aujourdhui())),
            'clôturer les journées en attente' => $this->avec($this->ticket($vendeur), fn (Facture $ticket, User $auteur) => app(CloturerLesJourneesAction::class)->parUnePersonne($auteur, $magasin, Calendrier::deParis()->aujourdhui())),
            'fermer la caisse d\'un vendeur' => $this->avec($this->membre($magasin, RoleMagasin::Vendeur), function (User $vendeur): Closure {
                $this->ticket($vendeur);

                return fn (User $auteur) => app(FermerSaCaisseAction::class)->handle($auteur, $vendeur);
            }),
            'envoyer un document' => $this->avec($this->ticket($vendeur), fn (Facture $ticket, User $auteur) => app(EnvoyerDocumentAction::class)->handle($auteur, $ticket, 'client@exemple.fr', null)),
            'relancer une facture' => $this->avec($this->ticket($vendeur), fn (Facture $ticket, User $auteur) => app(EnvoyerDocumentAction::class)->relancer($auteur, $ticket, 'client@exemple.fr')),
            'ajouter une preuve' => $this->avec($this->ticket($vendeur), fn (Facture $ticket, User $auteur) => app(AjouterPreuveOperationAction::class)->handle($auteur, $ticket, TypePreuve::Mrn, 'MRN-1234')),
            'noter un bordereau de détaxe' => $this->avec($this->ticket($vendeur), fn (Facture $ticket, User $auteur) => app(NoterBordereauDetaxeAction::class)->handle($auteur, $ticket, 'BD-1234')),
            'saisir une facture d\'achat' => $this->avec([Fournisseur::factory()->create(), $this->lignesDAchat()], fn (array $achat, User $auteur) => app(CreerFactureAchatAction::class)->handle($auteur, ...$achat)),
            'modifier une facture d\'achat' => $this->avec([$this->factureAchat($proprietaire), $this->lignesDAchat(5)], fn (array $modification, User $auteur) => app(ModifierFactureAchatAction::class)->handle($auteur, $modification[0], $modification[0]->fournisseur, $modification[1])),
            'valider une facture d\'achat' => $this->avec($this->factureAchat($proprietaire), fn (FactureAchat $factureAchat, User $auteur) => app(ValiderFactureAchatAction::class)->handle($auteur, $factureAchat)),
            'créer un avoir fournisseur' => $this->avec(app(ValiderFactureAchatAction::class)->handle($proprietaire, $this->factureAchat($proprietaire)), fn (FactureAchat $factureAchat, User $auteur) => app(CreerAvoirFournisseurAction::class)->handle($auteur, $factureAchat, [['ligne_achat_id' => $factureAchat->lignes->sole()->id, 'quantite' => 1]])),
            'émettre un avoir fournisseur' => $this->avec(app(ValiderFactureAchatAction::class)->handle($proprietaire, $this->factureAchat($proprietaire)), function (FactureAchat $factureAchat) use ($proprietaire): Closure {
                $avoir = app(CreerAvoirFournisseurAction::class)->handle($proprietaire, $factureAchat, [['ligne_achat_id' => $factureAchat->lignes->sole()->id, 'quantite' => 1]]);

                return fn (User $auteur) => app(EmettreAvoirFournisseurAction::class)->handle($auteur, $avoir);
            }),
            'créer un service à la caisse' => fn (User $auteur) => app(TrouverOuCreerServiceAction::class)->handle($auteur, 'Pose de verre trempé'),
            'vérifier un numéro de TVA' => $this->avec(Client::factory()->professionnel()->create(['pays' => 'DE', 'numero_tva' => 'DE123456789']), fn (Client $client, User $auteur) => app(VerifierNumeroTvaAction::class)->handle($auteur, $client)),
            'exporter le journal des événements' => fn (User $auteur) => app(ExporterJournalDesEvenementsAction::class)->handle($auteur, Calendrier::deParis()->aujourdhui(), Calendrier::deParis()->aujourdhui()),
            'vérifier l\'intégrité' => fn (User $auteur) => app(VerifierIntegriteAction::class)->handle($auteur, $magasin, false),
            'créer un vendeur' => fn (User $auteur) => app(CreerMembreAction::class)->handle($auteur, $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur),
            'modifier un vendeur' => $this->avec($this->membre($magasin, RoleMagasin::Vendeur), fn (User $vendeur, User $auteur) => app(ModifierMembreAction::class)->handle($auteur, $vendeur, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW])),
            'suspendre un vendeur' => $this->avec($this->membre($magasin, RoleMagasin::Vendeur), fn (User $vendeur, User $auteur) => app(ChangerStatutCompteAction::class)->suspendre($auteur, $vendeur)),
            'réactiver un vendeur' => $this->avec($this->membre($magasin, RoleMagasin::Vendeur), function (User $vendeur) use ($proprietaire): Closure {
                app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $vendeur);

                return fn (User $auteur) => app(ChangerStatutCompteAction::class)->reactiver($auteur, $vendeur);
            }),
            'retirer un vendeur' => $this->avec($this->membre($magasin, RoleMagasin::Vendeur), fn (User $vendeur, User $auteur) => app(ChangerStatutCompteAction::class)->retirer($auteur, $vendeur)),
            'réintégrer un vendeur' => $this->avec($this->membre($magasin, RoleMagasin::Vendeur), function (User $vendeur) use ($proprietaire): Closure {
                app(ChangerStatutCompteAction::class)->retirer($proprietaire, $vendeur);

                return fn (User $auteur) => app(ChangerStatutCompteAction::class)->reintegrer($auteur, $vendeur, RoleMagasin::Vendeur);
            }),
            'redonner l\'accès' => $this->avec($this->membre($magasin, RoleMagasin::Vendeur), fn (User $vendeur, User $auteur) => app(RedonnerLAccesAction::class)->handle($auteur, $vendeur, false)),
            'créer un administrateur' => fn (User $auteur) => app(CreerAdministrateurAction::class)->handle($auteur, $societe, ['name' => 'Alice'], null, PermissionRegistry::modeleDeLAdministrateur(), []),
            'changer le rôle de société' => $this->avec($this->membre($magasin, RoleMagasin::Vendeur), fn (User $vendeur, User $auteur) => app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($auteur, $vendeur, null, PermissionRegistry::modeleDeLAdministrateur(), [])),
            'modifier un administrateur' => $this->avec($this->administrateur($magasin), fn (User $administrateur, User $auteur) => app(ModifierAdministrateurAction::class)->modifier($auteur, $administrateur, null, PermissionRegistry::modeleDeLAdministrateur(), AutorisationsSociete::toutes())),
            'créer l\'expert-comptable' => fn (User $auteur) => app(CreerExpertComptableAction::class)->handle($auteur, $societe, ['name' => 'Émile']),
            'réintégrer l\'expert-comptable' => $this->avec($this->expertComptable($magasin), function (User $expert) use ($proprietaire): Closure {
                app(ChangerStatutCompteAction::class)->retirer($proprietaire, $expert);

                return fn (User $auteur) => app(ChangerStatutCompteAction::class)->reintegrerLExpertComptable($auteur, $expert);
            }),
            'annuler une nomination' => $this->avec($this->membre($magasin, RoleMagasin::Vendeur), function (User $vendeur) use ($magasin): Closure {
                app(ModifierMembreAction::class)->handle($this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]), $vendeur, RoleMagasin::Responsable);
                $nomination = Affectation::query()->where('user_id', $vendeur->id)->sole();

                return fn (User $auteur) => app(AnnulerUneNominationAction::class)->handle($auteur, $nomination);
            }),
            'demander la transmission de la propriété' => $this->avec($this->administrateurPretAReprendre($magasin), fn (User $administrateur, User $auteur) => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($auteur, $administrateur, 'password')),
            'créer un magasin' => fn (User $auteur) => app(CreerEtablissementAction::class)->handle($auteur, $societe, ['nom' => 'Lyon 2', 'nic' => MagasinFactory::nicLibre($societe)]),
            'modifier un magasin' => fn (User $auteur) => app(ModifierEtablissementAction::class)->handle($auteur, $magasin, ['nom' => 'Nouveau nom']),
            'modifier l\'identité de la société' => fn (User $auteur) => app(ModifierIdentiteSocieteAction::class)->handle($auteur, $societe, ['capital_social' => '5000']),
            'modifier les réglages fiscaux' => fn (User $auteur) => app(ModifierParametresFiscauxSocieteAction::class)->handle($auteur, $societe, ['mois_fin_exercice' => 6]),
            'ouvrir la démonstration' => fn (User $auteur) => app(OuvrirLaDemonstrationAction::class)->handle($auteur, $societe),
            'demander un nouveau courrier' => fn (User $auteur) => app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($auteur, $societe),
            'déposer une pièce' => fn (User $auteur) => app(DeposerUnePieceAction::class)->handle($auteur, $societe, TypePiece::Kbis, 'Extrait Kbis'),
        };
    }

    /**
     * Le geste refuse l'auteur pour défaut de droit, et la base n'a pas
     * bougé : ni écriture ni annulation partielle ; aucun e-mail, aucune
     * notification et aucun job ne part.
     *
     * @param  Closure(User): mixed  $geste
     */
    private function assertRefuseSansRienEcrire(Closure $geste, User $auteur): void
    {
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $avant = $this->empreinteDeLaBase();

        try {
            $geste($auteur);
            $this->fail('Le geste aurait dû être refusé.');
        } catch (Throwable $refus) {
            $this->assertContains($refus->getMessage(), self::REFUS_DES_DROITS, $refus::class.' : '.$refus->getMessage());
        }

        $this->assertSame($avant, $this->empreinteDeLaBase());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    /**
     * Le contenu de chaque table, lu en entier.
     */
    private function empreinteDeLaBase(): string
    {
        return json_encode(collect(Schema::getTableListing())->sort()->values()->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->get()])->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Prépare le geste avec ce que le propriétaire ou le responsable de
     * test a déjà fait : la
     * fermeture reçoit cette préparation, puis l'auteur ; ou, quand elle
     * n'attend que la préparation, rend elle-même le geste.
     *
     * @template TPreparation
     *
     * @param  TPreparation  $preparation
     * @return Closure(User): mixed
     */
    private function avec(mixed $preparation, Closure $geste): Closure
    {
        return (new \ReflectionFunction($geste))->getNumberOfParameters() === 1
            ? $geste($preparation)
            : fn (User $auteur) => $geste($preparation, $auteur);
    }

    /**
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function lignes(int $quantite = 1): array
    {
        return [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => $quantite]];
    }

    /**
     * @return list<array{produit_id: int, quantite: int, prix_unitaire_ht: int}>
     */
    private function lignesDAchat(int $quantite = 3): array
    {
        return [['produit_id' => Produit::factory()->create(['suivi_stock' => true])->id, 'quantite' => $quantite, 'prix_unitaire_ht' => 20]];
    }

    private function brouillon(User $auteur): Facture
    {
        return app(CreerFactureAction::class)->handle($auteur, Client::factory()->create(['email' => 'client@exemple.fr']), $this->lignes());
    }

    private function ticket(User $auteur): Facture
    {
        return app(EmettreFactureAction::class)->handle($auteur, $this->brouillon($auteur));
    }

    private function devis(User $auteur): Devis
    {
        return app(CreerDevisAction::class)->handle($auteur, Client::factory()->create(), $this->lignes());
    }

    private function payer(User $auteur, Facture $ticket): mixed
    {
        return app(EnregistrerPaiementAction::class)->handle($auteur, $ticket->client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '12.00', 'recu_le' => Calendrier::deParis()->aujourdhui()], [['facture_id' => $ticket->id, 'montant' => '12.00']]);
    }

    private function factureAchat(User $auteur): FactureAchat
    {
        return app(CreerFactureAchatAction::class)->handle($auteur, Fournisseur::factory()->create(), $this->lignesDAchat());
    }
}
