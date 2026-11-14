<?php

/*
 * La fenêtre « Créer un avoir fournisseur » du menu des avoirs fournisseur,
 * dans le navigateur : ouverte puis fermée plusieurs fois, elle ne laisse
 * aucune erreur (voir FactureAchatResource::formulaireDeLAvoirFournisseur()).
 * La société de test a une facture d'achat validée dont des produits
 * restent à retourner (voir TestCaseDuNavigateur::societeComplete()).
 */

test('le responsable ouvre et ferme deux fois la fenêtre « Créer un avoir fournisseur » sans erreur', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/avoir-fournisseurs');

    foreach ([1, 2] as $fois) {
        $onglet->cliquerSur('Créer un avoir fournisseur')
            ->voir('Facture d\'achat')
            ->fermerLaFenetre();
    }

    expect($onglet->fenetreOuverte())->toBeFalse();
});
