<?php

/*
 * Le balayage de l'espace d'administration d'une société, rôle par rôle,
 * et de celui d'une société qui attend le code de son courrier, par son
 * propriétaire : chaque page que ses liens atteignent et chaque action
 * visible de chacune, ouverte puis fermée sans rien valider, sans aucune
 * erreur (voir Balayeur et TestCaseDuNavigateur::balayer()).
 */

test('le propriétaire ouvre chaque page et chaque action de l’espace de sa société sans erreur', function () {
    $societe = $this->societeComplete();
    $espace = '/societe/'.$societe->societe->uuid;

    $pages = $this->balayer($this->seConnecter($societe->proprietaire, panneau: 'societe'), $espace, [$espace], __FILE__);

    expect($pages)->toContain($espace.'/journal-audit', $espace.'/cabinet-comptable', $espace.'/magasins');
});

test('l’administrateur qui a toutes les autorisations de société ouvre chaque page et chaque action de l’espace sans erreur', function () {
    $societe = $this->societeComplete();
    $espace = '/societe/'.$societe->societe->uuid;

    $pages = $this->balayer($this->seConnecter($societe->administrateur, panneau: 'societe'), $espace, [$espace], __FILE__);

    expect($pages)->toContain($espace.'/magasins')->not->toContain($espace.'/journal-audit');
});

test('l’administrateur sans autorisation de société ouvre chaque page et chaque action de l’espace sans erreur', function () {
    $societe = $this->societeComplete();
    $espace = '/societe/'.$societe->societe->uuid;

    $pages = $this->balayer($this->seConnecter($societe->administrateurVendeur, panneau: 'societe'), $espace, [$espace], __FILE__);

    expect($pages)->not->toContain($espace.'/journal-audit', $espace.'/cabinet-comptable');
});

test('le propriétaire d’une société qui attend son code ouvre chaque page et chaque action de l’espace de sa société sans erreur', function () {
    $demonstration = $this->demonstration();
    $espace = '/societe/'.$demonstration->societe->uuid;

    $pages = $this->balayer($this->seConnecter($demonstration->proprietaire, panneau: 'societe'), $espace, [$espace], __FILE__);

    expect($pages)->toContain($espace.'/verification-societe', '/societe/profile');
});
