<?php

namespace Tests\Feature\TableauDeBord;

use App\Domain\TableauDeBord\Support\DessinDeLEcartDesPrixDAchat;
use App\Domain\TableauDeBord\Support\EcartDesPrixDAchat;
use App\Domain\TableauDeBord\Support\SvgDeLEcartDesPrixDAchat;
use App\Domain\TableauDeBord\Support\TextesDeLEcartDesPrixDAchat;
use DOMElement;
use DOMXPath;
use Masterminds\HTML5;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\EcartDesPrixDAchat\LectureDesFixtures;

/**
 * L'écriture du `svg` de la carte « Écart des prix d'achat » (D76, B7) :
 * chaque attribut et chaque texte sont échappés, un élément dont un
 * attribut vaut « NaN » n'est pas écrit (le reste l'est), et chaque colonne
 * porte, en plus des attributs de la maquette, le clic, Entrée et Espace
 * qui appellent le geste de la grille avec le graphique et le rang du mois.
 * L'arbre lui-même est prouvé contre le relevé de la maquette (voir
 * DessinDeLEcartDesPrixDAchatTest et EcartDesPrixDAchatGrilleTest).
 */
class SvgDeLEcartDesPrixDAchatTest extends TestCase
{
    private const CARTE = 'ecart_des_prix_d_achat';

    public function test_chaque_colonne_porte_le_clic_entree_et_espace_avec_son_rang(): void
    {
        $calcul = EcartDesPrixDAchat::calculer(LectureDesFixtures::verite()['jeu']['produits'], LectureDesFixtures::moisDeLaMaquette());
        $svg = $this->ecrire($calcul, 5);
        $colonnes = $this->elements($svg, '//g');

        $this->assertCount(12, $colonnes);

        foreach ($colonnes as $rang => $colonne) {
            $appel = "choisirLeMois('".self::CARTE."', {$rang})";

            $this->assertSame($appel, $colonne->getAttribute('wire:click'));
            $this->assertSame('$wire.'.$appel, $colonne->getAttribute('x-on:keydown.enter.prevent'));
            $this->assertSame('$wire.'.$appel, $colonne->getAttribute('x-on:keydown.space.prevent'));
            $this->assertSame('button', $colonne->getAttribute('role'));
            $this->assertSame('0', $colonne->getAttribute('tabindex'));
            $this->assertSame($rang === 5 ? 'true' : 'false', $colonne->getAttribute('aria-pressed'));
        }

        $this->assertSame(12, substr_count($svg, 'wire:click='), 'Seules les douze colonnes sont des gestes.');
    }

    public function test_le_cadre_le_role_et_le_libelle_du_dessin_sont_ceux_de_la_maquette(): void
    {
        $calcul = EcartDesPrixDAchat::calculer(LectureDesFixtures::verite()['jeu']['produits'], LectureDesFixtures::moisDeLaMaquette());
        $racine = $this->elements($this->ecrire($calcul, 11), '//svg')[0];

        $this->assertSame('0 0 520 270', $racine->getAttribute('viewBox') ?: $racine->getAttribute('viewbox'));
        $this->assertSame('group', $racine->getAttribute('role'));
        $this->assertSame('Écart des prix d\'achat sur douze mois', $racine->getAttribute('aria-label'));
        $this->assertSame('graphique', $racine->getAttribute('data-ecart'));
        $this->assertSame(SvgDeLEcartDesPrixDAchat::CLASSE, $racine->getAttribute('class'));
    }

