<?php

use App\Domain\Tenancy\Enums\RoleMagasin;

/*
 * La liste des inventaires d'ouverture d'un magasin offre « Créer » tant
 * que le magasin n'a pas vendu (voir InventaireOuverturePolicy::create()) :
 * le propriétaire puis un responsable du magasin de la gare, qui n'a
 * encore rien vendu, cliquent sur « Créer » et arrivent sur le formulaire
 * d'un nouvel inventaire ; dans la boutique du port, qui a vendu, le
 * bouton ne se montre plus. La société de test a tous ses rôles (voir
 * TestCaseDuNavigateur::societeComplete()).
 */

test('le propriétaire puis un responsable ouvrent un nouvel inventaire d’ouverture depuis la liste d’un magasin qui n’a pas encore vendu', function () {
    $societe = $this->societeComplete();
    $responsable = $this->membre($societe->autreMagasin, RoleMagasin::Responsable, identite: ['name' => 'Gaël Roux']);
    $liste = '/app/'.$societe->autreMagasin->uuid.'/inventaires-ouverture';

    foreach ([$societe->proprietaire, $responsable] as $compte) {
        $this->seConnecter($compte)
            ->aller($liste)
            ->cliquerSurLeLien('Créer')
            ->attendreLaPage('^'.$liste.'/create$')
            ->voir('Produit déjà au catalogue, jamais reçu')
            ->seDeconnecter();
    }

    $onglet = $this->seConnecter($societe->proprietaire)
        ->aller('/app/'.$societe->magasin->uuid.'/inventaires-ouverture')
        ->voir('L\'inventaire d\'ouverture se saisit jusqu\'à la première vente du magasin');
    expect($onglet->page()->evaluate('() => [...document.querySelectorAll("a")].some((lien) => lien.textContent.trim() === "Créer")'))->toBeFalse();
});
