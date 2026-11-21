<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Tests\Browser\Support\Navigation;

/*
 * La Guadeloupe et la Martinique, un seul territoire de TVA, dans le
 * navigateur (décisions de Claude T2.2 et P41.4 de l'onglet « À faire
 * vérifier par un professionnel », voir ControleNatureOperation) : chaque
 * compte qui voit « Émettre » sur la fiche d'un brouillon d'un magasin de
 * la Guadeloupe, en « Livraison vers un DOM » à Fort-de-France, lit
 * l'alerte qui dit pourquoi l'émission est refusée, et la facture reste en
 * brouillon, sans numéro.
 */

const REFUS_ENTRE_LES_ANTILLES = 'Impossible d\'émettre en « Livraison vers un DOM » : la Guadeloupe et la Martinique forment un seul territoire de TVA, où la vente reste au taux du magasin. Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies.';

/**
 * Le brouillon d'une facture de ce compte à Marie Joseph, d'une lampe à
 * 108,50 € TTC à 8,5 %, en « Livraison vers un DOM », expédiée par le
 * magasin à Fort-de-France.
 */
function brouillonVersFortDeFrance(Magasin $magasin, User $auteur): Facture
{
    return app(TenantContext::class)->runAs($magasin, function () use ($auteur): Facture {
        $lampe = Produit::factory()->create(['nom' => 'Lampe de chevet', 'reference' => 'LAMPE', 'prix_vente_ttc' => '108.50', 'taux_tva' => '8.5', 'suivi_stock' => false]);

        return app(CreerFactureAction::class)->handle($auteur, Client::factory()->create(['nom' => 'Marie Joseph']), [['produit_id' => $lampe->id, 'quantite' => 1]], [
            'nature_operation' => NatureOperation::LivraisonDom,
            'livraison_adresse' => '1 rue Victor Hugo',
            'livraison_code_postal' => '97200',
            'livraison_ville' => 'Fort-de-France',
            'livraison_pays' => 'FR',
        ]);
    });
}

/**
 * Ce compte ouvre la fiche du brouillon, clique « Émettre » puis
 * « Confirmer » : l'alerte dit le refus, la fiche montre encore le
 * brouillon, et la facture reste en brouillon, sans numéro.
 */
function emissionRefuseeVersFortDeFrance(Navigation $onglet, Magasin $magasin, Facture $brouillon): void
{
    $onglet->aller('/app/'.$magasin->uuid.'/factures/'.$brouillon->getRouteKey())
        ->voir('Brouillon (pas encore émise)')
        ->cliquerSur('Émettre')
        ->soumettre('Confirmer')
        ->voir(REFUS_ENTRE_LES_ANTILLES)
        ->voir('Brouillon (pas encore émise)');

    $relu = app(TenantContext::class)->runAs($magasin, fn (): Facture => $brouillon->fresh());
    expect([$relu->statut, $relu->numero])->toBe([StatutFacture::Brouillon, null]);
}

test('le responsable d’un magasin de la Guadeloupe ne peut pas émettre une livraison vers un DOM à Fort-de-France : l’alerte dit pourquoi et la facture reste en brouillon', function () {
    $magasin = $this->ouvrirUneSocieteSituee(Territoire::Guadeloupe, ['nom' => 'Boutique de Pointe-à-Pitre', 'code' => 'PAP']);
    $responsable = $this->membre($magasin, RoleMagasin::Responsable, identite: ['name' => 'Rose Martin']);
    app(TenantContext::class)->clear();

    emissionRefuseeVersFortDeFrance($this->seConnecter($responsable), $magasin, brouillonVersFortDeFrance($magasin, $responsable));
});

test('le vendeur d’un magasin de la Guadeloupe ne peut pas émettre sa livraison vers un DOM à Fort-de-France : l’alerte dit pourquoi et la facture reste en brouillon', function () {
    $magasin = $this->ouvrirUneSocieteSituee(Territoire::Guadeloupe, ['nom' => 'Boutique de Pointe-à-Pitre', 'code' => 'PAP']);
    $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, identite: ['name' => 'Victor Durand']);
    app(TenantContext::class)->clear();

    emissionRefuseeVersFortDeFrance($this->seConnecter($vendeur), $magasin, brouillonVersFortDeFrance($magasin, $vendeur));
});

test('l’administrateur autorisé à vendre dans un magasin de la Guadeloupe ne peut pas émettre une livraison vers un DOM à Fort-de-France : l’alerte dit pourquoi et la facture reste en brouillon', function () {
    $magasin = $this->ouvrirUneSocieteSituee(Territoire::Guadeloupe, ['nom' => 'Boutique de Pointe-à-Pitre', 'code' => 'PAP']);
    $administrateur = $this->administrateur($magasin, null, [], [...PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), ...PermissionRegistry::deVente()], 'Xavier Garnier');
    app(TenantContext::class)->clear();

    emissionRefuseeVersFortDeFrance($this->seConnecter($administrateur), $magasin, brouillonVersFortDeFrance($magasin, $administrateur));
});
