<?php

use App\Domain\Catalogue\Enums\ArrondiDuPrixDeVente;
use App\Domain\Tenancy\Models\Magasin;
use Tests\Browser\Support\Navigation;

/*
 * L'arrondi du prix de vente (décision de l'éditeur du 22/11/2026) : sur la
 * page « Prix de vente », la case « Arrondir le prix de vente » est décochée
 * au départ ; cochée, elle fait choisir l'une des trois formules, dont
 * l'essai montre le prix avant et après l'arrondi et le tableau d'exemples ;
 * décochée de nouveau, l'essai ne montre plus qu'un prix, sans arrondi.
 * Chaque rôle qui règle le barème le vit de même ; la société de test a tous
 * ses rôles (voir TestCaseDuNavigateur::societeComplete()).
 */

/**
 * Le texte d'un élément de la page, repéré par son « data-arrondi ».
 */
function texteDeLArrondi(Navigation $onglet, string $quel): string
{
    return trim((string) $onglet->page()->evaluate('() => document.querySelector(\'[data-arrondi="'.$quel.'"]\')?.textContent ?? ""'));
}

/**
 * Dans cet onglet connecté, le calcul s'active, la case d'arrondi se coche,
 * l'euro le plus proche se choisit, s'essaie (0,47 € d'achat et 10 € de
 * bénéfice à 20 % : 12,564 € exact, 12,56 € au centime, 13 € à l'euro près)
 * et s'enregistre ; la page rouverte le retrouve, puis la case se décoche.
 */
function arrondirLePrixDeVenteALEuro(Navigation $onglet, Magasin $magasin): void
{
    $chemin = '/app/'.$magasin->uuid.'/parametres-prix-de-vente';

    $onglet->aller($chemin)
        ->cliquer('[id="data.actif"]')
        ->nePasVoir('Formule d\'arrondi')
        ->voir('Case décochée : aucun arrondi')
        ->cliquer('[id="data.arrondi"]')
        ->voir('Formule d\'arrondi')
        ->voir('Un prix en 9')
        ->voir('L\'euro le plus proche')
        ->voir('Le multiple de 5 € le plus proche')
        ->voir('18,40 € devient 18 €, 18,50 € devient 19 €, 18,55 € devient 19 €.')
        ->cliquer('[id="data.formule-euro"]')
        ->remplir('Prix d\'achat HT', '0.47')
        ->voir('Le prix exact est 12,564 €')
        ->voir('Exemples de la formule choisie')
        ->voir('+0,50 €');
    expect([texteDeLArrondi($onglet, 'avant'), texteDeLArrondi($onglet, 'apres')])->toBe(['12,56 €', '13,00 €']);

    $onglet->soumettre('Enregistrer')->voir('Barème de prix de vente enregistré');
    expect($magasin->fresh()->bareme_prix_vente->arrondi)->toBe(ArrondiDuPrixDeVente::EuroLePlusProche);

    $onglet->aller($chemin);
    expect($onglet->page()->evaluate('() => document.getElementById("data.formule-euro").checked'))->toBeTrue();

    $onglet->cliquer('[id="data.arrondi"]')
        ->nePasVoir('Exemples de la formule choisie')
        ->voir('Case décochée : aucun arrondi')
        ->voir('Prix de vente TTC (sans arrondi)');
}

test('le propriétaire coche l’arrondi du prix de vente, choisit l’euro le plus proche, l’essaie et l’enregistre', function () {
    $societe = $this->societeComplete();

    arrondirLePrixDeVenteALEuro($this->seConnecter($societe->proprietaire), $societe->magasin);
});

test('le responsable coche l’arrondi du prix de vente, choisit l’euro le plus proche, l’essaie et l’enregistre', function () {
    $societe = $this->societeComplete();

    arrondirLePrixDeVenteALEuro($this->seConnecter($societe->responsable), $societe->magasin);
});

test('l’administrateur coche l’arrondi du prix de vente, choisit l’euro le plus proche, l’essaie et l’enregistre', function () {
    $societe = $this->societeComplete();

    arrondirLePrixDeVenteALEuro($this->seConnecter($societe->administrateur), $societe->magasin);
});
