<?php

use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
 * « Envoyer par e-mail » sur la fiche d'une facture (lot ML, constat de
 * l'éditeur du 02/11/2026) : l'écran dit que l'envoi est demandé et qu'il
 * part dans quelques secondes, et la section « Envois » en montre le
 * résultat sans rechargement ; un serveur d'envoi qui ne répond pas y dit
 * sa cause et l'heure de la tentative suivante, puis le résultat s'affiche
 * seul dès qu'il est connu. La facture est celle de l'Hôtel des Pins,
 * émise par le vendeur (voir TestCaseDuNavigateur::remplirLeMagasin()).
 */

/**
 * La facture émise de l'Hôtel des Pins.
 */
function factureDeLHotelDesPins(object $societe): Facture
{
    return app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->whereNotNull('numero')->whereHas('client', fn ($client) => $client->where('nom', 'Hôtel des Pins'))->firstOrFail());
}

test('chaque compte qui envoie la facture lit que l’envoi est demandé, puis son résultat dans « Envois »', function (string $compte) {
    $societe = $this->societeComplete();
    $facture = factureDeLHotelDesPins($societe);

    $this->seConnecter($societe->{$compte})->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->cliquerSur('Envoyer par e-mail')
        ->remplir('Destinataire', 'compta@hotel-des-pins.fr')
        ->soumettre('Envoyer')
        ->voir('Envoi à compta@hotel-des-pins.fr demandé : il part dans quelques secondes ; son résultat s\'affiche dans « Envois »')
        ->voir('compta@hotel-des-pins.fr');

    $envoi = app(TenantContext::class)->runAs($societe->magasin, fn (): EnvoiDocument => EnvoiDocument::query()->where('destinataire', 'compta@hotel-des-pins.fr')->sole());
    expect($envoi->statut)->toBe(StatutEnvoi::Envoye);
})->with([
    'le propriétaire' => ['proprietaire'],
    'l’administrateur sans la vente' => ['administrateur'],
    'l’administrateur autorisé à vendre' => ['administrateurVendeur'],
    'le responsable' => ['responsable'],
]);

test('un serveur d’envoi muet se lit dans « Envois » avec l’heure de la tentative suivante, puis le résultat s’affiche sans rechargement', function () {
    Mail::extend('en-panne', fn (): AbstractTransport => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection to "sandbox.smtp.mailtrap.io:2525" timed out.');
        }

        public function __toString(): string
        {
            return 'en-panne';
        }
    });
    config(['mail.mailers.en-panne' => ['transport' => 'en-panne'], 'mail.default' => 'en-panne']);
    $societe = $this->societeComplete();
    $facture = factureDeLHotelDesPins($societe);

    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->id)
        ->cliquerSur('Envoyer par e-mail')
        ->remplir('Destinataire', 'compta@hotel-des-pins.fr')
        ->soumettre('Envoyer')
        ->voir('Envoi à compta@hotel-des-pins.fr demandé');

    $envoi = app(TenantContext::class)->runAs($societe->magasin, fn (): EnvoiDocument => EnvoiDocument::query()->where('destinataire', 'compta@hotel-des-pins.fr')->sole());
    expect([$envoi->statut, $envoi->tentatives, $envoi->derniere_erreur])->toBe([StatutEnvoi::EnAttente, 1, 'le serveur d\'envoi ne répond pas']);
    $onglet->voir('Le serveur d\'envoi ne répond pas, nouvelle tentative vers '.$societe->magasin->calendrier()->afficher($envoi->prochaine_tentative_le, 'H:i').'.');

    app(TenantContext::class)->runAs($societe->magasin, fn () => $envoi->forceFill(['statut' => StatutEnvoi::Envoye, 'envoye_le' => now(), 'derniere_erreur' => null, 'prochaine_tentative_le' => null])->save());

    $onglet->attendreQue('!document.body.innerText.includes("nouvelle tentative vers")', 'la section « Envois » ne s\'est pas relue seule');
});
