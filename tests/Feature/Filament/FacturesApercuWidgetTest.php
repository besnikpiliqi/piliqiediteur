<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Widgets\FacturesApercu;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B17 : le chiffre d'affaires du tableau de bord est net des avoirs, en HT
 * avec le TTC à côté ; B16 : sans l'autorisation de voir les factures de
 * toute l'équipe, il devient « Mes ventes ». Les ventes sont des tickets à
 * des particuliers, calculés article par article.
 */
class FacturesApercuWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_widget_additionne_le_chiffre_daffaires_de_la_periode_en_ht(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00'));
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20]);
        foreach (range(1, 2) as $_) {
            Livewire::test(CreateFacture::class)->fillForm([
                'client_id' => $client->id,
                'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            ])->call('create');
        }
        foreach (Facture::query()->where('client_id', $client->id)->get() as $facture) {
            Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);
        }

        $widget = Livewire::test(FacturesApercu::class);

        $widget->assertSee('Chiffre d\'affaires')
            ->assertSeeInOrder(['Cette semaine', '200,00 € HT', '240,00 € TTC · 2 factures', 'Ce mois-ci', '200,00 € HT', 'Cette année', '200,00 € HT']);
    }

    public function test_un_brouillon_ne_compte_pas_dans_le_chiffre_daffaires(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20]);
        app(CreerFactureAction::class)->handle(Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $widget = Livewire::test(FacturesApercu::class);

        $widget->assertSee('0,00 € HT')
            ->assertSee('0,00 € TTC · 0 facture');
    }

    /**
     * Les périodes se lisent à Paris : une vente émise le lundi 12 octobre
     * à 0 h 30 à Paris (22 h 30 UTC le dimanche) compte dans la semaine en
     * cours, comme dans le mois et l'année.
     */
    public function test_une_vente_du_lundi_apres_minuit_a_paris_compte_dans_la_semaine(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-11 22:30:00', 'UTC'));
        app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));

        $widget = Livewire::test(FacturesApercu::class);

        $widget->assertSeeInOrder(['Cette semaine', '100,00 € HT', '120,00 € TTC · 1 facture', 'Ce mois-ci'])
            ->assertDontSee('0 facture');
    }

    /**
     * Le vendeur A vend F1 (1 x 120,00 € = 100,00 € HT) ; le propriétaire
     * vend F2 (2 x 40,00 € = 66,66 € HT + 13,34 € de TVA), puis reprend un
     * article de F2 par un avoir (33,33 € HT, 6,67 € de TVA, 40,00 €) : le
     * propriétaire lit 100,00 + 66,66 - 33,33 = 133,33 € HT.
     */
    public function test_le_chiffre_d_affaires_du_magasin_est_net_des_avoirs(): void
    {
        ['magasin' => $magasin, 'proprietaire' => $proprietaire] = $this->ventesDuVendeurEtDuProprietaire();
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $widget = Livewire::test(FacturesApercu::class);

        $widget->assertSee('Chiffre d\'affaires')
            ->assertDontSee('Mes ventes')
            ->assertSeeInOrder(['Ce mois-ci', '133,33 € HT', '160,00 € TTC · 2 factures, 1 avoir', 'Cette année']);
    }

    /**
     * Sans l'autorisation de voir les factures de toute l'équipe, le
     * vendeur A ne lit que ses ventes : F1, que l'avoir sur F2 ne
     * concerne pas.
     */
    public function test_un_vendeur_ne_lit_que_ses_ventes(): void
    {
        ['magasin' => $magasin, 'vendeur' => $vendeur] = $this->ventesDuVendeurEtDuProprietaire();
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $widget = Livewire::test(FacturesApercu::class);

        $widget->assertSee('Mes ventes')
            ->assertDontSee('Chiffre d\'affaires')
            ->assertSeeInOrder(['Ce mois-ci', '100,00 € HT', '120,00 € TTC · 1 facture', 'Cette année'])
            ->assertDontSee('avoir');
    }

    /**
     * Un avoir du propriétaire sur la vente du vendeur réduit les ventes du
     * vendeur, quel que soit son auteur : l'avoir total de F1 ramène le
     * vendeur à 0,00 € HT, et le magasin à F2 moins ses deux avoirs.
     */
    public function test_l_avoir_du_proprietaire_sur_la_vente_d_un_vendeur_reduit_les_ventes_du_vendeur(): void
    {
        ['magasin' => $magasin, 'proprietaire' => $proprietaire, 'vendeur' => $vendeur, 'f1' => $f1] = $this->ventesDuVendeurEtDuProprietaire();
        $this->actingAs($proprietaire);
        app(EnregistrerAvoirAction::class)->handle($f1->client, [['ligne_facture_id' => $f1->lignes->sole()->id, 'quantite' => 1]], 'Retour complet');

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        $widgetDuVendeur = Livewire::test(FacturesApercu::class);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $widgetDuMagasin = Livewire::test(FacturesApercu::class);

        $widgetDuVendeur->assertSeeInOrder(['Ce mois-ci', '0,00 € HT', '0,00 € TTC · 1 facture, 1 avoir', 'Cette année']);
        $widgetDuMagasin->assertSeeInOrder(['Ce mois-ci', '33,33 € HT', '40,00 € TTC · 2 factures, 2 avoirs', 'Cette année']);
    }

    /**
     * Un avoir se déduit le jour de son émission : celui du lundi 2
     * novembre sur une vente du 20 octobre compte en novembre (semaine et
     * mois en négatif), et l'année retombe à zéro.
     */
    public function test_un_avoir_compte_dans_la_periode_de_son_emission(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00'));
        $vente = $this->emettreUnTicket($proprietaire, Client::factory()->create(), 120.0, 1);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
        app(EnregistrerAvoirAction::class)->handle($vente->client, [['ligne_facture_id' => $vente->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        Filament::setTenant($magasin);

        $widget = Livewire::test(FacturesApercu::class);

        $widget->assertSeeInOrder([
            'Cette semaine', '-100,00 € HT', '-120,00 € TTC · 0 facture, 1 avoir',
            'Ce mois-ci', '-100,00 € HT', '-120,00 € TTC · 0 facture, 1 avoir',
            'Cette année', '0,00 € HT', '0,00 € TTC · 1 facture, 1 avoir',
        ]);
    }

    public function test_les_ventes_d_un_autre_magasin_ne_comptent_jamais(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00'));
        $proprietaireB = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], $proprietaireB);
        $this->emettreUnTicket($proprietaireB, Client::factory()->create(), 120.0, 1);
        $proprietaireA = User::factory()->create();
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaireA);
        $this->actingAs($proprietaireA);
        Filament::setTenant($magasinA);

        $widget = Livewire::test(FacturesApercu::class);

        $widget->assertSeeInOrder(['Cette année', '0,00 € HT', '0,00 € TTC · 0 facture'])
            ->assertDontSee('100,00 €');
    }

    public function test_un_membre_sans_acces_aux_factures_ne_voit_pas_le_widget(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLIENT_VIEW]);
        $this->actingAs($membre);
        Filament::setTenant($magasin);

        $this->assertFalse(FacturesApercu::canView());
    }

    /**
     * F1 du vendeur A (1 x 120,00 €), F2 du propriétaire (2 x 40,00 €) et
     * l'avoir d'un article de F2, le 14 octobre 2026.
     *
     * @return array{magasin: Magasin, proprietaire: User, vendeur: User, f1: Facture, f2: Facture, avoir: Avoir}
     */
    private function ventesDuVendeurEtDuProprietaire(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00'));
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $client = Client::factory()->create();
        $f1 = $this->emettreUnTicket($vendeur, $client, 120.0, 1);
        $f2 = $this->emettreUnTicket($proprietaire, $client, 40.0, 2);
        $avoir = app(EnregistrerAvoirAction::class)->handle($client, [['ligne_facture_id' => $f2->lignes->sole()->id, 'quantite' => 1]], 'Retour d\'un article');

        return ['magasin' => $magasin, 'proprietaire' => $proprietaire, 'vendeur' => $vendeur, 'f1' => $f1, 'f2' => $f2, 'avoir' => $avoir];
    }

    /**
     * Un ticket émis par $vendeur dans le magasin actif.
     */
    private function emettreUnTicket(User $vendeur, Client $client, float $prixTtc, int $quantite): Facture
    {
        $this->actingAs($vendeur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => $quantite],
        ]));
    }
}
