<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Tests\Browser\Support\Navigation;

/*
 * Le PU HT affiché d'une vente est celui d'un article (décision de
 * l'éditeur du 14/11/2026, qui remplace celle du 09/11/2026) : son PU TTC
 * moins sa TVA, arrondie une fois au centime, le même sur le PDF, la fiche
 * et les lignes du formulaire, pour tout client et toute nature de vente.
 * 4 savons à 9,99 € TTC à 20 % : TVA d'un savon 1,665 €, arrondie à
 * 1,67 €, PU HT 8,32 €, jamais 8,325 € (le PU HT enregistré, celui de la
 * facture électronique) ni 8,33 € ; 33,30 € HT ; 2 savons rendus :
 * 16,65 € HT. Chaque rôle qui ouvre la fiche ou le PDF y lit le même PU
 * HT, avec la note de calcul de son document ; le PDF d'un document à un
 * professionnel suit le modèle M6-b, le PU HT exact écrit en petit sous
 * l'article, sans colonne PU TTC (décision de l'éditeur du 14/11/2026). La société de test a tous
 * ses rôles et son catalogue (voir TestCaseDuNavigateur::societeComplete()).
 */

/**
 * Le savon d'Alep à 9,99 € TTC à 20 % du catalogue du magasin, créé à
 * la première demande (le catalogue de la société de test a déjà un
 * savon au lait à 6,00 €).
 */
function savon(Magasin $magasin): Produit
{
    return app(TenantContext::class)->runAs($magasin, fn (): Produit => Produit::query()->where('reference', 'SAVON-999')->first()
        ?? Produit::factory()->create(['nom' => 'Savon d\'Alep', 'reference' => 'SAVON-999', 'prix_vente_ttc' => '9.99', 'taux_tva' => 20, 'suivi_stock' => false]));
}

/**
 * Le brouillon de facture de 4 savons fait par ce compte pour ce client du
 * catalogue de la société de test.
 */
function brouillonDeSavons(Magasin $magasin, User $auteur, string $client): Facture
{
    return app(TenantContext::class)->runAs($magasin, fn (): Facture => app(CreerFactureAction::class)->handle(
        $auteur,
        Client::query()->where('nom', $client)->sole(),
        [['produit_id' => savon($magasin)->id, 'quantite' => 4]],
    ));
}

/**
 * La facture de 4 savons à ce client du catalogue de la société de test,
 * faite et émise par ce compte.
 */
function factureDeSavons(Magasin $magasin, User $auteur, string $client): Facture
{
    return app(TenantContext::class)->runAs($magasin, fn (): Facture => app(EmettreFactureAction::class)->handle($auteur, brouillonDeSavons($magasin, $auteur, $client)));
}

/**
 * Attend que le champ du PU HT de la première ligne du formulaire montre
 * cette valeur.
 */
function attendreLePuHt(Navigation $onglet, string $valeur): Navigation
{
    return $onglet->attendreQue(
        'document.querySelector('.Navigation::texte('[id*=".lignes."][id$=".prix_unitaire_ht"]').')?.value === '.Navigation::texte($valeur),
        "le PU HT de la ligne ne montre pas {$valeur}",
    );
}

/**
 * Attend que le formulaire ait relu le client choisi : son nom s'affiche
 * dans le champ « Client ».
 */
function attendreLeClient(Navigation $onglet, string $nom): Navigation
{
    return $onglet->attendreQue(
        'document.body.innerText.includes('.Navigation::texte($nom).')',
        "le client {$nom} n'est pas choisi",
    );
}

test('le responsable lit le PU HT d’un article, 8,32 € pour un savon à 9,99 €, sur la fiche et sur le PDF d’une facture à un professionnel, et sur la fiche d’un ticket', function () {
    $societe = $this->societeComplete();
    [$facture, $ticket] = array_map(
        fn (string $client): Facture => factureDeSavons($societe->magasin, $societe->responsable, $client),
        ['Hôtel des Pins', 'Jean Dupont'],
    );
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id);

    $onglet->voir('8,32 €')
        ->voir('Le PU HT affiché est celui d\'un article')
        ->nePasVoir('8,325')
        ->nePasVoir('8,33 €')
        ->cliquerSur('PDF');
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1);
    $textes = array_column($this->pagesDuPdf(base64_decode($fichiers[0]['contenu']))[0]['textes'], 'texte');
    expect($textes)->toContain('8,32 €')
        ->and($textes)->toContain('33,30 €')
        ->and($textes)->toContain('PU HT exact : 8,325000 €')
        ->and($textes)->toContain('RÉF.')
        ->and($textes)->toContain('REM.')
        ->and($textes)->toContain('NET À PAYER')
        ->and($textes)->not->toContain('PU TTC')
        ->and($textes)->not->toContain('8,325 €')
        ->and($textes)->not->toContain('8,33 €');

    $onglet->aller('/app/'.$societe->magasin->uuid.'/factures/'.$ticket->id)
        ->voir('8,32 €')
        ->voir('pour chaque ligne, la TVA est le TTC')
        ->nePasVoir('Le PU HT affiché est celui d\'un article')
        ->nePasVoir('8,325');
});

