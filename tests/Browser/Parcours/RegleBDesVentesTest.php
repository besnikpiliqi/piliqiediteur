<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;

/*
 * La règle (b) des ventes et les modèles M6-b et P1 du PDF (décisions de
 * l'éditeur du 14/11/2026), comme une personne les voit dans le
 * navigateur : à la caisse, un savon à 9,99 € TTC à 20 % fait 1,67 € de
 * TVA (1,665 arrondi au centime supérieur) et 8,32 € HT, et le PDF du
 * ticket du client comptoir ne montre que des prix TTC ; le PDF d'une
 * facture à un professionnel montre, sans colonne PU TTC, le PU HT d'un
 * article, le PU HT exact en petit sous l'article et « Service » à la place
 * de la référence d'un service ; un avoir partiel fait à l'écran rend la
 * TVA de l'article rendu, arrondie une fois. La société de test a tous ses
 * rôles et son catalogue (voir TestCaseDuNavigateur::societeComplete()).
 */

/**
 * Le savon d'Alep à 9,99 € TTC à 20 % du catalogue du magasin, créé à la
 * première demande.
 */
function savonDeLaRegleB(Magasin $magasin): Produit
{
    return app(TenantContext::class)->runAs($magasin, fn (): Produit => Produit::query()->where('reference', 'SAVON-999')->first()
        ?? Produit::factory()->create(['nom' => 'Savon d\'Alep', 'reference' => 'SAVON-999', 'prix_vente_ttc' => '9.99', 'taux_tva' => 20, 'suivi_stock' => false]));
}

/**
 * Le PDF que le dernier clic sur « PDF » a téléchargé, le seul.
 */
function pdfTelecharge(Navigation $onglet): string
{
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1);

    return base64_decode($fichiers[0]['contenu']);
}

test('le vendeur vend à la caisse un savon à 9,99 € au client comptoir : 8,32 € HT et 1,67 € de TVA, et le PDF du ticket ne montre que ses prix TTC', function () {
    $societe = $this->societeComplete();
    savonDeLaRegleB($societe->magasin);
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid.'/caisse');

    $onglet->cliquerSur('Ajouter un produit')
        ->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is('.Navigation::texte('Savon d\'Alep').')')
        ->cliquerSur('Ajouter le produit')
        ->voir('Total HT 8,32 € · TVA 1,67 € · Total TTC 9,99 €')
        ->remplirLaLigne('parts', 0, 'montant', '9.99')
        ->soumettre('Encaisser')
        ->voir('Vente encaissée');

    $ticket = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->whereHas('client', fn ($client) => $client->where('est_comptoir', true))->latest('id')->firstOrFail());
    expect($ticket->statut)->toBe(StatutFacture::Payee)
        ->and([$ticket->total_ht, $ticket->total_tva, $ticket->total_ttc])->toBe(['8.32', '1.67', '9.99']);

    $onglet->aller('/app/'.$societe->magasin->uuid.'/factures/'.$ticket->id)
        ->voir('pour chaque ligne, la TVA est le TTC')
        ->cliquerSur('PDF');
    $textes = array_column($this->pagesDuPdf(pdfTelecharge($onglet))[0]['textes'], 'texte');
    expect($textes)->toContain('PU TTC')
        ->and($textes)->toContain('TOTAL TTC')
        ->and($textes)->toContain('9,99 €')
        ->and($textes)->toContain('Client comptoir')
        ->and($textes)->toContain('NET À PAYER')
        ->and($textes)->not->toContain('PU HT')
        ->and(preg_grep('/PU HT exact/u', $textes))->toBe([]);
});

test('le responsable lit sur le PDF d’une facture à un professionnel les colonnes du modèle M6-b, le PU HT exact sous l’article et « Service » à la place de la référence d’un service', function () {
    $societe = $this->societeComplete();
    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => app(EmettreFactureAction::class)->handle($societe->responsable, app(CreerFactureAction::class)->handle(
        $societe->responsable,
        Client::query()->where('nom', 'Hôtel des Pins')->sole(),
        [
            ['produit_id' => savonDeLaRegleB($societe->magasin)->id, 'quantite' => 2],
            ['service_id' => Service::factory()->create(['nom' => 'Main-d\'œuvre'])->id, 'prix_unitaire_ttc' => '54.00', 'taux_tva' => 20, 'quantite' => 3],
        ],
    )));
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id);

    $onglet->voir('8,32 €')
        ->voir('pour chaque ligne, la TVA est le TTC')
        ->cliquerSur('PDF');
    $textes = array_column($this->pagesDuPdf(pdfTelecharge($onglet))[0]['textes'], 'texte');
    expect($textes)->toContain('RÉF.')
        ->and($textes)->toContain('ARTICLE')
        ->and($textes)->toContain('PU HT')
        ->and($textes)->toContain('REM.')
        ->and($textes)->toContain('TOTAL HT')
        ->and($textes)->toContain('PU HT exact : 8,325000 €')
        ->and($textes)->toContain('PU HT exact : 45,000000 €')
        ->and($textes)->toContain('Service')
        ->and($textes)->toContain('16,65 €')
        ->and($textes)->toContain('135,00 €')
        ->and($textes)->toContain('NET À PAYER')
        ->and($textes)->not->toContain('PU TTC');
    expect([$facture->total_ht, $facture->total_tva, $facture->total_ttc])->toBe(['151.65', '30.33', '181.98']);
});

test('le responsable fait à l’écran l’avoir d’un des quatre savons d’une facture à un professionnel : il rend 8,32 € HT et 1,67 € de TVA, et son PDF suit le modèle M6-b', function () {
    $societe = $this->societeComplete();
    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => app(EmettreFactureAction::class)->handle($societe->responsable, app(CreerFactureAction::class)->handle(
        $societe->responsable,
        Client::query()->where('nom', 'Hôtel des Pins')->sole(),
        [['produit_id' => savonDeLaRegleB($societe->magasin)->id, 'quantite' => 4]],
    )));
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($societe->responsable)->aller($magasin.'/factures/'.$facture->id);

    $onglet->cliquerSurLeLien('Avoir')
        ->voir($facture->numero)
        ->remplirLaLigne('lignes', 0, 'quantite', '1')
        ->remplir('Motif de l\'avoir', 'Savon abîmé')
        ->soumettre('Créer')
        ->attendreLaPage('/avoirs$');

    $avoir = app(TenantContext::class)->runAs($societe->magasin, fn (): Avoir => Avoir::query()->latest('id')->firstOrFail());
    expect([$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc])->toBe(['8.32', '1.67', '9.99']);

    $onglet->aller($magasin.'/avoirs/'.$avoir->id)
        ->voir('chaque retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici')
        ->cliquerSur('PDF');
    $textes = array_column($this->pagesDuPdf(pdfTelecharge($onglet))[0]['textes'], 'texte');
    expect($textes)->toContain('PU HT exact : 8,325000 €')
        ->and($textes)->toContain('Montant de l\'avoir')
        ->and($textes)->toContain('TOTAL TTC')
        ->and($textes)->toContain('-9,99 €')
        ->and($textes)->not->toContain('PU TTC');
});
