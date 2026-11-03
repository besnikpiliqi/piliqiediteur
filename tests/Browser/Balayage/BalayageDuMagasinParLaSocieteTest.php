<?php

/*
 * Le balayage du panneau d'un magasin par le propriétaire et les
 * administrateurs de la société : chaque page que ses liens atteignent et
 * chaque action visible de chacune, ouverte puis fermée sans rien valider,
 * sans aucune erreur (voir Balayeur et TestCaseDuNavigateur::balayer()). La
 * société de test a tous ses rôles et de quoi remplir chaque page (voir
 * TestCaseDuNavigateur::societeComplete()).
 */

test('le propriétaire ouvre chaque page et chaque action du magasin sans erreur', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;

    $pages = $this->balayer($this->seConnecter($societe->proprietaire), $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/caisse-securisee', $magasin.'/equipe', $magasin.'/postes-reconnus')
        ->not->toContain($magasin.'/caisse');
});

test('l’administrateur sans la vente ouvre chaque page et chaque action du magasin sans erreur', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;

    $pages = $this->balayer($this->seConnecter($societe->administrateur), $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/factures')->not->toContain($magasin.'/caisse', $magasin.'/equipe');
});

test('l’administrateur autorisé à vendre ouvre chaque page et chaque action du magasin sans erreur', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;

    $pages = $this->balayer($this->seConnecter($societe->administrateurVendeur), $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/caisse', $magasin.'/factures/create');
});
