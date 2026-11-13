<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SocieteDeTest;

/*
 * La société membre d'un assujetti unique dans le navigateur (P3.1, voir
 * Magasin::REFUS_ASSUJETTI_UNIQUE) : le propriétaire, ou un administrateur
 * autorisé à gérer les paramètres de la société, coche la case de «
 * Paramètres fiscaux » ; ses magasins réels ne vendent plus : l'« À
 * faire » de chaque compte le dit, et « Émettre » ne se montre plus ;
 * décochée, la vente reprend.
 */

const CASE_ASSUJETTI_UNIQUE = 'La société est membre d\'un assujetti unique (groupe TVA, art. 256 C du CGI)';

/**
 * Coche ou décoche, dans « Paramètres fiscaux » de l'espace de la société,
 * la case « membre d'un assujetti unique », le régime de TVA choisi, puis
 * enregistre.
 */
function declarerMembreDUnAssujettiUnique(Navigation $onglet, bool $membre): Navigation
{
    $onglet->cliquerSurLeLien('Paramètres fiscaux')->voir('Paramètres fiscaux de la société')
        ->choisir('Régime de TVA', RegimeTva::ReelNormalMensuel->getLabel());
    $onglet->geste(fn () => $membre
        ? $onglet->element(Navigation::champ(CASE_ASSUJETTI_UNIQUE))->check()
        : $onglet->element(Navigation::champ(CASE_ASSUJETTI_UNIQUE))->uncheck(), 'la case « membre d\'un assujetti unique »');

    return $onglet->soumettre('Enregistrer')->voir('Paramètres fiscaux enregistrés');
}

/**
 * Un brouillon de facture du vendeur à un nouveau client professionnel,
 * dans le magasin de la société de test.
 */
function brouillonAEmettre(SocieteDeTest $societe): Facture
{
    return app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => app(CreerFactureAction::class)->handle($societe->vendeur, Client::factory()->professionnel()->create(), [['produit_id' => Produit::query()->where('reference', 'SAVON')->sole()->id, 'quantite' => 1]]));
}

test('le propriétaire déclare dans « Paramètres fiscaux » la société membre d’un assujetti unique, et le vendeur, à qui son « À faire » le dit, ne peut plus émettre sa facture', function () {
    $societe = $this->societeComplete();
    $brouillon = brouillonAEmettre($societe);

    declarerMembreDUnAssujettiUnique($this->seConnecter($societe->proprietaire, panneau: 'societe'), true)->seDeconnecter();

    expect($societe->societe->fresh()->membre_assujetti_unique)->toBeTrue();
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid)
        ->voir(Magasin::REFUS_ASSUJETTI_UNIQUE)
        ->aller('/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->getRouteKey())
        ->voir('Brouillon (pas encore émise)');
    expect($onglet->element(Navigation::bouton('Émettre'))->count())->toBe(0)
        ->and(app(TenantContext::class)->runAs($societe->magasin, fn (): StatutFacture => $brouillon->fresh()->statut))->toBe(StatutFacture::Brouillon);
});

test('l’administrateur autorisé aux paramètres de la société coche puis décoche « membre d’un assujetti unique », et le vendeur émet de nouveau sa facture', function () {
    $societe = $this->societeComplete();
    $brouillon = brouillonAEmettre($societe);

    $onglet = declarerMembreDUnAssujettiUnique($this->seConnecter($societe->administrateur, panneau: 'societe'), true);
    expect($societe->societe->fresh()->membre_assujetti_unique)->toBeTrue();
    declarerMembreDUnAssujettiUnique($onglet, false)->seDeconnecter();
    expect($societe->societe->fresh()->membre_assujetti_unique)->toBeFalse();

    $this->seConnecter($societe->vendeur)
        ->aller('/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->getRouteKey())
        ->cliquerSur('Émettre')
        ->soumettre('Confirmer')
        ->voir('Émise');
    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): StatutFacture => $brouillon->fresh()->statut))->toBe(StatutFacture::Emise);
});
