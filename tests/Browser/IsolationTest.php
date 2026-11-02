<?php

use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Services\TenantContext;

/*
 * L'isolation des magasins et des sociétés dans le navigateur : un compte
 * qui tape à la main l'adresse d'un autre magasin, d'une autre société ou
 * d'un document qui n'est pas le sien reçoit la page introuvable, sans
 * rien en apprendre (voir CLAUDE.md, « Produit »).
 */

test('le vendeur d’un magasin qui tape l’adresse d’un autre magasin de sa société reçoit la page introuvable', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->vendeur);
    $gare = '/app/'.$societe->autreMagasin->uuid.'/factures';
    $this->tolerer(404, $gare);

    $onglet->aller($gare)->voir('Page introuvable')->nePasVoir('Boutique de la Gare');
});

test('le propriétaire d’une société qui tape l’adresse d’une facture ou de l’espace d’une autre société reçoit la page introuvable', function () {
    $autre = $this->societeComplete();
    $facture = app(TenantContext::class)->runAs($autre->magasin, fn (): Facture => Facture::query()->whereNotNull('numero')->firstOrFail());
    app(TenantContext::class)->clear();
    $magasin = $this->ouvrirUneSociete(['nom' => 'Épicerie du Marché']);
    $proprietaire = $this->choisirSonMotDePasse($this->proprietaireDe($magasin));
    $onglet = $this->seConnecter($proprietaire);
    $adresses = ['/app/'.$autre->magasin->uuid.'/factures/'.$facture->id, '/app/'.$magasin->uuid.'/factures/'.$facture->id, '/societe/'.$autre->societe->uuid];

    foreach ($adresses as $adresse) {
        $this->tolerer(404, $adresse);
        $onglet->aller($adresse)->voir('Page introuvable')->nePasVoir($facture->numero)->nePasVoir('Hôtel des Pins');
    }
});

test('le collaborateur d’un cabinet qui tape l’adresse du magasin d’une société qui n’est pas son client reçoit la page introuvable', function () {
    $societe = $this->societeComplete();
    $autreMagasin = $this->ouvrirUneSociete(['nom' => 'Épicerie du Marché']);
    $onglet = $this->seConnecter($societe->collaborateur, panneau: 'cabinet');
    $adresse = '/app/'.$autreMagasin->uuid;
    $this->tolerer(404, $adresse);

    $onglet->aller($adresse)->voir('Page introuvable')->nePasVoir('Épicerie du Marché');
});
