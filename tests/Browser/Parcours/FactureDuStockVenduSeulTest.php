<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\ContrasteDesPages;

/*
 * La facture du stock vendu seul d'un magasin fermé et le message de la
 * cession, dans le navigateur (P4.1 et P4.2, voir VenteDuStockSeul et
 * MessageDeCession) : dans la Boutique du Port, fermée par son
 * propriétaire, le responsable prépare la facture du stock à l'Hôtel des
 * Pins depuis la liste des factures, puis l'émet depuis sa fiche, le
 * magasin restant en lecture seule pour toute autre vente ; le vendeur
 * la voit aussi, jamais l'observateur ni le propriétaire ; avant de
 * céder le magasin, le propriétaire lit la valeur du stock et la
 * différence entre la transmission sans TVA et la vente du stock seul, en
 * clair comme en sombre.
 */

/**
 * La Boutique du Port, fermée temporairement par son propriétaire (son
 * mot de passe choisi, voir TestCase::MOT_DE_PASSE_CHOISI), qui y garde ses
 * produits en stock.
 */
function leMagasinFerme(object $societe): Magasin
{
    app(FermerMagasinAction::class)->handle($societe->proprietaire, $societe->magasin->fresh(), MotifFermeture::Temporaire, 'mot-de-passe-sur');

    return $societe->magasin->fresh();
}

test('le responsable d’un magasin fermé prépare la facture du stock à un professionnel, puis l’émet depuis sa fiche', function () {
    $societe = $this->societeComplete();
    $magasin = leMagasinFerme($societe);
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$magasin->uuid.'/factures');

    $onglet->cliquerSur('Facturer le stock')
        ->voir("Ce magasin est fermé : cette facture du stock vendu seul, avec TVA, est la seule vente qu'il fasse encore.")
        ->choisirDansLaListe('Client professionnel', 'Hôtel des Pins')
        ->voir('BOUGIE — Bougie parfumée')
        ->soumettre('Préparer la facture')
        ->voir('La facture du stock est prête : relisez-la, puis émettez-la.')
        ->voir("Vente du stock d'un magasin fermé, facturée seule, avec TVA.")
        ->nePasVoir('Modifier');

    $brouillon = app(TenantContext::class)->runAs($magasin, fn (): Facture => Facture::query()->sole());
    expect($brouillon->statut)->toBe(StatutFacture::Brouillon)
        ->and($brouillon->vente_du_stock_seul)->toBeTrue();

    $onglet->cliquerSur('Émettre')
        ->soumettre('Confirmer')
        ->voir('Émise');

    $facture = app(TenantContext::class)->runAs($magasin, fn (): Facture => $brouillon->fresh());
    expect($facture->statut)->toBe(StatutFacture::Emise)
        ->and($facture->numero)->toStartWith('FAC-PORT-2026-')
        ->and(app(TenantContext::class)->runAs($magasin, fn (): int => Produit::query()->where('reference', 'BOUGIE')->sole()->stock_quantite))->toBe(0);
    $onglet->aller('/app/'.$magasin->uuid.'/factures')->nePasVoir('Nouvelle facture');
});

test('le vendeur voit « Facturer le stock » dans un magasin fermé, jamais l’observateur ni le propriétaire', function (string $role, bool $voit) {
    $societe = $this->societeComplete();
    $magasin = leMagasinFerme($societe);
    $onglet = $this->seConnecter($societe->{$role})->aller('/app/'.$magasin->uuid.'/factures')->voir('Factures');

    $voit ? $onglet->voir('Facturer le stock') : $onglet->nePasVoir('Facturer le stock');
})->with([
    'vendeur' => ['vendeur', true],
    'observateur' => ['observateur', false],
    'propriétaire' => ['proprietaire', false],
]);

test('le propriétaire lit, avant de confirmer la cession, la valeur du stock et la différence entre la transmission sans TVA et la vente du stock seul, en clair et en sombre', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->aller('/societe/'.$societe->societe->uuid.'/magasins/'.$societe->magasin->getRouteKey());

    $onglet->cliquerSur('Céder le magasin')
        ->voir('Valeur du stock au coût moyen :')
        ->voir('(valeur comptable, pas un prix de vente).')
        ->voir("la cession se fait sans TVA (transmission d'universalité, art. 257 bis du CGI)")
        ->voir('« Autres opérations non imposables »')
        ->voir('il se facture avec TVA avant de céder le magasin')
        ->voir('« Facturer le stock »')
        ->voir('Faites vérifier ce choix par votre expert-comptable.');

    $contraste = new ContrasteDesPages($onglet);
    expect([$contraste->defautsDeLaPage('clair'), $contraste->defautsDeLaPage('sombre')])->toBe([[], []]);

    $onglet->fermerLaFenetre();
    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): bool => $societe->magasin->fresh()->estFerme()))->toBeFalse();
});
