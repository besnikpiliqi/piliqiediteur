<?php

namespace Tests\Feature\Facturation;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Un produit n'a qu'une ligne d'un ticket, d'une facture, d'un devis ou
 * d'une facture d'achat, et un service qu'une ligne par prix et par taux
 * (décision de l'éditeur du 26/10/2026, série 9, règle 5) : le domaine
 * refuse ce que l'écran ne doit jamais envoyer, d'où que viennent les
 * lignes. Seul un service vendu à deux prix ou à deux taux a deux lignes.
 */
class LignesUniquesTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-27 10:00:00'));
        $this->magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $this->dansLeMagasin($this->magasin, self::responsableDeTest($this->magasin));
    }

    private function cable(): Produit
    {
        return Produit::factory()->create(['nom' => 'Câble', 'prix_vente_ttc' => '10.00', 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * Deux lignes du même produit, même l'une remisée et l'autre au prix de
     * la fiche, sont refusées : une remise ne fait jamais une seconde
     * ligne. Rien n'est enregistré.
     */
    public function test_une_facture_avec_deux_lignes_du_meme_produit_est_refusee_meme_a_des_conditions_differentes(): void
    {
        $cable = $this->cable();

        $this->assertThrows(
            fn () => app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
                ['produit_id' => $cable->id, 'quantite' => 1, 'remise_pourcentage' => '10'],
                ['produit_id' => $cable->id, 'quantite' => 1],
            ]),
            InvalidArgumentException::class,
            '« Câble » figure sur deux lignes : un produit n\'a qu\'une ligne, faites-en une seule de la quantité totale.',
        );

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Un brouillon de facture ou un devis modifié pour porter deux fois le
     * même produit est refusé, et garde ses lignes.
     */
    public function test_un_brouillon_et_un_devis_modifies_avec_deux_lignes_du_meme_produit_sont_refuses(): void
    {
        $cable = $this->cable();
        $client = Client::factory()->create();
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $cable->id, 'quantite' => 1]]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $cable->id, 'quantite' => 1]]);
        $enDouble = [['produit_id' => $cable->id, 'quantite' => 1], ['produit_id' => $cable->id, 'quantite' => 2]];

        $this->assertThrows(fn () => app(ModifierFactureAction::class)->handle(self::auteur(), $brouillon, $client, $enDouble), InvalidArgumentException::class, 'figure sur deux lignes');
        $this->assertThrows(fn () => app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $client, $enDouble), InvalidArgumentException::class, 'figure sur deux lignes');

        $this->assertSame([1], $brouillon->fresh()->lignes()->pluck('quantite')->all());
        $this->assertSame([1], $devis->fresh()->lignes()->pluck('quantite')->all());
    }

    /**
     * Un service au même prix et au même taux sur deux lignes est refusé,
     * remise ou non ; le même service à deux prix (un service sans prix de
     * référence, « Réparation » à 60,00 € puis à 80,00 €) ou à deux taux
     * garde ses deux lignes.
     */
    public function test_un_service_n_a_qu_une_ligne_par_prix_et_par_taux(): void
    {
        $reparation = Service::factory()->create(['nom' => 'Réparation']);
        $client = Client::factory()->create();

        $this->assertThrows(
            fn () => app(CreerDevisAction::class)->handle(self::auteur(), $client, [
                ['service_id' => $reparation->id, 'quantite' => 1, 'prix_unitaire_ttc' => '60.00', 'taux_tva' => '20'],
                ['service_id' => $reparation->id, 'quantite' => 1, 'prix_unitaire_ttc' => '60.00', 'taux_tva' => '20', 'remise_pourcentage' => '5'],
            ]),
            InvalidArgumentException::class,
            '« Réparation » figure sur deux lignes au même prix et au même taux : faites-en une seule ligne de la quantité totale.',
        );
        $this->assertSame(0, Devis::query()->count());

        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, [
            ['service_id' => $reparation->id, 'quantite' => 1, 'prix_unitaire_ttc' => '60.00', 'taux_tva' => '20'],
            ['service_id' => $reparation->id, 'quantite' => 1, 'prix_unitaire_ttc' => '80.00', 'taux_tva' => '20'],
            ['service_id' => $reparation->id, 'quantite' => 1, 'prix_unitaire_ttc' => '60.00', 'taux_tva' => '10'],
        ]);

        $this->assertSame([['60.00', '20.00'], ['80.00', '20.00'], ['60.00', '10.00']], $devis->lignes()->orderBy('ordre')->get()->map(fn (object $ligne): array => [$ligne->prix_unitaire_ttc, $ligne->taux_tva])->all());
    }

    /**
     * Une vente à la caisse refusée pour une ligne en double ne consomme
     * pas son jeton (R3) : le même panier, une fois ses lignes réunies,
     * passe avec le même jeton.
     */
    public function test_une_vente_a_la_caisse_refusee_pour_une_ligne_en_double_ne_consomme_pas_son_jeton(): void
    {
        $cable = $this->cable();
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $jeton = (string) Str::uuid();

        $this->assertThrows(
            fn () => self::vendreAuComptant(self::auteur(), $comptoir, [['produit_id' => $cable->id, 'quantite' => 1], ['produit_id' => $cable->id, 'quantite' => 1]], jeton: $jeton),
            InvalidArgumentException::class,
            'figure sur deux lignes',
        );
        $this->assertFalse(app(ActionsUniques::class)->aServi($jeton));

        $ticket = self::vendreAuComptant(self::auteur(), $comptoir, [['produit_id' => $cable->id, 'quantite' => 2]], jeton: $jeton);

        $this->assertSame(['16.67', '3.33', '20.00'], [$ticket->total_ht, $ticket->total_tva, $ticket->total_ttc]);
        $this->assertTrue(app(ActionsUniques::class)->aServi($jeton));
    }

    /**
     * Une ligne de 2 × 25,00 € à 20 % (41,67 € HT, 8,33 € de TVA) se rend
     * article par article comme deux lignes séparées l'auraient été :
     * 20,83 € HT et 4,17 € de TVA, puis le reste de la ligne, 20,84 € et
     * 4,16 €.
     */
    public function test_le_retour_article_par_article_d_une_ligne_de_deux_rend_les_montants_de_deux_lignes(): void
    {
        $tshirt = Produit::factory()->create(['nom' => 'T-shirt', 'prix_vente_ttc' => '25.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $client = Client::factory()->professionnel()->create();
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $tshirt->id, 'quantite' => 2]]));
        $ligne = $facture->lignes->sole();

        $premier = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ligne->id, 'quantite' => 1]]);
        $second = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ligne->id, 'quantite' => 1]]);

        $this->assertSame(['41.67', '8.33', '50.00'], [$ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc]);
        $this->assertSame(['20.83', '4.17', '25.00'], [$premier->total_ht, $premier->total_tva, $premier->total_ttc]);
        $this->assertSame(['20.84', '4.16', '25.00'], [$second->total_ht, $second->total_tva, $second->total_ttc]);
    }

    /**
     * Une facture d'achat n'a qu'une ligne par produit, même quand le
     * fournisseur le facture à deux prix (10 câbles à 4,00 € HT et 2
     * offerts) : deux lignes du même produit sont refusées, « 12 » écrit en
     * texte comme en nombre ; une ligne de 12 câbles au prix moyen passe.
     */
    public function test_une_facture_d_achat_avec_deux_lignes_du_meme_produit_est_refusee(): void
    {
        $cable = $this->cable();
        $fournisseur = Fournisseur::factory()->create();

        $this->assertThrows(
            fn () => app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
                ['produit_id' => $cable->id, 'quantite' => 10, 'prix_unitaire_ht' => '4.00'],
                ['produit_id' => (string) $cable->id, 'quantite' => 2, 'prix_unitaire_ht' => '0.00'],
            ], ['date_facture' => '2026-10-26']),
            InvalidArgumentException::class,
            '« Câble » figure sur deux lignes : une facture d\'achat n\'a qu\'une ligne par produit. Faites-en une seule ligne de la quantité totale, au prix moyen si le fournisseur le facture à deux prix.',
        );
        $this->assertSame(0, FactureAchat::query()->count());

        $achat = app(CreerFactureAchatAction::class)->handle(self::auteur(), $fournisseur, [
            ['produit_id' => $cable->id, 'quantite' => 12, 'prix_unitaire_ht' => '3.333333'],
        ], ['date_facture' => '2026-10-26']);

        $this->assertSame('40.00', $achat->total_ht);
    }

    /**
     * Un produit d'un autre magasin, même portant l'identifiant d'une autre
     * ligne en double, n'est jamais lu : la ligne est introuvable, avant
     * toute comparaison.
     */
    public function test_un_produit_d_un_autre_magasin_n_est_jamais_compare_ni_vendu(): void
    {
        $autreMagasin = $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $cableAilleurs = null;
        app(TenantContext::class)->runAs($autreMagasin, function () use (&$cableAilleurs): void {
            $cableAilleurs = $this->cable();
        });
        $this->dansLeMagasin($this->magasin, self::responsableDeTest($this->magasin));

        $this->assertThrows(
            fn () => app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
                ['produit_id' => $cableAilleurs->id, 'quantite' => 1],
                ['produit_id' => $cableAilleurs->id, 'quantite' => 1],
            ]),
            ModelNotFoundException::class,
        );
        $this->assertSame(0, Facture::query()->count());
    }
}
