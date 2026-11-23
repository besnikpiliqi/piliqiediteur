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
use Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les recherches ignorent la casse et les accents, en base, par la
 * fonction SQL sans_casse_ni_accents() (voir RechercheSansAccents).
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
     * Le pliage de la base est, caractère par caractère, celui que calcule
     * PHP selon Unicode avec les plages de diacritiques et les lettres sans
     * décomposition de RechercheSansAccents (voir pliageSelonUnicode()),
     * pour toutes les lettres latines, grecques et cyrilliques, la
     * ponctuation, les symboles et les formes pleine chasse : la
     * décomposition canonique et la correspondance simple des minuscules
     * de PostgreSQL, sous la collation C.UTF-8 de la base, sont celles
     * d'Unicode. Reprenant ces plages, ce test ne dit rien de celles qui
     * manqueraient : le suivant le vérifie sans elles. Le saut de ligne
     * sépare les caractères : il ne se plie pas, et ne se combine à aucun
     * accent.
     */
    public function test_la_base_plie_chaque_caractere_selon_unicode(): void
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
        $pliesSelonUnicode = explode("\n", self::pliageSelonUnicode($texte));
        $pliesParLaBase = explode("\n", DB::selectOne('select '.RechercheSansAccents::FONCTION_SQL.'(?) as pliage', [$texte])->pliage);

        $this->assertCount(count($caracteres), $pliesSelonUnicode);
        $this->assertCount(count($caracteres), $pliesParLaBase);
        $this->assertSame(
            array_combine(array_keys($caracteres), $pliesSelonUnicode),
            array_combine(array_keys($caracteres), $pliesParLaBase),
        );
    }

    /**
     * Sans les plages de RechercheSansAccents : une fois pliés par la base,
     * les caractères des blocs du test précédent et les blocs de marques
     * combinantes d'Unicode (diacritiques, diacritiques étendus et
     * supplémentaires, marques des symboles, demi-marques) ne gardent
     * aucune marque combinante (propriété \p{Mn} d'Unicode, lue par PCRE) :
     * ni une lettre latine, grecque ou cyrillique, dont « É », « ῼ » et
     * « Ӂ », ni un accent tapé seul. Une plage de diacritiques retirée de
     * la classe y laisserait les siens. Restent seules, voulues, des marques
     * qui ne sont pas des accents : les signes combinants cyrilliques
     * U+0483 à U+0487 (titlo et signes de l'écriture slavonne, qui ne sont
     * pas des lettres) et les points des formes de présentation de
     * l'hébreu (U+FB1D à U+FB4F : « שׁ » se plie en « ש » suivi du point
     * U+05C1).
     */
    public function test_aucun_accent_ne_reste_une_fois_plie_hors_des_marques_qui_n_en_sont_pas(): void
    {
        $caracteres = [];

        foreach ([[0x0001, 0x052F], [0x1AB0, 0x1AFF], [0x1DC0, 0x1FFF], [0x2000, 0x2BFF], [0xFB00, 0xFB4F], [0xFE20, 0xFE2F], [0xFF00, 0xFFEF]] as [$debut, $fin]) {
            foreach (range($debut, $fin) as $codePoint) {
                if ($codePoint !== 0x0A) {
                    $caracteres[$codePoint] = mb_chr($codePoint, 'UTF-8');
                }
            }
        }

        $plies = array_combine(array_keys($caracteres), explode("\n", DB::selectOne('select '.RechercheSansAccents::FONCTION_SQL.'(?) as pliage', [implode("\n", $caracteres)])->pliage));
        $gardentUneMarque = array_keys(array_filter($plies, static fn (string $plie): bool => preg_match('/\p{Mn}/u', $plie) === 1));
        $horsDesMarquesVoulues = array_map(
            static fn (int $codePoint): string => sprintf('U+%04X', $codePoint),
            array_values(array_filter($gardentUneMarque, static fn (int $codePoint): bool => ($codePoint < 0x0483 || $codePoint > 0x0487) && ($codePoint < 0xFB1D || $codePoint > 0xFB4F))),
        );

        $this->assertSame(['e', 'ω', 'ж', ''], [$plies[0x00C9], $plies[0x1FFC], $plies[0x04C1], $plies[0x0301]]);
        $this->assertContains(0x0483, $gardentUneMarque);
        $this->assertSame("\u{05E9}\u{05C1}", $plies[0xFB2A]);
        $this->assertSame([], $horsDesMarquesVoulues);
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

    /**
     * Le pliage que définit Unicode, calculé par PHP : décomposition
     * canonique (NFD), diacritiques combinants retirés, minuscules
     * (correspondance simple d'Unicode), puis lettres sans décomposition
     * remplacées, celles de RechercheSansAccents::LETTRES_SANS_DECOMPOSITION.
     */
    private static function pliageSelonUnicode(string $texte): string
    {
        $diacritiques = implode('', array_map(
            static fn (array $plage): string => sprintf('\\x{%04X}-\\x{%04X}', $plage[0], $plage[1]),
            RechercheSansAccents::DIACRITIQUES,
        ));
        $sansDiacritiques = (string) preg_replace("/[{$diacritiques}]/u", '', (string) Normalizer::normalize($texte, Normalizer::FORM_D));

        return strtr(mb_convert_case($sansDiacritiques, MB_CASE_LOWER_SIMPLE, 'UTF-8'), RechercheSansAccents::LETTRES_SANS_DECOMPOSITION);
    }
}
