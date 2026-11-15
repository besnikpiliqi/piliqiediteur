<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EmettreLaFactureRectificativeAction;
use App\Domain\Facturation\Actions\FaireLAvoirDeRegularisationAction;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SocieteDeTest;

/*
 * Lot REGUL. La régularisation d'une facture après la correction du SIREN
 * de la société : sur la fiche de la facture désignée, « Émettre la
 * facture rectificative » ne s'offre qu'après « Faire l'avoir de
 * régularisation » (T68.1), à qui peut vendre dans le magasin (le
 * responsable, l'administrateur autorisé à vendre, le vendeur qui voit la
 * facture), jamais au propriétaire, qui fait l'avoir ; la rectificative
 * reçoit le crédit de l'avoir. La contre-passation d'un paiement de la
 * facture régularisée (T68.3) : sa fenêtre nomme la rectificative, qui
 * porte le dû rouvert et se relance, la facture régularisée ne se
 * relançant jamais. Les relances (décision de l'éditeur du 14/11/2026) :
 * « Relancer aussi les particuliers » dans Paramètres > Relances, pour
 * chaque compte qui règle les relances, et « Relancer », à la main, sur le
 * ticket échu d'un particulier.
 */

/*
 * La facture émise de l'Hôtel des Pins (3 bougies, 54,00 € TTC, réglée de
 * 20,00 € par virement), désignée par la correction du SIREN de la société
 * par l'éditeur le 15/04/2026 à 10 h ; l'horloge passe ensuite à 10 h 05.
 */
$factureDesignee = function (SocieteDeTest $societe): Facture {
    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->whereNotNull('numero')->whereRelation('client', 'nom', 'Hôtel des Pins')->sole());
    $this->travelTo(self::aParis('2026-04-15 10:00:00'));
    $sirenCorrect = SocieteFactory::sirenFictif();
    $this->corrigerLeSirenParLEditeur($societe->societe, $sirenCorrect, [
        'PORT' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001'),
        'GARE' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0002'),
    ], IdentifiantsInsee::numeroTvaPropose($sirenCorrect));
    $this->travelTo(self::aParis('2026-04-15 10:05:00'));

    return $facture;
};

/*
 * Ce compte émet, depuis la fiche de la facture désignée dont la
 * propriétaire a fait l'avoir de régularisation, sa facture rectificative,
 * qui reçoit les 20,00 € réservés de l'avoir et reste due de 34,00 €.
 */
$rectificativePar = function (SocieteDeTest $societe, User $compte) use ($factureDesignee): void {
    $facture = $factureDesignee->call($this, $societe);
    $avoir = app(TenantContext::class)->runAs($societe->magasin, fn (): Avoir => app(FaireLAvoirDeRegularisationAction::class)->handle($societe->proprietaire, $facture->fresh()));
    $fiche = '/app/'.$societe->magasin->uuid.'/factures/'.$facture->getRouteKey();

    $onglet = $this->seConnecter($compte)->aller($fiche)
        ->voir((string) $facture->numero)
        ->cliquerSur('Émettre la facture rectificative')
        ->soumettre('Confirmer');
    $rectificative = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('facture_rectifiee_id', $facture->id)->sole());
    $onglet->voir('Facture rectificative '.$rectificative->numero.' émise.');

    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): array => [$rectificative->fresh()?->cree_par, $rectificative->fresh()?->resteDuEnCentimes(), $facture->fresh()?->resteDuEnCentimes(), $avoir->fresh()?->creditEnCentimes()]))
        ->toBe([$compte->id, 3400, 0, 0]);
};

/*
 * Ce compte, qui règle les relances, coche « Relancer aussi les
 * particuliers » avec les relances automatiques, et le réglage
 * s'enregistre pour le magasin.
 */
$caseDesParticuliersPar = function (SocieteDeTest $societe, User $compte): void {
    $onglet = $this->seConnecter($compte)->aller('/app/'.$societe->magasin->uuid.'/parametres-relances')
        ->voir('Relancer aussi les particuliers');
    $onglet->cliquer('label:has-text('.Navigation::texte('Relancer automatiquement les factures impayées').') >> button[role="switch"]')
        ->cliquer('label:has-text('.Navigation::texte('Relancer aussi les particuliers').') >> button[role="switch"]')
        ->soumettre('Enregistrer')
        ->voir('Relances automatiques enregistrées');

    expect(Magasin::query()->whereKey($societe->magasin->id)->sole()->only(['relances_automatiques', 'relancer_aussi_les_particuliers']))
        ->toBe(['relances_automatiques' => true, 'relancer_aussi_les_particuliers' => true]);
};

