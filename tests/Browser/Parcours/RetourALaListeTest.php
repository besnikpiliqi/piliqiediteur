<?php

use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;

/*
 * Après chaque enregistrement, retour à la page parente (D34, messages 71
 * et 110 de l'éditeur) : « Sauvegarder » sur la page d'un magasin de
 * l'espace de la société ramène à la liste des magasins, et sur la page de
 * modification d'un inventaire d'ouverture, à la liste des inventaires,
 * pour chaque rôle qui voit ces pages.
 */

/**
 * Un inventaire d'ouverture en brouillon de ce magasin, d'une ligne :
 * 10 carnets à 2,00 € HT.
 */
function inventaireEnBrouillon(Magasin $magasin, User $auteur): InventaireOuverture
{
    return app(TenantContext::class)->runAs($magasin, fn (): InventaireOuverture => app(CreerInventaireOuvertureAction::class)->handle($auteur, [
        ['produit' => ['nom' => 'Carnet A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
    ]));
}

test('le propriétaire et l’administrateur qui gère les magasins enregistrent la page d’un magasin et reviennent à la liste des magasins', function () {
    $societe = $this->societeComplete();
    $liste = '^/societe/'.$societe->societe->uuid.'/magasins$';

    foreach (['Gare et Port' => $societe->proprietaire, 'Gare du Nord' => $societe->administrateur] as $nom => $compte) {
        $this->seConnecter($compte)
            ->aller('/societe/'.$societe->societe->uuid.'/magasins/'.$societe->autreMagasin->getRouteKey())
            ->remplir('Nom du magasin', $nom)
            ->soumettre('Sauvegarder')
            ->attendreLaPage($liste)
            ->voir($nom)
            ->seDeconnecter();

        expect($societe->autreMagasin->fresh()->nom)->toBe($nom);
    }
});

test('le propriétaire et un responsable enregistrent un inventaire d’ouverture en brouillon et reviennent à la liste des inventaires', function () {
    $societe = $this->societeComplete();
    $responsable = $this->membre($societe->autreMagasin, RoleMagasin::Responsable, identite: ['name' => 'Gaël Roux']);
    $liste = '^/app/'.$societe->autreMagasin->uuid.'/inventaires-ouverture$';

    foreach (['Recompté le matin' => $societe->proprietaire, 'Recompté le soir' => $responsable] as $note => $compte) {
        $inventaire = inventaireEnBrouillon($societe->autreMagasin, $compte);

        $this->seConnecter($compte)
            ->aller('/app/'.$societe->autreMagasin->uuid.'/inventaires-ouverture/'.$inventaire->getRouteKey().'/edit')
            ->remplir('Notes', $note)
            ->soumettre('Sauvegarder')
            ->attendreLaPage($liste)
            ->seDeconnecter();

        expect(app(TenantContext::class)->runAs($societe->autreMagasin, fn (): ?string => $inventaire->fresh()?->notes))->toBe($note);
    }
});
