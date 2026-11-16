<?php

use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SocieteDeTest;

/*
 * La date de la livraison ou de la prestation (T49) dans le navigateur :
 * chaque compte qui fait une facture la saisit dans le formulaire, un jour
 * ou, pour une prestation de plusieurs jours, une période ; la fiche la
 * montre, et le PDF de la facture émise l'imprime quand elle n'est pas le
 * jour de l'émission. Un premier jour sans dernier jour est refusé sous le
 * champ, et rien ne s'enregistre. Chaque compte qui fait un devis y note la
 * date prévue, que la fiche et le PDF du devis numéroté montrent. Le test commence
 * le 10 mars 2026 à 10 h à Paris (voir TestCaseDuNavigateur).
 */

/**
 * Ajoute ce produit du catalogue de la société de test par « Ajouter un
 * produit ».
 */
function ajouterLeProduitDatee(Navigation $onglet, string $produit): Navigation
{
    return $onglet->cliquerSur('Ajouter un produit')
        ->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is('.Navigation::texte($produit).')')
        ->cliquerSur('Ajouter le produit');
}

/**
 * Les textes de la première page du PDF téléchargé depuis la fiche ouverte.
 *
 * @return list<string>
 */
function textesDuPdfTelecharge(Navigation $onglet, Closure $pagesDuPdf): array
{
    $onglet->cliquerSur('PDF');
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1);

    return collect($pagesDuPdf(base64_decode($fichiers[0]['contenu']))[0]['textes'])->pluck('texte')->all();
}

dataset('comptes qui font une facture', [
    'le responsable' => [fn (SocieteDeTest $societe) => $societe->responsable],
    'le vendeur' => [fn (SocieteDeTest $societe) => $societe->vendeur],
    'l’administrateur autorisé à vendre' => [fn (SocieteDeTest $societe) => $societe->administrateurVendeur],
]);

test('chaque compte qui fait une facture y saisit une prestation de plusieurs jours, la voit sur la fiche et l’imprime sur le PDF de la facture émise', function (Closure $compte) {
    $societe = $this->societeComplete();
    $auteur = $compte($societe);
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($auteur)->aller($magasin.'/factures/create');

    $onglet->choisirLeClient('Hôtel des Pins');
    ajouterLeProduitDatee($onglet, 'Bougie parfumée')
        ->remplir('Date de la livraison ou de la prestation', '2026-03-06')
        ->remplir('Premier jour de la prestation', '2026-03-02')
        ->soumettre('Créer')
        ->attendreLaPage('/factures$');

    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('cree_par', $auteur->id)->latest('id')->firstOrFail());
    expect([$facture->date_operation_debut, $facture->date_operation_fin])->toBe(['2026-03-02', '2026-03-06']);

    $onglet->aller($magasin.'/factures/'.$facture->id)
        ->voir('Date de la livraison ou de la prestation')
        ->voir('du 02/03/2026 au 06/03/2026')
        ->cliquerSur('Émettre')
        ->soumettre('Confirmer')
        ->voir('FAC-');

    expect(textesDuPdfTelecharge($onglet, fn (string $pdf): array => $this->pagesDuPdf($pdf)))
        ->toContain('Date de la livraison ou de la prestation : du 02/03/2026 au 06/03/2026');
})->with('comptes qui font une facture');

test('le responsable qui saisit le premier jour d’une prestation sans son dernier jour lit le refus sous le champ, et rien ne s’enregistre', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;
    $avant = app(TenantContext::class)->runAs($societe->magasin, fn (): int => Facture::query()->count());
    $onglet = $this->seConnecter($societe->responsable)->aller($magasin.'/factures/create');

    $onglet->choisirLeClient('Hôtel des Pins');
    ajouterLeProduitDatee($onglet, 'Bougie parfumée')
        ->remplir('Premier jour de la prestation', '2026-03-02')
        ->soumettre('Créer')
        ->voir(Facture::REFUS_DEBUT_SANS_FIN);

    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): int => Facture::query()->count()))->toBe($avant);
});

test('la facture livrée le jour de son émission garde sa date sur la fiche sans l’imprimer sur son PDF', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($societe->responsable)->aller($magasin.'/factures/create');

    $onglet->choisirLeClient('Hôtel des Pins');
    ajouterLeProduitDatee($onglet, 'Bougie parfumée')
        ->remplir('Date de la livraison ou de la prestation', '2026-03-10')
        ->soumettre('Créer')
        ->attendreLaPage('/factures$');

    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('cree_par', $societe->responsable->id)->latest('id')->firstOrFail());
    $onglet->aller($magasin.'/factures/'.$facture->id)
        ->voir('10/03/2026')
        ->cliquerSur('Émettre')
        ->soumettre('Confirmer')
        ->voir('FAC-');

    expect(collect(textesDuPdfTelecharge($onglet, fn (string $pdf): array => $this->pagesDuPdf($pdf)))->filter(fn (string $texte): bool => str_starts_with($texte, 'Date de la livraison'))->all())->toBe([]);
});

dataset('comptes qui font un devis', [
    'le propriétaire' => [fn (SocieteDeTest $societe) => $societe->proprietaire],
    'le responsable' => [fn (SocieteDeTest $societe) => $societe->responsable],
    'le vendeur' => [fn (SocieteDeTest $societe) => $societe->vendeur],
    'l’administrateur autorisé à vendre' => [fn (SocieteDeTest $societe) => $societe->administrateurVendeur],
]);

test('chaque compte qui fait un devis y note la date prévue de la livraison, la voit sur la fiche et l’imprime sur le PDF du devis numéroté', function (Closure $compte) {
    $societe = $this->societeComplete();
    $auteur = $compte($societe);
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($auteur)->aller($magasin.'/devis/create');

    $onglet->choisirLeClient('Hôtel des Pins');
    ajouterLeProduitDatee($onglet, 'Bougie parfumée')
        ->remplir('Valide jusqu\'au', '2026-04-10')
        ->remplir('Date prévue de la livraison ou de la prestation', '2026-03-20')
        ->soumettre('Créer')
        ->attendreLaPage('/devis$');

    $devis = app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => Devis::query()->latest('id')->firstOrFail());
    expect([$devis->date_operation_debut, $devis->date_operation_fin])->toBe([null, '2026-03-20']);

    $onglet->aller($magasin.'/devis/'.$devis->id)
        ->voir('Date prévue de la livraison ou de la prestation')
        ->voir('20/03/2026')
        ->cliquerSur('Numéroter sans e-mail')
        ->soumettre('Confirmer')
        ->voir('Envoyé');

    expect(textesDuPdfTelecharge($onglet, fn (string $pdf): array => $this->pagesDuPdf($pdf)))
        ->toContain('Date prévue de la livraison ou de la prestation : 20/03/2026');
})->with('comptes qui font un devis');
