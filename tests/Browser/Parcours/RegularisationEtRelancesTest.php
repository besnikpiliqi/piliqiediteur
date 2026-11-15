<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use Database\Factories\SocieteFactory;
use Tests\Browser\Support\Navigation;

/*
 * Lot REGUL. La régularisation d'une facture après la correction du SIREN
 * de la société : sur la fiche de la facture désignée, « Émettre la
 * facture rectificative » ne s'offre qu'après « Faire l'avoir de
 * régularisation » (T68.1), et la rectificative reçoit le crédit de
 * l'avoir. Les relances (décision de l'éditeur du 14/11/2026) : « Relancer
 * aussi les particuliers » dans Paramètres > Relances, et « Relancer », à
 * la main, sur le ticket échu d'un particulier.
 */

test('le responsable fait l’avoir de régularisation d’une facture désignée, puis seulement émet sa facture rectificative, réglée du crédit de l’avoir', function () {
    $societe = $this->societeComplete();
    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->whereNotNull('numero')->whereRelation('client', 'nom', 'Hôtel des Pins')->sole());
    $this->travelTo(self::aParis('2026-04-15 10:00:00'));
    $sirenCorrect = SocieteFactory::sirenFictif();
    $this->corrigerLeSirenParLEditeur($societe->societe, $sirenCorrect, [
        'PORT' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0001'),
        'GARE' => IdentifiantsInsee::avecSaCle($sirenCorrect.'0002'),
    ], IdentifiantsInsee::numeroTvaPropose($sirenCorrect));
    $this->travelTo(self::aParis('2026-04-15 10:05:00'));
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

test('le responsable coche « Relancer aussi les particuliers » avec les relances automatiques, et le réglage s’enregistre', function () {
    $societe = $this->societeComplete();

    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/parametres-relances')
        ->voir('Relancer aussi les particuliers');
    $onglet->cliquer('label:has-text('.Navigation::texte('Relancer automatiquement les factures impayées').') >> button[role="switch"]')
        ->cliquer('label:has-text('.Navigation::texte('Relancer aussi les particuliers').') >> button[role="switch"]')
        ->soumettre('Enregistrer')
        ->voir('Relances automatiques enregistrées');

    expect(Magasin::query()->whereKey($societe->magasin->id)->sole()->only(['relances_automatiques', 'relancer_aussi_les_particuliers']))
        ->toBe(['relances_automatiques' => true, 'relancer_aussi_les_particuliers' => true]);
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
