<?php

use Tests\Browser\Support\CartesParPeriode as Cartes;
use Tests\Browser\Support\SemeLesVentesDeTroisAnnees;

uses(SemeLesVentesDeTroisAnnees::class);

/*
 * Les graphiques par mois, par trimestre ou par année dans le panneau d'un
 * magasin (choix de l'éditeur du 16/11/2026, lot GRAPH-AN ; maquette
 * 01-graph-an.html et décisions de Claude du 22/11/2026, D76) : une barre
 * des filtres sans le menu « Magasin », qui tient sur une seule ligne, les
 * quatre cartes ouvertes au départ, des chiffres qui ne comptent que ce
 * magasin, et la liste d'un jour qui ne nomme aucun magasin et mène à la
 * liste de celui-ci. Le jeu est celui de SemeLesVentesDeTroisAnnees.
 */

test('la barre des filtres du panneau d’un magasin n’a pas de menu « Magasin » et tient sur une seule ligne ; les quatre cartes principales sont ouvertes au départ', function () {
    ['magasin' => $magasin, 'proprietaire' => $proprietaire] = $this->semerLesVentesDeTroisAnnees();
    $onglet = $this->seConnecter($proprietaire)->aller('/app/'.$magasin->uuid)->attendre()->verifier()
        ->attendreQue('(window.scrollTo(0, document.body.scrollHeight), document.querySelectorAll("[data-graphique]").length === 4)', 'les quatre cartes ouvertes au départ ne sont pas là');

    expect($onglet->page()->evaluate('() => Array.from(document.querySelectorAll("[data-graphique]")).map((carte) => carte.dataset.graphique).join(",")'))
        ->toBe('ventes_et_achats_par_mois,tva_par_mois,ca_par_mois,factures_par_anciennete');

    $onglet->page()->setViewportSize(1920, 1000);
    $onglet->attendreQue('window.innerWidth === 1920', 'la fenêtre n’est pas à 1 920 px');
    $barre = Cartes::barreDesFiltres($onglet);

    expect(array_column($barre, 'nom'))->toBe(['Période', 'Découper par', 'Comparer avec l\'année précédente', 'Cumul depuis le début de l\'année', 'Ajouter un graphique']);
    expect(Cartes::nombre($onglet, 'select[data-filtre="magasin"]'))->toBe(0, 'Le panneau d’un magasin ne laisse deviner aucun autre magasin.');
    expect(max(array_column($barre, 'haut')) - min(array_column($barre, 'haut')))->toBeLessThan(8, 'à 1 920 px, la barre tient sur une seule ligne');

    foreach ($barre as $commande) {
        if ($commande['menu']) {
            expect($commande['libelleDroite'])->toBeLessThanOrEqual($commande['commandeGauche'] + 1, "le libellé « {$commande['nom']} » est à gauche de son menu");
        }
    }

    expect(Cartes::debordements($onglet))->toBe([]);
});

test('dans le panneau d’un magasin, la carte ne compte que ce magasin, et la liste d’un jour n’en nomme aucun et mène à sa liste filtrée', function () {
    ['magasin' => $magasin, 'proprietaire' => $proprietaire] = $this->semerLesVentesDeTroisAnnees();
    $onglet = $this->seConnecter($proprietaire)->aller('/app/'.$magasin->uuid)->attendre()->verifier()
        ->attendreQue('(window.scrollTo(0, document.body.scrollHeight), document.querySelectorAll("[data-graphique]").length === 4)', 'les quatre cartes ouvertes au départ ne sont pas là');

    Cartes::choisir($onglet, 'decoupage', 'annee');

    expect(Cartes::colonnes($onglet)[3]['libelle'])->toStartWith('Année 2026 : 100,00', 'La Gare n’est pas comptée : 100,00 € et non 120,00 €.');

    Cartes::ouvrirLaListeDuCinqMars($onglet);
    $listes = Cartes::listeDuJour($onglet);

    expect($listes)->toHaveCount(1);
    expect($listes[0]['nom'])->toBeNull('Aucun nom de magasin dans le panneau d’un magasin.');
    expect($listes[0]['listes'])->toBe(['Factures et tickets 1 40,00 €', 'Avoirs 0 0,00 €']);
    expect($listes[0]['liens'][0])->toContain('/app/'.$magasin->uuid.'/factures');

    $onglet->geste(fn () => $onglet->element('a[data-liste-lien]:text-is("Factures et tickets")')->click(), 'le lien vers la liste des factures')
        ->attendreQue('location.pathname === '.json_encode('/app/'.$magasin->uuid.'/factures'), 'le lien n’ouvre pas la liste des factures du magasin')
        ->attendre()->verifier()
        ->voir('Jour d\'émission : 05/03/2026');

    expect(Cartes::nombre($onglet, 'tbody tr.fi-ta-row'))->toBe(1);
});
