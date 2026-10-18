<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\EReportingResource;
use App\Filament\App\Resources\EReportingResource\Pages\ListEReporting;
use App\Filament\App\Resources\EReportingResource\Pages\ViewEReporting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EReportingResourceTest extends TestCase
{
    use RefreshDatabase;

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
     * Ce jour-là, à 10 h, ces lignes vendues à la caisse en espèces, puis
     * la clôture de la journée à 19 h.
     *
     * @param  list<array<string, mixed>>  $lignes
     */
    private function journee(Magasin $magasin, string $jour, array $lignes): ClotureCaisse
    {
        $this->travelTo("{$jour} 10:00:00");

        if ($lignes !== []) {
            self::vendreAuComptant(self::auteur(), Client::factory()->create(), $lignes, ModePaiement::Especes);
        }

        $this->travelTo("{$jour} 19:00:00");

        return app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, $jour);
    }

    /**
     * Un écran de 12,00 € le 5, rien le 6 (clôture à zéro), une réparation
     * de 60,00 € le 7 : la page liste le 5 et le 7, chacun avec son aperçu
     * et son statut, jamais le 6.
     */
    public function test_la_liste_montre_chaque_journee_ayant_des_operations_avec_son_apercu(): void
    {
        $this->travelTo('2026-10-05 09:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $duCinq = $this->journee($magasin, '2026-10-05', [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]);
        $duSix = $this->journee($magasin, '2026-10-06', []);
        $duSept = $this->journee($magasin, '2026-10-07', [['service_id' => Service::factory()->create()->id, 'prix_unitaire_ttc' => 60, 'taux_tva' => 20, 'quantite' => 1]]);

        $liste = Livewire::test(ListEReporting::class);

        $liste->assertCanSeeTableRecords([$duSept, $duCinq], inOrder: true)
            ->assertCanNotSeeTableRecords([$duSix])
            ->assertSee('Livraisons de biens, 20 % : 10,00 € HT, 2,00 € de TVA')
            ->assertSee('Prestations de services, 20 % : 50,00 € HT, 10,00 € de TVA')
            ->assertSee('Prestations de services encaissées le 07/10/2026 à 20 % : 60,00 € (à confirmer)')
            ->assertSee('Non transmis : plateforme pas encore raccordée (étape 2).');
    }

    /**
     * La fiche d'une journée montre son aperçu complet et sa clôture.
     */
    public function test_la_fiche_d_une_journee_montre_son_apercu(): void
    {
        $this->travelTo('2026-10-05 09:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $cloture = $this->journee($magasin, '2026-10-05', [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]]);

        $fiche = Livewire::test(ViewEReporting::class, ['record' => $cloture->getRouteKey()]);

        $fiche->assertSee('E-reporting du 05/10/2026')
            ->assertSee($cloture->numero)
            ->assertSeeInOrder(['Aperçu de l\'e-reporting (non transmis)', 'Livraisons de biens', '20 %', '10,00 €', '2,00 €', 'Égal au net des ventes aux particuliers de la clôture.']);
    }

    /**
     * Un aperçu additionne les ventes de toute l'équipe : la page est
     * réservée à qui voit toutes les clôtures ; un vendeur qui ne voit que
     * les siennes n'y entre pas.
     */
    public function test_un_vendeur_qui_ne_voit_que_ses_clotures_n_a_pas_la_page(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER]);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $reponse = $this->get(EReportingResource::getUrl('index'));

        $reponse->assertForbidden();
    }
}
