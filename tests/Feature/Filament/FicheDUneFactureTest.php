<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La fiche d'une facture ou d'un ticket selon le compte qui l'ouvre :
 * la section « Paiements reçus » ne se montre qu'à qui voit les paiements
 * de ce document (constat C8, réponse de l'éditeur à T41) ; un brouillon
 * se modifie et s'émet depuis sa fiche, avec les mêmes droits que depuis
 * la liste (constat C9, réponse de l'éditeur à T42).
 */
class FicheDUneFactureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Une facture de ce vendeur au client « Hôtel des Pins », émise pour
     * une bougie à 18,00 € TTC, réglée en partie (10,00 € en espèces) par le
     * responsable du magasin, ou laissée en brouillon.
     */
    private function factureDe(Magasin $magasin, User $vendeur, bool $emise = true): Facture
    {
        return app(TenantContext::class)->runAs($magasin, function () use ($magasin, $vendeur, $emise): Facture {
            $client = Client::query()->where('nom', 'Hôtel des Pins')->first() ?? Client::factory()->professionnel()->create(['nom' => 'Hôtel des Pins']);
            $produit = Produit::query()->where('reference', 'BOUGIE')->first() ?? Produit::factory()->create(['nom' => 'Bougie', 'reference' => 'BOUGIE', 'prix_vente_ttc' => 18, 'taux_tva' => 20, 'suivi_stock' => false]);
            $facture = app(CreerFactureAction::class)->handle($vendeur, $client, [['produit_id' => $produit->id, 'quantite' => 1]]);

            if (! $emise) {
                return $facture;
            }

            $facture = app(EmettreFactureAction::class)->handle($vendeur, $facture);
            app(EnregistrerPaiementAction::class)->handle($this->responsable($magasin), $client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '10.00', 'recu_le' => '2026-03-10'], [['facture_id' => $facture->id, 'montant' => '10.00']]);

            return $facture->fresh();
        });
    }

    /**
     * Un vendeur avec les autorisations du modèle de son rôle, sauf
     * « Paiements : voir ».
     */
    private function vendeurSansLesPaiements(Magasin $magasin): User
    {
        return $this->membre($magasin, RoleMagasin::Vendeur, array_values(array_diff(
            RoleMagasin::Vendeur->permissionsParDefaut($magasin->societe->type_activite),
            [PermissionRegistry::PAIEMENT_VIEW],
        )), ['name' => 'Victor Durand']);
    }

    public function test_un_vendeur_sans_le_droit_de_voir_les_paiements_ne_voit_pas_la_section_mais_voit_le_paye_et_le_restant_du(): void
    {
        $this->travelTo(self::aParis('2026-03-10 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
        $this->completerIdentiteLegale($magasin);
        $vendeur = $this->vendeurSansLesPaiements($magasin);
        $facture = $this->factureDe($magasin, $vendeur);
        $this->dansLeMagasin($magasin, $vendeur);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertOk()
            ->assertSee('Payé')
            ->assertSee('Restant dû')
            ->assertSee('8,00 €')
            ->assertDontSee('Paiements reçus')
            ->assertDontSee('Reçu le')
            ->assertDontSee('Reçu (PDF)');
    }

    public function test_le_meme_vendeur_avec_le_droit_de_voir_les_paiements_voit_la_section(): void
    {
        $this->travelTo(self::aParis('2026-03-10 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
        $this->completerIdentiteLegale($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, identite: ['name' => 'Victor Durand']);
        $facture = $this->factureDe($magasin, $vendeur);
        $this->dansLeMagasin($magasin, $vendeur);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSee('Paiements reçus')
            ->assertSee('Espèces')
            ->assertSee('Reçu le');
    }

    /**
     * Le propriétaire, un responsable du magasin et le collaborateur du
     * cabinet lié, à qui le client accorde par défaut la consultation des
     * paiements, voient la section.
     */
    public function test_le_proprietaire_le_responsable_et_le_cabinet_lie_voient_les_paiements_recus(): void
    {
        $this->travelTo(self::aParis('2026-03-10 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
        $this->completerIdentiteLegale($magasin);
        $facture = $this->factureDe($magasin, $this->membre($magasin, RoleMagasin::Vendeur));
        $collaborateur = $this->collaborateurDuCabinetLie($magasin);

        foreach ([$this->proprietaireDe($magasin), $this->responsable($magasin), $collaborateur] as $compte) {
            $this->dansLeMagasin($magasin, $compte);

            Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])->assertSee('Paiements reçus')->assertSee('Espèces');
        }
    }

    /**
     * Un brouillon se modifie et s'émet depuis sa fiche, pour qui le peut
     * depuis la liste : le responsable et le vendeur qui l'a fait ;
     * « Modifier » ouvre le formulaire du brouillon.
     */
    public function test_le_brouillon_se_modifie_et_s_emet_depuis_sa_fiche_pour_qui_le_peut(): void
    {
        $this->travelTo(self::aParis('2026-03-10 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
        $this->completerIdentiteLegale($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $brouillon = $this->factureDe($magasin, $vendeur, emise: false);

        foreach ([$this->responsable($magasin), $vendeur] as $compte) {
            $this->dansLeMagasin($magasin, $compte);

            Livewire::test(ViewFacture::class, ['record' => $brouillon->getRouteKey()])
                ->assertActionVisible('edit')
                ->assertActionHasUrl('edit', FactureResource::getUrl('edit', ['record' => $brouillon]))
                ->assertActionVisible('emettre');
        }

        Livewire::test(ViewFacture::class, ['record' => $brouillon->getRouteKey()])
            ->callAction('emettre')
            ->assertHasNoActionErrors()
            ->assertActionHidden('edit')
            ->assertActionHidden('emettre');

        $emise = $brouillon->fresh();
        $this->assertNotNull($emise->numero);
        Livewire::test(ViewFacture::class, ['record' => $emise->getRouteKey()])
            ->assertActionHidden('edit')
            ->assertActionHidden('emettre');
    }

    /**
     * Ni le propriétaire, qui ne vend pas dans un magasin réel, ni le
     * collaborateur du cabinet lié, ni un vendeur à qui la création des
     * factures n'est pas cochée ne voient « Modifier » ni « Émettre » sur la
     * fiche d'un brouillon qu'ils voient.
     */
    public function test_sans_le_droit_ni_modifier_ni_emettre_sur_la_fiche_du_brouillon(): void
    {
        $this->travelTo(self::aParis('2026-03-10 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
        $this->completerIdentiteLegale($magasin);
        $brouillon = $this->factureDe($magasin, $this->membre($magasin, RoleMagasin::Vendeur), emise: false);
        $lecteur = $this->membre($magasin, RoleMagasin::Observateur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES]);

        foreach ([$this->proprietaireDe($magasin), $this->collaborateurDuCabinetLie($magasin), $lecteur] as $compte) {
            $this->dansLeMagasin($magasin, $compte);

            Livewire::test(ViewFacture::class, ['record' => $brouillon->getRouteKey()])
                ->assertOk()
                ->assertActionHidden('edit')
                ->assertActionHidden('emettre')
                ->assertActionHidden('emettre_et_encaisser');
        }
    }
}
