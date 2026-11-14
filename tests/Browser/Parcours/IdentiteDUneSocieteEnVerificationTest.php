<?php

/*
 * Constat C7 de l'éditeur : la page « Identité » d'une société qui attend
 * le code de son courrier recommandé, et dont l'identité n'a encore ni
 * capital social ni immatriculation, dit qu'aucune vente n'est possible
 * dans ses magasins réels avant sa validation, jamais que ses tickets de
 * caisse restent possibles. Le propriétaire s'y rend depuis son tableau
 * de bord, qui dit la même chose.
 */

test('la page Identité d’une société en cours de vérification n’annonce aucune vente', function () {
    $societe = $this->inscrireUneSociete();
    $avis = 'Votre société est en cours de vérification : aucune vente n\'est possible dans vos magasins réels avant sa validation, et le magasin de démonstration vous permet de vous essayer. Ces mentions seront nécessaires pour facturer un professionnel : capital social, immatriculation.';

    $onglet = $this->seConnecter($societe->proprietaire()->sole());

    expect($onglet->chemin())->toBe('/societe/'.$societe->uuid);

    $onglet->voir('Identité incomplète pour facturer un professionnel')
        ->voir($avis)
        ->nePasVoir('tickets de caisse restent possibles')
        ->cliquerSurLeLien('Identité')
        ->attendreLaPage('^/societe/'.$societe->uuid.'/identite-societe$')
        ->voir('Identité incomplète pour facturer un professionnel')
        ->voir($avis)
        ->nePasVoir('tickets de caisse restent possibles');
});
