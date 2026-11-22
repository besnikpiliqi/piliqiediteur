<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Filament\App\Resources\AvoirFournisseurResource\Pages\ListAvoirsFournisseur;
use App\Filament\App\Resources\AvoirResource\Pages\ListAvoirs;
use App\Filament\App\Resources\ClientResource\Pages\ListClients;
use App\Filament\App\Resources\DevisResource\Pages\ListDevis;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FournisseurResource\Pages\ListFournisseurs;
use App\Filament\App\Resources\PaiementResource\Pages\ListPaiements;
use App\Filament\App\Resources\ProduitResource\Pages\ListProduits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Chaque liste de documents ou de fiches s'affiche par défaut du plus
 * récent au plus ancien, les derniers au début (décision de l'éditeur du
 * 17/11/2026), jamais par numéro en texte ni par ordre de création des
 * lignes : le tri par défaut de chaque liste est celui de la date du
 * document, ou de la création de la fiche, puis de l'identifiant
 * décroissant, tant que la personne n'a choisi aucune colonne. Les listes
 * triées par nom (services, magasins, membres, collaborateurs) et les
 * relevés, journaux et clôtures déjà décroissants gardent leur ordre.
 */
class ListesDuPlusRecentAuPlusAncienTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La fin de la clause « order by » de chaque liste.
     *
     * @return array<string, array{class-string, string}>
     */
    public static function listes(): array
    {
        return [
            'factures' => [ListFactures::class, 'order by coalesce("factures"."date_emission", "factures"."created_at") desc, "factures"."id" desc'],
            'devis' => [ListDevis::class, 'order by coalesce("devis"."date_emission", "devis"."created_at") desc, "devis"."id" desc'],
            'avoirs' => [ListAvoirs::class, 'order by coalesce("avoirs"."date_emission", "avoirs"."created_at") desc, "avoirs"."id" desc'],
            'avoirs fournisseur' => [ListAvoirsFournisseur::class, 'order by coalesce("avoirs_fournisseur"."date_emission", "avoirs_fournisseur"."created_at") desc, "avoirs_fournisseur"."id" desc'],
            'paiements' => [ListPaiements::class, 'order by "paiements"."recu_le" desc, "paiements"."created_at" desc, "paiements"."id" desc'],
            'clients' => [ListClients::class, 'order by "clients"."created_at" desc, "clients"."id" desc'],
            'fournisseurs' => [ListFournisseurs::class, 'order by "fournisseurs"."created_at" desc, "fournisseurs"."id" desc'],
            'produits' => [ListProduits::class, 'order by "produits"."created_at" desc, "produits"."id" desc'],
            'équipe du magasin' => [ListEquipeDuMagasin::class, 'order by "affectations"."created_at" desc, "affectations"."id" desc'],
        ];
    }

    /**
     * @param  class-string  $page
     */
    #[DataProvider('listes')]
    public function test_chaque_liste_se_trie_par_defaut_du_plus_recent_au_plus_ancien(string $page, string $ordre): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        $sql = Livewire::test($page)->instance()->getFilteredSortedTableQuery()->toRawSql();

        $this->assertStringContainsString($ordre, $sql);
    }

    /**
     * Avec des fiches créées dans l'ordre inverse de leur date : la plus
     * récente est en tête, quel que soit son identifiant.
     */
    public function test_les_fiches_et_les_brouillons_les_plus_recents_sont_en_tete_quel_que_soit_leur_identifiant(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        $recents = [
            ListClients::class => [Client::factory()->create(['nom' => 'Récent', 'created_at' => self::aParis('2026-11-10 10:00:00')]), Client::factory()->create(['nom' => 'Ancien', 'created_at' => self::aParis('2026-01-10 10:00:00')])],
            ListFournisseurs::class => [Fournisseur::factory()->create(['nom' => 'Récent', 'created_at' => self::aParis('2026-11-10 10:00:00')]), Fournisseur::factory()->create(['nom' => 'Ancien', 'created_at' => self::aParis('2026-01-10 10:00:00')])],
            ListProduits::class => [Produit::factory()->create(['nom' => 'Récent', 'created_at' => self::aParis('2026-11-10 10:00:00')]), Produit::factory()->create(['nom' => 'Ancien', 'created_at' => self::aParis('2026-01-10 10:00:00')])],
        ];

        $client = Client::factory()->create(['created_at' => self::aParis('2026-01-01 10:00:00')]);
        $recents[ListFactures::class] = [Facture::factory()->create(['client_id' => $client->id, 'created_at' => self::aParis('2026-11-10 10:00:00')]), Facture::factory()->create(['client_id' => $client->id, 'created_at' => self::aParis('2026-01-10 10:00:00')])];
        $recents[ListDevis::class] = [Devis::factory()->create(['client_id' => $client->id, 'created_at' => self::aParis('2026-11-10 10:00:00')]), Devis::factory()->create(['client_id' => $client->id, 'created_at' => self::aParis('2026-01-10 10:00:00')])];

        foreach ($recents as $page => $enregistrements) {
            Livewire::test($page)->assertCanSeeTableRecords($enregistrements, inOrder: true);
        }
    }
}
