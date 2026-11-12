<?php

/*
 * Le balayage du panneau d'un magasin par le cabinet lié (son propriétaire
 * et le collaborateur à qui le client est confié), et du magasin de
 * démonstration d'une société qui attend son code, par son propriétaire et
 * par ses comptes d'entraînement : chaque page que ses liens atteignent et
 * chaque action visible de chacune, ouverte puis fermée sans rien valider,
 * sans aucune erreur (voir Balayeur et TestCaseDuNavigateur::balayer()). La
 * société de test a tous ses rôles et de quoi remplir chaque page (voir
 * TestCaseDuNavigateur::societeComplete()).
 */

test('le propriétaire du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($societe->proprietaireDuCabinet, panneau: 'cabinet');

    $pages = $this->balayer($onglet, $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/factures', '/app/profile')->not->toContain($magasin.'/caisse', $magasin.'/equipe');
});

test('le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($societe->collaborateur, panneau: 'cabinet');

    $pages = $this->balayer($onglet, $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/factures')->not->toContain($magasin.'/caisse', $magasin.'/equipe');
});

test('le propriétaire d’une société qui attend son code ouvre chaque page et chaque action du magasin de démonstration sans erreur', function () {
    $demonstration = $this->demonstration();
    $magasin = '/app/'.$demonstration->magasin->uuid;
    $onglet = $this->seConnecter($demonstration->proprietaire, panneau: 'societe');

    $pages = $this->balayer($onglet, $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/equipe', $magasin.'/caisse');
});

test('le responsable d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur', function () {
    $demonstration = $this->demonstration();
    $magasin = '/app/'.$demonstration->magasin->uuid;

    $pages = $this->balayer($this->seConnecter($demonstration->responsable), $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/caisse', $magasin.'/equipe');
});

test('le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur', function () {
    $demonstration = $this->demonstration();
    $magasin = '/app/'.$demonstration->magasin->uuid;

    $pages = $this->balayer($this->seConnecter($demonstration->vendeur), $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/caisse');
});
