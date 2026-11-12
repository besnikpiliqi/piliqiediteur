<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Tests\Browser\Support\Navigation;

/*
 * La fiche d'une facture ou d'un ticket dans le navigateur : un brouillon
 * s'y modifie et s'y émet, pour qui le peut depuis la liste (constat C9,
 * réponse de l'éditeur à T42) ; la section « Paiements reçus » ne se montre
 * qu'à qui voit les paiements de ce document, « Payé » et « Restant dû »
 * restant visibles (constat C8, réponse de l'éditeur à T41). La société de
 * test a tous ses rôles et son catalogue (voir
 * TestCaseDuNavigateur::societeComplete()).
 */

/**
 * Le brouillon de facture du magasin de la société de test, fait par son
 * vendeur (voir TestCaseDuNavigateur::remplirLeMagasin()).
 */
function brouillonDuVendeur(Magasin $magasin): Facture
{
    return app(TenantContext::class)->runAs($magasin, fn (): Facture => Facture::query()->where('statut', StatutFacture::Brouillon)->sole());
}

/**
 * Le nombre de boutons ou de liens de ce libellé en haut de la fiche.
 */
function actionsDeLaFiche(Navigation $onglet, string $libelle): int
{
    return $onglet->element('.fi-header >> internal:role=button[name='.Navigation::texte($libelle).'s]')->count()
        + $onglet->element('.fi-header >> internal:role=link[name='.Navigation::texte($libelle).'s]')->count();
}

test('le responsable modifie puis émet un brouillon depuis sa fiche', function () {
    $societe = $this->societeComplete();
    $brouillon = brouillonDuVendeur($societe->magasin);
    $fiche = '/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->id;
    $onglet = $this->seConnecter($societe->responsable)->aller($fiche)->voir('Brouillon (pas encore émise)');

    $onglet->cliquerSurLeLien('Modifier')->attendreLaPage('/factures/'.$brouillon->id.'/edit$');
    $onglet->aller($fiche)
        ->cliquerSur('Émettre')
        ->soumettre('Confirmer')
        ->voir('Émise');

    $emise = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => $brouillon->fresh());
    expect($emise->statut)->toBe(StatutFacture::Emise)
        ->and($emise->numero)->toStartWith('FAC-PORT-2026-');
    $onglet->voir((string) $emise->numero);
    expect(actionsDeLaFiche($onglet, 'Modifier'))->toBe(0)
        ->and(actionsDeLaFiche($onglet, 'Émettre'))->toBe(0);
});

test('le vendeur modifie puis émet son brouillon depuis sa fiche', function () {
    $societe = $this->societeComplete();
    $brouillon = brouillonDuVendeur($societe->magasin);
    $fiche = '/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->id;
    $onglet = $this->seConnecter($societe->vendeur)->aller($fiche);

    $onglet->cliquerSurLeLien('Modifier')->attendreLaPage('/factures/'.$brouillon->id.'/edit$');
    $onglet->aller($fiche)
        ->cliquerSur('Émettre')
        ->soumettre('Confirmer')
        ->voir('Émise');

    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): StatutFacture => $brouillon->fresh()->statut))->toBe(StatutFacture::Emise);
});

test('« Modifier » et « Émettre » ne se montrent sur la fiche d’un brouillon qu’à qui peut le modifier et l’émettre', function () {
    $societe = $this->societeComplete();
    $brouillon = brouillonDuVendeur($societe->magasin);
    $lecteur = app(TenantContext::class)->runAs($societe->magasin, fn (): User => $this->membre($societe->magasin, RoleMagasin::Observateur, [
        ...RoleMagasin::Observateur->permissionsParDefaut($societe->societe->type_activite),
        PermissionRegistry::FACTURE_VIEW_TOUTES,
    ], ['name' => 'Odile Regard']));
    $comptes = [
        'le propriétaire' => [$societe->proprietaire, 'app', false],
        'l’administrateur sans la vente' => [$societe->administrateur, 'app', false],
        'l’administrateur autorisé à vendre' => [$societe->administrateurVendeur, 'app', true],
        'l’observateur qui voit les factures de l’équipe' => [$lecteur, 'app', false],
        'le collaborateur du cabinet lié' => [$societe->collaborateur, 'cabinet', false],
    ];

    foreach ($comptes as $qui => [$compte, $panneau, $peut]) {
        $onglet = $this->seConnecter($compte, panneau: $panneau)
            ->aller('/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->id)
            ->voir('Brouillon (pas encore émise)');

        expect(actionsDeLaFiche($onglet, 'Modifier'))->toBe($peut ? 1 : 0, $qui.' : « Modifier »')
            ->and(actionsDeLaFiche($onglet, 'Émettre'))->toBe($peut ? 1 : 0, $qui.' : « Émettre »');
    }
});

test('« Paiements reçus » ne se montre qu’à qui voit les paiements du document ; « Payé » et « Restant dû » restent visibles', function () {
    $societe = $this->societeComplete();
    [$sansLesPaiements, $saFacture] = app(TenantContext::class)->runAs($societe->magasin, function () use ($societe): array {
        $vendeur = $this->membre($societe->magasin, RoleMagasin::Vendeur, array_values(array_diff(
            RoleMagasin::Vendeur->permissionsParDefaut($societe->societe->type_activite),
            [PermissionRegistry::PAIEMENT_VIEW],
        )), ['name' => 'Valentin Sanspaiement']);
        $client = Client::query()->where('nom', 'Hôtel des Pins')->sole();
        $facture = app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, $client, [['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 1]]));
        app(EnregistrerPaiementAction::class)->handle($societe->responsable, $client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '10.00', 'recu_le' => '2026-03-10'], [['facture_id' => $facture->id, 'montant' => '10.00']]);

        return [$vendeur, $facture];
    });
    $partiellementPayee = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('statut', StatutFacture::PartiellementPayee)->where('cree_par', $societe->vendeur->id)->sole());

    $onglet = $this->seConnecter($sansLesPaiements)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$saFacture->id)
        ->voir('Restant dû')
        ->voir('8,00 €')
        ->nePasVoir('Paiements reçus');
    expect($onglet->element('text=Reçu le')->count())->toBe(0);

    $comptes = [
        'le vendeur qui voit les paiements' => [$societe->vendeur, 'app'],
        'le propriétaire' => [$societe->proprietaire, 'app'],
        'le responsable' => [$societe->responsable, 'app'],
        'le collaborateur du cabinet lié' => [$societe->collaborateur, 'cabinet'],
    ];

    foreach ($comptes as $qui => [$compte, $panneau]) {
        $this->seConnecter($compte, panneau: $panneau)
            ->aller('/app/'.$societe->magasin->uuid.'/factures/'.$partiellementPayee->id)
            ->voir('Paiements reçus')
            ->voir('Virement')
            ->voir('Restant dû');
    }
});
