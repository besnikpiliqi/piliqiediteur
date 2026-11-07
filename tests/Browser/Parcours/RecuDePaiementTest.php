<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Tests\Browser\Support\Navigation;

/*
 * Le reçu de paiement (R2) dans le navigateur : la facture de l'Hôtel des
 * Pins (trois bougies, 54,00 €), émise par le vendeur, a reçu ensuite un
 * virement de 20,00 € (voir TestCaseDuNavigateur::remplirLeMagasin()), qui
 * a son reçu, RP-PORT-2026-000001. Sur la fiche de la facture, « Reçu
 * (PDF) » le télécharge, « Envoyer le reçu » l'envoie au client ; seul qui
 * voit ce paiement voit le premier (« Paiements : voir », et, sans « voir
 * celles de toute l'équipe », seulement pour un encaissement qu'il a fait
 * lui-même), seul qui peut aussi envoyer la facture le second, jamais le
 * cabinet lié.
 */

/**
 * La facture émise de l'Hôtel des Pins réglée en partie par un virement
 * reçu après son émission.
 */
function factureRegleeApresSonEmission(TenantContext $contexte, Magasin $magasin): Facture
{
    return $contexte->runAs($magasin, fn (): Facture => Facture::query()->whereNotNull('numero')->whereHas('allocationsPaiement')->whereDoesntHave('allocationsPaiement.paiement', fn ($paiement) => $paiement->whereNull('recu_paiement_id'))->sole());
}

/**
 * Une facture d'une bougie à l'Hôtel des Pins, faite et émise par cette
 * vendeuse, puis réglée en partie par un virement de 5,00 € que le
 * responsable enregistre : son reçu est celui d'un encaissement qu'elle n'a
 * pas fait.
 */
function factureEncaisseeParUnAutre(Magasin $magasin, User $vendeuse, User $responsable): Facture
{
    return app(TenantContext::class)->runAs($magasin, function () use ($vendeuse, $responsable): Facture {
        $client = Client::query()->where('nom', 'Hôtel des Pins')->sole();
        $facture = app(EmettreFactureAction::class)->handle($vendeuse, app(CreerFactureAction::class)->handle($vendeuse, $client, [
            ['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 1],
        ]));
        app(EnregistrerPaiementAction::class)->handle($responsable, $client, ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '5.00', 'recu_le' => '2026-03-10'], [['facture_id' => $facture->id, 'montant' => '5.00']]);

        return $facture;
    });
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
    $type = $societe->societe->type_activite;
    $modele = fn (RoleMagasin $role): array => $role->permissionsParDefaut($type);
    [$vendeurSansLesPaiements, $vendeuse, $observateurSansLesPaiements, $observateurAvecLesPaiements, $administrateurSansLesPaiements] = app(TenantContext::class)->runAs($societe->magasin, fn (): array => [
        $this->membre($societe->magasin, RoleMagasin::Vendeur, [...array_values(array_diff($modele(RoleMagasin::Vendeur), [PermissionRegistry::PAIEMENT_VIEW, PermissionRegistry::PAIEMENT_CREER])), PermissionRegistry::FACTURE_VIEW_TOUTES], ['name' => 'Valentin Sanspaiement']),
        $this->membre($societe->magasin, RoleMagasin::Vendeur, $modele(RoleMagasin::Vendeur), ['name' => 'Vanessa Leroy']),
        $this->membre($societe->magasin, RoleMagasin::Observateur, [...$modele(RoleMagasin::Observateur), PermissionRegistry::FACTURE_VIEW_TOUTES], ['name' => 'Odile Regard']),
        $this->membre($societe->magasin, RoleMagasin::Observateur, [...$modele(RoleMagasin::Observateur), PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::PAIEMENT_VIEW], ['name' => 'Oscar Paiement']),
        $this->administrateur($societe->magasin, null, [], array_values(array_diff(PermissionRegistry::modeleDeLAdministrateur($type), [PermissionRegistry::PAIEMENT_VIEW]))),
    ]);
    $deLaVendeuse = factureEncaisseeParUnAutre($societe->magasin, $vendeuse, $societe->responsable);
    $comptes = [
        'le propriétaire' => [$societe->proprietaire, 'app', $facture, true, true],
        'l’administrateur sans la vente' => [$societe->administrateur, 'app', $facture, true, true],
        'l’administrateur autorisé à vendre' => [$societe->administrateurVendeur, 'app', $facture, true, true],
        'l’administrateur sans « Paiements : voir »' => [$administrateurSansLesPaiements, 'app', $facture, false, false],
        'le responsable' => [$societe->responsable, 'app', $facture, true, true],
        'le vendeur qui a encaissé' => [$societe->vendeur, 'app', $facture, true, true],
        'la vendeuse, sur sa facture encaissée par le responsable' => [$vendeuse, 'app', $deLaVendeuse, false, false],
        'le vendeur qui voit toutes les factures, sans « Paiements : voir »' => [$vendeurSansLesPaiements, 'app', $facture, false, false],
        'l’observateur qui voit toutes les factures, sans « Paiements : voir »' => [$observateurSansLesPaiements, 'app', $facture, false, false],
        'l’observateur qui voit toutes les factures et les paiements' => [$observateurAvecLesPaiements, 'app', $facture, true, false],
        'le collaborateur du cabinet lié' => [$societe->collaborateur, 'cabinet', $facture, true, false],
    ];

    foreach ($comptes as $qui => [$compte, $panneau, $document, $telecharge, $envoie]) {
        $recu = app(TenantContext::class)->runAs($societe->magasin, fn (): string => $document->allocationsPaiement()->sole()->paiement->recuPaiement->numero);
        $onglet = $this->seConnecter($compte, panneau: $panneau)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$document->id)->voir('Paiements reçus')->voir($recu);
        $boutons = fn (string $libelle): int => $onglet->element('.fi-in-repeatable-item >> '.Navigation::bouton($libelle))->count();

        expect([$qui, $boutons('Reçu (PDF)'), $boutons('Envoyer le reçu')])->toBe([$qui, $telecharge ? 1 : 0, $envoie ? 1 : 0]);

        $onglet->seDeconnecter();
    }
});
