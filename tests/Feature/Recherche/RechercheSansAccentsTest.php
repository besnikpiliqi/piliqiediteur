<?php

namespace Tests\Feature\Recherche;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les recherches ignorent la casse et les accents, en base, par le même
 * pliage sur les deux moteurs : une fonction SQL sous PostgreSQL, la
 * fonction PHP enregistrée sur la connexion sous SQLite (voir
 * RechercheSansAccents::plier()). Ces tests passent donc tels quels sur
 * l'un et l'autre.
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

    /**
     * Ligatures, lettres barrées et apostrophe typographique d'un clavier
     * de téléphone : la décomposition canonique ne les sépare d'aucun
     * accent.
     *
     * @return array<string, array{string, string}>
     */
    public static function lettresSansDecomposition(): array
    {
        return [
            'ligature œ' => ['Œuf frais', 'oeuf'],
            'ligature æ' => ['Ex æquo', 'ex aequo'],
            'eszett' => ['Straße', 'STRASSE'],
            'l et o barrés' => ['Łódź Ørsted', 'lodz orsted'],
            'apostrophe typographique' => ['L’Oréal', 'l\'oreal'],
        ];
    }

    #[DataProvider('lettresSansDecomposition')]
    public function test_les_ligatures_et_lettres_barrees_se_cherchent_sans_accents(string $nom, string $terme): void
    {
        $trouve = Produit::factory()->create(['nom' => $nom]);
        Produit::factory()->create(['nom' => 'Boulangerie Martin']);

        $this->assertSame([$trouve->id], RechercheSansAccents::appliquer(Produit::query(), $terme, ['nom'])->pluck('id')->all());
    }

    /**
     * Un symbole tapé n'est jamais retiré du terme cherché : « ½ » ou
     * « µ » ne deviennent pas une recherche vide qui trouverait tout, et
     * « °C » ne se réduit pas à « c ».
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function symboles(): array
    {
        return [
            'fraction' => ['½', ['Bouteille ½ litre']],
            'degré' => ['°c', ['Thermomètre 40 °C']],
            'micro' => ['µ', []],
            'euro' => ['€', []],
        ];
    }

    /**
     * @param  list<string>  $attendus
     */
    #[DataProvider('symboles')]
    public function test_un_symbole_se_cherche_tel_qu_il_s_ecrit(string $terme, array $attendus): void
    {
        Produit::factory()->create(['nom' => 'Crème hydratante']);
        Produit::factory()->create(['nom' => 'Bouteille ½ litre']);
        Produit::factory()->create(['nom' => 'Thermomètre 40 °C']);

        $this->assertSame($attendus, RechercheSansAccents::appliquer(Produit::query(), $terme, ['nom'])->orderBy('id')->pluck('nom')->all());
    }

    /**
     * Le pliage de la base est celui de plier(), caractère par caractère,
     * pour toutes les lettres latines, grecques et cyrilliques, la
     * ponctuation, les symboles et les formes pleine chasse : sous
     * PostgreSQL, la fonction SQL et la fonction PHP de SQLite donnent le
     * même résultat, et les recherches trouvent les mêmes fiches sur les
     * deux moteurs. Le saut de ligne sépare les caractères : il ne se
     * plie pas, et ne se combine à aucun accent.
     */
    public function test_la_base_plie_chaque_caractere_comme_plier(): void
    {
        $caracteres = [];

        foreach ([[0x0001, 0x052F], [0x1E00, 0x1FFF], [0x2000, 0x2BFF], [0xFB00, 0xFB4F], [0xFF00, 0xFFEF]] as [$debut, $fin]) {
            foreach (range($debut, $fin) as $codePoint) {
                if ($codePoint !== 0x0A) {
                    $caracteres[sprintf('U+%04X', $codePoint)] = mb_chr($codePoint, 'UTF-8');
                }
            }
        }

        $texte = implode("\n", $caracteres);
        $pliesParPhp = explode("\n", (string) RechercheSansAccents::plier($texte));
        $pliesParLaBase = explode("\n", DB::selectOne('select '.RechercheSansAccents::FONCTION_SQL.'(?) as pliage', [$texte])->pliage);

        $this->assertCount(count($caracteres), $pliesParPhp);
        $this->assertCount(count($caracteres), $pliesParLaBase);
        $this->assertSame(
            array_combine(array_keys($caracteres), $pliesParPhp),
            array_combine(array_keys($caracteres), $pliesParLaBase),
        );
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
