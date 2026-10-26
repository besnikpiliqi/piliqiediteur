<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Actions\TrouverOuCreerServiceAction;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Livewire\SelecteurDeProduits;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Pages\ParametresPrixDeVente;
use App\Filament\App\Resources\AvoirFournisseurResource;
use App\Filament\App\Resources\DevisResource\Pages\CreateDevis;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FournisseurResource;
use App\Filament\App\Resources\InventaireOuvertureResource;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\ProduitResource\Widgets\ValeurStockApercu;
use App\Filament\App\Resources\ServiceResource;
use App\Filament\App\Resources\ServiceResource\Pages\ManageServices;
use App\Filament\App\Widgets\StockNegatifApercu;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Models\User;
use Closure;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les écrans d'une société de services, qui n'a pas d'articles (voir
 * Societe::aDesArticles()) : ni produits, ni achats, ni fournisseurs, ni
 * inventaire d'ouverture, ni prix de vente, ni aperçus du stock, dans la
 * navigation comme par leur adresse ; la caisse, les devis et les
 * factures n'ajoutent que des services ; seules les natures d'opération
 * qui ne portent pas sur des biens existent ; un service n'a pas de frais
 * accessoires ; l'état de la vérification ne parle pas d'achats. Un
 * commerce garde tout.
 */
class EcransSelonLeTypeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: TypeDActivite, 1: Closure(self, Magasin): User, 2: bool}>
     */
    public static function navigations(): array
    {
        return [
            'propriétaire d\'une société de services' => [TypeDActivite::Services, static fn (self $test, Magasin $magasin): User => $test->proprietaireDe($magasin), false],
            'responsable d\'une société de services' => [TypeDActivite::Services, static fn (self $test, Magasin $magasin): User => $test->responsable($magasin), false],
            'vendeur d\'une société de services' => [TypeDActivite::Services, static fn (self $test, Magasin $magasin): User => $test->membre($magasin, RoleMagasin::Vendeur), false],
            'propriétaire d\'un commerce' => [TypeDActivite::Commerce, static fn (self $test, Magasin $magasin): User => $test->proprietaireDe($magasin), true],
        ];
    }

    /**
     * La navigation d'une société de services n'a aucun écran des articles,
     * pour le propriétaire, un responsable et un vendeur, qui y gardent les
     * services ; celle d'un commerce les a.
     *
     * @param  Closure(self, Magasin): User  $compte
     */
    #[DataProvider('navigations')]
    public function test_sans_articles_la_navigation_n_a_ni_produits_ni_achats_ni_fournisseurs_ni_inventaire_ni_prix_de_vente(TypeDActivite $type, Closure $compte, bool $avecLesArticles): void
    {
        $magasin = $this->ouvrirUneSociete(['type' => $type]);
        $this->dansLeMagasin($magasin, $compte($this, $magasin));

        $page = $this->get(Dashboard::getUrl(panel: 'app', tenant: $magasin))->assertOk();

        foreach (self::adressesDesEcransDArticles($magasin) as $adresse) {
            $avecLesArticles ? $page->assertSee($adresse, false) : $page->assertDontSee($adresse, false);
        }
        $page->assertSee(ServiceResource::getUrl('index', panel: 'app', tenant: $magasin), false);
    }

    /**
     * L'adresse de chaque écran des articles répond 403 dans une société de
     * services, même au propriétaire.
     */
    public function test_sans_articles_les_adresses_des_ecrans_d_articles_repondent_403(): void
    {
        $services = $this->ouvrirUneSociete(['type' => TypeDActivite::Services]);
        $this->dansLeMagasin($services, $this->proprietaireDe($services));

        foreach ([...self::adressesDesEcransDArticles($services), FactureAchatResource::getUrl('create', panel: 'app', tenant: $services)] as $adresse) {
            $this->get($adresse)->assertForbidden();
        }
        $this->get(ServiceResource::getUrl('index', panel: 'app', tenant: $services))->assertOk();
    }

    public function test_sans_articles_les_apercus_de_stock_n_apparaissent_pas(): void
    {
        $services = $this->ouvrirUneSociete(['type' => TypeDActivite::Services]);
        $this->dansLeMagasin($services, $this->proprietaireDe($services));

        $this->assertSame([false, false], [StockNegatifApercu::canView(), ValeurStockApercu::canView()]);

        $commerce = $this->ouvrirUneSociete(['nom' => 'Boutique']);
        $this->dansLeMagasin($commerce, $this->proprietaireDe($commerce));

        $this->assertTrue(ValeurStockApercu::canView());
    }

    /**
     * La caisse d'une société de services s'intitule « Services », sans
     * douchette ni tableau des produits, que la fenêtre refuse même ouverte
     * par une requête ; les devis et les factures n'ajoutent que des
     * services, sans ligne de produit vide au départ ; un service s'y vend,
     * s'encaisse et se clôture.
     */
    public function test_sans_articles_la_caisse_les_devis_et_les_factures_n_ajoutent_que_des_services(): void
    {
        $this->travelTo(self::aParis('2026-11-16 10:00:00'));
        $services = $this->ouvrirUneSociete(['type' => TypeDActivite::Services]);
        $responsable = $this->responsable($services);
        $conseil = Service::factory()->auPrixDe('72.00')->create(['nom' => 'Heure de conseil']);
        $this->dansLeMagasin($services, $responsable);

        Livewire::test(Caisse::class)
            ->assertSeeText(['Services', 'Ajouter un service'])
            ->assertDontSeeText(['Produits et services', 'Ajouter un produit', 'Scanner ou taper une référence']);
        foreach ([CreateFacture::class, CreateDevis::class] as $page) {
            Livewire::test($page)
                ->assertSeeText('Ajouter un service')
                ->assertDontSeeText(['Ajouter un produit', 'Scanner ou taper une référence'])
                ->assertSet('data.lignes', []);
        }
        Livewire::test(SelecteurDeProduits::class)->assertForbidden();

        $ticket = self::vendreAuComptant($responsable, Client::query()->where('est_comptoir', true)->sole(), [['service_id' => $conseil->id, 'quantite' => 2]], ModePaiement::CarteBancaire);
        $cloture = app(CloturerCaisseAction::class)->handle($responsable, $services, '2026-11-16');

        $this->assertSame(['144.00', 'payee'], [$ticket->total_ttc, $ticket->statut->value]);
        $this->assertSame([1, '120.00', '24.00', '144.00'], [$cloture->nombre_ventes, $cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
    }

    /**
     * Une société de services ne propose ni n'accepte au brouillon que les
     * natures qui ne portent pas sur des biens : la vente en France pour un
     * particulier, dont le choix ne s'affiche donc pas, et, pour un
     * professionnel, les prestations à l'étranger.
     */
    public function test_sans_articles_seules_les_natures_sans_biens_sont_proposees_et_acceptees_au_brouillon(): void
    {
        $services = $this->ouvrirUneSociete(['type' => TypeDActivite::Services]);
        $responsable = $this->responsable($services);
        $conseil = Service::factory()->auPrixDe('72.00')->create(['nom' => 'Heure de conseil']);
        $particulier = Client::factory()->create(['type_client' => TypeClient::Particulier]);
        $professionnel = Client::factory()->professionnel()->create();

        $this->assertSame([NatureOperation::VenteEnFrance], NatureOperation::proposees(false, false, TypeDActivite::Services));
        $this->assertSame([NatureOperation::VenteEnFrance, NatureOperation::PrestationUeAutoliquidation, NatureOperation::PrestationHorsUe], NatureOperation::proposees(true, false, TypeDActivite::Services));
        $this->assertSame(NatureOperation::cases(), NatureOperation::proposees(true, false, TypeDActivite::Commerce));

        foreach ([NatureOperation::ExportHorsUe, NatureOperation::LivraisonDom, NatureOperation::LivraisonIntracommunautaire] as $nature) {
            $this->assertThrows(
                fn () => app(CreerFactureAction::class)->handle($responsable, $professionnel, [['service_id' => $conseil->id, 'quantite' => 1]], ['nature_operation' => $nature]),
                LogicException::class,
                "Impossible d'enregistrer en « {$nature->getLabel()} » : votre société, inscrite comme « Services, sans vente d'articles », ne vend pas de biens. Choisissez une autre nature.",
            );
        }
        $devis = app(CreerDevisAction::class)->handle($responsable, $professionnel, [['service_id' => $conseil->id, 'quantite' => 1]], ['nature_operation' => NatureOperation::PrestationHorsUe]);
        $this->assertSame(NatureOperation::PrestationHorsUe, $devis->nature_operation);

        $this->dansLeMagasin($services, $responsable);
        Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $particulier->id])
            ->assertFormFieldIsHidden('nature_operation')
            ->fillForm(['client_id' => $professionnel->id])
            ->assertFormFieldIsVisible('nature_operation');
    }

    /**
     * Dans une société de services, le formulaire d'un service n'a pas la
     * case des frais accessoires, qui suivent des biens, et la marque
     * s'enregistre fausse quel que soit l'écran qui crée ou modifie le
     * service ; un commerce garde la case.
     */
    public function test_sans_articles_le_service_n_a_pas_la_case_frais_accessoires(): void
    {
        $services = $this->ouvrirUneSociete(['type' => TypeDActivite::Services]);
        $proprietaire = $this->proprietaireDe($services);
        $this->dansLeMagasin($services, $proprietaire);

        Livewire::test(ManageServices::class)->mountAction('create')->assertDontSeeText('Frais de livraison ou accessoires');
        $cree = Service::query()->create(['nom' => 'Déplacement', 'frais_accessoires' => true]);
        $tape = app(TrouverOuCreerServiceAction::class)->handle($proprietaire, 'Atelier');
        $tape->update(['frais_accessoires' => true]);

        $this->assertSame([false, false], [$cree->fresh()->frais_accessoires, $tape->fresh()->frais_accessoires]);

        $commerce = $this->ouvrirUneSociete(['nom' => 'Boutique']);
        $this->dansLeMagasin($commerce, $this->proprietaireDe($commerce));

        Livewire::test(ManageServices::class)->mountAction('create')->assertSeeText('Frais de livraison ou accessoires');
        $this->assertTrue(Service::query()->create(['nom' => 'Port', 'frais_accessoires' => true])->fresh()->frais_accessoires);
    }

    /**
     * L'état de la vérification d'une société de services qui attend son
     * code ne lui parle pas d'achats ; celui d'un commerce, si.
     */
    public function test_sans_articles_l_etat_de_la_verification_ne_parle_pas_d_achats(): void
    {
        $etats = [];

        foreach ([TypeDActivite::Services, TypeDActivite::Commerce] as $type) {
            $societe = $this->inscrireUneSociete(type: $type);
            $this->dansLEspaceDeLaSociete($societe, $societe->proprietaire()->firstOrFail());
            $etats[$type->value] = Livewire::test(TableauDeBord::class)->instance()->etatDeLaVerification();
        }

        $this->assertStringNotContainsString('achats', $etats[TypeDActivite::Services->value]);
        $this->assertStringContainsString('catalogue, équipe et paramètres', $etats[TypeDActivite::Services->value]);
        $this->assertStringContainsString('catalogue, équipe, paramètres et achats', $etats[TypeDActivite::Commerce->value]);
    }

    /**
     * Les adresses des écrans des articles du magasin : produits,
     * fournisseurs, factures d'achat, avoirs fournisseur, inventaires
     * d'ouverture, prix de vente.
     *
     * @return list<string>
     */
    private static function adressesDesEcransDArticles(Magasin $magasin): array
    {
        app(TenantContext::class)->set($magasin);

        return [
            ProduitResource::getUrl('index', panel: 'app', tenant: $magasin),
            FournisseurResource::getUrl('index', panel: 'app', tenant: $magasin),
            FactureAchatResource::getUrl('index', panel: 'app', tenant: $magasin),
            AvoirFournisseurResource::getUrl('index', panel: 'app', tenant: $magasin),
            InventaireOuvertureResource::getUrl('index', panel: 'app', tenant: $magasin),
            ParametresPrixDeVente::getUrl(panel: 'app', tenant: $magasin),
        ];
    }
}
