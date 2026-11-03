<?php

use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;

/*
 * Les premiers parcours de vente de bout en bout, comme une personne les
 * fait dans le navigateur : une vente à la caisse réglée en deux modes ;
 * un devis puis une facture faits avec la loupe des clients et « Ajouter
 * un produit », la facture émise depuis la liste, encaissée depuis sa
 * fiche, puis créditée par « Avoir », qui ouvre « Nouvel avoir » sur elle.
 * Chaque test attend le résultat visible d'un geste (un avis, un statut,
 * la liste rouverte) avant de lire la base. La société de test a tous ses
 * rôles et son catalogue (voir TestCaseDuNavigateur::societeComplete()).
 */

/**
 * Ajoute ce produit aux lignes du formulaire par la fenêtre « Ajouter un
 * produit ».
 */
function ajouterLeProduit(Navigation $onglet, string $produit): Navigation
{
    return $onglet->cliquerSur('Ajouter un produit')
        ->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is('.Navigation::texte($produit).')')
        ->cliquerSur('Ajouter le produit');
}

/**
 * Choisit ce client du formulaire par la loupe « Chercher un client ».
 */
function choisirLeClient(Navigation $onglet, string $client): Navigation
{
    return $onglet->cliquer('[title="Chercher un client"]')
        ->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is('.Navigation::texte($client).')');
}

test('le vendeur vend à la caisse au client comptoir et encaisse en espèces et par carte', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid.'/caisse');

    ajouterLeProduit($onglet, 'Bougie parfumée')->voir('18,00 €');
    $onglet->remplirLaLigne('parts', 0, 'montant', '10')
        ->cliquerSur('Ajouter un autre mode')
        ->choisirDansLaLigne('parts', 1, 'mode', ModePaiement::CarteBancaire->value)
        ->remplirLaLigne('parts', 1, 'montant', '8')
        ->soumettre('Encaisser')
        ->voir('Vente encaissée');

    $ticket = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->whereHas('client', fn ($client) => $client->where('est_comptoir', true))->latest('id')->firstOrFail());
    $modes = app(TenantContext::class)->runAs($societe->magasin, fn (): array => Paiement::query()->whereHas('allocations', fn ($allocation) => $allocation->where('facture_id', $ticket->id))->orderBy('id')->get()->map(fn (Paiement $paiement): array => [$paiement->mode_paiement->value, (string) $paiement->montant])->all());

    expect($ticket->statut)->toBe(StatutFacture::Payee)
        ->and($ticket->cree_par)->toBe($societe->vendeur->id)
        ->and($modes)->toBe([[ModePaiement::Especes->value, '10.00'], [ModePaiement::CarteBancaire->value, '8.00']]);
});

test('le responsable fait un devis puis une facture avec la loupe des clients et « Ajouter un produit », l’émet, l’encaisse et la crédite par un avoir', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($societe->responsable)->aller($magasin.'/devis/create');

    choisirLeClient($onglet, 'Hôtel des Pins');
    ajouterLeProduit($onglet, 'Bougie parfumée')->soumettre('Créer')->attendreLaPage('/devis$');

    $devis = app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => Devis::query()->with(['client', 'lignes'])->latest('id')->firstOrFail());
    expect($devis->client->nom)->toBe('Hôtel des Pins')
        ->and($devis->lignes->pluck('description')->all())->toBe(['Bougie parfumée']);

    $onglet->aller($magasin.'/factures/create');
    choisirLeClient($onglet, 'Hôtel des Pins');
    ajouterLeProduit($onglet, 'Bougie parfumée')->soumettre('Créer')->attendreLaPage('/factures$');
    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->latest('id')->firstOrFail());

    $onglet->aller($magasin.'/factures')
        ->cliquer('.fi-ta-row:has-text("18,00 €") >> '.Navigation::bouton('Émettre'))
        ->soumettre('Confirmer')
        ->attendreQue('document.querySelector('.Navigation::texte(Navigation::FENETRE).') === null && ! [...document.querySelectorAll(".fi-ta-row")].some((ligne) => ligne.innerText.includes("18,00 €") && ligne.innerText.includes("Émettre"))', 'la facture ne s\'émet pas');

    expect($facture->refresh()->statut)->toBe(StatutFacture::Emise);

    $onglet->aller($magasin.'/factures/'.$facture->id)
        ->voir($facture->numero)
        ->cliquerSur('Encaisser')
        ->choisirDansLaLigne('parts', 0, 'mode', ModePaiement::Cheque->value)
        ->soumettre('Soumettre')
        ->voir('Payée');

    expect($facture->refresh()->statut)->toBe(StatutFacture::Payee);

    $onglet->cliquerSurLeLien('Avoir')
        ->voir($facture->numero)
        ->remplir('Motif de l\'avoir', 'Bougie cassée')
        ->soumettre('Créer')
        ->attendreLaPage('/avoirs$');

    $avoir = app(TenantContext::class)->runAs($societe->magasin, fn (): Avoir => Avoir::query()->with('client')->latest('id')->firstOrFail());
    expect($avoir->client->nom)->toBe('Hôtel des Pins')
        ->and((string) $avoir->total_ttc)->toBe((string) $facture->total_ttc);
});
