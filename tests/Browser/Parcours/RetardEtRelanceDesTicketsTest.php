<?php

use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Tests\Browser\Support\Navigation;

/*
 * Lot DEMO-V. « En retard » dans la colonne « À faire » de la liste des
 * factures : seulement tant qu'un montant reste dû après l'échéance (la
 * facture de l'Hôtel des Pins, émise le 10 mars, tombe le 9 avril ; le
 * ticket du client comptoir, réglé à la caisse le 9 mars, le 8 avril).
 * « Relancer » : jamais sur un ticket du client comptoir, anonyme, quand
 * il s'offre sur la facture échue d'un client nommé.
 */

/**
 * Le texte de la rangée de la liste qui porte ce numéro.
 */
function rangeeDe(Navigation $onglet, string $numero): string
{
    return $onglet->element('.fi-ta-row:has-text('.Navigation::texte($numero).')')->innerText();
}

/**
 * Un virement reçu aujourd'hui de ce montant, en centimes, imputé sur
 * cette facture, par ce compte.
 */
function virementSur(object $societe, User $auteur, Facture $facture, int $centimes): void
{
    app(TenantContext::class)->runAs($societe->magasin, fn () => app(EnregistrerPaiementAction::class)->handle($auteur, $facture->client, [
        'mode_paiement' => ModePaiement::Virement->value,
        'montant' => app(CalculateurLigne::class)->enDecimal($centimes),
        'reference' => null,
        'recu_le' => Calendrier::deParis()->aujourdhui(),
    ], [['facture_id' => $facture->id, 'montant' => app(CalculateurLigne::class)->enDecimal($centimes)]]));
}

test('le responsable lit « En retard » sur la facture échue tant qu’il en reste un montant dû, puis plus une fois réglée, ni sur le ticket payé à la caisse', function () {
    $societe = $this->societeComplete();
    [$facture, $ticketPaye] = app(TenantContext::class)->runAs($societe->magasin, fn (): array => [
        Facture::query()->whereNotNull('numero')->whereRelation('client', 'nom', 'Hôtel des Pins')->sole(),
        Facture::query()->whereNotNull('numero')->whereRelation('client', 'est_comptoir', true)->where('statut', StatutFacture::Payee)->firstOrFail(),
    ]);
    $this->travelTo(self::aParis('2026-04-20 10:00:00'));
    $resteDu = app(TenantContext::class)->runAs($societe->magasin, fn (): int => $facture->montantRestantEnCentimes());
    virementSur($societe, $societe->responsable, $facture, 100);

    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures')
        ->voir((string) $facture->numero);

    expect(rangeeDe($onglet, (string) $facture->numero))->toContain('En retard')
        ->and(rangeeDe($onglet, (string) $ticketPaye->numero))->not->toContain('En retard');

    virementSur($societe, $societe->responsable, $facture, $resteDu - 100);
    $onglet->aller('/app/'.$societe->magasin->uuid.'/factures')->voir((string) $facture->numero);

    expect(rangeeDe($onglet, (string) $facture->numero))->not->toContain('En retard')
        ->and(rangeeDe($onglet, (string) $ticketPaye->numero))->not->toContain('En retard')
        ->and(app(TenantContext::class)->runAs($societe->magasin, fn (): ?StatutFacture => $facture->fresh()?->statut))->toBe(StatutFacture::Payee);
});

test('ni le responsable ni le vendeur ne trouvent « Relancer » sur un ticket du client comptoir échu et resté dû, ni sur la liste ni sur sa fiche, quand ils le trouvent sur la facture échue d’un client nommé', function () {
    $societe = $this->societeComplete();
    [$ticket, $facture] = app(TenantContext::class)->runAs($societe->magasin, fn (): array => [
        app(EmettreFactureAction::class)->handle($societe->vendeur, app(CreerFactureAction::class)->handle($societe->vendeur, Client::query()->where('est_comptoir', true)->sole(), [
            ['produit_id' => Produit::query()->where('reference', 'SAVON')->sole()->id, 'quantite' => 1],
        ])),
        Facture::query()->whereNotNull('numero')->whereRelation('client', 'nom', 'Hôtel des Pins')->sole(),
    ]);
    $this->travelTo(self::aParis('2026-04-20 10:00:00'));
    expect(app(TenantContext::class)->runAs($societe->magasin, fn (): array => [$ticket->fresh()?->estEchueEtImpayee(), $facture->fresh()?->estEchueEtImpayee()]))->toBe([true, true]);
    $relancer = Navigation::bouton('Relancer');

    foreach (['responsable', 'vendeur'] as $compte) {
        $onglet = $this->seConnecter($societe->{$compte})->aller('/app/'.$societe->magasin->uuid.'/factures')
            ->voir((string) $ticket->numero);

        expect($onglet->element('.fi-ta-row:has-text('.Navigation::texte((string) $ticket->numero).') >> '.$relancer)->count())->toBe(0)
            ->and($onglet->element('.fi-ta-row:has-text('.Navigation::texte((string) $facture->numero).') >> '.$relancer)->count())->toBe(1);

        $onglet->aller('/app/'.$societe->magasin->uuid.'/factures/'.$ticket->getRouteKey())
            ->voir((string) $ticket->numero)
            ->voir('Envoyer par e-mail');

        expect($onglet->element($relancer)->count())->toBe(0);

        $onglet->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->getRouteKey())
            ->voir((string) $facture->numero);

        expect($onglet->element($relancer)->count())->toBe(1);
        $onglet->seDeconnecter();
    }
});
