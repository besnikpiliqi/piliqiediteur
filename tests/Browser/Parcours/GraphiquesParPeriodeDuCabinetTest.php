<?php

use App\Domain\Cabinets\Models\LienCabinet;
use Tests\Browser\Support\CartesParPeriode as Cartes;
use Tests\Browser\Support\SemeLesVentesDeTroisAnnees;

uses(SemeLesVentesDeTroisAnnees::class);

/*
 * Les graphiques par mois, par trimestre ou par année sur la page d'un
 * client de l'espace du cabinet (choix de l'éditeur du 16/11/2026, lot
 * GRAPH-AN ; décisions de Claude du 22/11/2026, D76) : le collaborateur à qui
 * le client est confié découpe la carte, ouvre le détail jusqu'au jour et la
 * liste de ses documents, dont chaque lien mène à la liste du magasin du
 * client, qu'il voit selon les consultations que le client lui accorde. Le
 * jeu est celui de SemeLesVentesDeTroisAnnees.
 */

test('le collaborateur à qui le client est confié découpe la carte par année, ouvre le détail jusqu’au jour et la liste de ses documents, et suit le lien vers la liste du magasin du client', function () {
    ['magasin' => $magasin] = $this->semerLesVentesDeTroisAnnees();
    $collaborateur = $this->collaborateurDuCabinetLie($magasin, nom: 'Paul Lefèvre');
    $cabinet = $collaborateur->societe()->firstOrFail();
    $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->sole();

    $onglet = $this->seConnecter($collaborateur, panneau: 'cabinet')
        ->aller('/cabinet/'.$cabinet->uuid.'/clients/'.$lien->uuid.'/graphiques')->attendre()->verifier()
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 4', 'les quatre cartes ouvertes au départ ne sont pas là');

    expect(Cartes::nombre($onglet, 'select[data-filtre="magasin"]'))->toBe(1, 'Le cabinet choisit entre les magasins que le client lui ouvre.');

    Cartes::choisir($onglet, 'decoupage', 'annee');

    expect(Cartes::intitules($onglet))->toBe(['Année 2023', 'Année 2024', 'Année 2025', 'Année 2026']);

    Cartes::ouvrirLaListeDuCinqMars($onglet);
    $listes = Cartes::listeDuJour($onglet);

    expect(array_column($listes, 'nom'))->toBe(['Boutique de la Gare', 'Boutique du Port']);
    expect(array_map(fn (array $liste): string => $liste['listes'][0], $listes))->toBe(['Factures et tickets 1 20,00 €', 'Factures et tickets 1 40,00 €']);
    expect($listes[1]['liens'][0])->toContain('/app/'.$magasin->uuid.'/factures');

    $onglet->geste(fn () => $onglet->element('[data-liste-du-magasin]:has(h3:text-is("Boutique du Port")) a[data-liste-lien]:text-is("Factures et tickets")')->click(), 'le lien vers la liste du Port')
        ->attendreQue('location.pathname === '.json_encode('/app/'.$magasin->uuid.'/factures'), 'le lien n’ouvre pas la liste des factures du client')
        ->attendre()->verifier()
        ->voir('Jour d\'émission : 05/03/2026');

    expect(Cartes::nombre($onglet, 'tbody tr.fi-ta-row'))->toBe(1);
});
