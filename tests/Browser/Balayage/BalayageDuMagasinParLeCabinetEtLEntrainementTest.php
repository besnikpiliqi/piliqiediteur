<?php

/*
 * Le balayage du panneau d'un magasin par le collaborateur du cabinet lié,
 * et par un vendeur d'entraînement dans le magasin de démonstration :
 * chaque page que ses liens atteignent et chaque action visible de chacune,
 * ouverte puis fermée sans rien valider, sans aucune erreur (voir Balayeur
 * et TestCaseDuNavigateur::balayer()). La société de test a tous ses rôles
 * et de quoi remplir chaque page (voir
 * TestCaseDuNavigateur::societeComplete()).
 */

test('le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($societe->collaborateur, panneau: 'cabinet');

    $pages = $this->balayer($onglet, $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/factures')->not->toContain($magasin.'/caisse', $magasin.'/equipe');
});

test('le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur', function () {
    [$demonstration, $vendeur] = $this->demonstrationAvecUnCompteDEntrainement();
    $magasin = '/app/'.$demonstration->uuid;

    $pages = $this->balayer($this->seConnecter($vendeur), $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/caisse');
});
