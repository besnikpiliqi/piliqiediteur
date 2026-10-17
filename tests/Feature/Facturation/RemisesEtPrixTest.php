<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Actions\TrouverOuCreerServiceAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\DerogationsDePrix;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\ServiceResource\Pages\ManageServices;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Remises et prix (R2, Q1) : accorder une remise, fixer le prix d'un
 * service (sa référence au catalogue comme celui d'une ligne) et mettre
 * sur une ligne de produit un autre prix que celui de sa fiche exigent
 * l'autorisation « accorder des remises et fixer le prix d'un service »,
 * contrôlée par le domaine sur le compte relu, quel que soit l'écran ;
 * une ligne dérogatoire reprise telle quelle garde l'accord déjà donné.
 */
class RemisesEtPrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_vendeur_n_accorde_aucune_remise_meme_par_requete_forgee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $cable = $this->produit('Câble USB', '12.00');

        $this->assertThrows(
            fn () => app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 1, 'remise_pourcentage' => 10]]),
            AuthorizationException::class,
            'Accorder une remise de 10 % sur « Câble USB » '.DerogationsDePrix::AUTORISATION.'.',
        );
        $this->assertSame(0, Facture::query()->count());

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => Client::factory()->create()->id,
                'lignes' => [['produit_id' => $cable->id, 'quantite' => 1, 'prix_unitaire_ttc' => '12.00', 'remise_pourcentage' => 25]],
            ])
            ->assertFormFieldIsDisabled('lignes.0.remise_pourcentage')
            ->assertFormFieldIsDisabled('lignes.0.prix_unitaire_ttc')
            ->call('create')
            ->assertNotified('Accorder une remise de 25 % sur « Câble USB » '.DerogationsDePrix::AUTORISATION.'.');
        $this->assertSame(0, Facture::query()->count());
    }

    public function test_un_responsable_accorde_une_remise(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $cable = $this->produit('Câble USB', '12.00');

        $facture = app(CreerFactureAction::class)->handle($responsable, Client::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 1, 'remise_pourcentage' => 10]]);

        $this->assertSame(['10.00', '10.80'], [$facture->lignes->sole()->remise_pourcentage, $facture->total_ttc]);
    }

    /**
     * Q1 (a) : sans l'autorisation, une ligne de produit prend le PU TTC
     * de sa fiche ; un autre prix est refusé, et le propriétaire le
     * saisit.
     */
    public function test_seul_un_membre_autorise_vend_un_produit_a_un_autre_prix_que_celui_de_sa_fiche(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $cable = $this->produit('Câble USB', '12.00');

        $auPrixDeLaFiche = app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 1]]);
        $this->assertThrows(
            fn () => app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 1, 'prix_unitaire_ttc' => '9.00']]),
            AuthorizationException::class,
            'Vendre « Câble USB » à 9,00 € au lieu de 12,00 €, le prix de sa fiche, '.DerogationsDePrix::AUTORISATION.'.',
        );
        $auPrixDuProprietaire = app(CreerFactureAction::class)->handle($this->proprietaireDe($magasin), Client::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 1, 'prix_unitaire_ttc' => '9.00']]);

        $this->assertSame(['12.00', '9.00'], [$auPrixDeLaFiche->total_ttc, $auPrixDuProprietaire->total_ttc]);
        $this->assertSame(2, Facture::query()->count());
    }

    public function test_un_vendeur_vend_un_service_a_son_prix_de_reference_sans_pouvoir_le_changer(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $pose = Service::factory()->auPrixDe('30.00', '20')->create(['nom' => 'Pose de verre trempé']);

        $facture = app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), [['service_id' => $pose->id, 'quantite' => 1]]);

        $this->assertSame(['30.00', '20.00'], [$facture->lignes->sole()->prix_unitaire_ttc, $facture->lignes->sole()->taux_tva]);
        $this->assertThrows(
            fn () => app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), [['service_id' => $pose->id, 'quantite' => 1, 'prix_unitaire_ttc' => '25.00', 'taux_tva' => '20']]),
            AuthorizationException::class,
            'Vendre « Pose de verre trempé » à 25,00 € au lieu de 30,00 €, son prix de référence, '.DerogationsDePrix::AUTORISATION.'.',
        );
        $this->assertThrows(
            fn () => app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), [['service_id' => $pose->id, 'quantite' => 1, 'prix_unitaire_ttc' => '30.00', 'taux_tva' => '10']]),
            AuthorizationException::class,
            'Vendre « Pose de verre trempé » à 10 % de TVA au lieu de 20 %, le taux de son prix de référence, '.DerogationsDePrix::AUTORISATION.'.',
        );
        $this->assertSame(1, Facture::query()->count());
    }

    public function test_un_service_sans_prix_de_reference_ne_se_vend_pas_par_un_vendeur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        Service::factory()->auPrixDe('15.00')->create(['nom' => 'Diagnostic']);
        $reparation = Service::factory()->create(['nom' => 'Réparation']);

        $this->assertThrows(
            fn () => app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), [['service_id' => $reparation->id, 'quantite' => 1]]),
            InvalidArgumentException::class,
            'Le prix du service « Réparation » n\'est pas fixé : demandez à un responsable.',
        );
        $this->assertThrows(
            fn () => app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), [['service_id' => $reparation->id, 'quantite' => 1, 'prix_unitaire_ttc' => '40.00', 'taux_tva' => '20']]),
            AuthorizationException::class,
            'Le prix du service « Réparation » n\'est pas fixé : demandez à un responsable.',
        );
        $this->assertSame(0, Facture::query()->count());
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        Livewire::test(Caisse::class)
            ->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')
            ->assertSee('Diagnostic')
            ->assertDontSee('Réparation');

        $facture = app(CreerFactureAction::class)->handle($this->responsable($magasin), Client::factory()->create(), [['service_id' => $reparation->id, 'quantite' => 1, 'prix_unitaire_ttc' => '40.00', 'taux_tva' => '20']]);

        $this->assertSame('40.00', $facture->total_ttc);
    }

    /**
     * Le prix de référence d'un service ne se fixe, dans le catalogue comme
     * à sa création depuis une vente, que par une personne autorisée : pour
     * un vendeur qui peut modifier le catalogue, le champ est désactivé, et
     * une valeur envoyée malgré tout ne s'enregistre pas.
     */
    public function test_seul_un_membre_autorise_fixe_le_prix_de_reference_d_un_service(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $catalogue = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::PRODUIT_CREATE, PermissionRegistry::PRODUIT_UPDATE]);
        $responsable = $this->responsable($magasin);
        $diagnostic = Service::factory()->create(['nom' => 'Diagnostic']);

        $this->actingAs($catalogue);
        Filament::setTenant($magasin);
        Livewire::test(ManageServices::class)
            ->mountTableAction('edit', $diagnostic)
            ->assertTableActionDataSet(['prix_ttc' => null])
            ->setTableActionData(['nom' => 'Diagnostic complet', 'prix_ttc' => '50.00', 'taux_tva' => '20'])
            ->callMountedTableAction();
        $this->assertSame(['Diagnostic complet', null, null], [$diagnostic->fresh()->nom, $diagnostic->fresh()->prix_ttc, $diagnostic->fresh()->taux_tva]);
        $this->assertThrows(fn () => app(TrouverOuCreerServiceAction::class)->handle($catalogue, 'Pose de verre trempé', '25.00', '20'), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame(0, Service::query()->where('nom', 'Pose de verre trempé')->count());

        $this->actingAs($responsable);
        Livewire::test(ManageServices::class)
            ->callTableAction('edit', $diagnostic, ['prix_ttc' => '50.00', 'taux_tva' => '20']);
        $pose = app(TrouverOuCreerServiceAction::class)->handle($responsable, 'Pose de verre trempé', '25.00', '20');

        $this->assertSame(['50.00', '20.00'], [$diagnostic->fresh()->prix_ttc, $diagnostic->fresh()->taux_tva]);
        $this->assertSame(['25.00', '20.00'], [$pose->prix_ttc, $pose->taux_tva]);
    }

    /**
     * Le devis remisé par un responsable, accepté, se convertit et
     * s'émet par un vendeur : la conversion recopie ses lignes telles
     * quelles, avec l'accord déjà donné.
     */
    public function test_convertir_un_devis_remise_par_un_responsable_reste_permis_au_vendeur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $cable = $this->produit('Câble USB', '12.00', suiviDuStock: false);
        $devis = app(CreerDevisAction::class)->handle($responsable, Client::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 2, 'remise_pourcentage' => 10]]);
        app(AccepterDevisAction::class)->handle($responsable, app(EmettreDevisAction::class)->handle($responsable, $devis));

        $facture = app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureDepuisDevisAction::class)->handle($vendeur, $devis->fresh()));

        $this->assertSame([StatutFacture::Emise, '10.00', '21.60'], [$facture->statut, $facture->lignes->sole()->remise_pourcentage, $facture->total_ttc]);
    }

    public function test_modifier_une_autre_ligne_d_un_brouillon_garde_la_remise_accordee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $cable = $this->produit('Câble USB', '12.00');
        $chargeur = $this->produit('Chargeur', '20.00');
        $client = Client::factory()->create();
        $brouillon = app(CreerFactureAction::class)->handle($responsable, $client, [
            ['produit_id' => $cable->id, 'quantite' => 1, 'remise_pourcentage' => 10],
            ['produit_id' => $chargeur->id, 'quantite' => 1],
        ]);

        $modifie = app(ModifierFactureAction::class)->handle($vendeur, $brouillon, $client, [
            ...$this->lignesTellesQuelles($brouillon, 0),
            ['produit_id' => $chargeur->id, 'quantite' => 3],
        ]);

        $this->assertSame([['10.00', 1], ['0.00', 3]], $modifie->lignes->sortBy('ordre')->map(fn (LigneFacture $ligne): array => [$ligne->remise_pourcentage, $ligne->quantite])->values()->all());
    }

    public function test_changer_le_produit_ou_la_quantite_d_une_ligne_remisee_exige_l_autorisation(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $cable = $this->produit('Câble USB', '12.00');
        $chargeur = $this->produit('Chargeur', '20.00');
        $client = Client::factory()->create();
        $brouillon = app(CreerFactureAction::class)->handle($responsable, $client, [['produit_id' => $cable->id, 'quantite' => 1, 'remise_pourcentage' => 10]]);
        $devis = app(CreerDevisAction::class)->handle($responsable, $client, [['produit_id' => $cable->id, 'quantite' => 1, 'remise_pourcentage' => 10]]);

        $this->assertThrows(fn () => app(ModifierFactureAction::class)->handle($vendeur, $brouillon, $client, [['produit_id' => $cable->id, 'quantite' => 2, 'remise_pourcentage' => 10]]), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierFactureAction::class)->handle($vendeur, $brouillon, $client, [['produit_id' => $chargeur->id, 'quantite' => 1, 'remise_pourcentage' => 10]]), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierDevisAction::class)->handle($vendeur, $devis, $client, [['produit_id' => $cable->id, 'quantite' => 5, 'remise_pourcentage' => 10]]), AuthorizationException::class);

        $this->assertSame([[(int) $cable->id, 1, '10.00']], $brouillon->fresh()->lignes->map(fn (LigneFacture $ligne): array => [(int) $ligne->produit_id, $ligne->quantite, $ligne->remise_pourcentage])->all());
        $this->assertSame(5, app(ModifierDevisAction::class)->handle($responsable, $devis, $client, [['produit_id' => $cable->id, 'quantite' => 5, 'remise_pourcentage' => 10]])->lignes->sole()->quantite);
    }

    public function test_le_proprietaire_et_le_responsable_ont_toujours_remises_et_prix_le_modele_de_l_administrateur_la_precoche_et_aucun_autre_modele(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $this->assertTrue($this->proprietaireDe($magasin)->can(PermissionRegistry::VENTE_REMISES_ET_PRIX));
        $this->assertTrue($this->responsable($magasin)->can(PermissionRegistry::VENTE_REMISES_ET_PRIX));
        $this->assertTrue($this->administrateur($magasin)->can(PermissionRegistry::VENTE_REMISES_ET_PRIX));
        $this->assertContains(PermissionRegistry::VENTE_REMISES_ET_PRIX, PermissionRegistry::modeleDeLAdministrateur());
        $this->assertNotContains(PermissionRegistry::VENTE_REMISES_ET_PRIX, RoleMagasin::Vendeur->permissionsParDefaut());
        $this->assertNotContains(PermissionRegistry::VENTE_REMISES_ET_PRIX, RoleMagasin::Observateur->permissionsParDefaut());
        $this->assertFalse($this->membre($magasin, RoleMagasin::Vendeur)->can(PermissionRegistry::VENTE_REMISES_ET_PRIX));
        $this->assertFalse($this->membre($magasin, RoleMagasin::Observateur)->can(PermissionRegistry::VENTE_REMISES_ET_PRIX));
        $this->assertFalse($this->expertComptable($magasin)->can(PermissionRegistry::VENTE_REMISES_ET_PRIX));
    }

    public function test_un_vendeur_a_qui_le_responsable_coche_remises_et_prix_accorde_une_remise(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $cable = $this->produit('Câble USB', '12.00');
        app(ModifierMembreAction::class)->handle($responsable, $vendeur, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::VENTE_REMISES_ET_PRIX]]]);
        app(TenantContext::class)->set(Magasin::query()->findOrFail($magasin->id));

        $facture = app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 1, 'remise_pourcentage' => 10]]);

        $this->assertSame('10.80', $facture->total_ttc);
    }

    private function produit(string $nom, string $prixTtc, bool $suiviDuStock = true): Produit
    {
        return Produit::factory()->create(['nom' => $nom, 'prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => $suiviDuStock]);
    }

    /**
     * La ligne du brouillon à cette place, telle que son formulaire la
     * rouvre et la renvoie.
     *
     * @return list<array<string, mixed>>
     */
    private function lignesTellesQuelles(Facture $facture, int $ordre): array
    {
        $ligne = $facture->lignes->firstWhere('ordre', $ordre);

        return [[
            'produit_id' => $ligne->produit_id,
            'quantite' => $ligne->quantite,
            'prix_unitaire_ttc' => $ligne->prix_unitaire_ttc,
            'remise_pourcentage' => $ligne->remise_pourcentage,
        ]];
    }
}
