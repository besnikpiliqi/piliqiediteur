<?php

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Browser\Support\Navigation;

/*
 * « Envoyer par e-mail » et « Relancer », dans la liste comme sur la fiche
 * d'une facture, d'un devis ou d'un avoir (lot ML, constat de l'éditeur du
 * 02/11/2026) : l'écran dit que l'envoi est demandé et qu'il part dans
 * quelques secondes, et la section « Envois » de la fiche en montre le
 * résultat sans rechargement, relue seule tant qu'un envoi attend sa tâche
 * dans une vraie file d'attente (QUEUE_CONNECTION=database le temps du
 * geste, la tâche lancée ensuite comme un travailleur la lance). Un serveur
 * d'envoi qui ne répond pas y dit sa cause et l'heure de la tentative
 * suivante ; avec la file sync, qui ne refait aucune tentative, il fait
 * aussitôt échouer l'envoi. Les documents sont ceux du magasin (voir
 * TestCaseDuNavigateur::remplirLeMagasin()) : la facture de l'Hôtel des
 * Pins et son devis envoyé, émis par le vendeur, et l'avoir du ticket de
 * Jean Dupont, fait par le responsable, que le vendeur ne voit pas.
 */

/**
 * La facture émise de l'Hôtel des Pins.
 */
function factureDeLHotelDesPins(object $societe): Facture
{
    return app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->whereNotNull('numero')->whereHas('client', fn ($client) => $client->where('nom', 'Hôtel des Pins'))->firstOrFail());
}

/**
 * Le document numéroté de cette liste du magasin : la facture de l'Hôtel
 * des Pins, son devis envoyé, l'avoir du ticket de Jean Dupont.
 */
function documentAEnvoyer(object $societe, string $liste): Facture|Devis|Avoir
{
    return match ($liste) {
        'factures' => factureDeLHotelDesPins($societe),
        'devis' => app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => Devis::query()->whereNotNull('numero')->sole()),
        'avoirs' => app(TenantContext::class)->runAs($societe->magasin, fn (): Avoir => Avoir::query()->sole()),
    };
}

/**
 * L'envoi du magasin à cette adresse.
 */
function envoiA(object $societe, string $adresse): EnvoiDocument
{
    return app(TenantContext::class)->runAs($societe->magasin, fn (): EnvoiDocument => EnvoiDocument::query()->where('destinataire', $adresse)->sole());
}

/**
 * Le serveur d'envoi ne répond plus : chaque e-mail lève l'erreur que lève
 * le transport SMTP d'un serveur muet, sans aucune connexion.
 */
function serveurDEnvoiMuet(): void
{
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
}

/**
 * La tâche d'envoi qui attend dans la file de la base de données, lancée
 * comme un travailleur la lance ; la file redevient celle des tests (sync).
 */
function lancerLaTacheDEnvoi(): void
{
    $tache = Queue::connection('database')->pop();
    expect($tache)->toBeInstanceOf(Job::class);
    $tache->fire();
    config(['queue.default' => 'sync']);
}

/**
 * L'expression JavaScript vraie quand la section « Envois » montre l'envoi
 * à cette adresse avec ce statut, sur une même ligne de son tableau.
 */
function envoiAffiche(string $adresse, string $statut): string
{
    return '[...document.querySelectorAll(".piliqi-tableau tbody tr")].some((envoi) => envoi.innerText.includes('.Navigation::texte($adresse).') && envoi.innerText.includes('.Navigation::texte($statut).'))';
}

/**
 * Dans la fenêtre déjà ouverte de « Envoyer par e-mail » ou de « Relancer »
 * : l'adresse saisie, la fenêtre validée, et l'écran qui dit que l'envoi
 * est demandé.
 */
function demanderLEnvoi(Navigation $onglet, string $adresse, string $geste): Navigation
{
    return $onglet->remplir('Destinataire', $adresse)
        ->soumettre($geste === 'Relancer' ? 'Relancer' : 'Envoyer')
        ->voir("Envoi à {$adresse} demandé : il part dans quelques secondes ; son résultat s'affiche dans « Envois »");
}