    /**
     * Un jeu dont tous les achats sont au même prix n'a ni hausse ni baisse
     * nulle part : la maquette divise par zéro, les positions valent
     * « NaN ». La page n'écrit aucun élément qui en porte une (ni
     * graduation, ni barre, ni cadre, ni losange, ni « — »), mais les
     * douze zones cliquables et les douze étiquettes de mois y sont.
     */
    public function test_un_jeu_sans_aucun_ecart_n_ecrit_aucun_nan_et_garde_les_colonnes_et_les_etiquettes(): void
    {
        $calcul = $this->calculDe([['Lampe', [[0, 2, 3, 500], [0, 9, 4, 500], [3, 4, 2, 500], [3, 20, 5, 500]]]]);
        $arbre = DessinDeLEcartDesPrixDAchat::noeuds($calcul, 0);

        $this->assertStringContainsString('NaN', json_encode($arbre, JSON_THROW_ON_ERROR), 'Le dessin de la maquette a bien des positions illisibles.');

        $svg = $this->ecrire($calcul, 0);

        $this->assertStringNotContainsString('NaN', $svg);
        $this->assertCount(0, $this->elements($svg, '//line'));
        $this->assertCount(0, $this->elements($svg, '//polygon'));
        $this->assertCount(12, $this->elements($svg, '//g'));
        $this->assertCount(12, $this->elements($svg, '//g/rect[@fill="transparent"]'), 'La zone cliquable de chaque colonne n\'a aucune position illisible.');
        $this->assertSame(
            ['déc.', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.'],
            array_map(fn (DOMElement $texte): string => (string) $texte->textContent, $this->elements($svg, '//g/text[@font-size="12"][@text-anchor="middle"][@y="258"]')),
        );
    }

    /**
     * Tout attribut et tout texte passent par htmlspecialchars : un texte
     * ou un attribut qui contient une balise, une esperluette ou des
     * guillemets ne sort jamais de son élément.
     */
    public function test_chaque_attribut_et_chaque_texte_sont_echappes(): void
    {
        $arbre = [
            ['t' => 'text', 'a' => ['x' => '1', 'data-nom' => '"><script>alert(1)</script>'], 'x' => '</text><script>alert(2)</script> & « x »', 'f' => []],
        ];

        $svg = SvgDeLEcartDesPrixDAchat::ecrire($arbre, '0 0 520 270', 'Écart "des" <prix> d\'achat', self::CARTE);

        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringContainsString('data-nom="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $svg);
        $this->assertStringContainsString('&lt;/text&gt;&lt;script&gt;alert(2)&lt;/script&gt; &amp; « x »</text>', $svg);
        $this->assertStringContainsString('aria-label="Écart &quot;des&quot; &lt;prix&gt; d&#039;achat"', $svg);
        $this->assertCount(1, $this->elements($svg, '//text'));
    }

    /**
     * Un attribut qui vaut « NaN », ou qui en contient, retire son élément
     * et ses enfants ; ses voisins restent.
     */
    public function test_un_element_dont_un_attribut_est_illisible_n_est_pas_ecrit_avec_ses_enfants(): void
    {
        $arbre = [
            ['t' => 'line', 'a' => ['x1' => '58', 'y1' => 'NaN'], 'x' => '', 'f' => []],
            ['t' => 'polygon', 'a' => ['points' => '1,NaN 2,3'], 'x' => '', 'f' => []],
            ['t' => 'g', 'a' => ['role' => 'button', 'data-x' => 'NaN'], 'x' => null, 'f' => [['t' => 'rect', 'a' => ['x' => '1'], 'x' => '', 'f' => []]]],
            ['t' => 'g', 'a' => ['role' => 'button'], 'x' => null, 'f' => [['t' => 'rect', 'a' => ['x' => '1'], 'x' => '', 'f' => []], ['t' => 'rect', 'a' => ['x' => 'NaN'], 'x' => '', 'f' => []]]],
        ];

        $svg = SvgDeLEcartDesPrixDAchat::ecrire($arbre, '0 0 520 270', 'Dessin', self::CARTE);

        $this->assertCount(0, $this->elements($svg, '//line'));
        $this->assertCount(0, $this->elements($svg, '//polygon'));
        $this->assertCount(1, $this->elements($svg, '//g'));
        $this->assertCount(1, $this->elements($svg, '//g/rect'));
        $this->assertSame("choisirLeMois('".self::CARTE."', 0)", $this->elements($svg, '//g')[0]->getAttribute('wire:click'), 'Un groupe omis n\'est pas compté : le rang du premier groupe écrit est 0.');
    }

    /**
     * Les attributs de la maquette sont écrits dans leur ordre, avant ceux
     * de la grille.
     */
    public function test_les_attributs_de_la_maquette_precedent_ceux_de_la_grille(): void
    {
        $calcul = EcartDesPrixDAchat::calculer(LectureDesFixtures::verite()['jeu']['produits'], LectureDesFixtures::moisDeLaMaquette());
        $colonne = $this->elements($this->ecrire($calcul, 0), '//g')[0];

        $this->assertSame(['class', 'tabindex', 'role', 'aria-label', 'aria-pressed', 'wire:click', 'x-on:keydown.enter.prevent', 'x-on:keydown.space.prevent'], array_keys(iterator_to_array($colonne->attributes ?? [])));
    }

    /**
     * @param  array<string, mixed>  $calcul
     */
    private function ecrire(array $calcul, int $mois): string
    {
        $lecture = TextesDeLEcartDesPrixDAchat::lecture($calcul, TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS, $mois);

        return SvgDeLEcartDesPrixDAchat::ecrire($lecture['dessin']['enfants'], $lecture['dessin']['viewBox'], $lecture['dessin']['ariaLabel'], self::CARTE);
    }

    /**
     * Un calcul à partir de produits écrits (nom, [[mois, jour, quantité, prix en centimes]…]).
     *
     * @param  list<array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int}>}>  $produits
     * @return array<string, mixed>
     */
    private function calculDe(array $produits): array
    {
        return EcartDesPrixDAchat::calculer(
            array_map(fn (array $produit): array => ['nom' => $produit[0], 'achats' => array_map(fn (array $achat): array => ['k' => $achat[0], 'j' => $achat[1], 'q' => $achat[2], 'c' => $achat[3]], $produit[1])], $produits),
            LectureDesFixtures::moisDeLaMaquette(),
        );
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(string $html, string $requete): array
    {
        $document = (new HTML5(['disable_html_ns' => true]))->loadHTML('<!DOCTYPE html><html><body>'.$html.'</body></html>');
        $trouves = (new DOMXPath($document))->query($requete);
        $elements = [];

        foreach ($trouves ?: [] as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }

        return $elements;
    }
}
