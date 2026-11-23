<?php

use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Charges\Services\PiecesDesCharges;
use Illuminate\Support\Facades\Storage;
use Tests\Browser\Support\ChargesDeChaqueEtat;
use Tests\Browser\Support\Navigation;

/*
 * Les écrans se dessinent comme leur modèle choisi (constat de l'éditeur du
 * 15/11/2026, lot DATEOP : des classes de style absentes de la feuille de
 * Filament ne faisaient rien) : sur la page « Charges » de l'espace de la
 * société, chaque case du tableau des mois est un carré coloré de l'état
 * de ses charges, en clair comme en sombre (modèle C1) ; et les grilles à
 * deux colonnes (La société, la fiche d'un client du cabinet, Télécharger
 * les documents) en ont bien deux sur un écran de 1 024 px.
 */

beforeEach(function () {
    Storage::fake(PiecesDesCharges::DISQUE);
});

/**
 * La couleur de fond calculée de la case de mars 2026 de ce type, et sa
 * taille.
 *
 * @return array{fond: string, largeur: float, hauteur: float}
 */
function caseDuTableau(Navigation $onglet, string $type): array
{
    $case = $onglet->page()->evaluate('() => { const c = document.querySelector(\'tr[data-type-de-charge="'.$type.'"] [data-mois="2026-03"]\'); const b = c.getBoundingClientRect(); return { fond: getComputedStyle(c).backgroundColor, largeur: b.width, hauteur: b.height }; }');

    return ['fond' => (string) $case['fond'], 'largeur' => (float) $case['largeur'], 'hauteur' => (float) $case['hauteur']];
}

/**
 * La teinte dominante d'une couleur calculée « rgb(r, g, b) » ou
 * « rgba(r, g, b, a) » : verte, orangée, rouge ou grise, ou
 * « transparente » quand elle ne colore rien.
 */
function teinte(string $couleur): string
{
    preg_match_all('/[\d.]+/', $couleur, $nombres);
    [$r, $g, $b] = array_map(floatval(...), array_slice($nombres[0], 0, 3));
    $alpha = isset($nombres[0][3]) ? (float) $nombres[0][3] : 1.0;

    return match (true) {
        $alpha === 0.0 => 'transparente',
        max($r, $g, $b) - min($r, $g, $b) < 16 => 'grise',
        $g > $r && $g > $b => 'verte',
        $r > $g && $g > $b && $g - $b > 20 => 'orangée',
        $r > $g && $r > $b => 'rouge',
        default => 'autre ('.$couleur.')',
    };
}

test('chaque case du tableau des charges est un carré coloré de son état, en clair comme en sombre', function () {
    $societe = $this->societeComplete();
    ChargesDeChaqueEtat::deposer($societe);
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->aller('/societe/'.$societe->societe->uuid.'/charges')
        ->attendreQue('document.querySelector(\'[data-tableau-des-mois] [data-case]\') !== null', 'le tableau des mois n’a pas de case');
    $attendues = ['piliqi:loyer' => 'verte', 'piliqi:electricite' => 'orangée', 'piliqi:telephone_internet' => 'grise', 'piliqi:honoraires' => 'rouge'];

    foreach (['clair' => false, 'sombre' => true] as $sombre) {
        $onglet->page()->evaluate('(sombre) => document.documentElement.classList.toggle("dark", sombre)', $sombre);

        foreach ($attendues as $type => $couleur) {
            $case = caseDuTableau($onglet, $type);

            expect(teinte($case['fond']))->toBe($couleur, $type.' ('.($sombre ? 'sombre' : 'clair').') : '.$case['fond'])
                ->and($case['largeur'])->toBeGreaterThanOrEqual(30.0)
                ->and($case['hauteur'])->toBeGreaterThanOrEqual(24.0);
        }
    }

    $onglet->voir('Rien de saisi')
        ->attendreQue('getComputedStyle(document.querySelector("[data-legende-des-charges] .piliqi-charges__case--validee")).backgroundColor !== "rgba(0, 0, 0, 0)"', 'la légende n’a pas les carrés colorés des cases')
        ->attendreQue('document.querySelector(\'[data-etat-de-la-charge="renvoyee"] .piliqi-charges__case--renvoyee\') !== null', 'l’état de la charge renvoyée n’a pas sa pastille dans la liste');
});

/**
 * Le nombre de colonnes de la première grille de ce sélecteur dans la page
 * ouverte.
 */
function colonnesDeLaGrille(Navigation $onglet, string $selecteur): int
{
    return (int) $onglet->page()->evaluate('(selecteur) => getComputedStyle(document.querySelector(selecteur)).gridTemplateColumns.split(" ").length', $selecteur);
}

test('les grilles à deux colonnes de « Télécharger les documents » et de la fiche d’un client du cabinet en ont deux sur un écran de 1 024 px', function () {
    $societe = $this->societeComplete();
    $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->societe->getKey())->sole();

    $proprietaire = $this->seConnecter($societe->proprietaire, panneau: 'societe');
    $proprietaire->page()->setViewportSize(1024, 800);
    $proprietaire->aller('/societe/'.$societe->societe->uuid.'/telecharger-les-documents');

    $cabinet = $this->seConnecter($societe->proprietaireDuCabinet, panneau: 'cabinet');
    $cabinet->page()->setViewportSize(1024, 800);
    $cabinet->aller('/cabinet/'.$societe->cabinet->uuid.'/clients/'.$lien->uuid);

    expect([colonnesDeLaGrille($proprietaire, 'fieldset .grid'), colonnesDeLaGrille($cabinet, 'dl.grid')])->toBe([2, 2]);
});

test('la grille à deux colonnes de « La société » de l’expert-comptable déclaré en a deux sur un écran de 1 024 px', function () {
    $this->travelTo(self::aParis('2026-03-09 08:00:00'));
    $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon 1']));
    $marc = $this->expertComptable($magasin);
    $this->travelTo(self::instantDuTest());

    $onglet = $this->seConnecter($marc);
    $onglet->page()->setViewportSize(1024, 800);
    $onglet->aller('/app/'.$magasin->uuid.'/la-societe');

    expect(colonnesDeLaGrille($onglet, 'dl.grid'))->toBe(2);
});
