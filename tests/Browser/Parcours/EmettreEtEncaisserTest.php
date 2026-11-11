<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Tests\Browser\Support\Navigation;

/*
 * « Émettre et encaisser » (R1) dans le navigateur : un brouillon fait hors
 * de la caisse s'émet et s'encaisse d'un seul geste, depuis sa fiche ou
 * depuis la liste des factures, en un ou plusieurs modes, en tout ou en
 * partie ; le PDF téléchargé imprime chaque règlement à gauche du Total
 * TTC (constat C3). Seul qui peut émettre et encaisser voit l'action. La
 * société de test a tous ses rôles et son catalogue (voir
 * TestCaseDuNavigateur::societeComplete()).
 */

/**
 * Un brouillon fait par ce compte dans ce magasin, pour ce client du
 * catalogue de la société de test, d'une bougie parfumée à 18,00 € TTC.
 */
function brouillonDe(Magasin $magasin, User $auteur, string $client): Facture
{
    return app(TenantContext::class)->runAs($magasin, fn (): Facture => app(CreerFactureAction::class)->handle(
        $auteur,
        Client::query()->where('nom', $client)->sole(),
        [['produit_id' => Produit::query()->where('reference', 'BOUGIE')->sole()->id, 'quantite' => 1]],
    ));
}

/**
 * Les paiements de cette facture, dans l'ordre : mode et montant.
 *
 * @return list<array{0: string, 1: string}>
 */
function paiementsDeLaFacture(Magasin $magasin, Facture $facture): array
{
    return app(TenantContext::class)->runAs($magasin, fn (): array => Paiement::query()
        ->whereHas('allocations', fn ($allocation) => $allocation->where('facture_id', $facture->id))
        ->orderBy('id')
        ->get()
        ->map(fn (Paiement $paiement): array => [$paiement->mode_paiement->value, (string) $paiement->montant])
        ->all());
}

test('le responsable émet et encaisse une facture en espèces et par carte depuis sa fiche, et son PDF imprime chaque règlement à gauche du Total TTC', function () {
    $societe = $this->societeComplete();
    $facture = brouillonDe($societe->magasin, $societe->responsable, 'Hôtel des Pins');
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id);

    $onglet->cliquerSur('Émettre et encaisser')
        ->voir('18,00 €')
        ->remplirLaLigne('parts', 0, 'montant', '10')
        ->cliquerSur('Ajouter un autre mode')
        ->choisirDansLaLigne('parts', 1, 'mode', ModePaiement::CarteBancaire->value)
        ->remplirLaLigne('parts', 1, 'montant', '8')
        ->remplirLaLigne('parts', 1, 'reference', 'CB-TRX-4521')
        ->soumettre('Émettre et encaisser')
        ->voir('Payée');

    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => $facture->fresh());
    expect($facture->statut)->toBe(StatutFacture::Payee)
        ->and(paiementsDeLaFacture($societe->magasin, $facture))->toBe([[ModePaiement::Especes->value, '10.00'], [ModePaiement::CarteBancaire->value, '8.00']]);

    $onglet->cliquerSur('PDF');
    $fichiers = $onglet->telechargements();
    expect($fichiers)->toHaveCount(1)
        ->and($fichiers[0]['nom'])->toBe('facture-'.$facture->numero.'.pdf');

    $page = $this->pagesDuPdf(base64_decode($fichiers[0]['contenu']))[0];
    $texte = fn (string $libelle): array => collect($page['textes'])->firstWhere('texte', $libelle) ?? throw new RuntimeException("« {$libelle} » n'est pas sur le PDF.");
    $totalTtc = $texte('Total TTC');
    foreach (['RÈGLEMENTS', 'Espèces', 'Carte bancaire', 'CB-TRX-4521', '10,00 €', '8,00 €', 'Restant dû à l\'émission'] as $reglement) {
        expect($texte($reglement)['x'] + $texte($reglement)['largeur'])->toBeLessThan($totalTtc['x']);
    }
    expect($texte('RÈGLEMENTS')['y'])->toBeGreaterThan($totalTtc['y'])
        ->and($texte('Restant dû à l\'émission')['y'])->toBeGreaterThan($totalTtc['y'] - 30);
});

/*
 * Un montant à zéro n'est pas un règlement (R1) : la part préremplie
 * ramenée à zéro, « Émettre et encaisser » émet le brouillon sans
 * l'encaisser, comme « Émettre ».
 */
test('le responsable ramène à zéro la part préremplie, et « Émettre et encaisser » émet le brouillon sans l’encaisser', function () {
    $societe = $this->societeComplete();
    $facture = brouillonDe($societe->magasin, $societe->responsable, 'Hôtel des Pins');
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id);

    $onglet->cliquerSur('Émettre et encaisser')
        ->remplirLaLigne('parts', 0, 'montant', '0')
        ->soumettre('Émettre et encaisser')
        ->voir('Émise');

    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => $facture->fresh());
    expect($facture->statut)->toBe(StatutFacture::Emise)
        ->and($facture->numero)->toStartWith('FAC-PORT-2026-')
        ->and(paiementsDeLaFacture($societe->magasin, $facture))->toBe([]);
});

