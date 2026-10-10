<?php

namespace Tests\Feature\Recherche;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les recherches ignorent la casse et les accents, en base, par la même
 * fonction sur les deux moteurs : lower(unaccent()) sous PostgreSQL, son
 * équivalent enregistré sur la connexion sous SQLite. Ces tests passent
 * donc tels quels sur l'un et l'autre.
 */
class RechercheSansAccentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function termesDeRecherche(): array
    {
        return [
            'sans accent, en minuscules' => ['creme'],
            'accentué, en majuscules' => ['CRÈME'],
            'accent différent' => ['crême'],
        ];
    }

    #[DataProvider('termesDeRecherche')]
    public function test_la_recherche_ignore_la_casse_et_les_accents(string $terme): void
    {
        $cremeBrulee = Client::factory()->create(['nom' => 'Crème Brûlée SARL']);
        $cremerie = Client::factory()->create(['nom' => 'CRÉMERIE DU PARC']);
        Client::factory()->create(['nom' => 'Boulangerie Martin']);

        $trouves = RechercheSansAccents::appliquer(Client::query(), $terme, ['nom'])->orderBy('id')->pluck('id')->all();

        $this->assertSame([$cremeBrulee->id, $cremerie->id], $trouves);
    }

    public function test_une_colonne_d_une_fiche_liee_se_cherche_de_meme(): void
    {
        $fromage = Produit::factory()->create(['nom' => 'Tomme']);
        $fromage->rattacherAuFournisseur(Fournisseur::factory()->create(['nom' => 'Élevage du Lac'])->id);
        $pain = Produit::factory()->create(['nom' => 'Baguette']);
        $pain->rattacherAuFournisseur(Fournisseur::factory()->create(['nom' => 'Moulin Martin'])->id);

        $trouves = RechercheSansAccents::appliquer(Produit::query(), 'ELEVAGE', ['nom', 'fournisseur.nom'])->pluck('id')->all();

        $this->assertSame([$fromage->id], $trouves);
    }

    /**
     * « 50% » ne trouve pas « Remise 500 », ni « a_b » « AXB » : les jokers
     * de LIKE tapés se cherchent tels quels.
     */
    public function test_les_caracteres_joker_tapes_se_cherchent_tels_quels(): void
    {
        $remise = Produit::factory()->create(['nom' => 'Remise 50% été']);
        Produit::factory()->create(['nom' => 'Remise 500 g']);
        $reference = Produit::factory()->create(['nom' => 'Câble', 'reference' => 'A_B-1']);
        Produit::factory()->create(['nom' => 'Câble long', 'reference' => 'AXB-1']);

        $this->assertSame([$remise->id], RechercheSansAccents::appliquer(Produit::query(), '50%', ['nom'])->pluck('id')->all());
        $this->assertSame([$reference->id], RechercheSansAccents::appliquer(Produit::query(), 'a_b', ['reference'])->pluck('id')->all());
    }
}