test('le responsable fait l’avoir de régularisation d’une facture désignée, puis seulement émet sa facture rectificative, réglée du crédit de l’avoir', function () use ($factureDesignee) {
    $societe = $this->societeComplete();
    $facture = $factureDesignee->call($this, $societe);
    $fiche = '/app/'.$societe->magasin->uuid.'/factures/'.$facture->getRouteKey();

    $onglet = $this->seConnecter($societe->responsable)->aller($fiche)
        ->voir((string) $facture->numero)
        ->voir('Faire l\'avoir de régularisation');
    expect($onglet->element(Navigation::bouton('Émettre la facture rectificative'))->count())->toBe(0);

    $onglet->cliquerSur('Faire l\'avoir de régularisation')
        ->soumettre('Confirmer');
    $avoir = app(TenantContext::class)->runAs($societe->magasin, fn (): Avoir => Avoir::query()->whereNotNull('motif_regularisation')->sole());
    $onglet->voir('Avoir de régularisation '.$avoir->numero.' émis.');

    $onglet->aller($fiche)
        ->voir((string) $facture->numero)
        ->cliquerSur('Émettre la facture rectificative')
        ->soumettre('Confirmer');
    $rectificative = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('facture_rectifiee_id', $facture->id)->sole());
    $onglet->voir('Facture rectificative '.$rectificative->numero.' émise.')
        ->voir((string) $facture->numero);

    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): array => [$rectificative->fresh()?->cree_par, $facture->fresh()?->resteDuEnCentimes(), $avoir->fresh()?->creditEnCentimes()]))
        ->toBe([$societe->responsable->id, 0, 0]);
});

test('la propriétaire fait l’avoir de régularisation d’une facture désignée, et « Émettre la facture rectificative » ne s’offre jamais à elle, qui ne vend pas', function () use ($factureDesignee) {
    $societe = $this->societeComplete();
    $facture = $factureDesignee->call($this, $societe);
    $fiche = '/app/'.$societe->magasin->uuid.'/factures/'.$facture->getRouteKey();

    $onglet = $this->seConnecter($societe->proprietaire)->aller($fiche)
        ->voir((string) $facture->numero)
        ->cliquerSur('Faire l\'avoir de régularisation')
        ->soumettre('Confirmer');
    $avoir = app(TenantContext::class)->runAs($societe->magasin, fn (): Avoir => Avoir::query()->whereNotNull('motif_regularisation')->sole());
    $onglet->voir('Avoir de régularisation '.$avoir->numero.' émis.');
    $onglet->aller($fiche)->voir((string) $facture->numero);

    expect([$onglet->element(Navigation::bouton('Émettre la facture rectificative'))->count(), $onglet->element(Navigation::bouton('Faire l\'avoir de régularisation'))->count()])->toBe([0, 0])
        ->and(app(TenantContext::class)->runAs($societe->magasin, fn (): array => [$avoir->cree_par, $facture->fresh()?->resteDuEnCentimes(), $avoir->fresh()?->creditEnCentimes(), Facture::query()->where('facture_rectifiee_id', $facture->id)->count()]))
        ->toBe([$societe->proprietaire->id, 0, 2000, 0]);
});

test('l’administrateur autorisé à vendre émet la facture rectificative, une fois l’avoir de régularisation fait', function () use ($rectificativePar) {
    $societe = $this->societeComplete();

    $rectificativePar->call($this, $societe, $societe->administrateurVendeur);
});

test('le vendeur émet la facture rectificative de la facture qu’il a faite, une fois l’avoir de régularisation fait', function () use ($rectificativePar) {
    $societe = $this->societeComplete();

    $rectificativePar->call($this, $societe, $societe->vendeur);
});