test('l’administrateur autorisé à vendre émet un brouillon depuis sa fiche, sans l’encaisser', function () {
    $societe = $this->societeComplete();
    $facture = brouillonDe($societe->magasin, $societe->responsable, 'Hôtel des Pins');
    $onglet = $this->seConnecter($societe->administrateurVendeur)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id);

    $onglet->cliquerSur('Émettre')
        ->soumettre('Confirmer')
        ->voir('Émise');

    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => $facture->fresh());
    expect($facture->statut)->toBe(StatutFacture::Emise)
        ->and($facture->numero)->toStartWith('FAC-PORT-2026-')
        ->and(paiementsDeLaFacture($societe->magasin, $facture))->toBe([]);
    $onglet->nePasVoir('Émettre et encaisser');
});

/*
 * Jean Dupont a 6,00 € de crédit d'avoir (le retour d'un savon) : il
 * s'impute d'office à l'émission, la fenêtre le montre et propose les
 * 12,00 € qui restent ; 5,00 € en espèces laissent 7,00 € dus.
 */
test('le vendeur émet et encaisse en partie un ticket depuis la liste des factures', function () {
    $societe = $this->societeComplete();
    $ticket = brouillonDe($societe->magasin, $societe->vendeur, 'Jean Dupont');
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid.'/factures');

    $onglet->cliquer('.fi-ta-row:has(a[href$="/factures/'.$ticket->id.'"]) >> '.Navigation::bouton('Émettre et encaisser'))
        ->voir('6,00 €');
    expect($onglet->page()->evaluate('() => document.querySelector('.Navigation::texte(Navigation::FENETRE.' [id*=".parts."][id$=".montant"]').').value'))->toBe('12.00');

    $onglet->remplirLaLigne('parts', 0, 'montant', '5')
        ->soumettre('Émettre et encaisser')
        ->voir('Partiellement payée');

    [$statut, $restant] = app(TenantContext::class)->runAs($societe->magasin, fn (): array => [$ticket->fresh()->statut, $ticket->fresh()->montantRestantEnCentimes()]);
    expect($statut)->toBe(StatutFacture::PartiellementPayee)
        ->and($restant)->toBe(700)
        ->and(paiementsDeLaFacture($societe->magasin, $ticket))->toBe([[ModePaiement::Especes->value, '5.00']]);
});

test('« Émettre et encaisser » ne se montre qu’à qui peut émettre et encaisser, jamais au propriétaire ni au cabinet lié', function () {
    $societe = $this->societeComplete();
    $modele = fn (RoleMagasin $role): array => $role->permissionsParDefaut($societe->societe->type_activite);
    [$vendeurSansEncaissement, $observateurSansLaVente, $observateurAvecLaVente] = app(TenantContext::class)->runAs($societe->magasin, fn (): array => [
        $this->membre($societe->magasin, RoleMagasin::Vendeur, [...array_values(array_diff($modele(RoleMagasin::Vendeur), [PermissionRegistry::PAIEMENT_CREER])), PermissionRegistry::FACTURE_VIEW_TOUTES], ['name' => 'Valérie Sansencaissement']),
        $this->membre($societe->magasin, RoleMagasin::Observateur, [...$modele(RoleMagasin::Observateur), PermissionRegistry::FACTURE_VIEW_TOUTES], ['name' => 'Odile Regard']),
        $this->membre($societe->magasin, RoleMagasin::Observateur, [...$modele(RoleMagasin::Observateur), PermissionRegistry::FACTURE_VIEW_TOUTES, ...PermissionRegistry::deVente()], ['name' => 'Octave Vendeur']),
    ]);
    $duResponsable = brouillonDe($societe->magasin, $societe->responsable, 'Hôtel des Pins');
    $duVendeur = brouillonDe($societe->magasin, $societe->vendeur, 'Hôtel des Pins');
    $comptes = [
        'le propriétaire' => [$societe->proprietaire, 'app', $duResponsable, false, false],
        'l’administrateur sans la vente' => [$societe->administrateur, 'app', $duResponsable, false, false],
        'l’administrateur autorisé à vendre' => [$societe->administrateurVendeur, 'app', $duResponsable, true, true],
        'le responsable' => [$societe->responsable, 'app', $duResponsable, true, true],
        'le vendeur, sur son brouillon' => [$societe->vendeur, 'app', $duVendeur, true, true],
        'le vendeur sans l’autorisation d’encaisser' => [$vendeurSansEncaissement, 'app', $duResponsable, true, false],
        'l’observateur sans la vente' => [$observateurSansLaVente, 'app', $duResponsable, false, false],
        'l’observateur à qui la vente est cochée' => [$observateurAvecLaVente, 'app', $duResponsable, true, true],
        'le collaborateur du cabinet lié' => [$societe->collaborateur, 'cabinet', $duResponsable, false, false],
    ];

    foreach ($comptes as $qui => [$compte, $panneau, $brouillon, $emet, $encaisse]) {
        $liste = '/app/'.$societe->magasin->uuid.'/factures';
        $onglet = $this->seConnecter($compte, panneau: $panneau)->aller($liste.'/'.$brouillon->id)->voir('Brouillon (pas encore émise)');
        $boutons = fn (string $libelle): int => $onglet->element('.fi-header >> '.Navigation::bouton($libelle))->count();

        expect([$qui, $boutons('Émettre'), $boutons('Émettre et encaisser')])->toBe([$qui, $emet ? 1 : 0, $encaisse ? 1 : 0]);

        $onglet->aller($liste);
        $dansLaLigne = $onglet->element('.fi-ta-row:has(a[href$="/factures/'.$brouillon->id.'"]) >> '.Navigation::bouton('Émettre et encaisser'))->count();
        expect([$qui, $dansLaLigne])->toBe([$qui, $encaisse ? 1 : 0]);

        $onglet->seDeconnecter();
    }
});
