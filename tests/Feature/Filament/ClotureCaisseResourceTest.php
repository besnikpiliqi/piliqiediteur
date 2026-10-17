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
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ListClotureCaisses;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ViewClotureCaisse;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClotureCaisseResourceTest extends TestCase
{
    use RefreshDatabase;

    private function emettreFactureParticulier(Client $client): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

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
        return app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
    }

    /**
     * Un ticket de 12,00 € TTC au client particulier, émis maintenant par
     * l'utilisateur connecté.
     */
    private function vendreUnTicket(): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }

    /**
     * Un magasin dont le propriétaire est connecté, sur son panneau.
     */
    private function ouvrirLeMagasin(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Le soir, après sa dernière vente, le magasin clôture lui-même la
     * journée en cours ; à minuit, la commande ne clôture rien de plus pour
     * cette journée.
     */
    public function test_le_proprietaire_cloture_la_journee_du_jour_depuis_le_panneau(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->travelTo('2026-10-09 10:00:00');
        $facture = $this->emettreFactureParticulier(Client::factory()->create()); // 60 € TTC
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));
        $this->travelTo('2026-10-09 19:00:00');

        Livewire::test(ListClotureCaisses::class)
            ->assertActionHasLabel('cloturer', 'Clôturer la journée du 09/10/2026')
            ->callAction('cloturer')
            ->assertNotified('Journée du 09/10/2026 clôturée');

        $cloture = ClotureCaisse::query()->sole();
        $this->assertSame(['2026-10-09', 'Z-MABOUT-2026-000001', 1, '60.00'], [$cloture->date_cloture->toDateString(), $cloture->numero, $cloture->nombre_ventes, $cloture->total_ttc]);
        $this->assertSame($cloture->id, $facture->fresh()->cloture_caisse_id);

        $this->travelTo('2026-10-10 00:00:00');
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        $this->assertSame(1, app(TenantContext::class)->runAs($magasin, fn (): int => ClotureCaisse::query()->count()));
    }

    /**
     * Le magasin voit avant de confirmer exactement ce qui sera regroupé,
     * et qu'il ne pourra plus vendre ensuite ce jour-là.
     */
    public function test_la_modale_annonce_les_ventes_du_jour_et_previent_qu_ensuite_plus_aucune_vente_n_est_possible(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->travelTo('2026-10-09 10:00:00');
        $this->emettreFactureParticulier(Client::factory()->create()); // 50 € HT, TVA 20 % => 60 € TTC
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));

        Livewire::test(ListClotureCaisses::class)
            ->mountAction('cloturer')
            ->assertSee('09/10/2026 : 1 vente(s) au comptoir pour un total de 60,00 €.')
            ->assertSee("Après la clôture, vous ne pourrez plus faire de ventes aujourd'hui.")
            ->assertSee('Cette action est IRRÉVERSIBLE');
    }

    /**
     * Une vente restée en cours à la caisse sera abandonnée par la clôture
     * : la fenêtre de confirmation le dit avant.
     */
    public function test_la_modale_annonce_les_ventes_en_cours_a_la_caisse_qu_elle_abandonnera(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->travelTo('2026-10-09 10:00:00');
        $this->emettreFactureParticulier(Client::factory()->create());
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));
        PanierCaisse::factory()->create(['user_id' => $this->proprietaireDe($magasin)->id]);

        Livewire::test(ListClotureCaisses::class)
            ->mountAction('cloturer')
            ->assertSee('1 vente(s) en cours à la caisse, jamais encaissée(s), sera(ont) abandonnée(s) et inscrite(s) au journal des événements.');
    }

    public function test_la_modale_annonce_les_avoirs_deduits_et_le_total_net(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $client = Client::factory()->create();
        $this->travelTo('2026-10-09 10:00:00');
        $this->emettreFactureParticulier($client); // 60 € TTC
        $this->emettreAvoir($client, $this->emettreFactureParticulier($client));
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));

        Livewire::test(ListClotureCaisses::class)
            ->mountAction('cloturer')
            ->assertSee('09/10/2026 : 2 vente(s) au comptoir et 1 avoir(s) déduit(s), pour un total net de 60,00 €.');
    }

    /**
     * Une vente à un professionnel ouvre les journées de caisse sans
     * entrer dans la clôture : la journée se clôture à zéro.
     */
    public function test_la_modale_previent_quand_aucune_vente_au_comptoir_n_est_en_attente(): void
    {
        $magasin = $this->completerIdentiteLegale($this->ouvrirLeMagasin());
        $this->travelTo('2026-10-09 10:00:00');
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));

        Livewire::test(ListClotureCaisses::class)
            ->mountAction('cloturer')
            ->assertSee('09/10/2026 : aucune vente au comptoir, la clôture sera créée à zéro.');
    }

    /**
     * Une journée oubliée (le 08/10, sans clôture manuelle ni passage de
     * la commande de minuit) est annoncée puis clôturée d'abord, dans
     * l'ordre des journées.
     */
    public function test_une_journee_oubliee_est_annoncee_puis_cloturee_avant_celle_du_jour(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $client = Client::factory()->create();
        $this->travelTo('2026-10-08 10:00:00');
        $venteDu8 = $this->emettreFactureParticulier($client);
        $this->travelTo('2026-10-09 10:00:00');
        $venteDu9 = $this->emettreFactureParticulier($client);
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));
        $this->travelTo('2026-10-09 19:00:00');

        Livewire::test(ListClotureCaisses::class)
            ->mountAction('cloturer')
            ->assertSee("Journées clôturées, dans l'ordre : 08/10/2026 (oubliée) et 09/10/2026.")
            ->callMountedAction()
            ->assertNotified('Journées du 08/10/2026 et 09/10/2026 clôturées');

        $clotures = ClotureCaisse::query()->orderBy('date_cloture')->get();
        $this->assertSame(
            [['2026-10-08', 'Z-MABOUT-2026-000001', '60.00'], ['2026-10-09', 'Z-MABOUT-2026-000002', '60.00']],
            $clotures->map(fn (ClotureCaisse $cloture): array => [$cloture->date_cloture->toDateString(), $cloture->numero, $cloture->total_ttc])->all(),
        );
        $this->assertSame([$clotures[0]->id, $clotures[1]->id], [$venteDu8->fresh()->cloture_caisse_id, $venteDu9->fresh()->cloture_caisse_id]);
    }

    /**
     * R5 : un vendeur qui n'a pas fermé sa caisse personnelle ne bloque
     * jamais la clôture. Le bouton reste actif, la fenêtre nomme la caisse
     * qu'elle fermera d'office, et la clôture la ferme, avec la mention
     * « Fermée automatiquement » ; la notification le dit.
     */
    public function test_le_bouton_reste_actif_et_la_fenetre_nomme_les_caisses_qui_seront_fermees_automatiquement(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $vendeur->forceFill(['name' => 'Paul Martin'])->save();
        $this->actingAs($vendeur);
        $this->vendreUnTicket();
        $this->actingAs($this->proprietaireDe($magasin));
        $this->travelTo('2026-10-09 19:00:00');

        Livewire::test(ListClotureCaisses::class)
            ->assertActionEnabled('cloturer')
            ->mountAction('cloturer')
            ->assertSee('Caisses personnelles encore ouvertes, qui seront fermées automatiquement : Paul Martin.')
            ->callMountedAction()
            ->assertNotified(Notification::make()->title('Journée du 09/10/2026 clôturée')->body('Caisses personnelles fermées automatiquement : Paul Martin.')->success());

        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame(
            [$vendeur->id, ModeFermetureCaisse::Automatique, null, 1, '12.00', '2026-10-09 19:00:00'],
            [$releve->vendeur_id, $releve->mode_fermeture, $releve->fermee_par, $releve->nombre_ventes, $releve->total_ttc, $releve->jusqu_au->format('Y-m-d H:i:s')],
        );
        $this->assertSame(1, ClotureCaisse::query()->count());
    }

    /**
     * Un membre aux cases de l'ancien comptable de magasin encaisse sans
     * vendre : la clôture de la journée ferme d'office sa caisse, avec ses
     * 12,00 € en espèces, sans qu'il ait à la fermer lui-même.
     */
    public function test_la_cloture_ferme_d_office_la_caisse_du_comptable_qui_encaisse(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));
        $comptable = $this->membre($magasin, RoleMagasin::Vendeur, self::casesDeLAncienComptable());
        $comptable->forceFill(['name' => 'Claire Compta'])->save();
        $this->actingAs($comptable);
        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $ticket->client, [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 12,
            'recu_le' => today()->toDateString(),
        ], [['facture_id' => $ticket->id, 'montant' => 12]]);
        $this->actingAs($this->proprietaireDe($magasin));

        Livewire::test(ListClotureCaisses::class)
            ->assertActionEnabled('cloturer')
            ->mountAction('cloturer')
            ->assertSee('Caisses personnelles encore ouvertes, qui seront fermées automatiquement : Claire Compta.')
            ->callMountedAction();

        $releve = ClotureCaissePersonnelle::query()->where('vendeur_id', $comptable->id)->sole();
        $this->assertSame([ModeFermetureCaisse::Automatique, 0, ['especes' => '12.00']], [$releve->mode_fermeture, $releve->nombre_ventes, $releve->encaissements]);
        $this->assertSame(1, ClotureCaisse::query()->count());
    }

    /**
     * Un ticket Z se produit à partir de la première vente : avant elle, il
     * n'y a aucune journée à clôturer.
     */
    public function test_le_bouton_est_absent_tant_que_le_magasin_n_a_rien_vendu(): void
    {
        $this->ouvrirLeMagasin();

        Livewire::test(ListClotureCaisses::class)
            ->assertActionHidden('cloturer');
    }

    public function test_le_bouton_disparait_une_fois_la_journee_cloturee(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->travelTo('2026-10-09 10:00:00');
        $this->emettreFactureParticulier(Client::factory()->create());
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));

        Livewire::test(ListClotureCaisses::class)
            ->callAction('cloturer');

        Livewire::test(ListClotureCaisses::class)
            ->assertActionHidden('cloturer');
    }

    /**
     * La fenêtre ouverte avant minuit et confirmée après ne clôture jamais
     * la journée qui commence : la journée visée est celle de l'ouverture
     * de la page, déjà clôturée par la commande de minuit.
     */
    public function test_une_confirmation_apres_minuit_ne_cloture_jamais_la_journee_qui_commence(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->travelTo('2026-10-09 10:00:00');
        $this->emettreFactureParticulier(Client::factory()->create());
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));
        $this->travelTo('2026-10-09 23:59:00');
        $page = Livewire::test(ListClotureCaisses::class)->mountAction('cloturer');
        $this->travelTo('2026-10-10 00:00:00');
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        app(TenantContext::class)->set($magasin);
        $this->travelTo('2026-10-10 00:01:00');

        $page->callMountedAction();

        $this->assertSame(['2026-10-09'], ClotureCaisse::query()->get()->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture->toDateString())->all());
    }

    public function test_le_bouton_est_absent_pour_un_vendeur_sans_l_autorisation_de_cloturer(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->vendreUnTicket();
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));
        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLOTURE_CAISSE_VIEW]));

        Livewire::test(ListClotureCaisses::class)
            ->assertActionHidden('cloturer');
    }

    /**
     * Le propriétaire coche à un vendeur l'autorisation de clôturer la
     * journée : le bouton lui apparaît.
     */
    public function test_le_bouton_apparait_au_vendeur_a_qui_le_proprietaire_coche_l_autorisation(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->vendreUnTicket();
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));
        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER]));

        Livewire::test(ListClotureCaisses::class)
            ->assertActionVisible('cloturer');
    }

    public function test_la_page_de_detail_affiche_les_factures_agregees(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $facture = $this->emettreFactureParticulier(Client::factory()->create());
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $cloture = ClotureCaisse::query()->sole();

        Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])
            ->assertSee($facture->numero);
    }

    public function test_la_page_de_detail_affiche_les_avoirs_deduits(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $client = Client::factory()->create();
        $avoir = $this->emettreAvoir($client, $this->emettreFactureParticulier($client));
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $cloture = ClotureCaisse::query()->sole();

        Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])
            ->assertSee('Avoirs déduits')
            ->assertSee($avoir->numero)
            ->assertSee('-60,00');
    }

    /**
     * Un membre à qui le propriétaire a délégué la clôture de la journée
     * ne voit que les clôtures qu'il a lui-même déclenchées, pas celles
     * d'un autre membre -- même confidentialité que les factures.
     */
    public function test_un_vendeur_ne_voit_que_les_clotures_quil_a_lui_meme_declenchees(): void
    {
        $this->travelTo('2026-10-08 10:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER]);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER]);
        $this->vendreUnTicket();
        app(FermerSaCaisseAction::class)->handle($proprietaire, $proprietaire);
        $this->travelTo('2026-10-08 19:00:00');
        $this->actingAs($vendeurA);
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');
        $this->travelTo('2026-10-09 19:00:00');
        $this->actingAs($vendeurB);
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $clotureA = ClotureCaisse::query()->where('cree_par', $vendeurA->id)->sole();
        $clotureB = ClotureCaisse::query()->where('cree_par', $vendeurB->id)->sole();

        Livewire::test(ListClotureCaisses::class)
            ->assertCanSeeTableRecords([$clotureB])
            ->assertCanNotSeeTableRecords([$clotureA]);

        $this->actingAs($proprietaire);
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 0, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
        ]));

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, $facture->date_emission);

        $this->assertSame(['76.67', '13.33', '90.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
        $this->assertSame([
            ['taux_tva' => '20.00', 'montant_ht' => 66.67, 'montant_tva' => 13.33, 'montant_ttc' => 80.0],
            ['taux_tva' => '0.00', 'montant_ht' => 10.0, 'montant_tva' => 0.0, 'montant_ttc' => 10.0],
        ], $cloture->ventilation_tva);

        Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('TVA par taux')
            ->assertSee('Biens et services')
            ->assertSee('66,67')
            ->assertSee('76,67');
    }
}
