<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Enums\NatureOperation;
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
 * date prévue, que la fiche et le PDF du devis numéroté montrent. Le
 * brouillon d'une facture ou d'un devis se rouvre avec sa date, qui se
 * change ; « Transformer en facture » reprend la date prévue du devis,
 * que la facture émise un autre jour imprime ; une livraison
 * intracommunautaire est refusée sous son champ sans sa date, puis
 * s'enregistre avec elle. Le test commence le 10 mars 2026 à 10 h à Paris
 * (voir TestCaseDuNavigateur).
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

test('chaque compte qui fait une facture change la période de son brouillon et la retrouve sur la fiche', function (Closure $compte) {
    $societe = $this->societeComplete();
    $auteur = $compte($societe);
    $magasin = '/app/'.$societe->magasin->uuid;
    $brouillon = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => app(CreerFactureAction::class)->handle(
        $auteur,
        Client::query()->where('nom', 'Hôtel des Pins')->sole(),
        [['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 2]],
        ['date_operation_debut' => '2026-03-02', 'date_operation_fin' => '2026-03-06'],
    ));
    app(TenantContext::class)->clear();

    $this->seConnecter($auteur)
        ->aller($magasin.'/factures/'.$brouillon->getRouteKey().'/edit')
        ->remplir('Date de la livraison ou de la prestation', '2026-03-09')
        ->remplir('Premier jour de la prestation', '2026-03-04')
        ->soumettre('Sauvegarder')
        ->attendreLaPage('/factures$')
        ->aller($magasin.'/factures/'.$brouillon->getRouteKey())
        ->voir('Date de la livraison ou de la prestation')
        ->voir('du 04/03/2026 au 09/03/2026');

    $relue = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => $brouillon->fresh());
    expect([$relue->date_operation_debut, $relue->date_operation_fin])->toBe(['2026-03-04', '2026-03-09']);
})->with('comptes qui font une facture');

test('chaque compte qui fait un devis change la date prévue de son brouillon et la retrouve sur la fiche', function (Closure $compte) {
    $societe = $this->societeComplete();
    $auteur = $compte($societe);
    $magasin = '/app/'.$societe->magasin->uuid;
    $brouillon = app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => app(CreerDevisAction::class)->handle(
        $auteur,
        Client::query()->where('nom', 'Hôtel des Pins')->sole(),
        [['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 2]],
        ['date_operation_fin' => '2026-03-20'],
    ));
    app(TenantContext::class)->clear();

    $this->seConnecter($auteur)
        ->aller($magasin.'/devis/'.$brouillon->getRouteKey().'/edit')
        ->remplir('Date prévue de la livraison ou de la prestation', '2026-03-25')
        ->remplir('Premier jour de la prestation', '2026-03-23')
        ->soumettre('Sauvegarder')
        ->attendreLaPage('/devis$')
        ->aller($magasin.'/devis/'.$brouillon->getRouteKey())
        ->voir('Date prévue de la livraison ou de la prestation')
        ->voir('du 23/03/2026 au 25/03/2026');

    $relu = app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => $brouillon->fresh());
    expect([$relu->date_operation_debut, $relu->date_operation_fin])->toBe(['2026-03-23', '2026-03-25']);
})->with('comptes qui font un devis');

dataset('comptes qui transforment un devis en facture', [
    'le responsable' => [fn (SocieteDeTest $societe) => $societe->responsable],
    'le vendeur' => [fn (SocieteDeTest $societe) => $societe->vendeur],
    'l’administrateur autorisé à vendre' => [fn (SocieteDeTest $societe) => $societe->administrateurVendeur],
]);

