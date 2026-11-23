<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;

/*
 * Les noms longs des graphiques en barres couchées (demande de l'éditeur du
 * 20/11/2026) : sur la carte « Meilleurs produits », un nom trop long pour
 * la place s'écrit à partir de sa gauche, son début visible, la fin cachée
 * par « … » (jamais le début) ; la souris au-dessus du nom en montre le nom
 * entier dans une info-bulle ; un clic ou un toucher sur le nom (un
 * téléphone ou une tablette n'a pas de souris) l'affiche directement ; un
 * nom court reste entier et sans info-bulle. À 1 280 px de large et sur un
 * téléphone, en clair comme en sombre (contraste AA de l'info-bulle). La
 * même règle vaut pour la légende d'un anneau.
 */

const NOM_TRES_LONG = 'Bougie parfumée à la cire de soja, mèche en coton, senteur thé vert et gingembre, grand format 500 g · Boutique du Port';

/**
 * Le graphique de cette carte, relu : ses noms entiers, ce que l'axe des
 * noms en écrit (graduations) et la place de la zone des noms, du canevas
 * et du nom de ce rang.
 *
 * @return array{noms: list<string>, ecrits: list<string>, zone: array{gauche: float, haut: float, bas: float}, rangs: list<float>}
 */
function nomsDuGraphique(Navigation $onglet, string $graphique): array
{
    return $onglet->page()->evaluate(<<<'JS'
        (graphique) => {
            const racine = document.querySelector(`[data-graphique="${graphique}"] canvas`).closest('[x-data]');
            const dessin = window.Alpine.$data(racine).getChart();
            const echelle = dessin.scales[dessin.options.indexAxis === 'y' ? 'y' : 'x'];

            return {
                noms: dessin.data.labels,
                ecrits: echelle.ticks.map((graduation) => graduation.label),
                zone: { gauche: dessin.chartArea.left, haut: dessin.chartArea.top, bas: dessin.chartArea.bottom },
                rangs: dessin.data.labels.map((nom, rang) => echelle.getPixelForValue(rang)),
            };
        }
        JS, $graphique);
}

/**
 * L'info-bulle du nom entier : son texte et sa couleur, si elle est
 * visible, sinon null.
 *
 * @return array{texte: string, couleur: string, fond: string}|null
 */
function nomComplet(Navigation $onglet, string $graphique): ?array
{
    return $onglet->page()->evaluate(<<<'JS'
        (graphique) => {
            const bulle = document.querySelector(`[data-graphique="${graphique}"] [data-nom-complet]`);

            if (bulle === null || bulle.hidden) {
                return null;
            }

            const style = getComputedStyle(bulle);

            return { texte: bulle.textContent, couleur: style.color, fond: style.backgroundColor };
        }
        JS, $graphique);
}

/**
 * La luminance relative d'une couleur « rgb(…) », et le contraste entre
 * deux couleurs.
 */
function contrasteDeDeuxCouleurs(string $premiere, string $seconde): float
{
    $luminance = function (string $couleur): float {
        preg_match_all('/\d+(?:\.\d+)?/', $couleur, $nombres);
        [$r, $g, $b] = array_map(fn (string $canal): float => ((float) $canal / 255) <= 0.03928 ? ((float) $canal / 255) / 12.92 : ((((float) $canal / 255) + 0.055) / 1.055) ** 2.4, array_slice($nombres[0], 0, 3));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    };
    [$plus, $moins] = [max($luminance($premiere), $luminance($seconde)), min($luminance($premiere), $luminance($seconde))];

    return ($plus + 0.05) / ($moins + 0.05);
}

