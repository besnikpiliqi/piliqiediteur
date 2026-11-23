<?php

use App\Domain\Facturation\Jobs\SubmitFactureToPdpJob;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Pdp\Enums\StatutTransmissionPdp;

/*
 * Une facture que la plateforme agréée n'a pas reçue après toutes les
 * tentatives automatiques (constat C33 des audits du 07/11/2026) : sa
 * fiche dit « Non transmise : incident technique » et l'explique ; le
 * propriétaire et l'administrateur à qui il a coché « suivre la plateforme
 * agréée » la relancent (décision de l'éditeur, Q-AUD3), et la passerelle
 * factice l'accepte ; un administrateur sans cette case et un responsable
 * du magasin voient l'état sans le bouton.
 */

/**
 * La facture émise de l'Hôtel des Pins (voir
 * TestCaseDuNavigateur::remplirLeMagasin()), dont la transmission a
 * échoué après toutes les tentatives automatiques.
 */
function factureNonTransmise(Magasin $magasin): Facture
{
    return app(TenantContext::class)->runAs($magasin, function (): Facture {
        $facture = Facture::query()->whereNotNull('numero')->whereHas('client', fn ($client) => $client->where('nom', 'Hôtel des Pins'))->orderBy('id')->firstOrFail();
        $facture->transmissionsPdp()->create([
            'direction' => 'outbound',
            'prestataire_pdp' => 'faux',
            'statut' => StatutTransmissionPdp::EchecTechnique,
            'message_erreur' => SubmitFactureToPdpJob::EXPLICATION_DE_L_ECHEC,
            'soumise_le' => now(),
        ]);

        return $facture;
    });
}

test('le propriétaire relance la transmission d’une facture que la plateforme n’a pas reçue, et elle est acceptée', function () {
    $societe = $this->societeComplete();
    $facture = factureNonTransmise($societe->magasin);

    $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->voir('Non transmise : incident technique')
        ->voir('c\'est un incident technique, pas un refus de la facture')
        ->cliquerSur('Relancer la transmission')
        ->voir('La facture repart vers la plateforme agréée sous la même référence de dépôt')
        ->soumettre('Confirmer')
        ->voir('La transmission est relancée.')
        ->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->voir('Acceptée par le PDP')
        ->nePasVoir('Relancer la transmission');

    $transmission = app(TenantContext::class)->runAs($societe->magasin, fn () => $facture->transmissionsPdp()->sole());
    expect($transmission->statut)->toBe(StatutTransmissionPdp::Acceptee);
});

test('le responsable voit qu’une facture n’a pas été transmise, sans pouvoir la relancer', function () {
    $societe = $this->societeComplete();
    $facture = factureNonTransmise($societe->magasin);

    $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->voir('Non transmise : incident technique')
        ->nePasVoir('Relancer la transmission');
});

test('l’administrateur qui suit la plateforme agréée relance la transmission d’une facture que la plateforme n’a pas reçue, et elle est acceptée', function () {
    $societe = $this->societeComplete();
    $facture = factureNonTransmise($societe->magasin);

    $this->seConnecter($societe->administrateur)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->voir('Non transmise : incident technique')
        ->cliquerSur('Relancer la transmission')
        ->voir('La facture repart vers la plateforme agréée sous la même référence de dépôt')
        ->soumettre('Confirmer')
        ->voir('La transmission est relancée.')
        ->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->voir('Acceptée par le PDP')
        ->nePasVoir('Relancer la transmission');

    $transmission = app(TenantContext::class)->runAs($societe->magasin, fn () => $facture->transmissionsPdp()->sole());
    expect($transmission->statut)->toBe(StatutTransmissionPdp::Acceptee);
});

test('l’administrateur sans la case de la plateforme agréée voit qu’une facture n’a pas été transmise, sans pouvoir la relancer', function () {
    $societe = $this->societeComplete();
    $facture = factureNonTransmise($societe->magasin);

    $this->seConnecter($societe->administrateurVendeur)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->voir('Non transmise : incident technique')
        ->nePasVoir('Relancer la transmission');
});
