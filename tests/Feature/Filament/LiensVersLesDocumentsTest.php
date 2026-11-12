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
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\AvoirResource\Pages\ListAvoirs;
use App\Filament\App\Resources\AvoirResource\Pages\ViewAvoir;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\App\Resources\PaiementResource\Pages\ListPaiements;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Constat C2 de l'éditeur, confirmé à T43 : tout numéro de document
 * affiché sur une fiche devient un lien vers la fiche de ce document pour
 * qui peut la voir ; pour les autres, il reste un texte simple, sans lien
 * qui mènerait à un refus ; un ticket est nommé « Ticket », une facture
 * « Facture ».
 */
class LiensVersLesDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    private User $responsable;

    private User $vendeur;

    private Facture $ticketDuResponsable;

    private Facture $ticketDuVendeur;

    private Avoir $avoir;

    /**
     * Le responsable émet un ticket resté dû à Jean Dupont ; le vendeur,
     * qui ne voit que ses ventes et peut faire un avoir, lui vend un ticket
     * réglé au comptant puis le crédite : le crédit de l'avoir, rien ne
     * restant dû sur le ticket du vendeur, règle le ticket du responsable.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-03-10 10:00:00'));
        $this->magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
        $this->responsable = $this->responsable($this->magasin);
        $this->vendeur = $this->membre($this->magasin, RoleMagasin::Vendeur, [
            ...RoleMagasin::Vendeur->permissionsParDefaut($this->magasin->societe->type_activite),
            PermissionRegistry::AVOIR_VIEW,
            PermissionRegistry::AVOIR_CREER,
        ]);

        app(TenantContext::class)->runAs($this->magasin, function (): void {
            $client = Client::factory()->create(['nom' => 'Jean Dupont']);
            $bougie = Produit::factory()->create(['nom' => 'Bougie', 'reference' => 'BOUGIE', 'prix_vente_ttc' => 18, 'taux_tva' => 20, 'suivi_stock' => false]);
            $this->ticketDuResponsable = app(EmettreFactureAction::class)->handle($this->responsable, app(CreerFactureAction::class)->handle($this->responsable, $client, [['produit_id' => $bougie->id, 'quantite' => 1]]));
            $this->ticketDuVendeur = self::vendreAuComptant($this->vendeur, $client, [['produit_id' => $bougie->id, 'quantite' => 1]]);
            $this->avoir = app(EnregistrerAvoirAction::class)->handle($this->vendeur, $client, [['ligne_facture_id' => $this->ticketDuVendeur->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        });
    }

    public function test_sur_la_fiche_de_l_avoir_chaque_document_est_un_lien_pour_qui_peut_le_voir(): void
    {
        $this->dansLeMagasin($this->magasin, $this->responsable);

        Livewire::test(ViewAvoir::class, ['record' => $this->avoir->getRouteKey()])
            ->assertSee('Crédit appliqué')
            ->assertSee('Documents crédités')
            ->assertSee(FactureResource::getUrl('view', ['record' => $this->ticketDuResponsable]))
            ->assertSee(FactureResource::getUrl('view', ['record' => $this->ticketDuVendeur]));
    }

    /**
     * Le vendeur voit son avoir et son ticket, en lien, mais pas le ticket
     * du responsable que le crédit a réglé : son numéro reste un texte, et
     * le document y est nommé « Ticket », jamais « Facture ».
     */
    public function test_un_vendeur_ne_recoit_pas_de_lien_vers_un_document_qu_il_ne_voit_pas(): void
    {
        $this->dansLeMagasin($this->magasin, $this->vendeur);

        $fiche = Livewire::test(ViewAvoir::class, ['record' => $this->avoir->getRouteKey()])
            ->assertSee($this->ticketDuResponsable->numero)
            ->assertDontSee(FactureResource::getUrl('view', ['record' => $this->ticketDuResponsable]))
            ->assertSee(FactureResource::getUrl('view', ['record' => $this->ticketDuVendeur]))
            ->assertSeeText('Ticket '.$this->ticketDuVendeur->numero);

        $this->assertStringNotContainsString('Facture</', $fiche->html());
    }

    /**
     * Sur la fiche d'un ticket, l'avoir qui l'a crédité mène à sa fiche
     * pour qui voit les avoirs ; un compte qui voit le ticket sans voir les
     * avoirs lit son numéro sans lien.
     */
    public function test_sur_la_fiche_du_ticket_l_avoir_est_un_lien_pour_qui_voit_les_avoirs(): void
    {
        $lecteur = $this->membre($this->magasin, RoleMagasin::Observateur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES]);

        $this->dansLeMagasin($this->magasin, $this->responsable);
        Livewire::test(ViewFacture::class, ['record' => $this->ticketDuResponsable->getRouteKey()])
            ->assertSee(AvoirResource::getUrl('view', ['record' => $this->avoir]));

        $this->dansLeMagasin($this->magasin, $lecteur);
        Livewire::test(ViewFacture::class, ['record' => $this->ticketDuResponsable->getRouteKey()])
            ->assertSee($this->avoir->numero)
            ->assertDontSee(AvoirResource::getUrl('view', ['record' => $this->avoir]));
    }

    /**
     * La fiche d'un document est titrée selon ce qu'il est : « Ticket … »
     * pour un ticket, « Facture … » pour une facture à un professionnel,
     * et, tant qu'il n'a pas de numéro, « Facture (brouillon) ».
     */
    public function test_la_fiche_d_un_document_est_titree_selon_ce_qu_il_est(): void
    {
        $this->completerIdentiteLegale($this->magasin);
        [$facture, $brouillon] = app(TenantContext::class)->runAs($this->magasin, function (): array {
            $hotel = Client::factory()->professionnel()->create(['nom' => 'Hôtel des Pins']);
            $lignes = [['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 1]];

            return [
                app(EmettreFactureAction::class)->handle($this->responsable, app(CreerFactureAction::class)->handle($this->responsable, $hotel, $lignes)),
                app(CreerFactureAction::class)->handle($this->responsable, $hotel, $lignes),
            ];
        });
        $this->dansLeMagasin($this->magasin, $this->responsable);

        $titres = array_map(
            fn (Facture $document): string => (string) Livewire::test(ViewFacture::class, ['record' => $document->getRouteKey()])->instance()->getTitle(),
            [$this->ticketDuResponsable, $facture, $brouillon],
        );

        $this->assertSame(['Ticket '.$this->ticketDuResponsable->numero, 'Facture '.$facture->numero, 'Facture (brouillon)'], $titres);
        $this->assertStringStartsWith('FAC-', (string) $facture->numero);
    }

    /**
     * La liste des avoirs titre leur colonne comme leur fiche : « Documents
     * crédités », un ticket n'étant pas une facture.
     */
    public function test_la_liste_des_avoirs_titre_ce_qu_ils_creditent_documents_credites(): void
    {
        $this->dansLeMagasin($this->magasin, $this->responsable);

        Livewire::test(ListAvoirs::class)
            ->assertCanSeeTableRecords([$this->avoir])
            ->assertSee('Documents crédités')
            ->assertDontSee('Factures créditées')
            ->assertSee($this->ticketDuVendeur->numero);
    }

    /**
     * La liste des paiements, dont une rangée n'ouvre aucune fiche, donne
     * en lien chaque document réglé.
     */
    public function test_la_liste_des_paiements_donne_chaque_document_regle_en_lien(): void
    {
        $this->dansLeMagasin($this->magasin, $this->responsable);

        Livewire::test(ListPaiements::class)
            ->assertSee('Documents réglés')
            ->assertSee(FactureResource::getUrl('view', ['record' => $this->ticketDuVendeur]));
    }
}
