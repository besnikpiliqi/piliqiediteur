<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages\ListClotureCaissePersonnelles;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ListClotureCaisses;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * R5 : une caisse personnelle restée ouverte ne bloque jamais la clôture
 * de la journée. La clôture, manuelle ou automatique, la ferme d'office
 * (« Fermée automatiquement »), minuit ferme toute caisse restée ouverte la
 * veille, même après une clôture manuelle, et un responsable peut la
 * fermer pour le vendeur (« Fermée par » et son nom). Chaque fermeture
 * compte les opérations jusqu'à sa borne, jamais au-delà.
 */
class CaissesPersonnellesFermeesDOfficeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un magasin dont le propriétaire, Alice Martin, est connecté sur son
     * panneau.
     */
    private function ouvrirLeMagasin(): Magasin
    {
        $proprietaire = User::factory()->create(['name' => 'Alice Martin']);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    private function vendeur(Magasin $magasin, string $nom): User
    {
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $vendeur->forceFill(['name' => $nom])->save();

        return $vendeur;
    }

    /**
     * Un ticket de 12,00 € TTC que le vendeur émet maintenant, non réglé.
     */
    private function vendreUnTicket(User $vendeur): Facture
    {
        $connecte = auth()->user();
        $this->actingAs($vendeur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
        $this->actingAs($connecte);

        return $ticket;
    }

    private function cloturerLaJourneeDepuisLePanneau(): void
    {
        Livewire::test(ListClotureCaisses::class)
            ->assertActionEnabled('cloturer')
            ->callAction('cloturer');
    }

    public function test_la_cloture_du_jour_n_est_jamais_bloquee_et_ferme_d_office_les_caisses_personnelles_ouvertes(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $paul = $this->vendeur($magasin, 'Paul Martin');
        $lea = $this->vendeur($magasin, 'Léa Durand');
        $ticketDePaul = $this->vendreUnTicket($paul);
        $this->vendreUnTicket($lea);
        $releveDeLea = app(FermerSaCaisseAction::class)->handle($lea, $lea);
        $this->travelTo('2026-10-09 19:00:00');

        $this->cloturerLaJourneeDepuisLePanneau();

        $this->assertSame(1, ClotureCaisse::query()->count());
        $releveDePaul = ClotureCaissePersonnelle::query()->where('vendeur_id', $paul->id)->sole();
        $this->assertSame(
            [ModeFermetureCaisse::Automatique, null, [$ticketDePaul->id], '12.00', '2026-10-09 19:00:00'],
            [$releveDePaul->mode_fermeture, $releveDePaul->fermee_par, $releveDePaul->factures->modelKeys(), $releveDePaul->total_ttc, $releveDePaul->jusqu_au->format('Y-m-d H:i:s')],
        );
        $this->assertSame([$releveDeLea->id], ClotureCaissePersonnelle::query()->where('vendeur_id', $lea->id)->pluck('id')->all());
        $this->assertTrue(app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->isEmpty());
    }

    public function test_le_releve_ferme_d_office_porte_la_mention_fermee_automatiquement(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $paul = $this->vendeur($magasin, 'Paul Martin');
        $lea = $this->vendeur($magasin, 'Léa Durand');
        $this->vendreUnTicket($paul);
        $this->vendreUnTicket($lea);
        $releveDeLea = app(FermerSaCaisseAction::class)->handle($lea, $lea);
        $this->travelTo('2026-10-09 19:00:00');
        $this->cloturerLaJourneeDepuisLePanneau();
        $releveDePaul = ClotureCaissePersonnelle::query()->where('vendeur_id', $paul->id)->sole();

        $liste = Livewire::test(ListClotureCaissePersonnelles::class);

        $liste->assertTableColumnStateSet('mode_fermeture', 'Fermée automatiquement', $releveDePaul)
            ->assertTableColumnStateSet('mode_fermeture', 'Fermée par le vendeur', $releveDeLea);
    }

    /**
     * Note de l'éditeur sur R5 : minuit ferme toute caisse personnelle qui
     * ne l'a pas été. Le magasin a clôturé à 19 h, puis Paul a encaissé à
     * 20 h le ticket du matin : la commande de minuit ne crée aucune
     * clôture de plus, mais ferme sa caisse jusqu'à la fin de la journée.
     */
    public function test_minuit_ferme_les_caisses_personnelles_restees_ouvertes_meme_apres_une_cloture_manuelle(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $paul = $this->vendeur($magasin, 'Paul Martin');
        $ticket = $this->vendreUnTicket($paul);
        $this->travelTo('2026-10-09 19:00:00');
        $this->cloturerLaJourneeDepuisLePanneau();
        $this->travelTo('2026-10-09 20:00:00');
        $this->actingAs($paul);
        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $ticket->client, [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 12,
            'recu_le' => '2026-10-09',
        ], [['facture_id' => $ticket->id, 'montant' => 12]]);
        $this->travelTo('2026-10-10 00:00:00');

        $this->artisan('app:cloturer-caisses-automatiquement')
            ->expectsOutputToContain('1 caisse(s) personnelle(s) fermée(s) automatiquement.')
            ->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $this->assertSame(1, ClotureCaisse::query()->count());
        $releveDeMinuit = ClotureCaissePersonnelle::query()->where('vendeur_id', $paul->id)->latest('id')->firstOrFail();
        $this->assertSame(
            [ModeFermetureCaisse::Automatique, 0, ['especes' => '12.00'], '2026-10-09 23:59:59'],
            [$releveDeMinuit->mode_fermeture, $releveDeMinuit->nombre_ventes, $releveDeMinuit->encaissements, $releveDeMinuit->jusqu_au->format('Y-m-d H:i:s')],
        );
        $this->assertSame(2, ClotureCaissePersonnelle::query()->where('vendeur_id', $paul->id)->count());
    }

    public function test_un_responsable_ferme_la_caisse_d_un_vendeur(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $paul = $this->vendeur($magasin, 'Paul Martin');
        $ticket = $this->vendreUnTicket($paul);
        $this->travelTo('2026-10-09 18:00:00');

        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertActionVisible('fermer_la_caisse_d_un_vendeur')
            ->callAction('fermer_la_caisse_d_un_vendeur', ['vendeur_id' => $paul->id])
            ->assertHasNoActionErrors()
            ->assertNotified('Caisse de Paul Martin fermée');

        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame(
            [$paul->id, ModeFermetureCaisse::Responsable, $this->proprietaireDe($magasin)->id, [$ticket->id], 'Fermée par Alice Martin'],
            [$releve->vendeur_id, $releve->mode_fermeture, $releve->fermee_par, $releve->factures->modelKeys(), $releve->libelleDeFermeture()],
        );
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertTableColumnStateSet('mode_fermeture', 'Fermée par Alice Martin', $releve);
    }

    /**
     * Qui peut clôturer la journée ferme la caisse d'un vendeur même s'il
     * ne vend ni n'encaisse : la page des relevés lui est ouverte pour ce
     * geste.
     */
    public function test_un_responsable_qui_ne_vend_ni_n_encaisse_ferme_la_caisse_d_un_vendeur(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $paul = $this->vendeur($magasin, 'Paul Martin');
        $ticket = $this->vendreUnTicket($paul);
        $responsable = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER]);
        $this->actingAs($responsable);

        $this->get(ClotureCaissePersonnelleResource::getUrl('index', ['tenant' => $magasin]))->assertOk();
        Filament::setTenant($magasin);
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertActionHidden('fermer_ma_caisse')
            ->callAction('fermer_la_caisse_d_un_vendeur', ['vendeur_id' => $paul->id])
            ->assertNotified('Caisse de Paul Martin fermée');

        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame(
            [$paul->id, ModeFermetureCaisse::Responsable, $responsable->id, [$ticket->id]],
            [$releve->vendeur_id, $releve->mode_fermeture, $releve->fermee_par, $releve->factures->modelKeys()],
        );
    }

    /**
     * Le relevé d'un vendeur ne se montre qu'à lui et à qui voit ceux de
     * toute l'équipe (cloture_caisse.view_toutes) : le propriétaire voit
     * les montants de Paul avant de fermer sa caisse ; le gérant, qui
     * clôture la journée sans voir les relevés de l'équipe, la ferme sans
     * en voir aucun.
     */
    public function test_seul_qui_voit_les_releves_de_l_equipe_voit_les_montants_de_la_caisse_qu_il_ferme(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $paul = $this->vendeur($magasin, 'Paul Martin');
        $this->vendreUnTicket($paul);
        $gerant = $this->membre($magasin, RoleMagasin::Vendeur, [
            PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE,
            PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER,
        ]);

        Livewire::test(ListClotureCaissePersonnelles::class)
            ->mountAction('fermer_la_caisse_d_un_vendeur')
            ->setActionData(['vendeur_id' => $paul->id])
            ->assertSee('TTC vendu : 12,00 € (1 vente)')
            ->assertSee('Reste dû : 12,00 €');
        $this->actingAs($gerant);
        $fenetreDuGerant = Livewire::test(ListClotureCaissePersonnelles::class)
            ->mountAction('fermer_la_caisse_d_un_vendeur')
            ->setActionData(['vendeur_id' => $paul->id]);

        $fenetreDuGerant->assertSee('Son relevé ne vous sera pas montré')
            ->assertDontSee('TTC vendu')
            ->assertDontSee('12,00');
        $fenetreDuGerant->callMountedAction()->assertNotified('Caisse de Paul Martin fermée');
        $this->assertSame(ModeFermetureCaisse::Responsable, ClotureCaissePersonnelle::query()->sole()->mode_fermeture);
    }

    /**
     * Un vendeur retiré de l'équipe garde sa caisse ouverte dans le magasin
     * où il a vendu : un responsable la ferme pour lui.
     */
    public function test_un_responsable_ferme_la_caisse_d_un_vendeur_retire_de_l_equipe(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $paul = $this->vendeur($magasin, 'Paul Martin');
        $ticket = $this->vendreUnTicket($paul);
        app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($magasin), $paul);

        Livewire::test(ListClotureCaissePersonnelles::class)
            ->callAction('fermer_la_caisse_d_un_vendeur', ['vendeur_id' => $paul->id])
            ->assertNotified('Caisse de Paul Martin fermée');

        $this->assertSame([$ticket->id], ClotureCaissePersonnelle::query()->where('vendeur_id', $paul->id)->sole()->factures->modelKeys());
    }

    /**
     * Isolation des magasins : la caisse de Bob, vendeur d'un autre
     * magasin, ne se ferme jamais depuis celui-ci, même par une requête
     * forgée. Aucun relevé n'y révèle son nom.
     */
    public function test_un_responsable_ne_ferme_jamais_la_caisse_d_un_compte_d_un_autre_magasin(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $alice = $this->proprietaireDe($magasin);
        $autreMagasin = $this->ouvrirUneSociete(['nom' => 'Autre Boutique']);
        $bob = $this->vendeur($autreMagasin, 'Bob Durand');
        $this->vendreUnTicket($bob);
        app(TenantContext::class)->set($magasin);

        $this->assertFalse($alice->can('fermerLaCaisseDe', [ClotureCaissePersonnelle::class, $bob]));
        $this->assertThrows(fn () => app(FermerSaCaisseAction::class)->handle($alice, $bob), AuthorizationException::class);
        $this->assertDatabaseCount('clotures_caisse_personnelles', 0);
    }

    /**
     * Une fermeture d'office n'a pas d'auteur, toute autre en a un : la
     * base refuse un relevé qui dit le contraire, dont la liste ne saurait
     * dire qui a fermé la caisse.
     *
     * @return array<string, array{ModeFermetureCaisse, bool}>
     */
    public static function auteursContraires(): array
    {
        return [
            'fermée par le vendeur, sans auteur' => [ModeFermetureCaisse::Vendeur, false],
            'fermée par un responsable, sans auteur' => [ModeFermetureCaisse::Responsable, false],
            'fermée automatiquement, avec un auteur' => [ModeFermetureCaisse::Automatique, true],
        ];
    }

    #[DataProvider('auteursContraires')]
    public function test_la_base_refuse_un_releve_dont_l_auteur_contredit_le_mode_de_fermeture(ModeFermetureCaisse $mode, bool $avecAuteur): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $paul = $this->vendeur($magasin, 'Paul Martin');

        $this->assertThrows(
            $this->dansUnPointDeSauvegarde(fn () => ClotureCaissePersonnelle::factory()->create([
                'vendeur_id' => $paul->id,
                'mode_fermeture' => $mode,
                'fermee_par' => $avecAuteur ? $this->proprietaireDe($magasin)->id : null,
            ])),
            QueryException::class,
            'ccp_auteur_selon_le_mode',
        );
        $this->assertDatabaseCount('clotures_caisse_personnelles', 0);
    }

    /**
     * Un vendeur, sans l'autorisation de clôturer la journée, ne ferme que
     * la sienne : le geste n'est pas proposé, et le domaine le refuse à une
     * requête forgée, sans rien noter.
     */
    public function test_un_vendeur_ne_ferme_pas_la_caisse_d_un_autre(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $paul = $this->vendeur($magasin, 'Paul Martin');
        $lea = $this->vendeur($magasin, 'Léa Durand');
        $this->vendreUnTicket($paul);
        $this->actingAs($lea);

        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertActionVisible('fermer_ma_caisse')
            ->assertActionHidden('fermer_la_caisse_d_un_vendeur');

        $this->assertThrows(fn () => app(FermerSaCaisseAction::class)->handle($lea, $paul), AuthorizationException::class);
        $this->assertDatabaseCount('clotures_caisse_personnelles', 0);
    }

    /**
     * La fermeture d'office de minuit compte jusqu'à la fin de la veille :
     * le ticket que Paul a émis à 0 h 00 min 30 s, avant le passage de la
     * commande, reste pour sa prochaine fermeture.
     */
    public function test_une_operation_posterieure_a_la_borne_n_est_pas_comptee(): void
    {
        $this->travelTo('2026-10-09 18:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $paul = $this->vendeur($magasin, 'Paul Martin');
        $ticketDeLaVeille = $this->vendreUnTicket($paul);
        $this->travelTo('2026-10-10 00:00:30');
        $ticketDApresMinuit = $this->vendreUnTicket($paul);
        $this->travelTo('2026-10-10 00:01:00');

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame(
            [[$ticketDeLaVeille->id], '12.00', '2026-10-09 23:59:59'],
            [$releve->factures->modelKeys(), $releve->total_ttc, $releve->jusqu_au->format('Y-m-d H:i:s')],
        );
        $this->assertTrue(Facture::query()->nonFermeesParLeVendeur($paul->id)->whereKey($ticketDApresMinuit->id)->exists());
        $this->assertSame([$paul->id], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(CarbonImmutable::parse('2026-10-10'))->pluck('id')->all());
    }

    /**
     * Une fermeture d'office qui échoue (ici, une panne simulée) ne défait
     * jamais la clôture de la journée : elle est faite et annoncée, la
     * panne est signalée au journal de l'application, et minuit ferme la
     * caisse restée ouverte.
     */
    public function test_un_echec_de_fermeture_d_office_laisse_la_cloture_du_jour_faite(): void
    {
        Exceptions::fake([RuntimeException::class]);
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $paul = $this->vendeur($magasin, 'Paul Martin');
        $this->vendreUnTicket($paul);
        $this->travelTo('2026-10-09 19:00:00');
        $this->mock(FermerSaCaisseAction::class)->shouldReceive('fermerDOffice')->andThrow(new RuntimeException('Panne simulée'));

        Livewire::test(ListClotureCaisses::class)
            ->callAction('cloturer')
            ->assertNotified('Journée du 09/10/2026 clôturée');

        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame(1, ClotureCaisse::query()->count());
        $this->assertDatabaseCount('clotures_caisse_personnelles', 0);

        $this->app->forgetInstance(FermerSaCaisseAction::class);
        $this->travelTo('2026-10-10 00:00:00');
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $this->assertSame(ModeFermetureCaisse::Automatique, ClotureCaissePersonnelle::query()->where('vendeur_id', $paul->id)->sole()->mode_fermeture);
    }
}
