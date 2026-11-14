<?php

namespace Tests\Feature\Filament;

use App\Filament\InitialesDuCompte;
use App\Filament\PoliceServieParPiliqi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aucune page ne demande rien à un site tiers (décision de l'éditeur du
 * 03/11/2026, Q-TIERS) : la police Inter et l'image des initiales du compte
 * viennent de PILIQI.
 */
class RessourcesServiesParPiliqiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function pagesDeConnexion(): array
    {
        return [
            'panneau des magasins' => ['/app/login'],
            'espace de la société' => ['/societe/login'],
            'espace du cabinet' => ['/cabinet/login'],
        ];
    }

    #[DataProvider('pagesDeConnexion')]
    public function test_chaque_panneau_charge_la_police_servie_par_piliqi(string $page): void
    {
        $reponse = $this->get($page);

        $reponse->assertOk()->assertSee('href="'.asset(PoliceServieParPiliqi::FEUILLE_DE_STYLE).'"', false);
        $reponse->assertDontSee('fonts.bunny.net');
        $this->assertFileExists(public_path(PoliceServieParPiliqi::FEUILLE_DE_STYLE));
    }

    /**
     * La feuille écrite à la main qui teinte les rangées choisies et les
     * lignes cochées (constat C5 de l'éditeur) se sert dans chaque panneau,
     * depuis la copie que « php artisan filament:assets » publie, identique
     * à la feuille du dépôt : une feuille changée sans être republiée ne
     * passerait pas.
     */
    #[DataProvider('pagesDeConnexion')]
    public function test_chaque_panneau_sert_la_feuille_des_rangees_choisies_publiee_telle_quelle(string $page): void
    {
        $this->get($page)->assertOk()->assertSee('css/app/rangees-choisies.css', false);
        $this->assertFileEquals(resource_path('css/rangees-choisies.css'), public_path('css/app/rangees-choisies.css'));
    }

    /**
     * La feuille écrite à la main des tableaux des fiches, au modèle D
     * (lot DEMO-V), se sert de même dans chaque panneau, publiée telle
     * qu'elle est dans le dépôt.
     */
    #[DataProvider('pagesDeConnexion')]
    public function test_chaque_panneau_sert_la_feuille_des_tableaux_publiee_telle_quelle(string $page): void
    {
        $this->get($page)->assertOk()->assertSee('css/app/tableaux.css', false);
        $this->assertFileEquals(resource_path('css/tableaux.css'), public_path('css/app/tableaux.css'));
    }

    /**
     * La feuille écrite à la main des fenêtres de sélection en plein écran
     * (lot DEMO-V) se sert de même dans chaque panneau, publiée telle
     * qu'elle est dans le dépôt.
     */
    #[DataProvider('pagesDeConnexion')]
    public function test_chaque_panneau_sert_la_feuille_des_fenetres_de_selection_publiee_telle_quelle(string $page): void
    {
        $this->get($page)->assertOk()->assertSee('css/app/fenetres-de-selection.css', false);
        $this->assertFileEquals(resource_path('css/fenetres-de-selection.css'), public_path('css/app/fenetres-de-selection.css'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noms(): array
    {
        return [
            'prénom et nom' => ['Alice Martin', 'AM'],
            'deux premiers mots seulement' => ['Émilie de Villiers', 'ÉD'],
            'un seul mot' => ['zoé', 'Z'],
            'espaces autour et entre' => ['  Jean   Dupont ', 'JD'],
            'nom vide' => ['', ''],
        ];
    }

    #[DataProvider('noms')]
    public function test_les_initiales_sont_la_premiere_lettre_des_deux_premiers_mots(string $nom, string $initiales): void
    {
        $this->assertSame($initiales, InitialesDuCompte::initiales($nom));
    }

    public function test_l_image_des_initiales_est_dessinee_dans_l_adresse_sans_aucune_requete(): void
    {
        $adresse = (new InitialesDuCompte)->get(User::factory()->make(['name' => 'Léa <Durand>']));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $adresse);
        $svg = base64_decode(substr($adresse, strlen('data:image/svg+xml;base64,')), true);
        $this->assertNotFalse($svg);
        $this->assertNotFalse(simplexml_load_string($svg), 'L\'image est un SVG bien formé, même avec un nom qui contient < et >.');
        $this->assertStringContainsString('>L&lt;</text>', $svg);
        $this->assertStringNotContainsString('ui-avatars.com', $adresse);
    }
}
