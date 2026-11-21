<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Courriel\CauseDEchecDEnvoi;
use App\Integrations\Courriel\RefusDeMailtrap;
use App\Integrations\Courriel\TransportMailtrap;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le transport « mailtrap » (voir TransportMailtrap), sur des réponses
 * simulées de l'API de la boîte de test (Http::fake) : aucune requête ne
 * part. Le jeton et la boîte sont fictifs, et ne figurent jamais dans
 * une exception.
 */
class TransportMailtrapTest extends TestCase
{
    private const JETON = 'jeton-fictif-de-mailtrap-0123456789';

    private const BOITE = '4242001';

    private const ADRESSE = TransportMailtrap::ADRESSE_DE_L_API.self::BOITE;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'mailtrap',
            'mail.from' => ['address' => 'piliqi@exemple.test', 'name' => 'PILIQI'],
            'services.mailtrap.jeton' => self::JETON,
            'services.mailtrap.boite' => self::BOITE,
        ]);
    }

    /**
     * Le message part à la boîte de test, le jeton dans l'en-tête
     * Api-Token : expéditeur, destinataires, copies, adresse de réponse,
     * objet, texte, HTML et pièce jointe en base64 avec son nom et son
     * type, comme le décrit la documentation de l'API.
     */
    public function test_le_message_part_a_la_boite_de_test_avec_ses_adresses_son_contenu_et_sa_piece_jointe(): void
    {
        Http::fake([self::ADRESSE => Http::response(['success' => true, 'message_ids' => ['identifiant-fictif']])]);

        Mail::to(new Address('client@exemple.test', 'Client Fictif'))
            ->cc('comptable@exemple.test')
            ->bcc('archive@exemple.test')
            ->send(self::messageDEssai());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $requete): bool {
            $this->assertSame('POST', $requete->method());
            $this->assertSame(self::ADRESSE, $requete->url());
            $this->assertTrue($requete->hasHeader('Api-Token', self::JETON));
            $corps = $requete->data();
            $this->assertSame(['email' => 'boutique@exemple.test', 'name' => 'Boutique Fictive'], $corps['from']);
            $this->assertSame([['email' => 'client@exemple.test', 'name' => 'Client Fictif']], $corps['to']);
            $this->assertSame([['email' => 'comptable@exemple.test']], $corps['cc']);
            $this->assertSame([['email' => 'archive@exemple.test']], $corps['bcc']);
            $this->assertSame(['email' => 'reponses@exemple.test', 'name' => 'Boutique Fictive'], $corps['reply_to']);
            $this->assertSame('Facture FAC-ESSAI-2026-000001 — Boutique Fictive', $corps['subject']);
            $this->assertStringContainsString('Veuillez trouver ci-joint notre facture.', $corps['html']);
            $this->assertStringContainsString('Veuillez trouver ci-joint notre facture.', $corps['text']);
            $this->assertStringNotContainsString('<p>', $corps['text']);
            $this->assertSame([[
                'content' => base64_encode("%PDF-1.4\ncontenu fictif"),
                'filename' => 'facture-FAC-ESSAI-2026-000001.pdf',
                'type' => 'application/pdf',
                'disposition' => 'attachment',
            ]], $corps['attachments']);

            return true;
        });
    }

    /**
     * Chaque refus de l'API lève une exception en français, qui dit la
     * cause et son remède, avec le statut HTTP pour code et la cause que
     * lit le classement des échecs, sans jamais montrer le jeton ni le
     * numéro de la boîte, même quand l'API les répète.
     *
     * @param  array<string, mixed>  $reponse
     */
    #[DataProvider('refusDeLApi')]
    public function test_un_refus_de_l_api_leve_une_exception_claire_sans_le_jeton_ni_la_boite(int $statut, array $reponse, string $attendu, CauseDEchecDEnvoi $cause): void
    {
        Http::fake([self::ADRESSE => Http::response($reponse, $statut)]);

        try {
            Mail::to('client@exemple.test')->send(self::messageDEssai());
            $this->fail('Le refus de l\'API devait lever une exception.');
        } catch (RefusDeMailtrap $refus) {
            $this->assertStringContainsString($attendu, $refus->getMessage());
            $this->assertSame($statut, $refus->getCode());
            $this->assertSame($cause, CauseDEchecDEnvoi::de($refus));
            $this->assertSecretsAbsents($refus);
        }
    }

    /**
     * @return array<string, array{int, array<string, mixed>, string, CauseDEchecDEnvoi}>
     */
    public static function refusDeLApi(): array
    {
        return [
            'jeton refusé' => [401, ['success' => false, 'errors' => ['Unauthorized: '.self::JETON]], 'Mailtrap a refusé le jeton d\'API (Mailtrap répond : « Unauthorized: [masqué] ») : vérifiez MAILTRAP_API_TOKEN', CauseDEchecDEnvoi::Identifiants],
            'boîte inconnue' => [404, ['error' => 'Inbox '.self::BOITE.' not found'], 'Mailtrap ne connaît pas cette boîte de test (Mailtrap répond : « Inbox [masqué] not found ») : vérifiez MAILTRAP_INBOX_ID', CauseDEchecDEnvoi::Configuration],
            'message refusé' => [400, ['success' => false, 'errors' => ['subject is missing', 'text is missing']], 'Mailtrap a refusé l\'e-mail (HTTP 400) (Mailtrap répond : « subject is missing ; text is missing »)', CauseDEchecDEnvoi::Inattendue],
            'limite atteinte' => [429, ['success' => false, 'errors' => ['Too many requests']], 'Mailtrap limite les envois (Mailtrap répond : « Too many requests »)', CauseDEchecDEnvoi::ServeurOccupe],
            'panne' => [500, [], 'Mailtrap est indisponible (HTTP 500) : réessayez plus tard.', CauseDEchecDEnvoi::Indisponible],
            'réponse sans succès' => [200, ['success' => false], 'Mailtrap a refusé l\'e-mail (HTTP 200).', CauseDEchecDEnvoi::Inattendue],
        ];
    }

    /**
     * Une API qui ne répond pas fait échouer l'envoi dans le délai
     * MAIL_TIMEOUT, en le disant, sans l'adresse de la boîte que porte
     * l'erreur du client HTTP.
     */
    public function test_une_api_injoignable_fait_echouer_l_envoi_sans_l_adresse_de_la_boite(): void
    {
        Http::fake([self::ADRESSE => Http::failedConnection()]);

        try {
            Mail::to('client@exemple.test')->send(self::messageDEssai());
            $this->fail('Une API injoignable devait lever une exception.');
        } catch (RefusDeMailtrap $refus) {
            $this->assertSame(CauseDEchecDEnvoi::Indisponible, CauseDEchecDEnvoi::de($refus));
            $this->assertSame('La boîte de test de Mailtrap n\'a pas répondu dans le délai de 10 secondes (MAIL_TIMEOUT), ou ne se joint pas : vérifiez l\'accès à sandbox.api.mailtrap.io, puis réessayez. L\'e-mail n\'est pas parti.', $refus->getMessage());
            $this->assertSecretsAbsents($refus);
        }
    }

    /**
     * Sans jeton ou sans boîte, rien ne part et l'exception dit quelle
     * variable manque.
     */
    public function test_sans_jeton_ni_boite_rien_ne_part_et_l_exception_dit_ce_qui_manque(): void
    {
        Http::fake();
        config(['services.mailtrap.jeton' => '', 'services.mailtrap.boite' => null]);
        Mail::forgetMailers();

        $this->expectException(RefusDeMailtrap::class);
        $this->expectExceptionMessage('Le transport « mailtrap » demande MAILTRAP_API_TOKEN et MAILTRAP_INBOX_ID dans le fichier .env (voir config/services.php) : l\'e-mail n\'est pas parti.');

        try {
            Mail::to('client@exemple.test')->send(self::messageDEssai());
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * Un e-mail comme ceux de PILIQI : expéditeur au nom du magasin,
     * réponse au magasin, HTML et texte, PDF joint.
     */
    private static function messageDEssai(): Mailable
    {
        return new class extends Mailable
        {
            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new Address('boutique@exemple.test', 'Boutique Fictive'),
                    replyTo: [new Address('reponses@exemple.test', 'Boutique Fictive')],
                    subject: 'Facture FAC-ESSAI-2026-000001 — Boutique Fictive',
                );
            }

            public function content(): Content
            {
                return new Content(markdown: 'mail.alerte-editeur', with: ['objet' => 'Essai', 'lignes' => ['Veuillez trouver ci-joint notre facture.']]);
            }

            /**
             * @return array<int, Attachment>
             */
            public function attachments(): array
            {
                return [Attachment::fromData(fn (): string => "%PDF-1.4\ncontenu fictif", 'facture-FAC-ESSAI-2026-000001.pdf')->withMime('application/pdf')];
            }
        };
    }

    /**
     * Le message et chaque exception chaînée.
     */
    private function assertSecretsAbsents(RefusDeMailtrap $refus): void
    {
        for ($exception = $refus; $exception !== null; $exception = $exception->getPrevious()) {
            $this->assertStringNotContainsString(self::JETON, $exception->getMessage());
            $this->assertStringNotContainsString(self::BOITE, $exception->getMessage());
        }
    }
}
