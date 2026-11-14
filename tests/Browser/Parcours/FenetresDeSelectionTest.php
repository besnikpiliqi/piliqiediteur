<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;

/*
 * Lot DEMO-V (constat de l'éditeur du 14/11/2026 sur la caisse). Les
 * fenêtres de sélection en plein écran tiennent dans la hauteur de
 * l'écran, sans que la page défile : en haut, toujours visibles, le titre,
 * le tri, la recherche et les filtres ; au milieu, seul le tableau défile,
 * ses titres collés en haut, « Voir 50 de plus » au bout ; en bas,
 * toujours visibles, les produits choisis (qui défilent à leur tour), la
 * phrase du total et le bouton d'ajout, ou la pagination des clients. La
 * fenêtre s'ouvre sur la caisse, à 1280 × 720.
 */

/**
 * Ce que montre la fenêtre ouverte : le haut et le bas de chaque élément
 * nommé par son sélecteur, dans la fenêtre, et si chacun tient dans la
 * hauteur de l'écran ; si la fenêtre elle-même défile ; si le tableau
 * défile dans sa zone.
 *
 * @param  array<string, string>  $elements
 * @return array{visibles: array<string, bool>, fenetreQuiDefile: bool, tableauQuiDefile: bool}
 */
function cadrageDeLaFenetre(Navigation $onglet, array $elements): array
{
    $mesure = $onglet->page()->evaluate('(elements) => {
        const fenetre = document.querySelector(".fi-modal.fi-modal-open .fi-modal-window");
        const visibles = {};

        for (const [nom, selecteur] of Object.entries(elements)) {
            const element = [...fenetre.querySelectorAll(selecteur)].find((e) => e.offsetParent !== null);
            const cadre = element?.getBoundingClientRect();
            visibles[nom] = cadre !== undefined && cadre.top >= 0 && cadre.bottom <= window.innerHeight && cadre.height > 0;
        }

        const zone = fenetre.querySelector(".fi-ta-content");

        return {
            visibles,
            fenetreQuiDefile: fenetre.scrollHeight > fenetre.clientHeight + 1 || fenetre.closest(".fi-modal").querySelector(":scope > div").scrollHeight > window.innerHeight + 1,
            tableauQuiDefile: zone.scrollHeight > zone.clientHeight + 1 && getComputedStyle(zone).overflowY === "auto",
        };
    }', $elements);

    expect($mesure)->toBeArray();

    return $mesure;
}

test('avec 80 produits, la fenêtre « Ajouter des produits » de la caisse garde son haut et son bas visibles, et seul le tableau défile', function () {
    $societe = $this->societeComplete();
    app(TenantContext::class)->runAs($societe->magasin, function (): void {
        foreach (range(1, 80) as $rang) {
            Produit::factory()->create(['nom' => sprintf('Zinnia %02d', $rang), 'reference' => sprintf('ZIN-%02d', $rang), 'prix_vente_ttc' => '2.50', 'taux_tva' => 20, 'suivi_stock' => false]);
        }
    });
    $onglet = $this->seConnecter($societe->responsable);
    $onglet->page()->setViewportSize(1280, 720);
    $onglet->aller('/app/'.$societe->magasin->uuid.'/caisse')->cliquerSur('Ajouter un produit');
    $haut = ['titre' => '.fi-modal-heading', 'tri' => '#tri-des-produits', 'recherche' => '.fi-ta-search-field input'];
    $bas = ['phrase' => '.piliqi-choisis p', 'bouton' => '.piliqi-choisis button'];

    expect(cadrageDeLaFenetre($onglet, [...$haut, ...$bas]))->toBe([
        'visibles' => ['titre' => true, 'tri' => true, 'recherche' => true, 'phrase' => true, 'bouton' => true],
        'fenetreQuiDefile' => false,
        'tableauQuiDefile' => true,
    ]);

    $onglet->page()->evaluate('() => { const zone = document.querySelector(".fi-modal.fi-modal-open .fi-ta-content"); zone.scrollTop = zone.scrollHeight; }');
    expect($onglet->page()->evaluate('() => {
        const zone = document.querySelector(".fi-modal.fi-modal-open .fi-ta-content");
        const titre = zone.querySelector("thead th").getBoundingClientRect();
        const voirPlus = [...zone.querySelectorAll("button")].find((bouton) => bouton.innerText.includes("Voir 50 de plus"));

        return [Math.abs(titre.top - zone.getBoundingClientRect().top) < 2, voirPlus !== undefined && voirPlus.getBoundingClientRect().bottom <= zone.getBoundingClientRect().bottom + 1];
    }'))->toBe([true, true]);

    $onglet->cliquer(Navigation::FENETRE.' .fi-ta-content tbody .fi-ta-row >> nth=-1')->voir('× 1');
    expect(cadrageDeLaFenetre($onglet, $bas)['visibles'])->toBe(['phrase' => true, 'bouton' => true]);
    $onglet->voir('Ajouter le produit');
});

test('avec 30 produits choisis, la liste des choisis défile et le bouton « Ajouter les 30 produits » reste visible', function () {
    $societe = $this->societeComplete();
    app(TenantContext::class)->runAs($societe->magasin, function (): void {
        foreach (range(1, 30) as $rang) {
            Produit::factory()->create(['nom' => sprintf('Aster %02d', $rang), 'reference' => sprintf('AST-%02d', $rang), 'prix_vente_ttc' => '2.50', 'taux_tva' => 20, 'suivi_stock' => false]);
        }
    });
    $onglet = $this->seConnecter($societe->responsable);
    $onglet->page()->setViewportSize(1280, 720);
    $onglet->aller('/app/'.$societe->magasin->uuid.'/caisse')->cliquerSur('Ajouter un produit');

    foreach (range(1, 30) as $rang) {
        $onglet->cliquer(Navigation::FENETRE.' .fi-ta-row:has(.fi-ta-text-item-label:text-is('.Navigation::texte(sprintf('Aster %02d', $rang)).'))');
    }

    $onglet->voir('Ajouter les 30 produits');
    expect(cadrageDeLaFenetre($onglet, ['recherche' => '.fi-ta-search-field input', 'bouton' => '.piliqi-choisis button.fi-btn']))->toMatchArray([
        'visibles' => ['recherche' => true, 'bouton' => true],
        'fenetreQuiDefile' => false,
    ])->and($onglet->page()->evaluate('() => { const liste = document.querySelector(".fi-modal.fi-modal-open .piliqi-choisis ul"); return liste.scrollHeight > liste.clientHeight; }'))->toBeTrue();
});

test('avec 80 clients, la fenêtre « Chercher un client » de la caisse garde sa recherche et sa pagination visibles, et seul le tableau défile', function () {
    $societe = $this->societeComplete();
    app(TenantContext::class)->runAs($societe->magasin, function (): void {
        foreach (range(1, 80) as $rang) {
            Client::factory()->create(['nom' => sprintf('Client Zéphyr %02d', $rang)]);
        }
    });
    $onglet = $this->seConnecter($societe->responsable);
    $onglet->page()->setViewportSize(1280, 720);
    $onglet->aller('/app/'.$societe->magasin->uuid.'/caisse')->cliquer('[title="Chercher un client"]')->voir('Cliquez sur le client de la vente.');

    expect(cadrageDeLaFenetre($onglet, ['titre' => '.fi-modal-heading', 'recherche' => '.fi-ta-search-field input', 'pagination' => '.fi-pagination']))->toBe([
        'visibles' => ['titre' => true, 'recherche' => true, 'pagination' => true],
        'fenetreQuiDefile' => false,
        'tableauQuiDefile' => true,
    ]);
});
