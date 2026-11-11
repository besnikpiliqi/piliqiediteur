<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Tests\Browser\Support\Navigation;

/*
 * Le PU HT tel qu'il est enregistré (décision de l'éditeur du 09/11/2026,
 * « exact ») : sur une facture, un avoir sur facture ou un devis à un
 * professionnel, et sur une vente sans TVA française, le PU HT s'affiche
 * jusqu'à six décimales, sans zéro inutile au-delà de deux, sur le PDF, la
 * fiche et les lignes du formulaire ; un ticket ou un devis à un
 * particulier le montre au centime. 4 diffuseurs à 65,00 € TTC à 20 % : PU
 * HT 54,166667 €, 54,17 € au centime ; 216,67 € HT. La société de test a
 * tous ses rôles et son catalogue (voir TestCaseDuNavigateur::societeComplete()).
 */

/**
 * Le diffuseur à 65,00 € TTC à 20 % du catalogue du magasin, créé à la
 * première demande.
 */
function diffuseur(Magasin $magasin): Produit
{
    return app(TenantContext::class)->runAs($magasin, fn (): Produit => Produit::query()->where('reference', 'DIFFUSEUR')->first()
        ?? Produit::factory()->create(['nom' => 'Diffuseur', 'reference' => 'DIFFUSEUR', 'prix_vente_ttc' => '65.00', 'taux_tva' => 20, 'suivi_stock' => false]));
}

/**
 * Le brouillon de facture de 4 diffuseurs fait par ce compte pour ce client
 * du catalogue de la société de test.
 */
function brouillonDeDiffuseurs(Magasin $magasin, User $auteur, string $client): Facture
{
    return app(TenantContext::class)->runAs($magasin, fn (): Facture => app(CreerFactureAction::class)->handle(
        $auteur,
        Client::query()->where('nom', $client)->sole(),
        [['produit_id' => diffuseur($magasin)->id, 'quantite' => 4]],
    ));
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

test('le responsable lit le PU HT tel qu’il est enregistré sur la fiche et sur le PDF d’une facture à un professionnel, et au centime sur la fiche d’un ticket', function () {
    $societe = $this->societeComplete();
    [$facture, $ticket] = array_map(
        fn (string $client): Facture => app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => app(EmettreFactureAction::class)->handle($societe->responsable, brouillonDeDiffuseurs($societe->magasin, $societe->responsable, $client))),
        ['Hôtel des Pins', 'Jean Dupont'],
    );
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id);

    $onglet->voir('54,166667 €')
        ->voir('Les prix unitaires HT sont affichés tels qu\'ils sont enregistrés')
        ->nePasVoir('54,17 €')
        ->cliquerSur('PDF');
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1);
    $textes = array_column($this->pagesDuPdf(base64_decode($fichiers[0]['contenu']))[0]['textes'], 'texte');
    expect($textes)->toContain('54,166667 €')
        ->and($textes)->toContain('216,67 €')
        ->and($textes)->not->toContain('54,17 €');

    $onglet->aller('/app/'.$societe->magasin->uuid.'/factures/'.$ticket->id)
        ->voir('54,17 €')
        ->voir('Les prix unitaires sont affichés arrondis au centime')
        ->nePasVoir('54,166667');
});

test('le propriétaire lit le PU HT tel qu’il est enregistré sur la fiche d’une facture à un professionnel', function () {
    $societe = $this->societeComplete();
    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => app(EmettreFactureAction::class)->handle($societe->responsable, brouillonDeDiffuseurs($societe->magasin, $societe->responsable, 'Hôtel des Pins')));

    $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->voir('54,166667 €')
        ->nePasVoir('54,17 €');
});

test('le vendeur change le client de son brouillon, et le PU HT des lignes suit : tel qu’il est enregistré pour un professionnel, au centime pour un particulier', function () {
    $societe = $this->societeComplete();
    $brouillon = brouillonDeDiffuseurs($societe->magasin, $societe->vendeur, 'Hôtel des Pins');
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->id.'/edit');

    attendreLePuHt($onglet, '54.166667');
    $onglet->choisirDansLaListe('Client', 'Jean Dupont');
    attendreLePuHt($onglet, '54.17');
    $onglet->choisirDansLaListe('Client', 'Hôtel des Pins');
    attendreLePuHt($onglet, '54.166667')
        ->soumettre('Sauvegarder')
        ->voir('Sauvegardé');

    $brouillon = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => $brouillon->fresh(['client', 'lignes']));
    expect($brouillon->client->nom)->toBe('Hôtel des Pins')
        ->and($brouillon->lignes->sole()->prix_unitaire_ht)->toBe('54.166667')
        ->and($brouillon->total_ht)->toBe('216.67');
});

test('le responsable fait sortir de la TVA française la vente d’un particulier, et le PU HT des lignes se montre tel qu’il sera enregistré', function () {
    $societe = $this->societeComplete();
    $brouillon = brouillonDeDiffuseurs($societe->magasin, $societe->responsable, 'Jean Dupont');
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->id.'/edit');

    attendreLePuHt($onglet, '54.17');
    $onglet->choisir('Nature de l\'opération', NatureOperation::ExportHorsUe->value);
    attendreLePuHt($onglet, '54.166667');
    $onglet->choisir('Nature de l\'opération', NatureOperation::VenteEnFrance->value);
    attendreLePuHt($onglet, '54.17');
});

test('le propriétaire change le client d’un devis, et le PU HT des lignes suit', function () {
    $societe = $this->societeComplete();
    $devis = app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => app(CreerDevisAction::class)->handle(
        $societe->proprietaire,
        Client::query()->where('nom', 'Jean Dupont')->sole(),
        [['produit_id' => diffuseur($societe->magasin)->id, 'quantite' => 4]],
    ));
    $onglet = $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid.'/devis/'.$devis->id.'/edit');

    attendreLePuHt($onglet, '54.17');
    $onglet->choisirDansLaListe('Client', 'Hôtel des Pins');
    attendreLePuHt($onglet, '54.166667')
        ->soumettre('Sauvegarder')
        ->voir('Sauvegardé');

    $devis = app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => $devis->fresh(['client', 'lignes']));
    expect($devis->client->nom)->toBe('Hôtel des Pins')
        ->and($devis->lignes->sole()->prix_unitaire_ht)->toBe('54.166667');
});
