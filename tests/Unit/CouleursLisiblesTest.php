<?php

namespace Tests\Unit;

use App\Filament\CouleursLisibles;
use Filament\Support\Colors\Color;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les couleurs des panneaux se lisent en clair comme en sombre (décision de
 * l'éditeur du 15/11/2026, contraste AA) : le texte blanc d'un bouton sur
 * la teinte 600 (clair) et 500 (sombre), le texte d'une pastille ou d'un
 * lien en teinte 600 sur la teinte 50 et sur le gris 100, et le texte en
 * teinte 400 du mode sombre sur le fond foncé d'un panneau et sur sa
 * pastille, chacun à 4,5:1 au moins, pour la couleur principale de chaque
 * panneau et pour celles des pastilles, des boutons et des avis.
 */
class CouleursLisiblesTest extends TestCase
{
    private const BLANC = '255, 255, 255';

    /**
     * @return array<string, array{0: array<int, string>}>
     */
    public static function panneaux(): array
    {
        return [
            'panneau des magasins' => [Color::Indigo],
            'espace du cabinet' => [Color::Sky],
            'espace de la société' => [Color::Emerald],
        ];
    }

    /**
     * @param  array<int, string>  $principale
     */
    #[DataProvider('panneaux')]
    public function test_chaque_couleur_d_un_panneau_se_lit_dans_les_deux_modes(array $principale): void
    {
        foreach (CouleursLisibles::duPanneau($principale) as $nom => $palette) {
            if ($nom === 'gray') {
                continue;
            }

            $this->assertSame([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950], array_keys($palette), $nom);
            $this->assertGreaterThanOrEqual(CouleursLisibles::SEUIL, CouleursLisibles::rapport($palette[500], self::BLANC), $nom.' : texte blanc sur la teinte 500');
            $this->assertGreaterThanOrEqual(CouleursLisibles::SEUIL, CouleursLisibles::rapport($palette[600], self::BLANC), $nom.' : texte blanc sur la teinte 600');
            $this->assertGreaterThanOrEqual(CouleursLisibles::SEUIL, CouleursLisibles::rapport($palette[600], $palette[50]), $nom.' : teinte 600 sur la teinte 50');
            $this->assertGreaterThanOrEqual(CouleursLisibles::SEUIL, CouleursLisibles::rapport($palette[600], Color::Zinc[100]), $nom.' : teinte 600 sur le gris 100');
            $this->assertGreaterThanOrEqual(CouleursLisibles::SEUIL, CouleursLisibles::rapport($palette[400], Color::Zinc[900]), $nom.' : teinte 400 sur le gris 900 du mode sombre');
            $this->assertGreaterThanOrEqual(CouleursLisibles::SEUIL, CouleursLisibles::rapport($palette[400], CouleursLisibles::dessus($palette[400], 0.1, Color::Zinc[800])), $nom.' : teinte 400 sur sa pastille du mode sombre');
        }
    }

    public function test_une_palette_garde_ses_teintes_et_change_seulement_les_teintes_400_500_et_600_qui_ne_se_lisent_pas(): void
    {
        $vert = CouleursLisibles::lisible(Color::Emerald);
        $indigo = CouleursLisibles::lisible(Color::Indigo);

        $this->assertSame([Color::Emerald[700], Color::Emerald[700]], [$vert[500], $vert[600]]);
        $this->assertSame([Color::Indigo[600], Color::Indigo[600]], [$indigo[500], $indigo[600]]);
        $this->assertSame(Color::Emerald[400], $vert[400]);
        $this->assertSame(Color::Indigo[300], $indigo[400]);
        $this->assertSame(Color::Emerald[50], $vert[50]);
        $this->assertSame(Color::Emerald[700], $vert[700]);
    }

    public function test_le_rapport_de_contraste_suit_wcag(): void
    {
        $this->assertSame(21.0, round(CouleursLisibles::rapport('0, 0, 0', self::BLANC), 2));
        $this->assertSame(1.0, CouleursLisibles::rapport(self::BLANC, self::BLANC));
        $this->assertSame(4.54, round(CouleursLisibles::rapport('118, 118, 118', self::BLANC), 2));
    }
}