test('chaque compte qui transforme un devis accepté en facture retrouve sa date prévue sur la facture, que le PDF de la facture émise imprime', function (Closure $compte) {
    $societe = $this->societeComplete();
    $auteur = $compte($societe);
    $magasin = '/app/'.$societe->magasin->uuid;
    $devis = app(TenantContext::class)->runAs($societe->magasin, function () use ($auteur, $societe): Devis {
        $devis = app(EmettreDevisAction::class)->handle($auteur, app(CreerDevisAction::class)->handle(
            $auteur,
            Client::query()->where('nom', 'Hôtel des Pins')->sole(),
            [['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 2]],
            ['date_operation_debut' => '2026-03-11', 'date_operation_fin' => '2026-03-12'],
        ));

        return app(AccepterDevisAction::class)->handle($societe->responsable, $devis);
    });
    app(TenantContext::class)->clear();

    $onglet = $this->seConnecter($auteur)
        ->aller($magasin.'/devis/'.$devis->getRouteKey())
        ->voir('du 11/03/2026 au 12/03/2026')
        ->cliquerSur('Transformer en facture')
        ->soumettre('Transformer en facture')
        ->attendreLaPage('^'.$magasin.'/factures/[^/]+$')
        ->voir('Date de la livraison ou de la prestation')
        ->voir('du 11/03/2026 au 12/03/2026');

    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('devis_id', $devis->id)->sole());
    expect([$facture->date_operation_debut, $facture->date_operation_fin, $facture->cree_par])->toBe(['2026-03-11', '2026-03-12', $auteur->id]);

    $onglet->cliquerSur('Émettre')
        ->soumettre('Confirmer')
        ->voir('FAC-');

    expect(textesDuPdfTelecharge($onglet, fn (string $pdf): array => $this->pagesDuPdf($pdf)))
        ->toContain('Date de la livraison ou de la prestation : du 11/03/2026 au 12/03/2026');
})->with('comptes qui transforment un devis en facture');

test('chaque compte qui fait une facture de livraison intracommunautaire se voit refuser l’envoi sous la date de la livraison qui manque, puis l’enregistre avec elle', function (Closure $compte) {
    $societe = $this->societeComplete();
    $auteur = $compte($societe);
    $magasin = '/app/'.$societe->magasin->uuid;
    app(TenantContext::class)->runAs($societe->magasin, fn (): Client => Client::factory()->etranger('DE', 'DE123456789')->create(['nom' => 'Muster GmbH', 'adresse' => 'Musterstraße 1', 'code_postal' => '10115', 'ville' => 'Berlin']));
    $factures = fn (): int => app(TenantContext::class)->runAs($societe->magasin, fn (): int => Facture::query()->count());
    $avant = $factures();
    $onglet = $this->seConnecter($auteur)->aller($magasin.'/factures/create');

    $onglet->choisirLeClient('Muster GmbH');
    ajouterLeProduitDatee($onglet, 'Bougie parfumée')
        ->choisir('Nature de l\'opération', NatureOperation::LivraisonIntracommunautaire->value)
        ->voir('Où partent les biens')
        ->cliquer('label:has-text("Expédié par le magasin ou pour son compte") input[type=radio]')
        ->soumettre('Créer');
    expect($onglet->page()->evaluate('() => { const champ = document.getElementById("data.date_operation_fin"); return { requis: champ.required, manquant: champ.validity.valueMissing, refusIci: document.activeElement === champ && champ.validationMessage !== "" }; }'))
        ->toBe(['requis' => true, 'manquant' => true, 'refusIci' => true], 'Le navigateur refuse l\'envoi et dit sous la date de la livraison qu\'elle manque.')
        ->and($factures())->toBe($avant);

    $onglet->remplir('Date de la livraison ou de la prestation', '2026-03-12')
        ->soumettre('Créer')
        ->attendreLaPage('/factures$');

    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('cree_par', $auteur->id)->latest('id')->firstOrFail());
    expect([$facture->nature_operation, $facture->date_operation_debut, $facture->date_operation_fin, $facture->livraison_ville])->toBe([NatureOperation::LivraisonIntracommunautaire, null, '2026-03-12', 'Berlin']);

    $onglet->aller($magasin.'/factures/'.$facture->getRouteKey())
        ->voir('Livraison intracommunautaire à un professionnel')
        ->voir('Date de la livraison ou de la prestation')
        ->voir('12/03/2026');
})->with('comptes qui font une facture');
