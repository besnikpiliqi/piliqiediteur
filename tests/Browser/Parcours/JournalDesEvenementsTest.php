<?php

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Tenancy\Services\TenantContext;

/*
 * L'export du journal des événements de la caisse de bout en bout, comme
 * une personne le fait dans le navigateur : la fenêtre de l'export, sa
 * période par défaut (du premier du mois à aujourd'hui), le fichier CSV que
 * le navigateur reçoit, et l'export inscrit lui-même au journal. Le
 * balayage n'en valide jamais la fenêtre : l'export écrit au journal. La
 * société de test a tous ses rôles (voir
 * TestCaseDuNavigateur::societeComplete()).
 */

test('le responsable exporte le journal des événements en CSV, et l’export s’inscrit au journal', function () {
    $societe = $this->societeComplete();

    $onglet = $this->seConnecter($societe->responsable)
        ->aller('/app/'.$societe->magasin->uuid.'/caisse-securisee')
        ->cliquerSur('Exporter le journal (CSV)')
        ->soumettre('Exporter');
    $fichiers = $onglet->telechargements();
    $dernier = app(TenantContext::class)->runAs($societe->magasin, fn (): EnregistrementFiscal => EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->orderByDesc('sequence')->firstOrFail());

    expect($fichiers)->toHaveCount(1)
        ->and($fichiers[0]['nom'])->toBe('journal-des-evenements-du-2026-03-01-au-2026-03-10.csv')
        ->and($fichiers[0]['type'])->toContain('text/csv')
        ->and($fichiers[0]['debut'])->toStartWith('Séquence;')
        ->and($dernier->contenuSigne()['donnees']['code'])->toBe(CodeEvenement::ExportDeDonnees->value);
});
