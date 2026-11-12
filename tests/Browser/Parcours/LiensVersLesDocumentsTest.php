<?php

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SocieteDeTest;

/*
 * Constat C2 de l'éditeur, confirmé à T43, dans le navigateur : chaque
 * numéro de document d'une fiche mène à la fiche de ce document pour qui
 * peut la voir, et reste un texte simple pour les autres ; un ticket est
 * nommé « Ticket ». Dans la société de test (voir
 * TestCaseDuNavigateur::remplirLeMagasin()), Jean Dupont a un avoir de
 * 6,00 €, fait par le responsable sur un ticket du vendeur ; le ticket que
 * le responsable lui émet ensuite, resté dû, en reçoit d'office le crédit.
 */

/**
 * L'avoir de Jean Dupont, le ticket du vendeur qu'il corrige et le ticket
 * du responsable, émis ensuite, que son crédit règle en partie.
 *
 * @return array{0: Avoir, 1: Facture, 2: Facture}
 */
function avoirEtTicketsDeJeanDupont(SocieteDeTest $societe): array
{
    return app(TenantContext::class)->runAs($societe->magasin, function () use ($societe): array {
        $client = Client::query()->where('nom', 'Jean Dupont')->sole();
        $ticketDuResponsable = app(EmettreFactureAction::class)->handle($societe->responsable, app(CreerFactureAction::class)->handle($societe->responsable, $client, [
            ['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 1],
        ]));
        $avoir = Avoir::query()->where('client_id', $client->id)->sole();

        return [$avoir, $avoir->facturesConcernees()->sole(), $ticketDuResponsable];
    });
}

/**
 * Le lien de ce texte exact, dans la page.
 */
function liensDeTexte(Navigation $onglet, string $texte): int
{
    return $onglet->element('internal:role=link[name='.Navigation::texte($texte).'s]')->count();
}

test('le responsable passe d’un document à l’autre par leurs numéros : avoir, ticket crédité, ticket réglé par le crédit', function () {
    $societe = $this->societeComplete();
    [$avoir, $ticketDuVendeur, $ticketDuResponsable] = avoirEtTicketsDeJeanDupont($societe);
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($societe->responsable)->aller($magasin.'/avoirs/'.$avoir->id)
        ->voir('Crédit appliqué')
        ->voir('Ticket '.$ticketDuVendeur->numero);

    $onglet->cliquerSurLeLien((string) $ticketDuResponsable->numero)
        ->attendreLaPage('/factures/'.$ticketDuResponsable->id.'$')
        ->voir('Crédité par avoir')
        ->cliquerSurLeLien((string) $avoir->numero)
        ->attendreLaPage('/avoirs/'.$avoir->id.'$')
        ->cliquerSurLeLien((string) $ticketDuVendeur->numero)
        ->attendreLaPage('/factures/'.$ticketDuVendeur->id.'$')
        ->voir('Avoirs émis')
        ->cliquerSurLeLien((string) $avoir->numero)
        ->attendreLaPage('/avoirs/'.$avoir->id.'$');

    $onglet->aller($magasin.'/paiements')
        ->cliquerSurLeLien((string) $ticketDuVendeur->numero)
        ->attendreLaPage('/factures/'.$ticketDuVendeur->id.'$');
});

test('le collaborateur du cabinet lié ouvre de même le ticket que le crédit d’un avoir a réglé', function () {
    $societe = $this->societeComplete();
    [$avoir, , $ticketDuResponsable] = avoirEtTicketsDeJeanDupont($societe);

    $this->seConnecter($societe->collaborateur, panneau: 'cabinet')
        ->aller('/app/'.$societe->magasin->uuid.'/avoirs/'.$avoir->id)
        ->cliquerSurLeLien((string) $ticketDuResponsable->numero)
        ->attendreLaPage('/factures/'.$ticketDuResponsable->id.'$');
});

/*
 * Le vendeur voit son ticket, mais pas les avoirs : le numéro de l'avoir
 * qui le corrige se lit sur sa fiche, sans lien qui le mènerait à un refus.
 */
test('un vendeur qui ne voit pas l’avoir de son ticket en lit le numéro, sans lien', function () {
    $societe = $this->societeComplete();
    [$avoir, $ticketDuVendeur] = avoirEtTicketsDeJeanDupont($societe);

    $onglet = $this->seConnecter($societe->vendeur)
        ->aller('/app/'.$societe->magasin->uuid.'/factures/'.$ticketDuVendeur->id)
        ->voir('Avoirs émis')
        ->voir((string) $avoir->numero);

    expect(liensDeTexte($onglet, (string) $avoir->numero))->toBe(0);
});

/*
 * L'avoir fournisseur de la société de test (le retour d'une bougie
 * cassée au Grossiste du Nord) mène à la facture d'achat qu'il crédite, qui
 * mène à son tour à lui.
 */
test('le responsable passe de l’avoir fournisseur à la facture d’achat qu’il crédite, et retour', function () {
    $societe = $this->societeComplete();
    $avoir = app(TenantContext::class)->runAs($societe->magasin, fn (): AvoirFournisseur => AvoirFournisseur::query()->with('factureAchat')->sole());
    $achat = $avoir->factureAchat;

    $this->seConnecter($societe->responsable)
        ->aller('/app/'.$societe->magasin->uuid.'/avoir-fournisseurs/'.$avoir->id)
        ->voir('Facture d\'achat créditée')
        ->cliquerSurLeLien((string) $achat->numero)
        ->attendreLaPage('/facture-achats/'.$achat->id.'$')
        ->cliquer('#avoirsFournisseurEmisListe a[href$="/avoir-fournisseurs/'.$avoir->id.'"]')
        ->attendreLaPage('/avoir-fournisseurs/'.$avoir->id.'$');
});
