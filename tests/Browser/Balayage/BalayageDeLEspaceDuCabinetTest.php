<?php

/*
 * Le balayage de l'espace d'un cabinet d'expertise comptable, rôle par
 * rôle : chaque page que ses liens atteignent et chaque action visible de
 * chacune, ouverte puis fermée sans rien valider, sans aucune erreur (voir
 * Balayeur et TestCaseDuNavigateur::balayer()).
 */

test('le propriétaire du cabinet ouvre chaque page et chaque action de l’espace du cabinet sans erreur', function () {
    $societe = $this->societeComplete();
    $espace = '/cabinet/'.$societe->cabinet->uuid;

    $pages = $this->balayer($this->seConnecter($societe->proprietaireDuCabinet, panneau: 'cabinet'), $espace, [$espace], __FILE__);

    expect($pages)->toContain($espace.'/collaborateurs', $espace.'/invitations-et-demandes', $espace.'/journal-audit');
});

test('le collaborateur ouvre chaque page et chaque action de l’espace du cabinet sans erreur', function () {
    $societe = $this->societeComplete();
    $espace = '/cabinet/'.$societe->cabinet->uuid;

    $pages = $this->balayer($this->seConnecter($societe->collaborateur, panneau: 'cabinet'), $espace, [$espace], __FILE__);

    expect($pages)->toContain($espace.'/clients')->not->toContain($espace.'/collaborateurs', $espace.'/journal-audit');
});
