<?php

namespace Tests\Feature\Authorization;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Database\Factories\MagasinFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TypeError;

/**
 * Chaque action métier relit son auteur en base au moment où elle écrit
 * (voir LecteurDesDroits), après les verrous qu'elle prend en premier :
 * un droit retiré pendant qu'un écran restait ouvert est vu, et c'est le
 * compte relu que l'action enregistre et que le registre signe (voir
 * OperateurCourant). Le système, lui, signe sans opérateur, même pendant
 * une requête authentifiée ; rien ne s'inscrit au registre sans opérateur
 * posé.
 *
 * Les tests « entre l'écran et le verrou » rendent l'ordre déterministe
 * sous les deux moteurs : l'autre transaction retire le droit dès la
 * première requête de l'action (le verrou du registre, ou celui des
 * comptes), avant que l'action relise son auteur.
 */
class LecteurDesDroitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_action_sans_auteur_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $brouillon = $this->brouillon($this->responsableDeTest($magasin));

        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle(null, $brouillon), TypeError::class);
        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle(new User, $brouillon), AuthorizationException::class, LecteurDesDroits::REFUS);

        $this->assertSame([StatutFacture::Brouillon, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero]);
        $this->assertSame(0, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente)->count());
    }

    public function test_un_contexte_absent_ou_d_un_autre_magasin_est_refuse(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $proprietaire = $this->proprietaireDe($lyon);
        $lecteur = app(LecteurDesDroits::class);
        $contexte = app(TenantContext::class);

        $contexte->set($villeurbanne);
        $this->assertThrows(fn () => $lecteur->exiger($proprietaire, 'create', $lyon, Facture::class), LogicException::class, 'Une action d\'un magasin ne se contrôle que dans le contexte de ce magasin.');
        $this->assertThrows(fn () => $lecteur->exigerSousVerrou($proprietaire, 'create', $lyon, Facture::class), LogicException::class, 'Une action d\'un magasin ne se contrôle que dans le contexte de ce magasin.');

        $contexte->clear();
        $this->assertThrows(fn () => $lecteur->exiger($proprietaire, 'create', $lyon, Facture::class), LogicException::class, 'Une action d\'un magasin ne se contrôle que dans le contexte de ce magasin.');
        $this->assertThrows(fn () => $lecteur->exigerDansLaSociete($proprietaire, 'update', $lyon->societe, $lyon->societe), LogicException::class, 'Un geste de la société ne se contrôle que dans le contexte de cette société.');
    }

    public function test_une_autorisation_retiree_entre_l_ecran_et_le_verrou_du_registre_refuse_l_emission(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $brouillon = $this->brouillon($vendeur);
        $this->assertTrue($vendeur->can('emettre', $brouillon), 'L\'écran a vu le droit d\'émettre.');

        $this->aLaPremiereRequeteDeLAction(fn () => $this->retirerLAutorisation($vendeur, $magasin, PermissionRegistry::FACTURE_EMETTRE));
        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle($vendeur, $brouillon), AuthorizationException::class, LecteurDesDroits::REFUS);

        $this->assertSame([StatutFacture::Brouillon, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero]);
        $this->assertSame(0, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente)->count());
    }

    public function test_une_autorisation_retiree_entre_l_ecran_et_le_verrou_refuse_la_cloture_manuelle(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->vendreUnTicket($this->responsableDeTest($magasin));
        $membre = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER]);
        $this->travelTo(self::aParis('2026-10-09 19:00:00'));
        $this->assertTrue($membre->can('cloturer', ClotureCaisse::class), 'L\'écran a vu le droit de clôturer.');

        $this->aLaPremiereRequeteDeLAction(fn () => $this->retirerLAutorisation($membre, $magasin, PermissionRegistry::CLOTURE_CAISSE_GERER));
        $this->assertThrows(fn () => app(CloturerLesJourneesAction::class)->parUnePersonne($membre, $magasin, now()), AuthorizationException::class, LecteurDesDroits::REFUS);

        $this->assertSame(0, ClotureCaisse::query()->count());
    }

    /**
     * L'écran a lu le compte et ses droits ; le droit de valider lui est
     * retiré avant que l'action ne verrouille quoi que ce soit : l'action
     * relit son auteur et refuse, sans créer aucun produit ni aucune
     * entrée en stock.
     */
    public function test_une_autorisation_retiree_avant_le_verrou_refuse_la_validation_d_un_achat(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $acheteur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER]);
        $produit = Produit::factory()->create(['suivi_stock' => true]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($acheteur, Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 20]]);
        $compteDeLEcran = User::query()->findOrFail($acheteur->id);
        $this->assertTrue($compteDeLEcran->can('valider', $factureAchat));

        $this->retirerLAutorisation($acheteur, $magasin, PermissionRegistry::FACTURE_ACHAT_VALIDER);

        $this->assertThrows(fn () => app(ValiderFactureAchatAction::class)->handle($compteDeLEcran, $factureAchat), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame([StatutFactureAchat::Brouillon, null], [$factureAchat->fresh()->statut, $factureAchat->fresh()->numero]);
        $this->assertSame(0, $produit->fresh()->stock_quantite);
    }

    /**
     * Une donnée par geste : le geste, préparé par un membre autorisé, et
     * l'autorisation que l'autre transaction lui retire.
     *
     * @return array<string, array{0: Closure(self, Magasin, User): Closure(): mixed, 1: string, 2: Closure(): int}>
     */
    public static function gestesSousVerrou(): array
    {
        return [
            'paiement' => [
                static function (self $test, Magasin $magasin, User $membre): Closure {
                    $ticket = $test->vendreUnTicket($membre);

                    return fn () => app(EnregistrerPaiementAction::class)->handle($membre, $ticket->client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '12.00', 'recu_le' => today()->toDateString()], [['facture_id' => $ticket->id, 'montant' => '12.00']]);
                },
                PermissionRegistry::PAIEMENT_CREER,
                static fn (): int => Paiement::query()->count(),
            ],
            'avoir' => [
                static function (self $test, Magasin $magasin, User $membre): Closure {
                    $ticket = $test->vendreUnTicket($membre);

                    return fn () => app(EnregistrerAvoirAction::class)->handle($membre, $ticket->client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
                },
                PermissionRegistry::AVOIR_CREER,
                static fn (): int => Avoir::query()->count(),
            ],
            'contre-passation' => [
                static function (self $test, Magasin $magasin, User $membre): Closure {
                    $ticket = $test->vendreUnTicket($membre);
                    $paiement = app(EnregistrerPaiementAction::class)->handle($membre, $ticket->client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '12.00', 'recu_le' => today()->toDateString()], [['facture_id' => $ticket->id, 'montant' => '12.00']]);

                    return fn () => app(ContrePasserPaiementAction::class)->handle($membre, $paiement, 'Erreur de saisie');
                },
                PermissionRegistry::PAIEMENT_CONTRE_PASSER,
                static fn (): int => Paiement::query()->count(),
            ],
            'clôture de la journée' => [
                static function (self $test, Magasin $magasin, User $membre): Closure {
                    $test->vendreUnTicket($membre);

                    return fn () => app(CloturerCaisseAction::class)->handle($membre, $magasin, today());
                },
                PermissionRegistry::CLOTURE_CAISSE_GERER,
                static fn (): int => ClotureCaisse::query()->count(),
            ],
            'caisse' => [
                static function (self $test, Magasin $magasin, User $membre): Closure {
                    $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
                    $client = Client::factory()->create();

                    return fn () => self::vendreAuComptant($membre, $client, [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
                },
                PermissionRegistry::FACTURE_EMETTRE,
                static fn (): int => Facture::query()->count(),
            ],
            'fermeture de la caisse d\'un autre' => [
                static function (self $test, Magasin $magasin, User $membre): Closure {
                    $vendeur = $test->membre($magasin, RoleMagasin::Vendeur);
                    $test->vendreUnTicket($vendeur);

                    return fn () => app(FermerSaCaisseAction::class)->handle($membre, $vendeur);
                },
                PermissionRegistry::CLOTURE_CAISSE_GERER,
                static fn (): int => ClotureCaissePersonnelle::query()->count(),
            ],
            'avoir fournisseur' => [
                static function (self $test, Magasin $magasin, User $membre): Closure {
                    $produit = Produit::factory()->create(['suivi_stock' => true]);
                    $factureAchat = app(ValiderFactureAchatAction::class)->handle($membre, app(CreerFactureAchatAction::class)->handle($membre, Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 20]]));
                    $avoir = app(CreerAvoirFournisseurAction::class)->handle($membre, $factureAchat, [['ligne_achat_id' => $factureAchat->lignes->sole()->id, 'quantite' => 1]]);

                    return fn () => app(EmettreAvoirFournisseurAction::class)->handle($membre, $avoir);
                },
                PermissionRegistry::AVOIR_FOURNISSEUR_CREER,
                static fn (): int => AvoirFournisseur::query()->where('statut', StatutAvoirFournisseur::Emise)->count(),
            ],
        ];
    }

    /**
     * @param  Closure(self, Magasin, User): Closure(): mixed  $preparer
     * @param  Closure(): int  $compter
     */
    #[DataProvider('gestesSousVerrou')]
    public function test_le_paiement_l_avoir_la_contre_passation_la_cloture_la_caisse_la_fermeture_de_la_caisse_d_un_autre_et_l_avoir_fournisseur_relisent_l_auteur_sous_le_verrou(Closure $preparer, string $autorisation, Closure $compter): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membre($magasin, RoleMagasin::Vendeur, PermissionRegistry::toutes());
        $geste = $preparer($this, $magasin, $membre);
        $avant = $compter();
        $this->assertTrue($membre->can($autorisation), 'L\'écran a vu le droit.');

        $this->aLaPremiereRequeteDeLAction(fn () => $this->retirerLAutorisation($membre, $magasin, $autorisation));
        $this->assertThrows($geste, AuthorizationException::class, LecteurDesDroits::REFUS);

        $this->assertSame($avant, $compter());
    }

    /**
     * Le propriétaire est connecté ; l'action reçoit la vendeuse : la
     * vente, son paiement et leurs enregistrements signés la désignent,
     * avec son nom figé.
     */
    public function test_l_operateur_signe_et_l_auteur_enregistre_sont_l_auteur_passe_a_l_action(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeuse = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Zoé Lefèvre', 'email' => 'zoe@example.test']);
        $this->actingAs($this->proprietaireDe($magasin));
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        $ticket = self::vendreAuComptant($vendeuse, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);

        $paiement = Paiement::query()->sole();
        $this->assertSame([$vendeuse->id, 'Zoé Lefèvre'], [$ticket->cree_par, $ticket->cree_par_nom]);
        $this->assertSame([$vendeuse->id, 'Zoé Lefèvre'], [$paiement->cree_par, $paiement->cree_par_nom]);
        foreach ([[ChaineFiscale::Vente, $ticket], [ChaineFiscale::Encaissement, $paiement]] as [$chaine, $document]) {
            $enregistrement = $this->enregistrementDe($chaine, $document);
            $this->assertSame(['id' => $vendeuse->id, 'nom' => 'Zoé Lefèvre'], $enregistrement->contenuSigne()['operateur']);
            $this->assertSame($vendeuse->id, $enregistrement->operateur_id);
            $this->assertSame($vendeuse->id, $enregistrement->contenuSigne()['donnees']['document']['cree_par'] ?? $enregistrement->contenuSigne()['donnees']['paiement']['cree_par']);
        }
    }

    /**
     * Les actions de la société qui écrivent aux journaux des événements
     * de ses magasins, chacune avec l'événement qu'elle doit y inscrire.
     *
     * @return array<string, array{0: string, 1: CodeEvenement}>
     */
    public static function actionsDeLaSociete(): array
    {
        return [
            'régler l\'option pour les débits' => ['option pour les débits', CodeEvenement::ParametreDeConformite],
            'effacer le n° de TVA' => ['n° de TVA', CodeEvenement::ParametreDeConformite],
            'créer un magasin dans une société validée' => ['nouveau magasin', CodeEvenement::ModificationDesDroits],
            'changer le SIRET d\'un magasin avant la validation' => ['SIRET', CodeEvenement::ParametreDeConformite],
            'ouvrir la démonstration' => ['démonstration', CodeEvenement::EntreeEnModeDemonstration],
        ];
    }

    /**
     * Une personne d'une autre société est connectée ; l'action de la
     * société reçoit le propriétaire : chaque événement qu'elle inscrit
     * aux journaux des événements de ses magasins le désigne, jamais la
     * personne connectée.
     */
    #[DataProvider('actionsDeLaSociete')]
    public function test_les_actions_de_la_societe_inscrivent_leurs_evenements_au_nom_de_l_auteur_passe_a_l_action(string $action, CodeEvenement $attendu): void
    {
        $societe = in_array($action, ['SIRET', 'démonstration'], true) ? $this->inscrireUneSociete() : $this->ouvrirUneSociete(['nom' => 'Lyon'])->societe;
        $magasinAVerifier = $action === 'SIRET' ? $this->creerLeMagasinDuSiege($societe) : null;
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $this->actingAs(User::factory()->create());
        app(TenantContext::class)->clear();
        $dejaInscrits = EnregistrementFiscal::withoutGlobalScopes()->max('id') ?? 0;

        match ($action) {
            'option pour les débits' => app(ModifierParametresFiscauxSocieteAction::class)->handle($proprietaire, $societe, ['option_debits_tva' => true]),
            'n° de TVA' => app(ModifierIdentiteSocieteAction::class)->handle($proprietaire, $societe, ['numero_tva' => '', 'capital_social' => '1000']),
            'nouveau magasin' => app(CreerEtablissementAction::class)->handle($proprietaire, $societe, ['nom' => 'Villeurbanne', 'nic' => MagasinFactory::nicLibre($societe)]),
            'SIRET' => app(ModifierEtablissementAction::class)->handle($proprietaire, $magasinAVerifier, ['nic' => MagasinFactory::nicLibre($societe)]),
            'démonstration' => app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe),
        };

        $inscrits = EnregistrementFiscal::withoutGlobalScopes()
            ->whereIn('magasin_id', Magasin::query()->where('societe_id', $societe->id)->select('id'))
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('id', '>', $dejaInscrits)
            ->get();
        $this->assertContains((string) $attendu->value, $inscrits->pluck('type')->map(fn (mixed $type): string => (string) $type)->all());
        $this->assertSame([(int) $proprietaire->id], $inscrits->pluck('operateur_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all());
    }

    /**
     * Le responsable clôture deux journées en attente : chacune enregistre
     * son auteur et son nom, la vente restée ouverte dans le panier d'un
     * vendeur s'abandonne en son nom au registre, puis la caisse de ce
     * vendeur se ferme d'office, sans auteur.
     */
    public function test_la_cloture_manuelle_enregistre_et_signe_son_auteur(): void
    {
        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $responsable->forceFill(['name' => 'Claire Martin'])->save();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->vendreUnTicket($vendeur);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        PanierCaisse::factory()->create(['user_id' => $vendeur->id]);
        $this->travelTo(self::aParis('2026-10-09 19:00:00'));

        ['clotures' => $clotures, 'caisses_fermees' => $caissesFermees] = app(CloturerLesJourneesAction::class)->parUnePersonne($responsable, $magasin, now());

        $this->assertSame(
            [['2026-10-08', $responsable->id, 'Claire Martin'], ['2026-10-09', $responsable->id, 'Claire Martin']],
            $clotures->map(fn (ClotureCaisse $cloture): array => [$cloture->date_cloture->toDateString(), $cloture->cree_par, $cloture->cree_par_nom])->all(),
        );
        $abandon = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement)->where('type', (string) CodeEvenement::TransactionAbandonnee->value)->sole();
        $this->assertSame(['id' => $responsable->id, 'nom' => 'Claire Martin'], $abandon->contenuSigne()['operateur']);
        $releve = ClotureCaissePersonnelle::query()->where('vendeur_id', $vendeur->id)->sole();
        $this->assertSame([ModeFermetureCaisse::Automatique, null, null, 'Fermée automatiquement'], [$releve->mode_fermeture, $releve->fermee_par, $releve->fermee_par_nom, $releve->libelleDeFermeture()]);
        $this->assertSame([$releve->id], $caissesFermees->modelKeys());
    }

    /**
     * La clôture automatique tourne pendant que le propriétaire est
     * connecté (une commande appelée depuis une requête) : la vente qu'elle
     * abandonne s'inscrit sans opérateur, jamais au nom de la personne
     * connectée.
     */
    public function test_une_entree_du_systeme_appelee_pendant_une_requete_authentifiee_signe_un_operateur_nul(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsableDeTest($magasin);
        $this->vendreUnTicket($responsable);
        PanierCaisse::factory()->create(['user_id' => $responsable->id]);
        $this->actingAs($proprietaire);
        $this->travelTo(self::aParis('2026-10-10 00:05:00'));

        app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, self::aParis('2026-10-09 00:05:00'));

        $abandon = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement)->where('type', (string) CodeEvenement::TransactionAbandonnee->value)->sole();
        $this->assertNull($abandon->contenuSigne()['operateur']);
        $this->assertNull($abandon->operateur_id);
        $this->assertNull(ClotureCaisse::query()->sole()->cree_par);
    }

    /**
     * Le registre refuse une vente, un avoir ou un encaissement qu'aucune
     * action n'a posé au nom de son auteur contrôlé, ou du système, même
     * pendant une requête authentifiée.
     */
    public function test_une_operation_inscrite_au_registre_sans_operateur_pose_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->proprietaireDe($magasin));
        $this->assertFalse(app(OperateurCourant::class)->estPose());

        $this->assertThrows(
            fn () => DB::transaction(fn () => app(RegistreFiscal::class)->enregistrerVente('ticket', 1200, ['total_ttc_centimes' => 1200])),
            LogicException::class,
            'Une vente, un avoir ou un encaissement ne s\'inscrit qu\'au nom de l\'auteur contrôlé par son action, ou du système.',
        );
        $this->assertThrows(
            fn () => DB::transaction(fn () => app(RegistreFiscal::class)->enregistrer(ChaineFiscale::Encaissement, 'paiement', ['montant' => 1200])),
            LogicException::class,
            'Une vente, un avoir ou un encaissement ne s\'inscrit qu\'au nom de l\'auteur contrôlé par son action, ou du système.',
        );

        $this->assertSame(0, EnregistrementFiscal::query()->where('chaine', '!=', ChaineFiscale::Evenement)->count());
    }

    /**
     * Un ticket de 12,00 € TTC que ce compte crée et émet maintenant, non
     * réglé.
     */
    public function vendreUnTicket(User $vendeur): Facture
    {
        return app(EmettreFactureAction::class)->handle($vendeur, $this->brouillon($vendeur));
    }

    /**
     * Un brouillon de ticket de 12,00 € TTC que ce compte crée.
     */
    private function brouillon(User $auteur): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(CreerFactureAction::class)->handle($auteur, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
    }

    /**
     * Retire une case au compte dans ce magasin, comme une autre
     * transaction validée l'aurait fait.
     */
    private function retirerLAutorisation(User $compte, Magasin $magasin, string $autorisation): void
    {
        $affectation = Affectation::query()->where('user_id', $compte->id)->where('magasin_id', $magasin->id)->sole();

        $this->assertSame(1, DB::table('autorisations_affectations')->where('affectation_id', $affectation->id)->where('autorisation', $autorisation)->delete());
    }

    /**
     * Exécute le changement dès la première requête de l'action qui suit,
     * avant qu'elle relise son auteur.
     */
    private function aLaPremiereRequeteDeLAction(Closure $changement): void
    {
        $fait = false;
        DB::listen(function (QueryExecuted $requete) use (&$fait, $changement): void {
            if (! $fait) {
                $fait = true;
                $changement();
            }
        });
    }

    private function enregistrementDe(ChaineFiscale $chaine, Facture|Paiement $document): EnregistrementFiscal
    {
        return EnregistrementFiscal::query()
            ->where('chaine', $chaine->value)
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->getKey())
            ->sole();
    }
}