/**
 * Le geste dans la ligne de ce document de la liste, avec la file des
 * tests (sync) : l'envoi est demandé, et parti.
 */
function envoyerDepuisLaListe(Navigation $onglet, object $societe, string $liste, string $numero, string $geste, string $adresse): void
{
    $onglet->aller('/app/'.$societe->magasin->uuid.'/'.$liste)
        ->cliquer('.fi-ta-row:has-text('.Navigation::texte($numero).') >> '.Navigation::bouton($geste));
    demanderLEnvoi($onglet, $adresse, $geste);

    expect(envoiA($societe, $adresse)->statut)->toBe(StatutEnvoi::Envoye);
}

/**
 * Le geste sur la fiche du document, avec une vraie file d'attente : la
 * section « Envois » montre l'envoi en attente ; sa tâche lancée, elle se
 * relit seule et le montre envoyé, sans rechargement.
 */
function envoyerDepuisLaFiche(Navigation $onglet, object $societe, string $liste, Facture|Devis|Avoir $document, string $geste, string $adresse): void
{
    config(['queue.default' => 'database']);
    $onglet->aller('/app/'.$societe->magasin->uuid.'/'.$liste.'/'.$document->getRouteKey())
        ->voir((string) $document->numero)
        ->cliquerSur($geste);
    demanderLEnvoi($onglet, $adresse, $geste)
        ->attendreQue(envoiAffiche($adresse, 'En attente'), "l'envoi à {$adresse} ne se lit pas en attente dans « Envois »");

    lancerLaTacheDEnvoi();

    $onglet->attendreQue(envoiAffiche($adresse, 'Envoyé'), 'la section « Envois » ne s\'est pas relue seule');
    expect(envoiA($societe, $adresse)->statut)->toBe(StatutEnvoi::Envoye);
}

test('chaque compte qui envoie un document lit, depuis sa liste comme depuis sa fiche, que l’envoi est demandé, puis son résultat dans « Envois », relu sans rechargement', function (string $liste, array $comptes) {
    $societe = $this->societeComplete();
    $document = documentAEnvoyer($societe, $liste);

    foreach ($comptes as $compte) {
        $onglet = $this->seConnecter($societe->{$compte});
        $adresse = strtolower($compte).'.'.$liste;

        envoyerDepuisLaListe($onglet, $societe, $liste, (string) $document->numero, 'Envoyer par e-mail', "{$adresse}.liste@hotel-des-pins.fr");
        envoyerDepuisLaFiche($onglet, $societe, $liste, $document, 'Envoyer par e-mail', "{$adresse}.fiche@hotel-des-pins.fr");

        $onglet->seDeconnecter();
    }
})->with([
    'la facture de l’Hôtel des Pins' => ['factures', ['proprietaire', 'administrateur', 'administrateurVendeur', 'responsable', 'vendeur']],
    'le devis envoyé de l’Hôtel des Pins' => ['devis', ['proprietaire', 'administrateur', 'administrateurVendeur', 'responsable', 'vendeur']],
    'l’avoir du ticket de Jean Dupont, que le vendeur ne voit pas' => ['avoirs', ['proprietaire', 'administrateur', 'administrateurVendeur', 'responsable']],
]);

/*
 * Une facture échue se relance une fois par jour : chaque compte relance,
 * un jour après l'autre, depuis la liste une seconde facture de l'Hôtel
 * des Pins émise par le vendeur, et depuis sa fiche celle du magasin, toutes
 * deux échues le 9 avril.
 */
