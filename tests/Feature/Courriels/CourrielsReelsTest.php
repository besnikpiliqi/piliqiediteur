<?php

namespace Tests\Feature\Courriels;

use App\Domain\Cabinets\Mail\DemandeDeRattachementMail;
use App\Domain\Cabinets\Mail\InvitationACreerUnEspaceMail;
use App\Domain\Cabinets\Mail\InvitationDuCabinetMail;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EncaisserEnPlusieursModesAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\ReponseAuDevis;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Facturation\Models\ReponseDuDevis;
use App\Domain\Facturation\Notifications\ReponseDuClientAuDevis;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Mail\AdresseChangeeMail;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
use App\Domain\Tenancy\Mail\CompteExistantMail;
use App\Domain\Tenancy\Mail\ConfirmationNouvelleAdresseMail;
use App\Domain\Tenancy\Mail\DemandeInscriptionMail;
use App\Domain\Tenancy\Mail\InscriptionCloseMail;
use App\Domain\Tenancy\Mail\TransmissionDeLaProprieteMail;
use App\Domain\Tenancy\Notifications\AvisDeLAntivirus;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Notifications\LienPourChoisirSonMotDePasse;
use App\Integrations\Courriel\CauseDEchecDEnvoi;
use App\Integrations\Courriel\CourrielDEssai;
use App\Integrations\Courriel\RefusDeMailtrap;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Les e-mails de PILIQI vérifiés sur un vrai serveur de test (décision de
 * l'éditeur du 01/11/2026) : chacun part par le transport « mailtrap »
 * dans la boîte de test de l'éditeur, puis l'API de la boîte dit qu'il y
 * est arrivé avec son objet, son expéditeur, son destinataire et ses
 * pièces jointes ; les messages de ce groupe sont ensuite effacés de la
 * boîte, et eux seuls (ceux de son destinataire).
 *
 * Groupe « courriels-reels », exclu de la suite et de la CI (phpunit.xml),
 * lancé à la demande, à la fin d'un lot qui ajoute ou change un e-mail et
 * avant la mise en production (voir le README) ; il demande
 * MAILTRAP_API_TOKEN et MAILTRAP_INBOX_ID, et se saute en disant ce qui
 * manque. Il compte sur la boîte : une vingtaine d'e-mails par passage,
 * en une minute et demie environ (la boîte en prend cinq par dix secondes).
 * Chaque e-mail refusé fait échouer le test, avis compris : ceux-ci
 * passent ici par le canal « mail » de Laravel, qui laisse remonter le
 * refus, et non par CanalDesCourriels, qui le tait pour ne pas casser le
 * geste qui fait naître l'avis ; et le nombre d'e-mails arrivés est celui
 * qu'attendent la liste COURRIELS et les documents envoyés, pas seulement
 * celui des e-mails partis.
 * L'API de lecture, d'après sa documentation officielle (Email Sandbox
 * API 2.0.0, « Get messages », « Get attachments » et « Delete
 * message », https://docs.mailtrap.io/developers/email-sandbox/messages) :
 * GET https://mailtrap.io/api/sandboxes/{sandbox_id}/messages?search=…,
 * GET …/messages/{message_id}/attachments et DELETE …/messages/{message_id},
 * le jeton dans l'en-tête Api-Token.
 */
#[Group('courriels-reels')]
class CourrielsReelsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque e-mail que PILIQI envoie : les e-mails (Mailable) et les
     * notifications qui partent aussi par e-mail ; ListeDesCourrielsTest
     * exige que tout e-mail du code y figure. Les devis, factures, tickets,
     * avoirs, reçus de paiement et relances partent tous dans DocumentMail.
     *
     * @var list<class-string>
     */
    public const COURRIELS = [
        DemandeInscriptionMail::class,
        CompteExistantMail::class,
        ConfirmationNouvelleAdresseMail::class,
        AdresseChangeeMail::class,
        TransmissionDeLaProprieteMail::class,
        InscriptionCloseMail::class,
        AlerteEditeurMail::class,
        InvitationDuCabinetMail::class,
        DemandeDeRattachementMail::class,
        InvitationACreerUnEspaceMail::class,
        CourrielDEssai::class,
        DocumentMail::class,
        AvisDeLEquipe::class,
        AvisDuCabinet::class,
        ReponseDuClientAuDevis::class,
        AvisDeLAntivirus::class,
        LienPourChoisirSonMotDePasse::class,
    ];

    private const API = 'https://mailtrap.io/api/sandboxes/';

    /**
     * Le seul destinataire de ce groupe : la boîte ne garde que lui à
     * effacer.
     */
    private const DESTINATAIRE = 'courriels-reels@piliqi-essai.test';

    /**
     * Chaque e-mail parti pendant le test, tel que PILIQI l'a remis au
     * transport.
     *
     * @var list<Email>
     */
    private array $envoyes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $jeton = trim((string) config('services.mailtrap.jeton'));
        $boite = trim((string) config('services.mailtrap.boite'));

        match (true) {
            $jeton === '' && $boite === '' => $this->markTestSkipped('Groupe courriels-reels : MAILTRAP_API_TOKEN et MAILTRAP_INBOX_ID manquent.'),
            $jeton === '' => $this->markTestSkipped('Groupe courriels-reels : MAILTRAP_API_TOKEN manque.'),
            $boite === '' => $this->markTestSkipped('Groupe courriels-reels : MAILTRAP_INBOX_ID manque.'),
            default => null,
        };

        Http::allowStrayRequests();
        config(['mail.default' => 'mailtrap', 'mail.mailers.mailtrap.timeout' => 30, 'queue.default' => 'sync']);
        $this->app->bind(MailChannel::class, fn (Application $app): MailChannel => new MailChannel($app->make(MailFactory::class), $app->make(Markdown::class)));
        $this->app->make(ChannelManager::class)->forgetDrivers();
        $this->effacerLesMessagesDuGroupe();
        Event::listen(MessageSent::class, function (MessageSent $envoi): void {
            $this->envoyes[] = $envoi->message;
        });
    }

    /**
     * Chaque e-mail de PILIQI arrive dans la boîte de test, avec son objet,
     * son expéditeur, son destinataire et ses pièces jointes.
     */
    public function test_chaque_e_mail_de_piliqi_arrive_dans_la_boite_de_test(): void
    {
        $this->travelTo(self::aParis('2026-11-12 10:00:00'));
        $this->envoyerLesEMails();
        $this->envoyerLesNotifications();
        $attendus = count(self::COURRIELS) - 1 + $this->envoyerLesDocuments();

        $recus = $this->messagesRecus($attendus);

        try {
            $this->assertCount($attendus, $this->envoyes, 'PILIQI n\'a pas remis au transport chaque e-mail attendu.');
            $this->assertCount($attendus, $recus, 'Des e-mails attendus ne sont pas arrivés dans la boîte de test.');

            foreach ($this->envoyes as $envoye) {
                $this->assertArriveTelQuIlEstParti($envoye, $recus);
            }
        } finally {
            $this->effacerLesMessagesDuGroupe();
        }
    }

    /**
     * Les e-mails qui partent sans file d'attente : inscription, adresse de
     * connexion, transmission de la propriété, inscription close, alerte de
     * l'éditeur, invitations et demandes des cabinets, e-mail d'essai.
     */
    private function envoyerLesEMails(): void
    {
        $lien = 'https://piliqi.test/lien/essai';
        $courriels = [
            new DemandeInscriptionMail($lien, now()->addHours(24), TypeDActivite::Commerce),
            new CompteExistantMail('https://piliqi.test/app/login', 'https://piliqi.test/app/password-reset/request'),
            new ConfirmationNouvelleAdresseMail($lien, '13/11/2026 à 10:00'),
            new AdresseChangeeMail('Claire Martin', '12/11/2026 à 10:00'),
            new TransmissionDeLaProprieteMail($lien, 'Boutique Essai', 'Claire Martin', '13/11/2026 à 10:00'),
            new InscriptionCloseMail('Boutique Essai', 'sans code au bout de 30 jours', 'https://piliqi.test/app/register', 'Ses magasins restent consultables, en lecture seule.', null),
            new AlerteEditeurMail('Essai des e-mails de PILIQI', ['Une ligne d\'alerte d\'essai.']),
            new InvitationDuCabinetMail($lien, 'Cabinet Essai', 'Lyon', '000000018', '19/11/2026 à 10:00'),
            new DemandeDeRattachementMail($lien, 'Cabinet Essai', 'Lyon', '000000026', '19/11/2026 à 10:00'),
            new InvitationACreerUnEspaceMail('https://piliqi.test/app/register', 'Boutique Essai'),
            new CourrielDEssai('mailtrap'),
        ];

        foreach ($courriels as $courriel) {
            $this->envoyer(fn () => Mail::to(self::DESTINATAIRE)->send($courriel));
        }
    }

    /**
     * Les avis qui partent aussi par e-mail, chacun à un compte dont c'est
     * l'adresse, le lien « Mot de passe oublié » et l'avis de la réponse
     * d'un client à un devis.
     */
    private function envoyerLesNotifications(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Société des avis']);
        $compte = User::factory()->create(['email' => self::DESTINATAIRE]);
        $avis = [
            new AvisDeLEquipe($magasin->societe, 'Essai de l\'avis de l\'équipe', 'Un accès a été redonné.', parCourriel: true),
            new AvisDuCabinet($magasin->societe, 'Essai de l\'avis du cabinet', 'Une demande de lien attend votre réponse.', parCourriel: true),
            new AvisDeLAntivirus($magasin->societe, 'Essai de l\'avis de l\'antivirus', 'Le fichier « essai.pdf » a été refusé.'),
            new LienPourChoisirSonMotDePasse('https://piliqi.test/app/password-reset/reset?token=essai', 60),
            new ReponseDuClientAuDevis(
                $magasin,
                (new Devis)->forceFill(['id' => 1, 'numero' => 'DEV-ESSAI-2026-000001']),
                (new ReponseDuDevis)->forceFill(['reponse' => ReponseAuDevis::Acceptation, 'signataire' => 'Claire Martin', 'created_at' => now()]),
                [['produit' => 'Câble électrique tressé', 'demande' => 3, 'disponible' => 1]],
            ),
        ];

        foreach ($avis as $notification) {
            $this->envoyer(fn () => $compte->notify($notification));
        }
    }

    /**
     * Une facture à un professionnel, un ticket, un devis, un avoir, un
     * reçu de paiement et une relance, chacun avec son PDF, par
     * EnvoyerDocumentAction et sa tâche d'envoi.
     *
     * @return int le nombre de documents envoyés, chacun dans un DocumentMail
     */
    private function envoyerLesDocuments(): int
    {
        $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Boutique Essai', 'code' => 'ESSAI']));
        $professionnel = Client::factory()->professionnel()->create(['email' => self::DESTINATAIRE, 'delai_paiement_jours' => 0]);
        $particulier = Client::factory()->create(['email' => self::DESTINATAIRE]);
        $produit = Produit::factory()->create(['nom' => 'Câble électrique tressé', 'prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
        $auteur = self::auteur();
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
        $echue = $this->facture($auteur, $professionnel, $produit);
        $this->travelTo(self::aParis('2026-11-12 10:00:00'));
        $facture = $this->facture($auteur, $professionnel, $produit);
        $ticket = $this->facture($auteur, $particulier, $produit);
        app(EncaisserEnPlusieursModesAction::class)->handle($auteur, $facture, [new PartDeReglement(ModePaiement::Virement, 3000)], [], '2026-11-12', (string) Str::uuid());
        $devis = app(CreerDevisAction::class)->handle($auteur, $particulier, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $avoir = app(EmettreAvoirAction::class)->handle($auteur, app(CreerAvoirAction::class)->handle($auteur, $particulier, [['ligne_facture_id' => $ticket->lignes->first()->id, 'quantite' => 1]]));
        $envoi = app(EnvoyerDocumentAction::class);

        $documents = [$facture, $ticket, $devis, $avoir, RecuPaiement::query()->sole()];

        foreach ($documents as $document) {
            $this->envoyer(fn () => $envoi->handle($auteur, $document, self::DESTINATAIRE, 'Message d\'essai des e-mails de PILIQI.', (string) Str::uuid()));
        }
        $this->envoyer(fn () => $envoi->relancer($auteur, $echue, self::DESTINATAIRE, (string) Str::uuid()));

        $this->assertSame(count($documents) + 1, EnvoiDocument::query()->where('statut', StatutEnvoi::Envoye)->count(), 'Un document n\'est pas parti.');

        return count($documents) + 1;
    }

    private function facture(User $auteur, Client $client, Produit $produit): Facture
    {
        return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    /**
     * Un envoi, puis une pause de deux secondes et demie : la boîte de test
     * refuse plus de cinq e-mails en dix secondes (« Too many emails per
     * second »). Un e-mail refusé pour cette limite repart une fois, dix
     * secondes plus tard ; tout autre refus fait échouer le test.
     *
     * @param  Closure(): mixed  $envoi
     */
    private function envoyer(Closure $envoi): void
    {
        if (! $this->partir($envoi)) {
            sleep(10);
            $this->assertTrue($this->partir($envoi), 'La boîte de test limite encore les envois dix secondes plus tard.');
        }

        usleep(2_500_000);
    }

    /**
     * L'e-mail part (vrai), ou la boîte de test le refuse pour sa limite
     * d'envois (faux) : un e-mail ou un avis lève alors RefusDeMailtrap ;
     * un document, dont la tâche d'envoi tourne pendant le geste
     * (QUEUE_CONNECTION=sync), passe en échec avec cette cause, et repart
     * par un nouvel envoi. Tout autre refus fait échouer le test.
     *
     * @param  Closure(): mixed  $envoi
     */
    private function partir(Closure $envoi): bool
    {
        try {
            $resultat = $envoi();
        } catch (RefusDeMailtrap $refus) {
            if ($refus->cause !== CauseDEchecDEnvoi::ServeurOccupe) {
                throw $refus;
            }

            return false;
        }

        if (! $resultat instanceof EnvoiDocument) {
            return true;
        }

        $resultat->refresh();

        if ($resultat->statut === StatutEnvoi::Echec && $resultat->derniere_erreur === CauseDEchecDEnvoi::ServeurOccupe->libelle()) {
            return false;
        }

        $this->assertSame(StatutEnvoi::Envoye, $resultat->statut, "Un document n'est pas parti : {$resultat->erreur}");

        return true;
    }

    /**
     * L'e-mail est arrivé tel que PILIQI l'a remis au transport : même
     * objet, même expéditeur, même destinataire, mêmes pièces jointes (nom
     * et type).
     *
     * @param  list<array<string, mixed>>  $recus
     */
    private function assertArriveTelQuIlEstParti(Email $envoye, array $recus): void
    {
        $objet = (string) $envoye->getSubject();
        $recu = collect($recus)->first(fn (array $message): bool => $message['subject'] === $objet);
        $this->assertNotNull($recu, "« {$objet} » n'est pas arrivé dans la boîte de test.");
        $expediteur = $envoye->getFrom()[0];
        $this->assertSame([$expediteur->getAddress(), $expediteur->getName()], [$recu['from_email'], $recu['from_name']], "Expéditeur de « {$objet} »");
        $this->assertSame(array_map(fn (Address $adresse): string => $adresse->getAddress(), $envoye->getTo()), [$recu['to_email']], "Destinataire de « {$objet} »");

        $attendues = array_map(fn ($piece): array => [(string) $piece->getFilename(), "{$piece->getMediaType()}/{$piece->getMediaSubtype()}"], $envoye->getAttachments());
        $arrivees = array_map(fn (array $piece): array => [(string) $piece['filename'], Str::before((string) $piece['content_type'], ';')], $this->api()->get(self::API.$this->boite()."/messages/{$recu['id']}/attachments")->throw()->json());
        sort($attendues);
        sort($arrivees);
        $this->assertSame($attendues, $arrivees, "Pièces jointes de « {$objet} »");
    }

    /**
     * Les messages du groupe arrivés dans la boîte, attendus jusqu'à ce
     * qu'il y en ait autant que d'e-mails partis, une minute et demie au
     * plus.
     *
     * @return list<array<string, mixed>>
     */
    private function messagesRecus(int $attendus): array
    {
        $limite = hrtime(true) + 90 * 1_000_000_000;

        do {
            $recus = $this->messagesDuGroupe();

            if (count($recus) >= $attendus) {
                return $recus;
            }

            usleep(3_000_000);
        } while (hrtime(true) < $limite);

        return $recus;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messagesDuGroupe(): array
    {
        $messages = $this->api()->get(self::API.$this->boite().'/messages', ['search' => self::DESTINATAIRE])->throw()->json();

        return array_values(array_filter(is_array($messages) ? $messages : [], fn (mixed $message): bool => is_array($message) && ($message['to_email'] ?? null) === self::DESTINATAIRE));
    }

    private function effacerLesMessagesDuGroupe(): void
    {
        foreach ($this->messagesDuGroupe() as $message) {
            $this->api()->delete(self::API.$this->boite()."/messages/{$message['id']}")->throw();
        }
    }

    private function api(): PendingRequest
    {
        return Http::withHeaders(['Api-Token' => (string) config('services.mailtrap.jeton'), 'User-Agent' => 'PILIQI'])->acceptJson()->timeout(30);
    }

    private function boite(): string
    {
        return rawurlencode((string) config('services.mailtrap.boite'));
    }
}
