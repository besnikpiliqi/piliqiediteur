<?php

use Tests\Browser\Support\CartesParPeriode as Cartes;
use Tests\Browser\Support\SemeLesVentesDeTroisAnnees;

uses(SemeLesVentesDeTroisAnnees::class);

/*
 * Les graphiques par mois, par trimestre ou par année sur la page
 * « Tableau de bord de la société » de l'expert-comptable déclaré (choix de
 * l'éditeur du 16/11/2026, lot GRAPH-AN ; décisions de Claude du 22/11/2026,
 * D76) : il découpe la carte, ouvre le détail jusqu'au jour et la liste de
 * ses documents, dont chaque lien mène à la liste du magasin de la société,
 * qu'il voit selon les consultations de sa déclaration. Le jeu est celui de
 * SemeLesVentesDeTroisAnnees.
 */

test('l’expert-comptable déclaré découpe la carte par année, ouvre le détail jusqu’au jour et la liste de ses documents, et suit le lien vers la liste du magasin', function () {
    ['magasin' => $magasin] = $this->semerLesVentesDeTroisAnnees(proprietaireChoisitSonMotDePasse: false);
    $marc = $this->expertComptable($magasin);

    $onglet = $this->seConnecter($marc)
        ->cliquerSurLeLien('Tableau de bord de la société')
        ->attendreQue('location.pathname.endsWith("/tableau-de-bord-de-la-societe")', 'la page de l’expert-comptable ne s’ouvre pas')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 4', 'les quatre cartes ouvertes au départ ne sont pas là');

    Cartes::choisir($onglet, 'decoupage', 'annee');

    expect(Cartes::intitules($onglet))->toBe(['Année 2023', 'Année 2024', 'Année 2025', 'Année 2026']);

    Cartes::ouvrirLaListeDuCinqMars($onglet);
    $listes = Cartes::listeDuJour($onglet);

    expect(array_column($listes, 'nom'))->toBe(['Boutique de la Gare', 'Boutique du Port']);
    expect($listes[1]['liens'][0])->toContain('/app/'.$magasin->uuid.'/factures');

    $onglet->geste(fn () => $onglet->element('[data-liste-du-magasin]:has(h3:text-is("Boutique du Port")) a[data-liste-lien]:text-is("Factures et tickets")')->click(), 'le lien vers la liste du Port')
        ->attendreQue('location.pathname === '.json_encode('/app/'.$magasin->uuid.'/factures'), 'le lien n’ouvre pas la liste des factures')
        ->attendre()->verifier()
        ->voir('Jour d\'émission : 05/03/2026');

    expect(Cartes::nombre($onglet, 'tbody tr.fi-ta-row'))->toBe(1);
});