test('chaque compte qui relance une facture échue lit, depuis la liste comme depuis sa fiche, que la relance est demandée, puis son résultat dans « Envois », relu sans rechargement', function () {
    $societe = $this->societeComplete();
    $fiche = factureDeLHotelDesPins($societe);
    $liste = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => app(EmettreFactureAction::class)->handle($societe->vendeur, app(CreerFactureAction::class)->handle($societe->vendeur, Client::query()->where('nom', 'Hôtel des Pins')->sole(), [
        ['produit_id' => Produit::query()->where('reference', 'SAVON')->sole()->id, 'quantite' => 1],
    ])));

    foreach (['proprietaire', 'administrateur', 'administrateurVendeur', 'responsable', 'vendeur'] as $jour => $compte) {
        $this->travelTo(self::aParis('2026-04-'.(15 + $jour).' 10:00:00'));
        $onglet = $this->seConnecter($societe->{$compte});
        $adresse = strtolower($compte).'.relance';

        envoyerDepuisLaListe($onglet, $societe, 'factures', (string) $liste->numero, 'Relancer', "{$adresse}.liste@hotel-des-pins.fr");
        envoyerDepuisLaFiche($onglet, $societe, 'factures', $fiche, 'Relancer', "{$adresse}.fiche@hotel-des-pins.fr");

        foreach (['liste', 'fiche'] as $ou) {
            $relance = envoiA($societe, "{$adresse}.{$ou}@hotel-des-pins.fr");
            expect([$compte, $ou, $relance->nature, $relance->niveau_relance])->toBe([$compte, $ou, NatureEnvoi::Relance, $jour + 1]);
        }

        $onglet->seDeconnecter();
    }
});

test('un serveur d’envoi muet se lit dans « Envois » avec l’heure de la tentative suivante, puis le résultat s’affiche sans rechargement', function () {
    $societe = $this->societeComplete();
    serveurDEnvoiMuet();
    $facture = factureDeLHotelDesPins($societe);
    config(['queue.default' => 'database']);

    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->getRouteKey())
        ->cliquerSur('Envoyer par e-mail');
    demanderLEnvoi($onglet, 'compta@hotel-des-pins.fr', 'Envoyer par e-mail')
        ->attendreQue(envoiAffiche('compta@hotel-des-pins.fr', 'En attente'), 'l\'envoi ne se lit pas en attente dans « Envois »');

    lancerLaTacheDEnvoi();

    $envoi = envoiA($societe, 'compta@hotel-des-pins.fr');
    expect([$envoi->statut, $envoi->tentatives, $envoi->derniere_erreur])->toBe([StatutEnvoi::EnAttente, 1, 'le serveur d\'envoi ne répond pas']);
    $onglet->voir('Le serveur d\'envoi ne répond pas, nouvelle tentative vers '.$societe->magasin->calendrier()->afficher($envoi->prochaine_tentative_le, 'H:i').'.');

    app(TenantContext::class)->runAs($societe->magasin, fn () => $envoi->forceFill(['statut' => StatutEnvoi::Envoye, 'envoye_le' => now(), 'derniere_erreur' => null, 'prochaine_tentative_le' => null])->save());

    $onglet->attendreQue('!document.body.innerText.includes("nouvelle tentative vers")', 'la section « Envois » ne s\'est pas relue seule');
});

test('avec la file d’attente sync, un serveur d’envoi muet fait échouer l’envoi aussitôt, et « Envois » le dit sans annoncer de nouvelle tentative', function () {
    $societe = $this->societeComplete();
    serveurDEnvoiMuet();
    $facture = factureDeLHotelDesPins($societe);

    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$facture->getRouteKey())
        ->cliquerSur('Envoyer par e-mail');
    demanderLEnvoi($onglet, 'compta@hotel-des-pins.fr', 'Envoyer par e-mail')
        ->attendreQue(envoiAffiche('compta@hotel-des-pins.fr', 'Échec'), 'l\'envoi ne se lit pas en échec dans « Envois »')
        ->voir('L\'e-mail n\'est pas parti : le serveur d\'envoi ne répond pas. Plus tard, renvoyez-le ; si cela se répète, prévenez l\'éditeur.')
        ->nePasVoir('nouvelle tentative vers');

    $envoi = envoiA($societe, 'compta@hotel-des-pins.fr');
    expect([$envoi->statut, $envoi->tentatives, $envoi->prochaine_tentative_le])->toBe([StatutEnvoi::Echec, 1, null]);
});
