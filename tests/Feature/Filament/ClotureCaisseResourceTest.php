<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ListClotureCaisses;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ViewClotureCaisse;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClotureCaisseResourceTest extends TestCase
{
    use RefreshDatabase;

    private function emettreFactureParticulier(Client $client): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        return $facture->fresh();
    }

    private function emettreAvoir(Client $client, Facture $facture): Avoir
    {
        return app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
    }

    public function test_le_proprietaire_cloture_une_journee_depuis_le_panneau(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create(); // particulier par défaut

        // La clôture ne porte jamais sur une date choisie : uniquement sur
        // "hier" au moment de l'action (voir
        // ListClotureCaisses::getHeaderActions()). La facture doit donc
        // être émise hier pour être agrégée.
        $this->travelTo(now()->subDay());
        $facture = $this->emettreFactureParticulier($client);
        $this->travelBack();

        // Exigence explicite : un vendeur (ici le propriétaire lui-même, qui
        // a créé la facture) qui n'a pas fermé sa caisse personnelle pour
        // cette journée bloque la clôture officielle -- voir
        // VerificateurCaissesPersonnelles.
        app(FermerSaCaisseAction::class)->handle($proprietaire);

        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $cloture = ClotureCaisse::query()->firstOrFail();
        $this->assertSame(1, $cloture->nombre_ventes);
        $this->assertSame($cloture->id, $facture->fresh()->cloture_caisse_id);
    }

    /**
     * Le gérant doit voir avant de confirmer exactement ce qui sera
     * regroupé dans la clôture, pas seulement un avertissement générique
     * (voir ListClotureCaisses::getHeaderActions()).
     */
    public function test_la_modale_de_confirmation_annonce_le_nombre_et_le_total_des_ventes(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create(); // particulier par défaut

        $this->travelTo(now()->subDay());
        $this->emettreFactureParticulier($client); // 50 € HT, TVA 20 % => 60 € TTC
        $this->travelBack();
        app(FermerSaCaisseAction::class)->handle($proprietaire);

        Livewire::test(ListClotureCaisses::class)
            ->mountAction('cloturer')
            ->assertSee('1 vente(s) au comptoir pour un total de 60,00 €');
    }

    public function test_la_modale_de_confirmation_annonce_les_avoirs_deduits_et_le_total_net(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $this->travelTo(now()->subDay());
        $this->emettreFactureParticulier($client); // 60 € TTC
        $this->emettreAvoir($client, $this->emettreFactureParticulier($client));
        $this->travelBack();
        app(FermerSaCaisseAction::class)->handle($proprietaire);

        Livewire::test(ListClotureCaisses::class)
            ->mountAction('cloturer')
            ->assertSee('2 vente(s) au comptoir et 1 avoir(s) déduit(s), pour un total net de 60,00 €');
    }

    /**
     * Exigence explicite : un vendeur qui oublie de fermer sa caisse
     * personnelle pour la journée à clôturer doit bloquer la clôture
     * manuelle -- jamais se fermer tout seul (voir
     * VerificateurCaissesPersonnelles) -- et redevient possible dès qu'il
     * la ferme.
     */
    public function test_laction_cloturer_est_desactivee_tant_quun_vendeur_na_pas_ferme_sa_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();

        $this->travelTo(now()->subDay());
        $this->emettreFactureParticulier($client);
        $this->travelBack();

        Livewire::test(ListClotureCaisses::class)
            ->assertActionDisabled('cloturer')
            ->assertSee('Caisses personnelles non fermées');

        app(FermerSaCaisseAction::class)->handle($proprietaire);

        Livewire::test(ListClotureCaisses::class)
            ->assertActionEnabled('cloturer');
    }

    public function test_la_modale_de_confirmation_previent_quand_aucune_vente_nest_en_attente(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(ListClotureCaisses::class)
            ->mountAction('cloturer')
            ->assertSee("Aucune vente au comptoir n'a été enregistrée ce jour-là");
    }

    /**
     * Exigence explicite : aucun choix de date -- la clôture manuelle
     * cible toujours exactement la veille, le seul jour "terminé" en
     * attente, et redevient indisponible dès que cette journée est
     * clôturée (ici manuellement, mais ce serait pareil si la commande
     * automatique l'avait fait à minuit, voir ClotureAutomatiqueCaisse).
     */
    public function test_laction_cloturer_disparait_une_fois_la_veille_deja_cloturee(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Livewire::test(ListClotureCaisses::class)
            ->assertActionVisible('cloturer')
            ->callAction('cloturer');

        Livewire::test(ListClotureCaisses::class)
            ->assertActionHidden('cloturer');
    }

    public function test_laction_cloturer_nest_pas_visible_pour_un_vendeur(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        Permission::findOrCreate(PermissionRegistry::CLOTURE_CAISSE_VIEW, 'web');
        $roleVendeur = Role::findOrCreate('employee', 'web');
        $roleVendeur->givePermissionTo([PermissionRegistry::CLOTURE_CAISSE_VIEW]);

        $vendeur = User::factory()->create();
        $vendeur->assignRole($roleVendeur);
        $magasin->utilisateurs()->attach($vendeur->id, ['role' => 'employee', 'statut' => 'active', 'joined_at' => now()]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        Livewire::test(ListClotureCaisses::class)
            ->assertActionHidden('cloturer');
    }

    public function test_la_page_de_detail_affiche_les_factures_agregees(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();

        $this->travelTo(now()->subDay());
        $facture = $this->emettreFactureParticulier($client);
        $this->travelBack();
        app(FermerSaCaisseAction::class)->handle($proprietaire);

        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $cloture = ClotureCaisse::query()->firstOrFail();

        Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee($facture->numero);
    }

    public function test_la_page_de_detail_affiche_les_avoirs_deduits(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $this->travelTo(now()->subDay());
        $avoir = $this->emettreAvoir($client, $this->emettreFactureParticulier($client));
        $this->travelBack();
        app(FermerSaCaisseAction::class)->handle($proprietaire);
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $cloture = ClotureCaisse::query()->firstOrFail();

        Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])
            ->assertSee('Avoirs déduits')
            ->assertSee($avoir->numero)
            ->assertSee('-60,00');
    }

    /**
     * Un vendeur qui clôture sa propre caisse (ex: fin de service d'un
     * caissier) ne doit voir que ses propres clôtures, pas celles
     * déclenchées par un autre vendeur -- même confidentialité que les
     * factures.
     */
    public function test_un_vendeur_ne_voit_que_les_clotures_quil_a_lui_meme_declenchees(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        Permission::findOrCreate(PermissionRegistry::CLOTURE_CAISSE_VIEW, 'web');
        Permission::findOrCreate(PermissionRegistry::CLOTURE_CAISSE_GERER, 'web');
        $roleVendeur = Role::findOrCreate('employee', 'web');
        $roleVendeur->givePermissionTo([PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER]);

        $vendeurA = User::factory()->create();
        $vendeurB = User::factory()->create();
        $vendeurA->assignRole($roleVendeur);
        $vendeurB->assignRole($roleVendeur);
        foreach ([$vendeurA, $vendeurB] as $vendeur) {
            $magasin->utilisateurs()->attach($vendeur->id, ['role' => 'employee', 'statut' => 'active', 'joined_at' => now()]);
        }

        $this->actingAs($vendeurA);
        Filament::setTenant($magasin);
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        // Un jour réel s'écoule entre les deux clôtures : l'action ne
        // porte jamais sur une date choisie, seulement sur "hier" au
        // moment où elle est déclenchée (voir
        // ListClotureCaisses::getHeaderActions()).
        $this->travelTo(now()->addDay());

        $this->actingAs($vendeurB);
        Filament::setTenant($magasin);
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $this->travelBack();

        $clotureA = ClotureCaisse::where('cree_par', $vendeurA->id)->firstOrFail();
        $clotureB = ClotureCaisse::where('cree_par', $vendeurB->id)->firstOrFail();

        Livewire::test(ListClotureCaisses::class)
            ->assertCanSeeTableRecords([$clotureB])
            ->assertCanNotSeeTableRecords([$clotureA]);

        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        Livewire::test(ListClotureCaisses::class)
            ->assertCanSeeTableRecords([$clotureA, $clotureB]);
    }

    /**
     * L'e-reporting déclare les ventes par taux de TVA : la clôture montre
     * donc la ventilation des lignes de ses ventes, et chaque vente avec
     * son HT et sa TVA.
     */
    public function test_la_cloture_ventile_ses_ventes_par_taux_de_tva(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 0, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
        ]));

        $cloture = app(CloturerCaisseAction::class)->handle($magasin, $facture->date_emission);

        $this->assertSame(['76.66', '13.34', '90.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
        $this->assertSame([
            ['taux_tva' => '20.00', 'montant_ht' => 66.66, 'montant_tva' => 13.34, 'montant_ttc' => 80.0],
            ['taux_tva' => '0.00', 'montant_ht' => 10.0, 'montant_tva' => 0.0, 'montant_ttc' => 10.0],
        ], $cloture->ventilation_tva);

        Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('TVA par taux')
            ->assertSee('Biens et services')
            ->assertSee('66,66')
            ->assertSee('76,66');
    }
}
