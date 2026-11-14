<?php

use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;

/*
 * Lot DEMO-V. Les listes des fiches au modèle D choisi par l'éditeur le
 * 13/11/2026 : un seul tableau, les titres une fois en haut, une ligne
 * par mode dans l'ordre fixe, « – » pour une valeur nulle, le total en
 * dernière ligne et le crédit d'avoir dessous ; sur un téléphone, le
 * tableau défile de côté dans son cadre, sans que la page défile. La
 * clôture du 9 mars de la société de test compte une bougie vendue au
 * comptoir (voir TestCaseDuNavigateur::societeComplete()).
 */

/**
 * Le texte visible de chaque ligne du tableau dont la première colonne
 * porte ce titre, cellules séparées par « | » (le libellé de chaque
 * valeur, que seul un lecteur d'écran lit, n'y est pas : le titre de sa
 * colonne le dit à l'œil).
 *
 * @return list<string>
 */
function lignesDuTableauAffiche(Navigation $onglet, string $premierTitre): array
{
    $lignes = $onglet->page()->evaluate('(titre) => {
        const tableau = [...document.querySelectorAll(".piliqi-tableau table")].find((t) => t.querySelector("thead th")?.innerText.trim() === titre);

        const texte = (cellule) => {
            const copie = cellule.cloneNode(true);
            copie.querySelectorAll(".sr-only").forEach((cache) => cache.remove());

            return copie.textContent.replace(/\s+/g, " ").trim();
        };

        return tableau === undefined ? [] : [...tableau.querySelectorAll("tr")].map((ligne) => [...ligne.children].map(texte).join(" | "));
    }', $premierTitre);

    return is_array($lignes) ? array_values(array_map(strval(...), $lignes)) : [];
}

test('le responsable lit les encaissements de la clôture en un seul tableau au modèle D, qui défile de côté sur un téléphone', function () {
    $societe = $this->societeComplete();
    $cloture = app(TenantContext::class)->runAs($societe->magasin, fn (): ClotureCaisse => ClotureCaisse::query()->where('date_cloture', '2026-03-09')->sole());
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/cloture-caisses/'.$cloture->id)
        ->voir('Encaissements');

    $lignes = lignesDuTableauAffiche($onglet, 'Mode de paiement');
    expect($lignes[0])->toBe('Mode de paiement | Paiements | Contre-passations | Montant encaissé')
        ->and(array_map(fn (string $ligne): string => explode(' | ', $ligne)[0], array_slice($lignes, 1, 5)))->toBe(['Espèces', 'Carte bancaire', 'Chèque', 'Virement', 'Prélèvement'])
        ->and($lignes)->toContain('Chèque | – | – | –')
        ->and($lignes[6])->toStartWith('Total encaissé | ')
        ->and($lignes[7])->toBe('Crédit d\'avoir utilisé (n\'entre pas dans les encaissements) | –')
        ->and($onglet->page()->evaluate('() => [...document.querySelectorAll("th")].filter((titre) => titre.innerText.trim() === "Mode de paiement").length'))->toBe(1);

    $onglet->page()->setViewportSize(375, 800);
    $onglet->attendreQue('window.innerWidth === 375', 'la fenêtre n\'a pas pris la largeur d\'un téléphone');
    expect($onglet->page()->evaluate('() => {
        const cadre = [...document.querySelectorAll(".piliqi-tableau")].find((c) => c.querySelector("thead th")?.innerText.trim() === "Mode de paiement");

        return [getComputedStyle(cadre).overflowX, cadre.scrollWidth > cadre.clientWidth, document.documentElement.scrollWidth <= window.innerWidth];
    }'))->toBe(['auto', true, true]);
});
