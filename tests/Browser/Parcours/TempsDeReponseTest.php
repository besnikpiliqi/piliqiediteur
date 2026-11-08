<?php

/*
 * Le temps de réponse des pages principales du magasin, mesuré dans le
 * navigateur (décision de l'éditeur du 01/11/2026, point 5) : de la
 * demande de la page à la fin de son chargement (Navigation Timing,
 * loadEventEnd), chaque page s'ouvre en moins de PLAFOND_EN_MS, sur un
 * magasin rempli (voir TestCaseDuNavigateur::remplirLeMagasin()). Le
 * plafond est large : le serveur du test sert une requête à la fois, sur
 * une machine que d'autres tests occupent ; il arrête une page devenue
 * lente (une requête par ligne, un calcul répété), pas une variation de
 * la machine. La charge réelle et le volume restent à la campagne de
 * tests (étape 1 bis).
 */

const PLAFOND_EN_MS = 5000;

test('chaque page principale du magasin s’ouvre pour le responsable en moins du plafond', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($societe->responsable);

    foreach (['', '/factures', '/caisse', '/clients', '/produits', '/paiements', '/avoirs', '/facture-achats', '/cloture-caisses'] as $page) {
        $onglet->aller($magasin.$page);
        $duree = $onglet->page()->evaluate('() => performance.getEntriesByType("navigation")[0].loadEventEnd');

        expect($duree)->toBeGreaterThan(0)
            ->and($duree)->toBeLessThan(PLAFOND_EN_MS, "La page {$magasin}{$page} s'ouvre en {$duree} ms.");
    }
});
