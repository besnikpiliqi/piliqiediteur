<?php

/*
 * Le balayage du panneau d'un magasin par l'équipe du magasin (responsable,
 * vendeur, observateur) : chaque page que ses liens atteignent et chaque
 * action visible de chacune, ouverte puis fermée sans rien valider, sans
 * aucune erreur (voir Balayeur et TestCaseDuNavigateur::balayer()). La
 * société de test a tous ses rôles et de quoi remplir chaque page (voir
 * TestCaseDuNavigateur::societeComplete()).
 */

test('le responsable ouvre chaque page et chaque action du magasin sans erreur', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;

    $pages = $this->balayer($this->seConnecter($societe->responsable), $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/caisse', $magasin.'/equipe', $magasin.'/facture-achats/create');
});

test('le vendeur ouvre chaque page et chaque action du magasin sans erreur', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;

    $pages = $this->balayer($this->seConnecter($societe->vendeur), $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/caisse')->not->toContain($magasin.'/equipe', $magasin.'/caisse-securisee');
});

test('l’observateur ouvre chaque page et chaque action du magasin sans erreur', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;

    $pages = $this->balayer($this->seConnecter($societe->observateur), $magasin, [$magasin], __FILE__);

    expect($pages)->not->toContain($magasin.'/caisse', $magasin.'/equipe');
});
