<?php

use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;

/*
 * Le règlement en parts dans le navigateur (constat C1 de l'éditeur) : ce
 * qui sépare les parts saisies de ce qu'elles doivent couvrir ne
 * s'affiche qu'une fois, avant comme après le clic sur « Encaisser », le
 * refus prenant la place de l'aide, et plus rien ne s'affiche une fois le
 * règlement juste ; à la caisse comme sur l'encaissement d'une facture. La
 * vente faite, son numéro mène à sa fiche (constat C2), et le PDF du ticket
 * imprime chacun de ses règlements à gauche du Total TTC (constat C3). La
 * société de test a tous ses rôles et son catalogue (voir
 * TestCaseDuNavigateur::societeComplete()).
 */

/**
 * Combien de fois ce texte se lit dans la page, fenêtres comprises.
 */
function foisDansLaPage(Navigation $onglet, string $texte): int
{
    return (int) $onglet->page()->evaluate('(texte) => document.body.innerText.split(texte).length - 1', $texte);
}

/**
 * Combien de fois ce texte se lit dans un refus de Filament, affiché sous
 * le champ refusé.
 */
function foisDansUnRefus(Navigation $onglet, string $texte): int
{
    return (int) $onglet->page()->evaluate('(texte) => [...document.querySelectorAll(".fi-fo-field-wrp-error-message")].filter((refus) => refus.offsetParent !== null && refus.innerText.includes(texte)).length', $texte);
}

/**
 * Ajoute ce produit au panier de la caisse par la fenêtre « Ajouter un
 * produit ».
 */
function ajouterALaCaisse(Navigation $onglet, string $produit): Navigation
{
    return $onglet->cliquerSur('Ajouter un produit')
        ->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is('.Navigation::texte($produit).')')
        ->cliquerSur('Ajouter le produit');
}

test('à la caisse, « Il reste … à répartir » ne s’affiche qu’une fois, avant comme après « Encaisser » ; la vente faite, son numéro mène au ticket, dont le PDF imprime chaque règlement à gauche du Total TTC', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid.'/caisse');
    $reste = 'Il reste 8,00 € à répartir.';

    ajouterALaCaisse($onglet, 'Bougie parfumée')->voir('18,00 €');
    $onglet->remplirLaLigne('parts', 0, 'montant', '10')->voir($reste);
    expect(foisDansLaPage($onglet, $reste))->toBe(1);

    $onglet->soumettre('Encaisser');
    expect(foisDansLaPage($onglet, $reste))->toBe(1)
        ->and(foisDansUnRefus($onglet, $reste))->toBe(1);

    $onglet->cliquerSur('Ajouter un autre mode')
        ->choisirDansLaLigne('parts', 1, 'mode', ModePaiement::CarteBancaire->value)
        ->remplirLaLigne('parts', 1, 'montant', '8')
        ->remplirLaLigne('parts', 1, 'reference', 'CB-TRX-7788');
    expect(foisDansLaPage($onglet, 'à répartir'))->toBe(0);

    $onglet->soumettre('Encaisser')->voir('Vente encaissée : ticket');
    $ticket = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('cree_par', $societe->vendeur->id)->latest('id')->firstOrFail());
    expect($ticket->statut)->toBe(StatutFacture::Payee);

    $onglet->aller('/app/'.$societe->magasin->uuid.'/caisse')
        ->voir('Dernière vente enregistrée : ticket N°')
        ->cliquerSurLeLien((string) $ticket->numero)
        ->attendreLaPage('/factures/'.$ticket->id.'$')
        ->voir((string) $ticket->numero);

    $onglet->cliquerSur('PDF');
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1);
    $page = $this->pagesDuPdf(base64_decode($fichiers[0]['contenu']))[0];
    $texte = fn (string $libelle): array => collect($page['textes'])->firstWhere('texte', $libelle) ?? throw new RuntimeException("« {$libelle} » n'est pas sur le PDF.");
    $totalTtc = $texte('Total TTC');
    foreach (['RÈGLEMENTS', 'Espèces', 'Carte bancaire', 'CB-TRX-7788', '10,00 €', '8,00 €'] as $reglement) {
        expect($texte($reglement)['x'] + $texte($reglement)['largeur'])->toBeLessThan($totalTtc['x']);
    }
});

test('à la caisse de l’administrateur autorisé à vendre, l’écart ne s’affiche qu’une fois après « Encaisser »', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->administrateurVendeur)->aller('/app/'.$societe->magasin->uuid.'/caisse');
    $reste = 'Il reste 13,00 € à répartir.';

    ajouterALaCaisse($onglet, 'Bougie parfumée')->voir('18,00 €');
    $onglet->remplirLaLigne('parts', 0, 'montant', '5')->voir($reste)->soumettre('Encaisser');

    expect(foisDansLaPage($onglet, $reste))->toBe(1)
        ->and(foisDansUnRefus($onglet, $reste))->toBe(1);
});

test('sur l’encaissement d’une facture, l’écart ne s’affiche qu’une fois, avant comme après le clic, puis plus du tout', function () {
    $societe = $this->societeComplete();
    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('statut', StatutFacture::PartiellementPayee)->sole());
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id);
    $ecart = 'Les parts du règlement (50,00 €) dépassent ce qui reste dû (34,00 €).';

    $onglet->cliquerSur('Encaisser')->remplirLaLigne('parts', 0, 'montant', '50')->voir($ecart);
    expect(foisDansLaPage($onglet, $ecart))->toBe(1);

    $onglet->soumettre('Soumettre');
    expect(foisDansLaPage($onglet, $ecart))->toBe(1)
        ->and(foisDansUnRefus($onglet, $ecart))->toBe(1);

    $onglet->remplirLaLigne('parts', 0, 'montant', '34');
    expect(foisDansLaPage($onglet, 'dépassent ce qui reste dû'))->toBe(0);

    $onglet->soumettre('Soumettre')->voir('Payée');
    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): StatutFacture => $facture->fresh()->statut))->toBe(StatutFacture::Payee);
});
