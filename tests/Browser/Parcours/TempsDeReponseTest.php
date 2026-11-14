<?php

/*
 * Le temps de réponse des pages principales du magasin, de l'espace de la
 * société et de celui d'un cabinet, mesuré dans le navigateur (décision
 * de l'éditeur du 01/11/2026, point 5) : de la
 * demande de la page à la fin de son chargement (Navigation Timing,
 * loadEventEnd), chaque page s'ouvre en moins de PLAFOND_EN_MS, sur un
 * magasin rempli (voir TestCaseDuNavigateur::remplirLeMagasin()). Le
 * plafond est large : le serveur du test sert une requête à la fois, sur
 * une machine que d'autres tests occupent ; il arrête une page devenue
 * lente (une requête par ligne, un calcul répété), pas une variation de
 * la machine. La charge réelle et le volume restent à la campagne de
 * tests (étape 1 bis).
 */

use Tests\Browser\Support\Navigation;

const PLAFOND_EN_MS = 5000;

test('chaque page principale du magasin s’ouvre pour le responsable en moins du plafond', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($societe->responsable);

    foreach (['', '/factures', '/caisse', '/clients', '/produits', '/paiements', '/avoirs', '/facture-achats', '/cloture-caisses'] as $page) {
        verifierLeTempsDeLaPage($onglet->aller($magasin.$page), $magasin.$page);
    }
});

test('le tableau de bord et les caisses de la société s’ouvrent pour son propriétaire en moins du plafond', function () {
    $societe = $this->societeComplete();
    $espace = '/societe/'.$societe->societe->uuid;
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe');

    foreach (['', '/caisses'] as $page) {
        verifierLeTempsDeLaPage($onglet->aller($espace.$page), $espace.$page);
    }
});

test('le tableau de bord du cabinet s’ouvre pour son propriétaire en moins du plafond', function () {
    $societe = $this->societeComplete();
    $cabinet = '/cabinet/'.$societe->cabinet->uuid;

    verifierLeTempsDeLaPage($this->seConnecter($societe->proprietaireDuCabinet, panneau: 'cabinet')->aller($cabinet), $cabinet);
});

/**
 * De la demande de la page à la fin de son chargement (Navigation Timing).
 */
function verifierLeTempsDeLaPage(Navigation $onglet, string $adresse): void
{
    $duree = $onglet->page()->evaluate('() => performance.getEntriesByType("navigation")[0].loadEventEnd');

    expect($duree)->toBeGreaterThan(0)
        ->and($duree)->toBeLessThan(PLAFOND_EN_MS, "La page {$adresse} s'ouvre en {$duree} ms.");
}