test('un nom trop long s’écrit par son début suivi de « … », l’info-bulle en dit le nom entier au survol et au clic, un nom court reste entier, à 1 280 px, sur un téléphone, en clair et en sombre', function () {
    $societe = $this->societeComplete();
    // Un produit au nom très long, vendu : « Meilleurs produits » classe les noms figés des lignes vendues.
    app(TenantContext::class)->runAs($societe->magasin, function () use ($societe): void {
        $produit = Produit::factory()->create(['nom' => NOM_TRES_LONG, 'reference' => 'BOUGIE-GF', 'prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        app(EnregistrerVenteCaisseAction::class)->handle($societe->vendeur, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $produit->id, 'quantite' => 3]], new ReglementComptant([new PartDeReglement(ModePaiement::Especes, 12000)]));
    });
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe');
    $carte = '[data-graphique="meilleurs_produits"]';

    $onglet->attendreQue('document.querySelectorAll("[data-graphique]").length === 4', 'les graphiques par défaut ne sont pas là');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="ajouter"]')->selectOption('meilleurs_produits'), 'l’ajout des meilleurs produits')
        ->attendreQue('document.querySelector(\''.$carte.' canvas\') !== null && document.querySelector(\''.$carte.' canvas\').width > 0', 'les meilleurs produits ne sont pas dessinés');
    $onglet->attendreQue('window.Alpine.$data(document.querySelector(\''.$carte.' canvas\').closest(\'[x-data]\')).getChart() !== null', 'le graphique des meilleurs produits n’est pas prêt');

    foreach ([['largeur' => 1280, 'hauteur' => 1000], ['largeur' => 390, 'hauteur' => 900]] as $fenetre) {
        foreach (['light', 'dark'] as $mode) {
            $onglet->page()->setViewportSize($fenetre['largeur'], $fenetre['hauteur']);
            $onglet->page()->evaluate('(mode) => localStorage.setItem("theme", mode)', $mode);
            $onglet->aller($onglet->chemin())->attendre()->verifier()
                ->attendreQue('document.querySelector(\''.$carte.' canvas\') !== null && window.Alpine.$data(document.querySelector(\''.$carte.' canvas\').closest(\'[x-data]\')).getChart() !== null', 'le graphique des meilleurs produits n’est pas prêt');
            // Sur un téléphone, le tiroir du menu, ouvert, couvre la page : le visiteur le referme.
            $onglet->page()->evaluate('(largeur) => { if (largeur < 1024) { window.Alpine.store("sidebar").close(); } }', $fenetre['largeur']);
            $onglet->page()->evaluate('(carte) => document.querySelector(carte).scrollIntoView()', $carte);
            $qui = "{$fenetre['largeur']} px, {$mode}";

            $noms = nomsDuGraphique($onglet, 'meilleurs_produits');
            // Avec plusieurs magasins, la carte écrit « nom · magasin ».
            $rangDuLong = array_find_key($noms['noms'], fn (string $nom): bool => str_starts_with($nom, NOM_TRES_LONG));
            expect($rangDuLong)->toBeInt("Le produit au nom très long est dans la carte ({$qui}).");
            $nomEntier = $noms['noms'][$rangDuLong];

            $ecrit = $noms['ecrits'][$rangDuLong];
            expect($ecrit)->toStartWith(mb_substr($nomEntier, 0, 12), "Le début du nom reste visible ({$qui}).")
                ->and($ecrit)->toEndWith('…', "La fin cachée par « … » ({$qui}).")
                ->and(mb_strlen($ecrit))->toBeLessThan(mb_strlen($nomEntier));

            foreach ($noms['noms'] as $rang => $nom) {
                if ($rang !== $rangDuLong && mb_strlen($nom) <= 20) {
                    expect($noms['ecrits'][$rang])->toBe($nom, "Un nom court reste entier ({$qui}).");
                }
            }

            $canevas = $carte.' canvas';
            $y = $noms['rangs'][$rangDuLong];
            $x = max(2.0, $noms['zone']['gauche'] / 2);

            // Au survol : l'info-bulle, le nom entier ; la souris qui part la ferme.
            $onglet->geste(fn () => $onglet->element($canevas)->hover(['position' => ['x' => $x, 'y' => $y]]), "le survol du nom long ({$qui})")
                ->attendreQue('document.querySelector(\''.$carte.' [data-nom-complet]\') !== null && ! document.querySelector(\''.$carte.' [data-nom-complet]\').hidden', "l’info-bulle ne s’ouvre pas au survol ({$qui})");
            $bulle = nomComplet($onglet, 'meilleurs_produits');
            expect($bulle['texte'])->toBe($nomEntier)
                ->and(contrasteDeDeuxCouleurs($bulle['couleur'], $bulle['fond']))->toBeGreaterThanOrEqual(4.5, "Le texte de l’info-bulle se lit ({$qui}).");

            $onglet->geste(fn () => $onglet->element($canevas)->hover(['position' => ['x' => $noms['zone']['gauche'] + 40, 'y' => $noms['zone']['haut'] - 4]]), "la souris qui quitte le nom ({$qui})")
                ->attendreQue('document.querySelector(\''.$carte.' [data-nom-complet]\').hidden', "l’info-bulle reste ouverte ({$qui})");

            // Au clic ou au toucher : directement l'info-bulle.
            $onglet->geste(fn () => $onglet->element($canevas)->click(['position' => ['x' => $x, 'y' => $y]]), "le clic sur le nom long ({$qui})")
                ->attendreQue('! document.querySelector(\''.$carte.' [data-nom-complet]\').hidden', "l’info-bulle ne s’ouvre pas au clic ({$qui})");
            expect(nomComplet($onglet, 'meilleurs_produits')['texte'])->toBe($nomEntier);
        }
    }
});

test('la légende d’un anneau écrit un nom trop long par son début suivi de « … » et l’info-bulle en dit le nom entier au survol et au clic', function () {
    $societe = $this->societeComplete();
    app(TenantContext::class)->runAs($societe->magasin, function () use ($societe): void {
        $produit = Produit::factory()->create(['nom' => NOM_TRES_LONG, 'reference' => 'BOUGIE-GF', 'prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        app(EnregistrerVenteCaisseAction::class)->handle($societe->vendeur, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $produit->id, 'quantite' => 3]], new ReglementComptant([new PartDeReglement(ModePaiement::Especes, 12000)]));
    });
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe');
    $carte = '[data-graphique="meilleurs_produits"]';
    $graphique = 'window.Alpine.$data(document.querySelector(\''.$carte.' canvas\').closest(\'[x-data]\')).getChart()';

    $onglet->attendreQue('document.querySelectorAll("[data-graphique]").length === 4', 'les graphiques par défaut ne sont pas là');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="ajouter"]')->selectOption('meilleurs_produits'), 'l’ajout des meilleurs produits');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="type"]')->selectOption('anneau'), 'le passage en anneau')
        ->attendreQue('document.querySelector(\''.$carte.' canvas\') !== null && '.$graphique.' !== null && '.$graphique.'.config.type === "doughnut"', 'l’anneau n’est pas dessiné');
    $onglet->page()->evaluate('(carte) => document.querySelector(carte).scrollIntoView()', $carte);

    $legende = $onglet->page()->evaluate('() => { const g = '.$graphique.'; const boite = g.legend.legendHitBoxes; return { noms: g.data.labels, ecrits: g.legend.legendItems.map((element) => element.text), boites: boite.map((b) => ({ x: b.left + b.width / 2, y: b.top + b.height / 2 })) }; }');
    $rang = array_find_key($legende['noms'], fn (string $nom): bool => str_starts_with($nom, NOM_TRES_LONG));
    expect($rang)->toBeInt()
        ->and($legende['ecrits'][$rang])->toStartWith(mb_substr($legende['noms'][$rang], 0, 12))->toEndWith('…');

    $onglet->geste(fn () => $onglet->element($carte.' canvas')->hover(['position' => $legende['boites'][$rang]]), 'le survol du nom dans la légende')
        ->attendreQue('document.querySelector(\''.$carte.' [data-nom-complet]\') !== null && ! document.querySelector(\''.$carte.' [data-nom-complet]\').hidden', 'l’info-bulle de la légende ne s’ouvre pas au survol');
    expect(nomComplet($onglet, 'meilleurs_produits')['texte'])->toBe($legende['noms'][$rang]);
});

