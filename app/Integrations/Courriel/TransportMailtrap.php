<?php

namespace App\Integrations\Courriel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use SensitiveParameter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Le transport d'envoi « mailtrap » (MAIL_MAILER=mailtrap, voir
 * config/mail.php) : chaque e-mail part dans la boîte de test de Mailtrap
 * (« Email Sandbox ») par son API HTTPS, avec le client HTTP de Laravel,
 * sans aucun paquet de Mailtrap. Il sert à vérifier les e-mails de PILIQI
 * depuis une machine qui ne joint aucun serveur SMTP (décision de
 * l'éditeur du 01/11/2026) ; jamais en production, où partent les vrais
 * e-mails.
 *
 * L'API, d'après sa documentation officielle (« Send email (including
 * templates) », Email Sandbox API 2.0.0,
 * https://docs.mailtrap.io/developers/email-sandbox/send-test-emails/send-email-including-templates,
 * consultée le 12/11/2026) : POST https://sandbox.api.mailtrap.io/api/send/{sandbox_id},
 * le jeton dans l'en-tête Api-Token, un corps JSON avec from
 * ({email, name}), to, cc et bcc (listes d'adresses), reply_to (une seule
 * adresse), subject, text, html, attachments (content en base64,
 * filename, type, disposition, content_id pour une pièce affichée dans le
 * texte) et headers (les autres en-têtes, en texte) ; 200 et
 * {success: true, message_ids: [...]} quand le message est déposé,
 * sinon 400, 401, 429 ou 500 et {success: false, errors: [...]}.
 *
 * Un refus lève une RefusDeMailtrap (une TransportException) en
 * français, qui dit la cause et le remède sans jamais montrer le jeton ni
 * le numéro de la boîte : ni dans le message, ni dans une exception
 * chaînée (celle du client HTTP porte l'adresse de la boîte), ni dans le
 * texte que rend l'API, où l'un et l'autre seraient masqués. Son code est le statut HTTP de la réponse
 * (0 quand l'API n'a pas répondu), et sa cause se lit sans deviner (voir
 * CauseDEchecDEnvoi::de()).
 */
class TransportMailtrap extends AbstractTransport
{
    public const ADRESSE_DE_L_API = 'https://sandbox.api.mailtrap.io/api/send/';

    /**
     * Les en-têtes que le corps JSON porte déjà, ou que l'API écrit
     * elle-même : ils ne repartent pas dans headers.
     *
     * @var list<string>
     */
    private const EN_TETES_DU_CORPS = ['from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding', 'return-path'];

    public function __construct(
        private readonly Factory $http,
        #[SensitiveParameter] private readonly ?string $jeton,
        #[SensitiveParameter] private readonly ?string $boite,
        private readonly int $delai,
    ) {
        parent::__construct();
    }

    /**
     * @throws RefusDeMailtrap
     */
    protected function doSend(SentMessage $message): void
    {
        $jeton = (string) $this->jeton;
        $boite = (string) $this->boite;
        $manquantes = array_keys(array_filter(['MAILTRAP_API_TOKEN' => $jeton, 'MAILTRAP_INBOX_ID' => $boite], fn (string $valeur): bool => trim($valeur) === ''));

        if ($manquantes !== []) {
            throw new RefusDeMailtrap('Le transport « mailtrap » demande '.implode(' et ', $manquantes).' dans le fichier .env (voir config/services.php) : l\'e-mail n\'est pas parti.', CauseDEchecDEnvoi::Configuration);
        }

        $corps = self::corps(MessageConverter::toEmail($message->getOriginalMessage()), $message->getEnvelope());

        try {
            $reponse = $this->http
                ->timeout($this->delai)
                ->connectTimeout($this->delai)
                ->withHeaders(['Api-Token' => $jeton, 'User-Agent' => 'PILIQI'])
                ->acceptJson()
                ->asJson()
                ->post(self::ADRESSE_DE_L_API.rawurlencode($boite), $corps);
        } catch (ConnectionException) {
            throw new RefusDeMailtrap("La boîte de test de Mailtrap n'a pas répondu dans le délai de {$this->delai} secondes (MAIL_TIMEOUT), ou ne se joint pas : vérifiez l'accès à sandbox.api.mailtrap.io, puis réessayez. L'e-mail n'est pas parti.", CauseDEchecDEnvoi::Indisponible);
        }

        if (! $reponse->successful() || $reponse->json('success') !== true) {
            throw $this->refus($reponse, $jeton, $boite);
        }

        $identifiants = $reponse->json('message_ids');

        if (is_array($identifiants) && is_string($identifiants[0] ?? null)) {
            $message->setMessageId($identifiants[0]);
        }
    }

    public function __toString(): string
    {
        return 'mailtrap';
    }

    /**
     * Le corps JSON de la requête, d'après la documentation de l'API :
     * l'expéditeur, les destinataires (ceux de l'enveloppe, hors copies),
     * les copies, l'adresse de réponse, l'objet, le texte, le HTML, les
     * pièces jointes et les autres en-têtes. Sans destinataire, sans
     * expéditeur ou avec plus d'une adresse de réponse, que l'API ne
     * prend pas, l'e-mail ne part pas.
     *
     * @return array<string, mixed>
     *
     * @throws RefusDeMailtrap
     */
    private static function corps(Email $email, Envelope $enveloppe): array
    {
        $copies = [...$email->getCc(), ...$email->getBcc()];
        $destinataires = array_values(array_filter($enveloppe->getRecipients(), fn (Address $adresse): bool => ! in_array($adresse, $copies, true)));
        $reponses = $email->getReplyTo();

        if (count($reponses) > 1) {
            throw new RefusDeMailtrap('Mailtrap n\'accepte qu\'une adresse de réponse par e-mail : l\'e-mail n\'est pas parti.', CauseDEchecDEnvoi::Inattendue);
        }

        return array_filter([
            'from' => self::adresse($enveloppe->getSender()),
            'to' => array_map(self::adresse(...), $destinataires),
            'cc' => array_map(self::adresse(...), $email->getCc()),
            'bcc' => array_map(self::adresse(...), $email->getBcc()),
            'reply_to' => $reponses === [] ? null : self::adresse($reponses[0]),
            'subject' => (string) $email->getSubject(),
            'text' => self::texte($email->getTextBody()),
            'html' => self::texte($email->getHtmlBody()),
            'attachments' => array_map(self::pieceJointe(...), $email->getAttachments()),
            'headers' => self::autresEnTetes($email),
        ], fn (mixed $valeur): bool => $valeur !== null && $valeur !== [] && $valeur !== '');
    }

    /**
     * @return array{email: string, name?: string}
     */
    private static function adresse(Address $adresse): array
    {
        return $adresse->getName() === ''
            ? ['email' => $adresse->getAddress()]
            : ['email' => $adresse->getAddress(), 'name' => $adresse->getName()];
    }

    /**
     * Le texte ou le HTML de l'e-mail, qu'il soit une chaîne ou un flux.
     *
     * @param  resource|string|null  $corps
     */
    private static function texte(mixed $corps): ?string
    {
        if (is_resource($corps)) {
            $lu = stream_get_contents($corps, -1, 0);

            return $lu === false ? null : $lu;
        }

        return is_string($corps) ? $corps : null;
    }

    /**
     * Une pièce jointe : son contenu en base64, son nom et son type ; une
     * pièce affichée dans le texte (inline) garde son identifiant.
     *
     * @return array{content: string, filename: string, type: string, disposition: string, content_id?: string}
     */
    private static function pieceJointe(DataPart $piece): array
    {
        $disposition = $piece->getDisposition() === 'inline' ? 'inline' : 'attachment';
        $champs = [
            'content' => base64_encode($piece->getBody()),
            'filename' => (string) $piece->getFilename(),
            'type' => "{$piece->getMediaType()}/{$piece->getMediaSubtype()}",
            'disposition' => $disposition,
        ];

        return $disposition === 'inline' && $piece->hasContentId() ? [...$champs, 'content_id' => $piece->getContentId()] : $champs;
    }

    /**
     * Les en-têtes que le corps ne porte pas déjà, en texte.
     *
     * @return array<string, string>
     */
    private static function autresEnTetes(Email $email): array
    {
        $enTetes = [];

        foreach ($email->getHeaders()->all() as $nom => $enTete) {
            if (! in_array(strtolower((string) $nom), self::EN_TETES_DU_CORPS, true)) {
                $enTetes[$enTete->getName()] = $enTete->getBodyAsString();
            }
        }

        return $enTetes;
    }

    /**
     * Le refus, en français, avec sa cause et son remède : le texte que
     * rend l'API n'y figure qu'une fois le jeton et le numéro de la boîte
     * masqués.
     */
    private function refus(Response $reponse, string $jeton, string $boite): RefusDeMailtrap
    {
        $erreurs = $reponse->json('errors') ?? $reponse->json('error');
        $detail = is_array($erreurs) ? implode(' ; ', array_filter($erreurs, is_string(...))) : (is_string($erreurs) ? $erreurs : '');
        $detail = trim(str_replace([$jeton, $boite], '[masqué]', $detail));
        $precision = $detail === '' ? '' : " (Mailtrap répond : « {$detail} »)";
        $statut = $reponse->status();

        [$message, $cause] = match (true) {
            in_array($statut, [401, 403], true) => ["Mailtrap a refusé le jeton d'API{$precision} : vérifiez MAILTRAP_API_TOKEN et ses droits sur la boîte de test.", CauseDEchecDEnvoi::Identifiants],
            $statut === 404 => ["Mailtrap ne connaît pas cette boîte de test{$precision} : vérifiez MAILTRAP_INBOX_ID.", CauseDEchecDEnvoi::Configuration],
            $statut === 429 => ["Mailtrap limite les envois{$precision} : le nombre d'e-mails permis par mois ou par 10 secondes est atteint ; réessayez plus tard.", CauseDEchecDEnvoi::ServeurOccupe],
            $reponse->serverError() => ["Mailtrap est indisponible (HTTP {$statut}){$precision} : réessayez plus tard.", CauseDEchecDEnvoi::Indisponible],
            default => ["Mailtrap a refusé l'e-mail (HTTP {$statut}){$precision}.", CauseDEchecDEnvoi::Inattendue],
        };

        return new RefusDeMailtrap("{$message} L'e-mail n'est pas parti.", $cause, $statut);
    }
}