test('le propriétaire lit le PU HT d’un article sur la fiche d’une facture à un professionnel', function () {
    $societe = $this->societeComplete();
    $facture = factureDeSavons($societe->magasin, $societe->responsable, 'Hôtel des Pins');

    $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->voir('8,32 €')
        ->nePasVoir('8,325');
});

test('le vendeur change le client de son brouillon, et le PU HT des lignes reste celui d’un article, pour un professionnel comme pour un particulier', function () {
    $societe = $this->societeComplete();
    $brouillon = brouillonDeSavons($societe->magasin, $societe->vendeur, 'Hôtel des Pins');
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->id.'/edit');

    attendreLePuHt($onglet, '8.32');
    $onglet->choisirDansLaListe('Client', 'Jean Dupont');
    attendreLeClient($onglet, 'Jean Dupont');
    attendreLePuHt($onglet, '8.32');
    $onglet->choisirDansLaListe('Client', 'Hôtel des Pins');
    attendreLeClient($onglet, 'Hôtel des Pins');
    attendreLePuHt($onglet, '8.32')
        ->soumettre('Sauvegarder')
        ->voir('Sauvegardé');

    $brouillon = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => $brouillon->fresh(['client', 'lignes']));
    expect($brouillon->client->nom)->toBe('Hôtel des Pins')
        ->and($brouillon->lignes->sole()->prix_unitaire_ht)->toBe('8.325000')
        ->and($brouillon->total_ht)->toBe('33.30');
});

test('le responsable fait sortir de la TVA française la vente d’un particulier, et le PU HT des lignes reste celui d’un article', function () {
    $societe = $this->societeComplete();
    $brouillon = brouillonDeSavons($societe->magasin, $societe->responsable, 'Jean Dupont');
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->id.'/edit');

    attendreLePuHt($onglet, '8.32');
    $onglet->choisir('Nature de l\'opération', NatureOperation::ExportHorsUe->value);
    $onglet->attendreQue('document.body.innerText.includes("Où partent les biens")', 'la livraison de l\'export ne s\'affiche pas');
    attendreLePuHt($onglet, '8.32');
    $onglet->choisir('Nature de l\'opération', NatureOperation::VenteEnFrance->value);
    attendreLePuHt($onglet, '8.32');
});

test('le responsable fait passer en prestation hors UE la facture d’un service à 9,99 € TTC, dont le prix sans TVA devient 8,32 €, son montant inchangé', function () {
    $societe = $this->societeComplete();
    $brouillon = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => app(CreerFactureAction::class)->handle(
        $societe->responsable,
        Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']),
        [['service_id' => Service::factory()->create(['nom' => 'Conseil à l\'export'])->id, 'prix_unitaire_ttc' => '9.99', 'taux_tva' => 20, 'quantite' => 1]],
    ));
    expect($brouillon->total_ht)->toBe('8.32');
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->id.'/edit');

    $onglet->choisir('Nature de l\'opération', NatureOperation::PrestationHorsUe->value)
        ->voir('Prix des services convertis')
        ->attendreQue(
            'document.querySelector('.Navigation::texte('[id*=".lignes."][id$=".prix_unitaire_ttc"]').')?.value === "8.32"',
            'le prix sans TVA du service ne montre pas 8.32',
        )
        ->soumettre('Sauvegarder')
        ->voir('Sauvegardé');

    $brouillon = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => $brouillon->fresh());
    expect($brouillon->nature_operation)->toBe(NatureOperation::PrestationHorsUe)
        ->and([$brouillon->total_ht, $brouillon->total_tva, $brouillon->total_ttc])->toBe(['8.32', '0.00', '8.32']);
});

