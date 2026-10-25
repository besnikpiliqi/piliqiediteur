<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
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
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ListClotureCaisses;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ViewClotureCaisse;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
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
     * Un magasin dont le responsable de test, qui y vend, est connecté, sur
     * son panneau.
     */
    private function ouvrirLeMagasin(): Magasin
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->responsableDeTest($magasin));
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Le soir, après la dernière vente de son responsable, le propriétaire
     * clôture lui-même la journée en cours depuis le panneau ; à minuit, la
     * commande ne clôture rien de plus pour cette journée.
     */
    public function test_le_proprietaire_cloture_la_journee_du_jour_depuis_le_panneau(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $facture = $this->emettreFactureParticulier(Client::factory()->create()); // 60 € TTC
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));
        $this->actingAs($this->proprietaireDe($magasin));
        $this->travelTo(self::aParis('2026-10-09 19:00:00'));

        Livewire::test(ListClotureCaisses::class)
            ->assertActionHasLabel('cloturer', 'Clôturer la journée du 09/10/2026')
            ->callAction('cloturer')
            ->assertNotified('Journée du 09/10/2026 clôturée');

        $cloture = ClotureCaisse::query()->sole();
        $this->assertSame(['2026-10-09', 'Z-MABOUT-2026-000001', 1, '60.00'], [$cloture->date_cloture, $cloture->numero, $cloture->nombre_ventes, $cloture->total_ttc]);
        $this->assertSame($cloture->id, $facture->fresh()->cloture_caisse_id);

        $this->travelTo(self::aParis('2026-10-10 00:00:00'));
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->emettreFactureParticulier(Client::factory()->create()); // 50 € HT, TVA 20 % => 60 € TTC
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));

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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->emettreFactureParticulier(Client::factory()->create());
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));
        PanierCaisse::factory()->create(['user_id' => $this->responsableDeTest($magasin)->id]);

        Livewire::test(ListClotureCaisses::class)
            ->mountAction('cloturer')
            ->assertSee('1 vente(s) en cours à la caisse, jamais encaissée(s), sera(ont) abandonnée(s) et inscrite(s) au journal des événements.');
    }

    public function test_la_modale_annonce_les_avoirs_deduits_et_le_total_net(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $client = Client::factory()->create();
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->emettreFactureParticulier($client); // 60 € TTC
        $this->emettreAvoir($client, $this->emettreFactureParticulier($client));
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));

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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));

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
        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $venteDu8 = $this->emettreFactureParticulier($client);
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $venteDu9 = $this->emettreFactureParticulier($client);
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));
        $this->travelTo(self::aParis('2026-10-09 19:00:00'));

        Livewire::test(ListClotureCaisses::class)
            ->mountAction('cloturer')
            ->assertSee("Journées clôturées, dans l'ordre : 08/10/2026 (oubliée) et 09/10/2026.")
            ->callMountedAction()
            ->assertNotified('Journées du 08/10/2026 et 09/10/2026 clôturées');

        $clotures = ClotureCaisse::query()->orderBy('date_cloture')->get();
        $this->assertSame(
            [['2026-10-08', 'Z-MABOUT-2026-000001', '60.00'], ['2026-10-09', 'Z-MABOUT-2026-000002', '60.00']],
            $clotures->map(fn (ClotureCaisse $cloture): array => [$cloture->date_cloture, $cloture->numero, $cloture->total_ttc])->all(),
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirLeMagasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $vendeur->forceFill(['name' => 'Paul Martin'])->save();
        $this->actingAs($vendeur);
        $this->vendreUnTicket();
        $this->actingAs($this->proprietaireDe($magasin));
        $this->travelTo(self::aParis('2026-10-09 19:00:00'));

        Livewire::test(ListClotureCaisses::class)
            ->assertActionEnabled('cloturer')
            ->mountAction('cloturer')
            ->assertSee('Caisses personnelles encore ouvertes, qui seront fermées automatiquement : Paul Martin.')
            ->callMountedAction()
            ->assertNotified(Notification::make()->title('Journée du 09/10/2026 clôturée')->body('Caisses personnelles fermées automatiquement : Paul Martin.')->success());

        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame(
            [$vendeur->id, ModeFermetureCaisse::Automatique, null, 1, '12.00', self::aParis('2026-10-09 19:00:00')->getTimestamp()],
            [$releve->vendeur_id, $releve->mode_fermeture, $releve->fermee_par, $releve->nombre_ventes, $releve->total_ttc, $releve->jusqu_au->getTimestamp()],
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));
        $comptable = $this->membre($magasin, RoleMagasin::Vendeur, self::casesDeLAncienComptable());
        $comptable->forceFill(['name' => 'Claire Compta'])->save();
        $this->actingAs($comptable);
        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $ticket->client, [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 12,
            'recu_le' => Calendrier::deParis()->aujourdhui(),
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->emettreFactureParticulier(Client::factory()->create());
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));

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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $this->emettreFactureParticulier(Client::factory()->create());
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));
        $this->travelTo(self::aParis('2026-10-09 23:59:00'));
        $page = Livewire::test(ListClotureCaisses::class)->mountAction('cloturer');
        $this->travelTo(self::aParis('2026-10-10 00:00:00'));
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        app(TenantContext::class)->set($magasin);
        $this->travelTo(self::aParis('2026-10-10 00:01:00'));

        $page->callMountedAction();

        $this->assertSame(['2026-10-09'], ClotureCaisse::query()->get()->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture)->all());
    }

    public function test_le_bouton_est_absent_pour_un_vendeur_sans_l_autorisation_de_cloturer(): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->vendreUnTicket();
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));
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
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));
        $this->actingAs($this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER]));

        Livewire::test(ListClotureCaisses::class)
            ->assertActionVisible('cloturer');
    }

    public function test_la_page_de_detail_affiche_les_factures_agregees(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirLeMagasin();
        $facture = $this->emettreFactureParticulier(Client::factory()->create());
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $cloture = ClotureCaisse::query()->sole();

        Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])
            ->assertSee($facture->numero);
    }

    public function test_la_page_de_detail_affiche_les_avoirs_deduits(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirLeMagasin();
        $client = Client::factory()->create();
        $avoir = $this->emettreAvoir($client, $this->emettreFactureParticulier($client));
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));
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
        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $magasin = $this->ouvrirLeMagasin();
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER]);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER]);
        $this->vendreUnTicket();
        app(FermerSaCaisseAction::class)->handle($this->responsableDeTest($magasin), $this->responsableDeTest($magasin));
        $this->travelTo(self::aParis('2026-10-08 19:00:00'));
        $this->actingAs($vendeurA);
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');
        $this->travelTo(self::aParis('2026-10-09 19:00:00'));
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 0, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
        ]));

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, Calendrier::deParis()->jourDe($facture->date_emission));

        $this->assertSame(['76.67', '13.33', '90.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
        $this->assertSame([
            ['taux_tva' => '20.00', 'montant_ht' => 66.67, 'montant_tva' => 13.33, 'montant_ttc' => 80.0],
            ['taux_tva' => '0.00', 'montant_ht' => 10.0, 'montant_tva' => 0.0, 'montant_ttc' => 10.0],
        ], $cloture->ventilation_tva);

        Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('TVA par taux')
            ->assertSee('Aperçu de l\'e-reporting (non transmis)')
            ->assertSee('66,67')
            ->assertSee('76,67');
    }

    /**
     * Point 26 : la fiche montre en français ce que l'e-reporting déclarera
     * pour la journée chiffrée du point 26 (trois tickets, une facture à un
     * professionnel, le retour d'un câble) : les totaux par catégorie et
     * par taux, leur contrôle contre le net de la clôture, l'encaissement
     * de la réparation et le statut, sans aucun code ni nom de champ de la
     * plateforme.
     */
    public function test_la_fiche_montre_l_apercu_de_l_e_reporting_en_francais(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $magasin = $this->ouvrirLeMagasin();
        $particulier = Client::factory()->create();
        $cable = Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $livre = Produit::factory()->create(['prix_vente_ttc' => 10.55, 'taux_tva' => 5.5, 'suivi_stock' => false]);
        $deuxCables = self::vendreAuComptant(self::auteur(), $particulier, [['produit_id' => $cable->id, 'quantite' => 2]], ModePaiement::Especes);
        self::vendreAuComptant(self::auteur(), $particulier, [
            ['produit_id' => $livre->id, 'quantite' => 1],
            ['service_id' => Service::factory()->create()->id, 'prix_unitaire_ttc' => 60, 'taux_tva' => 20, 'quantite' => 1],
        ], ModePaiement::CarteBancaire);
        self::vendreAuComptant(self::auteur(), $particulier, [['produit_id' => $cable->id, 'quantite' => 1]], ModePaiement::CarteBancaire);
        $this->completerIdentiteLegale($magasin);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $cable->id, 'quantite' => 1]]));
        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '50.00', 'recu_le' => '2026-10-05'], [['facture_id' => $facture->id, 'montant' => '50.00']]);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $particulier, [['ligne_facture_id' => $deuxCables->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $this->travelTo(self::aParis('2026-10-05 19:00:00'));
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, '2026-10-05');

        $fiche = Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()]);

        $fiche->assertSeeInOrder([
            'Aperçu de l\'e-reporting (non transmis)',
            '05/10/2026', 'Livraisons de biens', '20 %', '83,33 €', '16,67 €',
            '05/10/2026', 'Livraisons de biens', '5,5 %', '10,00 €', '0,55 €',
            '05/10/2026', 'Prestations de services', '20 %', '50,00 €', '10,00 €',
            '143,33 € HT, 27,22 € de TVA',
            'Égal au net des ventes aux particuliers de la clôture.',
            'Encaissements des prestations de services', '05/10/2026', '20 %', '60,00 €',
            'Non transmis : plateforme pas encore raccordée (étape 2).',
        ]);
        // Les codes des catégories se cherchent comme mots entiers : un
        // identifiant de composant Livewire, fait de lettres et de
        // chiffres, peut en contenir un, jamais isolé.
        $this->assertDoesNotMatchRegularExpression('/\b(TLB1|TPS1|TNT1)\b/', $fiche->html());
        $fiche->assertDontSee(['LivraisonsDeBiens', 'tax_exclusive_amount', 'category_code', 'taxable_amount']);
    }

    /**
     * Un ticket de ce montant à 20 % au client, réglé à la caisse dans ce
     * mode par l'utilisateur connecté.
     */
    private function vendreAuComptantUnTicketDe(float $prixTtc, ModePaiement $mode, ?Client $client = null): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return self::vendreAuComptant(self::auteur(), $client ?? Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], $mode);
    }

    /**
     * Points 15 et 24 : la fiche montre les ventes, la somme des avoirs, le
     * net, les encaissements par mode avec leur total, le crédit d'avoir
     * utilisé à part, et les grands totaux. Un ticket de 60,00 € en
     * espèces, un de 30,00 € par carte, rendu.
     */
    public function test_la_fiche_affiche_les_encaissements_par_mode_et_la_somme_des_avoirs(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirLeMagasin();
        $this->vendreAuComptantUnTicketDe(60, ModePaiement::Especes);
        $client = Client::factory()->create();
        $this->emettreAvoir($client, $this->vendreAuComptantUnTicketDe(30, ModePaiement::CarteBancaire, $client));
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui());

        $fiche = Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()]);

        $fiche->assertSeeInOrder(['Ventes aux particuliers (tickets)', '2', '75,00 €', '15,00 €', '90,00 €'])
            ->assertSeeInOrder(['Avoirs sur des tickets', '1', '-25,00 €', '-5,00 €', '-30,00 €'])
            ->assertSeeInOrder(['Net des ventes aux particuliers (base de l\'e-reporting)', '50,00 €', '10,00 €', '60,00 €'])
            ->assertSeeInOrder(['Espèces', '1', '0', '60,00 €', 'Carte bancaire', '1', '0', '30,00 €', 'Chèque', '0', '0', '0,00 €'])
            ->assertSeeInOrder(['Total encaissé', '90,00 €'])
            ->assertSeeInOrder(['Crédit d\'avoir utilisé (n\'entre pas dans les encaissements)', '0,00 €'])
            ->assertSee('Ventes et encaissements diffèrent quand un client garde un crédit d\'avoir ou paie plus tard.')
            ->assertSeeInOrder(['Grand total perpétuel réel', '60,00 €', 'Grand total perpétuel absolu', '120,00 €']);
        Livewire::test(ListClotureCaisses::class)
            ->assertTableColumnStateSet('encaisse', '90,00 €', $cloture);
    }

    /**
     * Point 25 : chaque numéro de ticket et d'avoir de la fiche ouvre la
     * fiche de son document.
     */
    public function test_les_numeros_des_tickets_et_des_avoirs_ouvrent_leur_fiche(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirLeMagasin();
        $client = Client::factory()->create();
        $ticket = $this->vendreAuComptantUnTicketDe(30, ModePaiement::Especes, $client);
        $avoir = $this->emettreAvoir($client, $ticket);
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui());

        Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])
            ->assertSeeHtml('href="'.FactureResource::getUrl('view', ['record' => $ticket]).'"')
            ->assertSeeHtml('href="'.AvoirResource::getUrl('view', ['record' => $avoir]).'"')
            ->assertSee($ticket->numero)
            ->assertSee($avoir->numero);
    }

    /**
     * Règle « Vendeurs » : un vendeur qui clôture la journée mais ne voit
     * que ses ventes voit dans sa clôture les totaux du magasin et le
     * détail de ses seuls tickets ; ceux d'un autre vendeur, et l'avoir
     * qu'il ne peut pas ouvrir, ne se comptent qu'en nombre et en total,
     * sans numéro ni client.
     */
    public function test_un_vendeur_qui_ne_voit_que_ses_ventes_ne_voit_dans_la_cloture_que_le_detail_de_ses_documents(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirLeMagasin();
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER], ['name' => 'Claire Vendeuse']);
        $collegue = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Luc Collègue']);
        $this->actingAs($vendeur);
        $sonTicket = $this->vendreAuComptantUnTicketDe(12, ModePaiement::Especes, Client::factory()->create(['nom' => 'Jeanne Cliente']));
        $this->actingAs($collegue);
        $clientDuCollegue = Client::factory()->create(['nom' => 'Paul Martin']);
        $ticketDuCollegue = $this->vendreAuComptantUnTicketDe(30, ModePaiement::CarteBancaire, $clientDuCollegue);
        $this->actingAs($proprietaire);
        $avoir = $this->emettreAvoir($clientDuCollegue, $ticketDuCollegue);
        $this->actingAs($vendeur);
        $cloture = app(CloturerCaisseAction::class)->handle($vendeur, $magasin, Calendrier::deParis()->aujourdhui());

        $fiche = Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()]);

        $fiche->assertSeeHtml('href="'.FactureResource::getUrl('view', ['record' => $sonTicket]).'"')
            ->assertSeeInOrder(['Autres tickets de l\'équipe', '1 ticket, 30,00 € TTC'])
            ->assertSeeInOrder(['Autres avoirs de l\'équipe', '1 avoir, -30,00 € TTC'])
            ->assertSeeInOrder(['Net des ventes aux particuliers (base de l\'e-reporting)', '10,00 €', '2,00 €', '12,00 €'])
            ->assertDontSee($ticketDuCollegue->numero)
            ->assertDontSee($avoir->numero)
            ->assertDontSee('Paul Martin');
    }
}