test('les étiquettes d’un graphique par mois restent entières, sans « … », à 1 280 px et sur un téléphone, en clair et en sombre', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe');
    $carte = '[data-graphique="ca_par_mois"]';
    $graphique = 'window.Alpine.$data(document.querySelector(\''.$carte.' canvas\').closest(\'[x-data]\')).getChart()';

    $onglet->attendreQue('document.querySelectorAll("[data-graphique]").length === 4', 'les graphiques par défaut ne sont pas là');

    foreach ([['largeur' => 1280, 'hauteur' => 1000], ['largeur' => 390, 'hauteur' => 900]] as $fenetre) {
        foreach (['light', 'dark'] as $mode) {
            $onglet->page()->setViewportSize($fenetre['largeur'], $fenetre['hauteur']);
            $onglet->page()->evaluate('(mode) => localStorage.setItem("theme", mode)', $mode);
            $onglet->aller($onglet->chemin())->attendre()->verifier()
                ->attendreQue('document.querySelector(\''.$carte.' canvas\') !== null && '.$graphique.' !== null', 'le graphique par mois n’est pas prêt');
            $onglet->page()->evaluate('(largeur) => { if (largeur < 1024) { window.Alpine.store("sidebar").close(); } }', $fenetre['largeur']);
            $qui = "{$fenetre['largeur']} px, {$mode}";

            $axe = $onglet->page()->evaluate('() => { const g = '.$graphique.'; return { noms: g.data.labels, ticks: g.scales.x.ticks.map((graduation) => ({ rang: graduation.value, ecrit: graduation.label })) }; }');
            expect($axe['noms'])->toHaveCount(12)
                ->and($axe['ticks'])->not->toBeEmpty("L’axe des mois écrit au moins un mois ({$qui}).");

            foreach ($axe['ticks'] as $graduation) {
                expect($graduation['ecrit'])->toBe($axe['noms'][$graduation['rang']], "Un mois écrit sur l’axe est entier, année comprise ({$qui}).")
                    ->and($graduation['ecrit'])->not->toContain('…');
            }
        }
    }
});