test('le propriétaire change le client d’un devis, et le PU HT des lignes reste celui d’un article', function () {
    $societe = $this->societeComplete();
    $devis = app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => app(CreerDevisAction::class)->handle(
        $societe->proprietaire,
        Client::query()->where('nom', 'Jean Dupont')->sole(),
        [['produit_id' => savon($societe->magasin)->id, 'quantite' => 4]],
    ));
    $onglet = $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid.'/devis/'.$devis->id.'/edit');

    attendreLePuHt($onglet, '8.32');
    $onglet->choisirDansLaListe('Client', 'Hôtel des Pins');
    attendreLeClient($onglet, 'Hôtel des Pins');
    attendreLePuHt($onglet, '8.32')
        ->soumettre('Sauvegarder')
        ->voir('Sauvegardé');

    $devis = app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => $devis->fresh(['client', 'lignes']));
    expect($devis->client->nom)->toBe('Hôtel des Pins')
        ->and($devis->lignes->sole()->prix_unitaire_ht)->toBe('8.325000');
});

test('le responsable lit le PU HT d’un article et la note de calcul d’un retour sur la fiche et sur le PDF d’un avoir sur une facture à un professionnel', function () {
    $societe = $this->societeComplete();
    $facture = factureDeSavons($societe->magasin, $societe->responsable, 'Hôtel des Pins');
    $avoir = app(TenantContext::class)->runAs($societe->magasin, fn (): Avoir => app(EnregistrerAvoirAction::class)->handle(
        $societe->responsable,
        $facture->client()->sole(),
        [['ligne_facture_id' => $facture->lignes()->sole()->id, 'quantite' => 2]],
        'Retour de deux savons',
    ));
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/avoirs/'.$avoir->id);

    $onglet->voir('8,32 €')
        ->voir('16,65 €')
        ->voir('chaque retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici')
        ->voir('Le PU HT affiché est celui d\'un article')
        ->nePasVoir('8,325')
        ->cliquerSur('PDF');
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1);
    $textes = array_column($this->pagesDuPdf(base64_decode($fichiers[0]['contenu']))[0]['textes'], 'texte');
    expect($textes)->toContain('8,32 €')
        ->and($textes)->toContain('16,65 €')
        ->and($textes)->toContain('PU HT exact : 8,325000 €')
        ->and($textes)->toContain('Montant de l\'avoir')
        ->and($textes)->not->toContain('8,325 €')
        ->and(preg_replace('/\s+/u', ' ', implode(' ', $textes)))->toContain('La quantité × ce prix peut différer de quelques centimes du total HT de la ligne');
});

test('le propriétaire lit le PU HT d’un article et la note de calcul sur la fiche et sur le PDF d’un devis envoyé à un professionnel', function () {
    $societe = $this->societeComplete();
    $devis = app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => app(EmettreDevisAction::class)->handle($societe->proprietaire, app(CreerDevisAction::class)->handle(
        $societe->proprietaire,
        Client::query()->where('nom', 'Hôtel des Pins')->sole(),
        [['produit_id' => savon($societe->magasin)->id, 'quantite' => 4]],
    )));
    $onglet = $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid.'/devis/'.$devis->id);

    $onglet->voir('8,32 €')
        ->voir('33,30 €')
        ->voir('Le PU HT affiché est celui d\'un article')
        ->nePasVoir('8,325')
        ->cliquerSur('PDF');
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1);
    $textes = array_column($this->pagesDuPdf(base64_decode($fichiers[0]['contenu']))[0]['textes'], 'texte');
    expect($textes)->toContain('8,32 €')
        ->and($textes)->toContain('33,30 €')
        ->and($textes)->not->toContain('8,325 €')
        ->and(preg_replace('/\s+/u', ' ', implode(' ', $textes)))->toContain('La quantité × ce prix peut différer de quelques centimes du total HT de la ligne');
});

test('le vendeur lit le PU HT d’un article sur la fiche de sa facture à un professionnel', function () {
    $societe = $this->societeComplete();
    $facture = factureDeSavons($societe->magasin, $societe->vendeur, 'Hôtel des Pins');

    $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->voir('8,32 €')
        ->voir('Le PU HT affiché est celui d\'un article')
        ->nePasVoir('8,325');
});

test('l’administrateur lit le PU HT d’un article sur la fiche d’une facture à un professionnel', function () {
    $societe = $this->societeComplete();
    $facture = factureDeSavons($societe->magasin, $societe->vendeur, 'Hôtel des Pins');

    $this->seConnecter($societe->administrateur)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->voir('8,32 €')
        ->voir('Le PU HT affiché est celui d\'un article')
        ->nePasVoir('8,325');
});

test('le collaborateur du cabinet lié lit le PU HT d’un article sur la fiche d’une facture de son client à un professionnel', function () {
    $societe = $this->societeComplete();
    $facture = factureDeSavons($societe->magasin, $societe->vendeur, 'Hôtel des Pins');

    $this->seConnecter($societe->collaborateur, panneau: 'cabinet')->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->voir('8,32 €')
        ->voir('Le PU HT affiché est celui d\'un article')
        ->nePasVoir('8,325');
});
