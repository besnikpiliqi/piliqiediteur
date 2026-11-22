<?php

namespace Tests\Browser\Support;

use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Fixtures\EcartDesPrixDAchat\AchatsDeLaMaquette;
use Tests\Fixtures\EcartDesPrixDAchat\LectureDeLaPage;
use Tests\TestCase;

use function expect;

/**
 * Ce que les tests en navigateur de la carte « Écart des prix d'achat »
 * (décision de l'éditeur du 21/11/2026, D76) partagent : les achats de la
 * maquette semés dans un magasin au 13/11/2026, la carte lue dans la page
 * comme la maquette est lue (voir LectureDeLaPage) et comparée à son
 * relevé, la colonne d'un mois, l'ajout de la carte par le menu « Ajouter
 * un graphique ».
 */
final class CarteDeLEcartDesPrixDAchat
{
    public const CARTE = 'ecart_des_prix_d_achat';

    /**
     * Sème, par les vraies actions, les achats de la maquette dans ce
     * magasin au nom de ce responsable, à cet instant, qui doit être le 13
     * novembre 2026 à Paris (voir TestCase::aParis()), où la période « 12
     * derniers mois » est celle de la maquette (déc. 2025 à nov. 2026) ; le
     * temps du test y reste, jamais avant le registre du magasin.
     */
    public static function semer(TestCase $test, CarbonImmutable $instant, Magasin $magasin, User $auteur): void
    {
        $test->travelTo($instant);
        app(TenantContext::class)->runAs($magasin, fn () => AchatsDeLaMaquette::semer($auteur, Fournisseur::factory()->create(['nom' => 'Maison Verdier'])));
        app(TenantContext::class)->clear();
    }

    /**
     * La carte lue dans la page comme la maquette est lue : son HTML vivant
     * (celui que Livewire et Alpine ont écrit), rangé par LectureDeLaPage.
     *
     * @return array<string, mixed>
     */
    public static function lire(Navigation $onglet): array
    {
        $html = $onglet->page()->evaluate('() => document.querySelector(\'[data-graphique="'.self::CARTE.'"]\').outerHTML');

        return LectureDeLaPage::lire(is_string($html) ? $html : '', self::CARTE);
    }

    /**
     * La page est la maquette pour cet affichage et ce mois choisi.
     *
     * @param  'avant'|'debut'  $affichage
     */
    public static function verifierLaMaquette(Navigation $onglet, string $affichage, int $mois, string $contexte): void
    {
        $ecart = LectureDeLaPage::premierEcartAvecLaMaquette(self::lire($onglet), $affichage, $mois);

        expect($ecart)->toBeNull("{$contexte} ({$affichage}, mois {$mois}) : premier écart avec la maquette : {$ecart}");
    }

    /**
     * Le sélecteur de la colonne de ce mois, dans le dessin de la carte.
     */
    public static function colonne(int $mois): string
    {
        return '[data-graphique="'.self::CARTE.'"] svg > g:nth-of-type('.($mois + 1).')';
    }

    /**
     * L'expression JavaScript : la carte est dessinée, avec ses douze
     * colonnes et, sous chacune, l'étiquette de son mois.
     */
    public static function dessinee(): string
    {
        return '(() => { const d = document.querySelector(\'[data-graphique="'.self::CARTE.'"] svg[role="group"]\'); return d !== null && d.querySelectorAll(\'g[role="button"]\').length === 12 && d.querySelectorAll(\'g[role="button"] > text[font-weight]\').length === 12 && d.getBoundingClientRect().height > 100; })()';
    }

    /**
     * Attend que la colonne de ce mois soit la seule choisie.
     */
    public static function attendreLeMoisChoisi(Navigation $onglet, int $mois): Navigation
    {
        return $onglet->attendreQue(
            'Array.from(document.querySelectorAll(\'[data-graphique="'.self::CARTE.'"] svg > g\')).map((colonne) => colonne.getAttribute("aria-pressed")).join(",") === '.json_encode(implode(',', array_map(fn (int $rang): string => $rang === $mois ? 'true' : 'false', range(0, 11)))),
            "le mois {$mois} n’est pas le mois choisi",
        );
    }

    /**
     * Sur la page ouverte, qui montre $cartes cartes, ajoute la carte par le
     * menu « Ajouter un graphique » (elle ne se montre pas d'elle-même),
     * puis attend son dessin.
     */
    public static function ajouter(Navigation $onglet, int $cartes): Navigation
    {
        $onglet->attendreQue('(window.scrollTo(0, document.body.scrollHeight), document.querySelectorAll("[data-graphique]").length === '.$cartes.')', "les {$cartes} graphiques par défaut ne sont pas là");
        $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="ajouter"]')->selectOption(self::CARTE), 'l’ajout de l’écart des prix d’achat')
            ->attendreQue('document.querySelector(\'[data-graphique="'.self::CARTE.'"]\') !== null', 'la carte n’est pas ajoutée');

        return $onglet->attendreQue(self::dessinee(), 'la carte n’est pas dessinée');
    }

    /**
     * La carte, lue comme la maquette pour les mois 0, 2, 3, 8 et 11 par un
     * clic sur leur colonne, puis pour les douze mois ; le mois de départ
     * est le dernier ; le mois choisi est gardé d'un affichage à l'autre.
     */
    public static function verifier(Navigation $onglet, string $contexte): void
    {
        self::verifierLaMaquette($onglet, 'avant', 11, $contexte.', au départ');

        foreach ([0, 2, 3, 8, 11] as $mois) {
            $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element(self::colonne($mois))->click(), "le clic sur la colonne du mois {$mois}");
            self::attendreLeMoisChoisi($onglet, $mois);
            self::verifierLaMaquette($onglet, 'avant', $mois, $contexte.', par un clic');
        }

        $menu = '[data-graphique="'.self::CARTE.'"] select[data-geste="affichage"]';
        $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($menu)->selectOption('debut'), 'le passage aux douze mois')
            ->attendreQue('document.querySelector(\'[data-graphique="'.self::CARTE.'"] [data-ecart="stat-base"]\') !== null', 'les cartes de chiffres des douze mois ne paraissent pas');
        self::verifierLaMaquette($onglet, 'debut', 11, $contexte.', douze mois');

        $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($menu)->selectOption('avant'), 'le retour à l’affichage par mois');
        self::attendreLeMoisChoisi($onglet, 11);
        self::verifierLaMaquette($onglet, 'avant', 11, $contexte.', retour par mois (le mois choisi est gardé)');
    }
}
