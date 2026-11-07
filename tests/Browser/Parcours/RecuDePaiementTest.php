<?php

use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;

/*
 * Le reçu de paiement (R2) dans le navigateur : la facture de l'Hôtel des
 * Pins (trois bougies, 54,00 €), émise par le vendeur, a reçu ensuite un
 * virement de 20,00 € (voir TestCaseDuNavigateur::remplirLeMagasin()), qui
 * a son reçu, RP-PORT-2026-000001. Sur la fiche de la facture, « Reçu
 * (PDF) » le télécharge, « Envoyer le reçu » l'envoie au client ; seul qui
 * voit ce paiement voit le premier, seul qui peut envoyer la facture le
 * second, jamais le cabinet lié.
 */

/**
 * La facture émise de l'Hôtel des Pins réglée en partie par un virement
 * reçu après son émission.
 */
function factureRegleeApresSonEmission(TenantContext $contexte, Magasin $magasin): Facture
{
    return $contexte->runAs($magasin, fn (): Facture => Facture::query()->whereNotNull('numero')->whereHas('allocationsPaiement')->whereDoesntHave('allocationsPaiement.paiement', fn ($paiement) => $paiement->whereNull('recu_paiement_id'))->sole());
}

test('le responsable télécharge le reçu du virement reçu après l’émission et l’envoie au client', function () {
    $societe = $this->societeComplete();
    $facture = factureRegleeApresSonEmission(app(TenantContext::class), $societe->magasin);
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->voir('RP-PORT-2026-000001');

    $onglet->cliquerSur('Reçu (PDF)');
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1)
        ->and($fichiers[0]['nom'])->toBe('recu-RP-PORT-2026-000001.pdf');
    $textes = array_column($this->pagesDuPdf(base64_decode($fichiers[0]['contenu']))[0]['textes'], 'texte');
    foreach (['Reçu de paiement', 'RP-PORT-2026-000001', 'Hôtel des Pins', 'Virement', '20,00 €', 'Total encaissé', 'Facture '.$facture->numero, '34,00 €', 'Ce reçu n\'est pas une facture.'] as $attendu) {
        expect(in_array($attendu, $textes, true))->toBeTrue($attendu);
    }

    $onglet->cliquerSur('Envoyer le reçu')
        ->remplir('Destinataire', 'compta@hotel-des-pins.fr')
        ->soumettre('Envoyer')
        ->voir('Envoi à compta@hotel-des-pins.fr programmé');

    $envoi = app(TenantContext::class)->runAs($societe->magasin, fn (): EnvoiDocument => EnvoiDocument::query()->where('document_type', 'recu_paiement')->sole());
    expect([$envoi->destinataire, $envoi->statut, (int) $envoi->document_id])
        ->toBe(['compta@hotel-des-pins.fr', StatutEnvoi::Envoye, app(TenantContext::class)->runAs($societe->magasin, fn (): int => RecuPaiement::query()->sole()->id)]);
});

test('« Reçu (PDF) » ne se montre qu’à qui voit le paiement, « Envoyer le reçu » qu’à qui envoie la facture, jamais au cabinet lié', function () {
    $societe = $this->societeComplete();
    $facture = factureRegleeApresSonEmission(app(TenantContext::class), $societe->magasin);
    $comptes = [
        'le propriétaire' => [$societe->proprietaire, 'app', true, true],
        'l’administrateur sans la vente' => [$societe->administrateur, 'app', true, true],
        'l’administrateur autorisé à vendre' => [$societe->administrateurVendeur, 'app', true, true],
        'le responsable' => [$societe->responsable, 'app', true, true],
        'le vendeur qui a encaissé' => [$societe->vendeur, 'app', true, true],
        'le collaborateur du cabinet lié' => [$societe->collaborateur, 'cabinet', true, false],
    ];

    foreach ($comptes as $qui => [$compte, $panneau, $telecharge, $envoie]) {
        $onglet = $this->seConnecter($compte, panneau: $panneau)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)->voir('Paiements reçus');
        $boutons = fn (string $libelle): int => $onglet->element('.fi-in-repeatable-item >> '.Navigation::bouton($libelle))->count();

        expect([$qui, $boutons('Reçu (PDF)'), $boutons('Envoyer le reçu')])->toBe([$qui, $telecharge ? 1 : 0, $envoie ? 1 : 0]);

        $onglet->seDeconnecter();
    }
});
