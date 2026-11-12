<?php

use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;

/*
 * Le propriétaire d'une société qui attend son code s'entraîne à tout dans
 * le magasin de démonstration, le seul où il vend (décision de l'éditeur du
 * 12/11/2026) : il vend à la caisse et encaisse en deux modes, fait une
 * facture qu'il émet et encaisse depuis sa fiche, ferme sa caisse et
 * clôture la journée, comme un responsable. Chaque test attend le résultat
 * visible d'un geste (un avis, un statut, la fenêtre refermée) avant de
 * lire la base. La démonstration de test a son catalogue fictif et ses
 * comptes d'entraînement (voir TestCaseDuNavigateur::demonstration()).
 */

/**
 * Ajoute ce produit du catalogue fictif aux lignes de la page par la
 * fenêtre « Ajouter un produit ».
 */
function ajouterLeProduitDeDemonstration(Navigation $onglet, string $produit): Navigation
{
    return $onglet->cliquerSur('Ajouter un produit')
        ->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is('.Navigation::texte($produit).')')
        ->cliquerSur('Ajouter le produit');
}

/**
 * Les paiements de cette vente de la démonstration, dans l'ordre : mode,
 * montant et auteur.
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
function paiementsDeLaVenteDeDemonstration(Magasin $demonstration, Facture $vente): array
{
    return app(TenantContext::class)->runAs($demonstration, fn (): array => Paiement::query()
        ->whereHas('allocations', fn ($allocation) => $allocation->where('facture_id', $vente->id))
        ->orderBy('id')
        ->get()
        ->map(fn (Paiement $paiement): array => [$paiement->mode_paiement->value, (string) $paiement->montant, (int) $paiement->cree_par])
        ->all());
}

test('le propriétaire vend à la caisse de la démonstration, encaisse en espèces et par carte, ferme sa caisse et clôture la journée', function () {
    $demonstration = $this->demonstration();
    $magasin = '/app/'.$demonstration->magasin->uuid;
    $proprietaire = $demonstration->proprietaire;
    $onglet = $this->seConnecter($proprietaire, panneau: 'societe')->aller($magasin.'/caisse');

    foreach (['Carnet A5', 'Stylo bille bleu', 'Tasse en céramique'] as $produit) {
        ajouterLeProduitDeDemonstration($onglet, $produit);
    }
    $onglet->voir('16,00 €')
        ->remplirLaLigne('parts', 0, 'montant', '10')
        ->cliquerSur('Ajouter un autre mode')
        ->choisirDansLaLigne('parts', 1, 'mode', ModePaiement::CarteBancaire->value)
        ->remplirLaLigne('parts', 1, 'montant', '6')
        ->soumettre('Encaisser')
        ->voir('Vente encaissée');

    $ticket = app(TenantContext::class)->runAs($demonstration->magasin, fn (): Facture => Facture::query()->latest('id')->firstOrFail());
    expect($ticket->statut)->toBe(StatutFacture::Payee)
        ->and((int) $ticket->cree_par)->toBe((int) $proprietaire->id)
        ->and((string) $ticket->numero)->toStartWith('TK-DEMO-')
        ->and(paiementsDeLaVenteDeDemonstration($demonstration->magasin, $ticket))->toBe([
            [ModePaiement::Especes->value, '10.00', (int) $proprietaire->id],
            [ModePaiement::CarteBancaire->value, '6.00', (int) $proprietaire->id],
        ]);

    $onglet->aller($magasin.'/cloture-caisse-personnelles')
        ->cliquerSur('Fermer ma caisse')
        ->soumettre('Confirmer')
        ->attendreQue('document.querySelector('.Navigation::texte(Navigation::FENETRE).') === null', 'la caisse ne se ferme pas');

    $releve = app(TenantContext::class)->runAs($demonstration->magasin, fn (): ClotureCaissePersonnelle => ClotureCaissePersonnelle::query()->sole());
    expect([(int) $releve->vendeur_id, $releve->mode_fermeture, $releve->nombre_ventes, (string) $releve->total_ttc])
        ->toBe([(int) $proprietaire->id, ModeFermetureCaisse::Vendeur, 1, '16.00']);

    $onglet->aller($magasin.'/cloture-caisses')
        ->cliquerSur('Clôturer la journée du 10/03/2026')
        ->soumettre('Oui, clôturer définitivement')
        ->voir('Journée du 10/03/2026 clôturée');

    $cloture = app(TenantContext::class)->runAs($demonstration->magasin, fn (): ClotureCaisse => ClotureCaisse::query()->sole());
    expect([$cloture->date_cloture, $cloture->nombre_ventes])->toBe(['2026-03-10', 1]);
});

test('le propriétaire fait une facture dans la démonstration, l’émet et l’encaisse depuis sa fiche', function () {
    $demonstration = $this->demonstration();
    $magasin = '/app/'.$demonstration->magasin->uuid;
    $proprietaire = $demonstration->proprietaire;
    $onglet = $this->seConnecter($proprietaire, panneau: 'societe')->aller($magasin.'/factures/create');

    $onglet->cliquer('[title="Chercher un client"]')
        ->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is('.Navigation::texte('Entreprise cliente de démonstration').')');
    ajouterLeProduitDeDemonstration($onglet, 'Tasse en céramique')->soumettre('Créer')->attendreLaPage('/factures$');

    $facture = app(TenantContext::class)->runAs($demonstration->magasin, fn (): Facture => Facture::query()->latest('id')->firstOrFail());
    expect([$facture->statut, (int) $facture->cree_par])->toBe([StatutFacture::Brouillon, (int) $proprietaire->id]);

    $onglet->aller($magasin.'/factures/'.$facture->id)
        ->cliquerSur('Émettre et encaisser')
        ->voir('9,90 €')
        ->choisirDansLaLigne('parts', 0, 'mode', ModePaiement::CarteBancaire->value)
        ->soumettre('Émettre et encaisser')
        ->voir('Payée');

    $facture = app(TenantContext::class)->runAs($demonstration->magasin, fn (): Facture => $facture->fresh());
    expect($facture->statut)->toBe(StatutFacture::Payee)
        ->and((string) $facture->numero)->toContain('-DEMO-')
        ->and(paiementsDeLaVenteDeDemonstration($demonstration->magasin, $facture))->toBe([[ModePaiement::CarteBancaire->value, '9.90', (int) $proprietaire->id]]);
});