test('la propriétaire contre-passe le paiement de la facture régularisée : la fenêtre nomme la rectificative, qui porte le dû rouvert et se relance, et la facture régularisée ne se relance pas', function () use ($factureDesignee) {
    $societe = $this->societeComplete();
    $facture = $factureDesignee->call($this, $societe);
    [$avoir, $rectificative] = app(TenantContext::class)->runAs($societe->magasin, fn (): array => [
        app(FaireLAvoirDeRegularisationAction::class)->handle($societe->proprietaire, $facture->fresh()),
        app(EmettreLaFactureRectificativeAction::class)->handle($societe->responsable, $facture->fresh()),
    ]);
    $this->travelTo(self::aParis('2026-06-01 10:00:00'));

    $onglet = $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid.'/paiements')
        ->voir('Hôtel des Pins')
        ->cliquer('.fi-ta-row:has-text("Hôtel des Pins") >> '.Navigation::bouton('Contre-passer'))
        ->voir('La facture '.$facture->numero.', régularisée par l\'avoir '.$avoir->numero.', restera soldée : ce que ce paiement y réglait deviendra dû sur sa facture rectificative '.$rectificative->numero.'.')
        ->remplir('Motif', 'Virement rejeté par la banque')
        ->soumettre('Confirmer');
    $onglet->attendreQue('document.querySelector('.Navigation::texte(Navigation::FENETRE).') === null', 'la fenêtre de la contre-passation ne se ferme pas');

    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): array => [$facture->fresh()?->resteDuEnCentimes(), $rectificative->fresh()?->resteDuEnCentimes()]))->toBe([0, 5400]);

    $onglet->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->getRouteKey())->voir((string) $facture->numero);
    expect($onglet->element(Navigation::bouton('Relancer'))->count())->toBe(0);

    $onglet->aller('/app/'.$societe->magasin->uuid.'/factures/'.$rectificative->getRouteKey())
        ->voir((string) $rectificative->numero)
        ->voir('54,00')
        ->cliquerSur('Relancer')
        ->remplir('Destinataire', 'compta@hotel-des-pins.example')
        ->soumettre('Relancer')
        ->voir('Envoi à compta@hotel-des-pins.example demandé');

    $relance = app(TenantContext::class)->runAs($societe->magasin, fn (): EnvoiDocument => EnvoiDocument::query()->where('destinataire', 'compta@hotel-des-pins.example')->sole());
    expect([$relance->nature, $relance->document_id])->toBe([NatureEnvoi::Relance, $rectificative->id]);
});

test('le responsable coche « Relancer aussi les particuliers » avec les relances automatiques, et le réglage s’enregistre', function () use ($caseDesParticuliersPar) {
    $societe = $this->societeComplete();

    $caseDesParticuliersPar->call($this, $societe, $societe->responsable);
});

test('la propriétaire coche « Relancer aussi les particuliers » avec les relances automatiques, et le réglage s’enregistre', function () use ($caseDesParticuliersPar) {
    $societe = $this->societeComplete();

    $caseDesParticuliersPar->call($this, $societe, $societe->proprietaire);
});

test('l’administrateur coche « Relancer aussi les particuliers » avec les relances automatiques, et le réglage s’enregistre', function () use ($caseDesParticuliersPar) {
    $societe = $this->societeComplete();

    $caseDesParticuliersPar->call($this, $societe, $societe->administrateur);
});

test('le vendeur à qui « Régler les relances » est cochée coche « Relancer aussi les particuliers », et le réglage s’enregistre', function () use ($caseDesParticuliersPar) {
    $societe = $this->societeComplete();
    $vendeuse = $this->membre($societe->magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut($societe->societe->type_activite), PermissionRegistry::RELANCES_REGLER], ['name' => 'Valérie Roux']);
    app(TenantContext::class)->clear();

    $caseDesParticuliersPar->call($this, $societe, $vendeuse);
});

test('le vendeur relance à la main le ticket échu d’un client particulier qui a une adresse e-mail', function () {
    $societe = $this->societeComplete();
    $ticket = app(TenantContext::class)->runAs($societe->magasin, function () use ($societe): Facture {
        $client = Client::factory()->create(['nom' => 'Marie Leroy', 'email' => 'marie.leroy@exemple.fr', 'delai_paiement_jours' => 0]);

        return app(EmettreFactureAction::class)->handle($societe->vendeur, app(CreerFactureAction::class)->handle($societe->vendeur, $client, [
            ['produit_id' => Produit::query()->where('reference', 'SAVON')->sole()->id, 'quantite' => 1],
        ]));
    });
    $this->travelTo(self::aParis('2026-03-20 10:00:00'));

    $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$ticket->getRouteKey())
        ->voir((string) $ticket->numero)
        ->cliquerSur('Relancer')
        ->remplir('Destinataire', 'marie.leroy@exemple.fr')
        ->soumettre('Relancer')
        ->voir('Envoi à marie.leroy@exemple.fr demandé');

    $relance = app(TenantContext::class)->runAs($societe->magasin, fn (): EnvoiDocument => EnvoiDocument::query()->where('destinataire', 'marie.leroy@exemple.fr')->sole());
    expect([$relance->nature, $relance->niveau_relance, $relance->document_id])->toBe([NatureEnvoi::Relance, 1, $ticket->id]);
});
